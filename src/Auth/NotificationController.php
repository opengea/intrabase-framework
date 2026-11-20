<?php

declare(strict_types=1);

namespace Intrabase\Auth;

use Intrabase\Database\Connection;
use Intrabase\Security\CSRF;
use Intrabase\Security\Session;
use PDO;

/**
 * NotificationController
 *
 * Handles AJAX endpoints for notification management:
 *   GET  /notifications/poll         — unread count + new unread items (+ panel list)
 *   GET  /notifications/history      — paginated history (read + unread), keyset by id
 *   POST /notifications/mark-read    — mark all (or one) notification(s) as read
 *   POST /notifications/mark-unread  — mark one notification as unread
 *
 * Every query is constrained by scopeSql(): a notification belonging to another
 * user_id is never readable nor writable through these endpoints.
 */
final class NotificationController
{
    public function __construct(
        private readonly Connection $db,
        private readonly Session    $session,
        private readonly CSRF       $csrf,
    ) {}

    private function activeEntityId(): ?int
    {
        $active = $this->session->get('_ib_active_entity');
        if (!is_array($active)) return null;
        $id = (int)($active['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /**
     * Visibility/ownership predicate shared by every query in this controller.
     * Placeholders: :uid always, :eid only when $eid !== null (bind via bindScope()).
     *
     * @param bool $withExpiry include the expiry filter (visibility). Write paths that
     *                         historically ignored expires_at pass false to keep behaviour.
     */
    private function scopeSql(?int $eid, bool $withExpiry = true): string
    {
        $sql = '(user_id = :uid OR user_id IS NULL)'
             . ' AND (entity_id IS NULL' . ($eid !== null ? ' OR entity_id = :eid' : '') . ')';
        if ($withExpiry) {
            $sql .= ' AND (expires_at IS NULL OR expires_at > NOW())';
        }
        return $sql;
    }

    private function bindScope(\PDOStatement $stmt, int $uid, ?int $eid, bool $withRead = false): void
    {
        $stmt->bindValue('uid', $uid, PDO::PARAM_INT);
        if ($eid !== null) $stmt->bindValue('eid', $eid, PDO::PARAM_INT);
        if ($withRead) $stmt->bindValue('ruid', $uid, PDO::PARAM_INT);
    }

    /**
     * Read state as seen by THIS user. A personal notification keeps it in its
     * own row (is_read); a broadcast one (user_id IS NULL) is shared by many
     * users, so each user's read mark lives in ib_sys_notification_reads —
     * otherwise the first reader would mark it read for everybody.
     * Placeholder :ruid (bind with bindScope(..., true)).
     */
    private function readSql(): string
    {
        return '(CASE WHEN ib_sys_notifications.user_id IS NULL'
             . ' THEN EXISTS (SELECT 1 FROM ib_sys_notification_reads r'
             . ' WHERE r.notification_id = ib_sys_notifications.id AND r.user_id = :ruid)'
             . ' ELSE ib_sys_notifications.is_read END)';
    }

    /** Unread count as shown by the header badge. */
    private function unreadCount(PDO $pdo, int $uid, ?int $eid): int
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM ib_sys_notifications
              WHERE " . $this->readSql() . " = 0
                AND " . $this->scopeSql($eid)
        );
        $this->bindScope($stmt, $uid, $eid, true);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /** True when the notification exists and is visible to this user/entity. */
    private function ownsNotification(PDO $pdo, int $nid, int $uid, ?int $eid): bool
    {
        $stmt = $pdo->prepare(
            "SELECT id FROM ib_sys_notifications
              WHERE id = :nid
                AND " . $this->scopeSql($eid) . "
              LIMIT 1"
        );
        $stmt->bindValue('nid', $nid, PDO::PARAM_INT);
        $this->bindScope($stmt, $uid, $eid);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    public function handle(): void
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if ($path === '/notifications/mark-read') {
            $this->markRead();
            return;
        }
        if ($path === '/notifications/mark-unread') {
            $this->markUnread();
            return;
        }
        if ($path === '/notifications/history') {
            $this->history();
            return;
        }
        if ($path === '/notifications/poll') {
            $this->poll();
            return;
        }
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
    }

    /**
     * GET /notifications/poll?since=<id>[&list=1][&check=<id,id,…>]
     * Returns the current unread count + any new unread notifications with id > since.
     * When list=1 also returns the latest 5 notifications (read+unread) for panel rebuild.
     * When check= lists notification ids the page is currently showing as
     * "event starts now" modals, `gone` returns those that are no longer
     * pending here (read from another window or device, expired, deleted),
     * so the page can take its modal down instead of waiting for a click.
     */
    private function poll(): void
    {
        header('Content-Type: application/json');

        if (!$this->session->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthenticated']);
            return;
        }

        $user = $this->session->user();
        $uid  = (int)($user['id'] ?? 0);
        if ($uid <= 0) {
            echo json_encode(['ok' => true, 'unread' => 0, 'items' => [], 'list' => []]);
            return;
        }

        $since    = isset($_GET['since']) ? (int)$_GET['since'] : 0;
        $wantList = isset($_GET['list']) && (string)$_GET['list'] === '1';
        $pdo      = $this->db->pdo();

        $eid   = $this->activeEntityId();
        $scope = $this->scopeSql($eid);

        $unread = $this->unreadCount($pdo, $uid, $eid);

        $itemsStmt = $pdo->prepare(
            "SELECT id, notification_type, title, message, url, icon_class, created_at
               FROM ib_sys_notifications
              WHERE {$this->readSql()} = 0
                AND id > :since
                AND {$scope}
              ORDER BY id DESC
              LIMIT 20"
        );
        $itemsStmt->bindValue('since', $since, PDO::PARAM_INT);
        $this->bindScope($itemsStmt, $uid, $eid, true);
        $itemsStmt->execute();
        $rows = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = [];
        foreach ($rows as $r) {
            $createdTs = strtotime((string)($r['created_at'] ?? ''));
            $items[] = [
                'id'    => (int)$r['id'],
                'type'  => (string)($r['notification_type'] ?? 'info'),
                'title' => (string)($r['title'] ?? ''),
                'msg'   => (string)($r['message'] ?? ''),
                'url'   => (string)($r['url'] ?? ''),
                'icon'  => (string)($r['icon_class'] ?? 'fa-solid fa-bell'),
                // Unix creation time: the page uses it to age out "event starts
                // now" prompts that predate the &until= stamp on the URL.
                'ts'    => $createdTs === false ? 0 : $createdTs,
            ];
        }

        // Which of the modals the page still shows are no longer pending?
        $gone = [];
        $checkIds = [];
        foreach (explode(',', (string)($_GET['check'] ?? '')) as $piece) {
            $cid = (int)trim($piece);
            if ($cid > 0) $checkIds[$cid] = $cid;
        }
        if ($checkIds !== []) {
            $checkIds = array_slice(array_values($checkIds), 0, 50);
            $marks = implode(',', array_fill(0, count($checkIds), '?'));
            // Positional placeholders for the ids, named ones for the scope:
            // PDO can't mix them, so the scope is inlined here.
            $checkStmt = $pdo->prepare(
                "SELECT id FROM ib_sys_notifications
                  WHERE id IN ({$marks})
                    AND (CASE WHEN user_id IS NULL
                              THEN EXISTS (SELECT 1 FROM ib_sys_notification_reads r
                                            WHERE r.notification_id = ib_sys_notifications.id AND r.user_id = ?)
                              ELSE is_read END) = 0
                    AND (user_id = ? OR user_id IS NULL)
                    AND (entity_id IS NULL" . ($eid !== null ? ' OR entity_id = ?' : '') . ")
                    AND (expires_at IS NULL OR expires_at > NOW())"
            );
            $params = $checkIds;
            $params[] = $uid;   // read mark of broadcasts
            $params[] = $uid;   // scope
            if ($eid !== null) $params[] = $eid;
            $checkStmt->execute($params);
            $still = array_map('intval', $checkStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $gone  = array_values(array_diff($checkIds, $still));
        }

        $list = [];
        if ($wantList) {
            $listStmt = $pdo->prepare(
                "SELECT id, notification_type, title, message, url, icon_class, 0 AS is_read, created_at
                   FROM ib_sys_notifications
                  WHERE {$this->readSql()} = 0
                    AND {$scope}
                  ORDER BY created_at DESC
                  LIMIT 5"
            );
            $this->bindScope($listStmt, $uid, $eid, true);
            $listStmt->execute();
            $listRows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($listRows as $r) {
                $list[] = [
                    'id'         => (int)$r['id'],
                    'type'       => (string)($r['notification_type'] ?? 'info'),
                    'title'      => (string)($r['title'] ?? ''),
                    'msg'        => (string)($r['message'] ?? ''),
                    'url'        => (string)($r['url'] ?? ''),
                    'icon'       => (string)($r['icon_class'] ?? 'fa-solid fa-bell'),
                    'is_read'    => (int)($r['is_read'] ?? 0),
                    'created_at' => (string)($r['created_at'] ?? ''),
                ];
            }
        }

        echo json_encode(['ok' => true, 'unread' => $unread, 'items' => $items, 'list' => $list, 'gone' => $gone]);
    }

    /**
     * GET /notifications/history?before=<id>&limit=<n>
     * Paginated history (read AND unread), newest first, keyset by id.
     * before absent/0 = first page. limit defaults to 30, clamped to 1..50.
     */
    private function history(): void
    {
        header('Content-Type: application/json');

        if (!$this->session->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthenticated']);
            return;
        }

        $user = $this->session->user();
        $uid  = (int)($user['id'] ?? 0);
        if ($uid <= 0) {
            echo json_encode([
                'ok'          => true,
                'items'       => [],
                'has_more'    => false,
                'next_before' => null,
                'unread'      => 0,
            ]);
            return;
        }

        $before = isset($_GET['before']) ? (int)$_GET['before'] : 0;
        $limit  = isset($_GET['limit']) ? (int)$_GET['limit'] : 30;
        if ($limit < 1)  $limit = 1;
        if ($limit > 50) $limit = 50;
        $fetch = $limit + 1; // one extra row tells us whether another page exists

        $pdo   = $this->db->pdo();
        $eid   = $this->activeEntityId();
        $scope = $this->scopeSql($eid);

        $beforeClause = $before > 0 ? 'id < :before AND ' : '';

        $stmt = $pdo->prepare(
            "SELECT id, notification_type, title, message, url, icon_class,
                    {$this->readSql()} AS is_read, created_at
               FROM ib_sys_notifications
              WHERE {$beforeClause}{$scope}
              ORDER BY id DESC
              LIMIT {$fetch}"
        );
        if ($before > 0) $stmt->bindValue('before', $before, PDO::PARAM_INT);
        $this->bindScope($stmt, $uid, $eid, true);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id'         => (int)$r['id'],
                'type'       => (string)($r['notification_type'] ?? 'info'),
                'title'      => (string)($r['title'] ?? ''),
                'msg'        => (string)($r['message'] ?? ''),
                'url'        => (string)($r['url'] ?? ''),
                'icon'       => (string)($r['icon_class'] ?? 'fa-solid fa-bell'),
                'is_read'    => (int)($r['is_read'] ?? 0),
                'created_at' => (string)($r['created_at'] ?? ''),
            ];
        }

        $nextBefore = ($hasMore && $items) ? (int)$items[count($items) - 1]['id'] : null;

        echo json_encode([
            'ok'          => true,
            'items'       => $items,
            'has_more'    => $hasMore,
            'next_before' => $nextBefore,
            'unread'      => $this->unreadCount($pdo, $uid, $eid),
        ]);
    }

    private function markRead(): void
    {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        // CSRF check (reads _token from POST or X-CSRF-Token header)
        if (!$this->csrf->validateToken()) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid CSRF token']);
            return;
        }

        if (!$this->session->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthenticated']);
            return;
        }

        $user = $this->session->user();
        $uid  = (int)($user['id'] ?? 0);
        if ($uid <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'No user']);
            return;
        }

        $pdo = $this->db->pdo();
        $nid = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $eid = $this->activeEntityId();
        if ($nid > 0 && !$this->ownsNotification($pdo, $nid, $uid, $eid)) {
            // Ownership first: a notification of another user is a 404, with no write at all.
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            return;
        }
        $only = $nid > 0 ? ' AND id = :nid' : '';

        // Personal notifications: the row's own flag.
        $stmt = $pdo->prepare(
            "UPDATE ib_sys_notifications
                SET is_read = 1, read_at = NOW()
              WHERE user_id = :uid
                AND (entity_id IS NULL" . ($eid !== null ? ' OR entity_id = :eid' : '') . ")
                AND is_read = 0{$only}"
        );
        if ($nid > 0) $stmt->bindValue('nid', $nid, PDO::PARAM_INT);
        $this->bindScope($stmt, $uid, $eid);
        $stmt->execute();

        // Broadcast notifications: this user's read mark only.
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO ib_sys_notification_reads (notification_id, user_id, read_at)
             SELECT id, :ruid, NOW() FROM ib_sys_notifications
              WHERE user_id IS NULL
                AND (entity_id IS NULL" . ($eid !== null ? ' OR entity_id = :eid' : '') . "){$only}"
        );
        if ($nid > 0) $stmt->bindValue('nid', $nid, PDO::PARAM_INT);
        $stmt->bindValue('ruid', $uid, PDO::PARAM_INT);
        if ($eid !== null) $stmt->bindValue('eid', $eid, PDO::PARAM_INT);
        $stmt->execute();

        // Return updated unread count
        echo json_encode(['ok' => true, 'unread' => $this->unreadCount($pdo, $uid, $eid)]);
    }

    /**
     * POST /notifications/mark-unread   body: id=<n> (+ CSRF)
     * Marks a single notification as unread. Ownership is verified before writing.
     */
    private function markUnread(): void
    {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        // CSRF check (reads _token from POST or X-CSRF-Token header)
        if (!$this->csrf->validateToken()) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid CSRF token']);
            return;
        }

        if (!$this->session->isAuthenticated()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthenticated']);
            return;
        }

        $user = $this->session->user();
        $uid  = (int)($user['id'] ?? 0);
        if ($uid <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'No user']);
            return;
        }

        $nid = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($nid <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid id']);
            return;
        }

        $pdo = $this->db->pdo();
        $eid = $this->activeEntityId();

        // Ownership first: a notification of another user is a 404, with no write at all.
        if (!$this->ownsNotification($pdo, $nid, $uid, $eid)) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            return;
        }

        // Personal: the row's own flag. Broadcast: drop only this user's read mark.
        $stmt = $pdo->prepare(
            'UPDATE ib_sys_notifications SET is_read = 0, read_at = NULL WHERE id = :nid AND user_id = :uid'
        );
        $stmt->execute(['nid' => $nid, 'uid' => $uid]);
        $stmt = $pdo->prepare(
            'DELETE FROM ib_sys_notification_reads WHERE notification_id = :nid AND user_id = :uid'
        );
        $stmt->execute(['nid' => $nid, 'uid' => $uid]);

        echo json_encode(['ok' => true, 'unread' => $this->unreadCount($pdo, $uid, $eid)]);
    }
}
