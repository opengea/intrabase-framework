<?php

declare(strict_types=1);

namespace Intrabase\Support;

use Intrabase\Database\Connection;
use PDO;

/**
 * NotificationEngine
 *
 * Handles automatic notifications triggered by module events.
 * Processes notification rules from ib_sys_notification_rules,
 * substitutes {{variable}} placeholders in email templates,
 * and queues/sends notifications.
 *
 * Variable substitution supports:
 *   Global: {{app_name}}, {{app_url}}, {{current_date}}, {{current_year}}
 *   User:   {{user_name}}, {{user_email}}
 *   Record: any field from the saved record ({{invoice_number}}, {{client_name}}, etc.)
 */
final class NotificationEngine
{
    private Mailer $mailer;

    public function __construct(
        private readonly Connection $db,
    ) {
        $this->mailer = new Mailer($db);
    }

    /**
     * Trigger notifications for a module event.
     *
     * @param string $moduleKey   e.g. 'erp_invoices'
     * @param string $event       'record_created' | 'record_updated' | 'status_changed'
     * @param array  $record      Full record data (all fields)
     * @param int    $userId      Acting user ID
     * @param array  $changedFields  Optional: keys that changed (for status_changed)
     */
    public function trigger(
        string $moduleKey,
        string $event,
        array  $record,
        int    $userId,
        array  $changedFields = [],
    ): void {
        try {
            $rules = $this->db->fetchAll(
                "SELECT r.*, t.subject_line, t.body_html, t.body_text
                   FROM ib_sys_notification_rules r
                   LEFT JOIN ib_lib_email_templates t ON t.id = r.template_id
                  WHERE r.module_key = :mk AND r.trigger_event = :ev AND r.is_active = 1
                  ORDER BY r.sort_order ASC",
                ['mk' => $moduleKey, 'ev' => $event]
            );

            foreach ($rules as $rule) {
                // Check field condition
                if (!$this->checkCondition($rule, $record, $changedFields)) {
                    continue;
                }

                // Resolve recipients
                $recipients = $this->resolveRecipients($rule, $record, $userId);

                // Build variables for substitution
                $vars = $this->buildVariables($record, $userId);

                foreach ($recipients as $recipient) {
                    $notifType = $rule['notification_type'] ?? 'in_app';

                    if (in_array($notifType, ['in_app', 'both'], true)) {
                        $this->createInAppNotification(
                            userId: (int) $recipient['user_id'],
                            title: $this->substitute($rule['rule_name'], $vars),
                            message: '',
                            type: 'info',
                        );
                    }

                    if (in_array($notifType, ['email', 'both'], true) && !empty($recipient['email'])) {
                        $subject = $this->substitute((string)($rule['subject_line'] ?? $rule['rule_name']), $vars);
                        $html    = $this->substitute((string)($rule['body_html'] ?? ''), $vars);
                        $text    = $this->substitute((string)($rule['body_text'] ?? ''), $vars);

                        if (empty($html)) {
                            $html = '<p>' . nl2br(htmlspecialchars($text ?: $rule['rule_name'])) . '</p>';
                        }

                        $delayMin = (int)($rule['delay_minutes'] ?? 0);
                        if ($delayMin > 0) {
                            // Queue for later
                            $this->mailer->queue(
                                toEmail: $recipient['email'],
                                toName: $recipient['name'] ?? '',
                                subject: $subject,
                                htmlBody: $this->wrapEmailLayout($html, $subject),
                                textBody: $text,
                                relatedModule: $moduleKey,
                                relatedId: (int)($record['id'] ?? 0),
                            );
                        } else {
                            // Queue immediately (processed by cron)
                            $this->mailer->queue(
                                toEmail: $recipient['email'],
                                toName: $recipient['name'] ?? '',
                                subject: $subject,
                                htmlBody: $this->wrapEmailLayout($html, $subject),
                                textBody: $text,
                                relatedModule: $moduleKey,
                                relatedId: (int)($record['id'] ?? 0),
                            );
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[NotificationEngine] Error: ' . $e->getMessage());
        }
    }

    /**
     * Send an email directly using a template + record data for variable substitution.
     */
    public function sendFromTemplate(
        int    $templateId,
        string $toEmail,
        string $toName,
        array  $record  = [],
        int    $userId  = 0,
        bool   $queue   = true,
    ): bool {
        try {
            $tpl = $this->db->fetchOne(
                "SELECT * FROM ib_lib_email_templates WHERE id = :id AND is_active = 1",
                ['id' => $templateId]
            );
            if (!$tpl) {
                return false;
            }

            $vars    = $this->buildVariables($record, $userId);
            $subject = $this->substitute((string)($tpl['subject_line'] ?? ''), $vars);
            $html    = $this->substitute((string)($tpl['body_html'] ?? ''), $vars);
            $text    = $this->substitute((string)($tpl['body_text'] ?? ''), $vars);

            $html = $this->wrapEmailLayout($html, $subject, $tpl);

            if ($queue) {
                return $this->mailer->queue($toEmail, $toName, $subject, $html, $text);
            } else {
                return $this->mailer->send($toEmail, $toName, $subject, $html, $text);
            }
        } catch (\Throwable $e) {
            error_log('[NotificationEngine] sendFromTemplate error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Substitute {{variable}} placeholders in a string.
     */
    public function substitute(string $template, array $vars): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', function ($m) use ($vars) {
            $key = strtolower($m[1]);
            return $vars[$key] ?? $vars[$m[1]] ?? '';
        }, $template);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function checkCondition(array $rule, array $record, array $changedFields): bool
    {
        $field = $rule['trigger_field'] ?? '';
        $value = $rule['trigger_value'] ?? '';

        if ($field === '') {
            return true; // No condition = always fire
        }

        // For status_changed events, check if the field actually changed
        if ($rule['trigger_event'] === 'status_changed' && !in_array($field, $changedFields, true)) {
            return false;
        }

        if ($value === '') {
            return true; // Has field condition but no value = just check field changed
        }

        return isset($record[$field]) && (string) $record[$field] === (string) $value;
    }

    private function resolveRecipients(array $rule, array $record, int $actingUserId): array
    {
        $targetType = $rule['target_type'] ?? 'creator';
        $recipients = [];

        switch ($targetType) {
            case 'creator':
                $uid = $actingUserId;
                $user = $uid ? $this->loadUser($uid) : null;
                if ($user) {
                    $recipients[] = $user;
                }
                break;

            case 'owner_field':
                $ownerField = $rule['trigger_field'] ?? 'created_by';
                $uid = (int)($record[$ownerField] ?? 0);
                $user = $uid ? $this->loadUser($uid) : null;
                if ($user) {
                    $recipients[] = $user;
                }
                break;

            case 'user':
                $uid = (int)($rule['target_user_id'] ?? 0);
                $user = $uid ? $this->loadUser($uid) : null;
                if ($user) {
                    $recipients[] = $user;
                }
                break;

            case 'group':
                $gid = (int)($rule['target_group_id'] ?? 0);
                if ($gid) {
                    $users = $this->db->fetchAll(
                        "SELECT u.id as user_id, u.name, u.email
                           FROM ib_users u
                           JOIN ib_user_groups ug ON ug.user_id = u.id
                          WHERE ug.group_id = :gid AND u.active = 1",
                        ['gid' => $gid]
                    );
                    $recipients = array_merge($recipients, $users);
                }
                break;
        }

        return $recipients;
    }

    private function loadUser(int $userId): ?array
    {
        return $this->db->fetchOne(
            "SELECT id as user_id, name, email FROM ib_users WHERE id = :id AND active = 1",
            ['id' => $userId]
        ) ?: null;
    }

    private function buildVariables(array $record, int $userId): array
    {
        // Global variables
        $config   = $this->loadAppConfig();
        $appName  = $config['app_name']  ?? 'Intrabase';
        $appUrl   = $config['app_url']   ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

        $vars = [
            'app_name'    => $appName,
            'app_url'     => $appUrl,
            'current_date' => date('d/m/Y'),
            'current_year' => date('Y'),
        ];

        // Acting user variables
        if ($userId > 0) {
            $user = $this->loadUser($userId);
            if ($user) {
                $vars['user_name']  = $user['name'] ?? '';
                $vars['user_email'] = $user['email'] ?? '';
            }
        }

        // Record variables (all fields, lowercased)
        foreach ($record as $k => $v) {
            if (!is_array($v) && !is_object($v)) {
                $vars[strtolower((string)$k)] = (string)($v ?? '');
            }
        }

        return $vars;
    }

    private function loadAppConfig(): array
    {
        return EmailLayout::appConfig($this->db);
    }

    private function createInAppNotification(
        int    $userId,
        string $title,
        string $message = '',
        string $type    = 'info',
    ): void {
        try {
            $this->db->insert('ib_sys_notifications', [
                'user_id'           => $userId,
                'notification_type' => $type,
                'title'             => mb_substr($title, 0, 200),
                'message'           => mb_substr($message, 0, 1000),
                'is_read'           => 0,
                'created_at'        => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[NotificationEngine] createInAppNotification error: ' . $e->getMessage());
        }
    }

    /**
     * Wrap email body in a responsive HTML layout using template settings,
     * or a minimal default layout if no template settings are provided.
     *
     * The markup itself now lives in EmailLayout so the cron reminder mails can
     * reuse it (see EmailLayout); this stays as the engine's own entry point so
     * call sites and behaviour are unchanged.
     */
    private function wrapEmailLayout(string $body, string $subject, ?array $tpl = null): string
    {
        return EmailLayout::wrap($this->db, $body, $subject, $tpl);
    }
}
