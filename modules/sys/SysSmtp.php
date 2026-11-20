<?php

declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;
use Intrabase\Security\Input;
use Intrabase\Security\Output;
use Intrabase\Security\CSRF;
use Intrabase\Security\Session;
use Intrabase\Support\Mailer;

/**
 * sys_smtp — SMTP Account Manager
 *
 * Stores outgoing SMTP accounts used for application emails.
 * Each account has a function_type (system / invoicing / marketing /
 * support) and an is_default flag; only one account per type may be
 * the default (enforced by onPreSave).
 *
 * Custom action 'test_email': sends a test email via this account.
 *
 * Table: ib_sys_smtp
 */
class SysSmtp extends Module
{
    public string $table = 'ib_sys_smtp';
    public string $key   = 'id';
    public string $title = 'SMTP Accounts';
    public string $icon  = 'fa-solid fa-paper-plane';

    public array $fields = [
        'id',
        'name',
        'smtp_host',
        'smtp_port',
        'from_email',
        'function_type',
        'is_default',
        'status',
    ];

    public array $showFields = [
        'name',
        'smtp_host',
        'smtp_port',
        'smtp_user',
        'smtp_secure',
        'smtp_auth',
        'from_email',
        'from_name',
        'reply_to',
        'function_type',
        'is_default',
        'status',
        'notes',
        'created_at',
        'updated_at',
    ];

    public array $editFields = [
        'name',
        'smtp_host',
        'smtp_port',
        'smtp_user',
        'smtp_password',
        'smtp_secure',
        'smtp_auth',
        'from_email',
        'from_name',
        'reply_to',
        'function_type',
        'is_default',
        'status',
        'notes',
    ];

    public array $searchFields = ['name', 'smtp_host', 'from_email'];

    public array $humans = [
        'id'            => 'ID',
        'name'          => 'Account Name',
        'smtp_host'     => 'SMTP Host',
        'smtp_port'     => 'Port',
        'smtp_user'     => 'Username',
        'smtp_password' => 'Password',
        'smtp_secure'   => 'Encryption',
        'smtp_auth'     => 'Authentication',
        'from_email'    => 'From Address',
        'from_name'     => 'From Name',
        'reply_to'      => 'Reply-To',
        'function_type' => 'Purpose',
        'is_default'    => 'Default Account',
        'status'        => 'Active',
        'notes'         => 'Notes',
        'created_at'    => 'Created',
        'updated_at'    => 'Updated',
    ];

    public array $components = [
        'smtp_password' => 'password',
        'smtp_secure'   => 'select',
        'smtp_auth'     => 'checkbox',
        'function_type' => 'select',
        'is_default'    => 'checkbox',
        'status'        => 'checkbox',
        'notes'         => 'textarea',
        'from_email'    => 'email',
        'reply_to'      => 'email',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    public array $selects = [
        'smtp_secure' => [
            'tls'  => 'TLS (recommended)',
            'ssl'  => 'SSL',
            'none' => 'None (insecure)',
        ],
        'function_type' => [
            'system'    => 'System',
            'invoicing' => 'Invoicing',
            'marketing' => 'Marketing',
            'support'   => 'Support',
        ],
    ];

    public array $validates  = ['name', 'smtp_host', 'smtp_port', 'smtp_user', 'from_email', 'function_type'];
    public array $readonly   = ['created_at', 'updated_at'];
    public array $hidden     = ['updated_at'];

    public string $orderBy  = 'function_type';
    public string $orderDir = 'ASC';

    public function __construct(mixed ...$args)
    {
        parent::__construct(...$args);

        $this->defaultValues['smtp_port']   = 587;
        $this->defaultValues['smtp_secure'] = 'tls';
        $this->defaultValues['smtp_auth']   = 1;
        $this->defaultValues['is_default']  = 0;
        $this->defaultValues['status']      = 1;
        $this->defaultValues['function_type'] = 'system';

        // Custom detail action: Send Test Email
        $this->customDetailActions[] = [
            'label'  => 'Send Test Email',
            'icon'   => 'fa-solid fa-vial',
            'class'  => 'ib-btn ib-btn--secondary',
            'action' => 'test_email',
            'method' => 'get',
        ];

        $this->setGroup('connection', [
            'label'  => 'Connection',
            'icon'   => 'fa-solid fa-server',
            'fields' => ['name', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_password', 'smtp_secure', 'smtp_auth'],
        ]);

        $this->setGroup('sender', [
            'label'  => 'Sender Identity',
            'icon'   => 'fa-solid fa-id-card',
            'fields' => ['from_email', 'from_name', 'reply_to'],
        ]);

        $this->setGroup('settings', [
            'label'  => 'Settings',
            'icon'   => 'fa-solid fa-sliders',
            'fields' => ['function_type', 'is_default', 'status', 'notes'],
        ]);
    }

    // =========================================================================
    // Custom execute — handle test_email action
    // =========================================================================

    public function execute(): void
    {
        $action = Input::get('_', 'db');

        $this->applyPermissions();

        if (!$this->canBrowse) {
            $this->renderDenied();
            return;
        }

        if ($action === 'test_email') {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->handleTestEmailSend();
            } else {
                $this->renderTestEmailForm();
            }
            return;
        }

        parent::execute();
    }

    // =========================================================================
    // Test Email: render form
    // =========================================================================

    private function renderTestEmailForm(): void
    {
        $smtpId = (int) Input::get('id', '0');
        if ($smtpId <= 0) {
            header('Location: ' . \Intrabase\Core\Router::url('sys_smtp'));
            exit;
        }

        $smtp = $this->db->fetchOne("SELECT * FROM {$this->table} WHERE id = :id", ['id' => $smtpId]);
        if (!$smtp) {
            header('Location: ' . \Intrabase\Core\Router::url('sys_smtp'));
            exit;
        }

        $session  = $this->container->get(Session::class);
        $userId   = $session->userId();
        $userRow  = $this->db->fetchOne("SELECT email FROM ib_users WHERE id = :id", ['id' => $userId]);
        $myEmail  = $userRow['email'] ?? '';

        $csrfToken = $this->csrf->token();
        $smtpName  = Output::e($smtp['name']);

        ob_start();
        ?>
        <div class="ib-section">
            <div class="ib-section__header">
                <h2 class="ib-section__title">
                    <i class="fa-solid fa-vial"></i>
                    Send Test Email — <?= $smtpName ?>
                </h2>
            </div>
            <div class="ib-section__body">
                <p>This will send a test email using the <strong><?= $smtpName ?></strong> SMTP account to verify the configuration is correct.</p>

                <form method="post" action="?mod=sys_smtp&_=test_email&id=<?= $smtpId ?>" class="ib-form">
                    <input type="hidden" name="_token" value="<?= Output::attr($csrfToken) ?>">
                    <input type="hidden" name="smtp_id" value="<?= $smtpId ?>">

                    <div class="ib-form__group">
                        <label class="ib-form__label" for="test_to">Send To (Email)</label>
                        <input type="email" id="test_to" name="test_to"
                               class="ib-form__input"
                               value="<?= Output::attr($myEmail) ?>"
                               required
                               placeholder="your@email.com">
                    </div>

                    <div class="ib-form__group">
                        <label class="ib-form__label" for="test_subject">Subject</label>
                        <input type="text" id="test_subject" name="test_subject"
                               class="ib-form__input"
                               value="Intrabase SMTP Test — <?= $smtpName ?>"
                               required>
                    </div>

                    <div class="ib-form__actions">
                        <button type="submit" class="ib-btn ib-btn--primary">
                            <i class="fa-solid fa-paper-plane"></i> Send Test Email
                        </button>
                        <a href="?mod=sys_smtp&_=d&id=<?= $smtpId ?>" class="ib-btn ib-btn--ghost">
                            <i class="fa-solid fa-arrow-left"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
        <?php
        $content = ob_get_clean();

        $this->renderLayout($content);
    }

    // =========================================================================
    // Test Email: handle POST send
    // =========================================================================

    private function handleTestEmailSend(): void
    {
        if (!$this->csrf->validate(Input::post('_token', ''))) {
            $_SESSION['flash_error'] = 'CSRF token validation failed.';
            header('Location: ?mod=sys_smtp');
            exit;
        }

        $smtpId  = (int) Input::post('smtp_id', '0');
        $toEmail = filter_var(Input::post('test_to', ''), FILTER_VALIDATE_EMAIL);
        $subject = trim(Input::post('test_subject', 'Intrabase SMTP Test'));

        if (!$toEmail) {
            $_SESSION['flash_error'] = 'Invalid email address.';
            header("Location: ?mod=sys_smtp&_=test_email&id={$smtpId}");
            exit;
        }

        $smtp = $this->db->fetchOne("SELECT * FROM {$this->table} WHERE id = :id", ['id' => $smtpId]);
        if (!$smtp) {
            $_SESSION['flash_error'] = 'SMTP account not found.';
            header('Location: ?mod=sys_smtp');
            exit;
        }

        $htmlBody = '
<h2 style="color:#1a1a2e;">SMTP Test Email</h2>
<p>This is a test email sent from <strong>Intrabase</strong> to verify your SMTP configuration.</p>
<table style="border-collapse:collapse;margin-top:16px;">
  <tr><td style="padding:4px 8px;color:#666;">Account</td><td style="padding:4px 8px;"><strong>' . htmlspecialchars($smtp['name']) . '</strong></td></tr>
  <tr><td style="padding:4px 8px;color:#666;">Host</td><td style="padding:4px 8px;">' . htmlspecialchars($smtp['smtp_host']) . ':' . (int)$smtp['smtp_port'] . '</td></tr>
  <tr><td style="padding:4px 8px;color:#666;">Encryption</td><td style="padding:4px 8px;">' . strtoupper($smtp['smtp_secure']) . '</td></tr>
  <tr><td style="padding:4px 8px;color:#666;">From</td><td style="padding:4px 8px;">' . htmlspecialchars($smtp['from_email']) . '</td></tr>
  <tr><td style="padding:4px 8px;color:#666;">Sent At</td><td style="padding:4px 8px;">' . date('Y-m-d H:i:s') . '</td></tr>
</table>
<p style="color:#888;margin-top:20px;font-size:13px;">If you received this email, your SMTP configuration is working correctly.</p>';

        $mailer = new Mailer($this->db);
        $ok     = $mailer->send($toEmail, '', $subject, $htmlBody, '', $smtpId);

        if ($ok) {
            $_SESSION['flash_success'] = "Test email sent successfully to {$toEmail}. Check your inbox.";
        } else {
            $_SESSION['flash_error'] = 'Failed to send test email. Check SMTP settings and server logs.';
        }

        header("Location: ?mod=sys_smtp&_=d&id={$smtpId}");
        exit;
    }

    // =========================================================================
    // onPreSave
    // =========================================================================

    /**
     * Ensure only one default account per function_type.
     */
    public function onPreSave(array &$data): bool
    {
        $isDefault     = !empty($data['is_default']) && (int) $data['is_default'] === 1;
        $functionType  = $data['function_type'] ?? '';
        $currentId     = (int) ($data[$this->key] ?? 0);

        if ($isDefault && $functionType !== '') {
            // Clear default flag on other accounts of the same type
            $pdo  = $this->db->pdo();
            $sql  = 'UPDATE ib_sys_smtp SET is_default = 0
                      WHERE function_type = :ft AND is_default = 1';
            $params = [':ft' => $functionType];

            if ($currentId > 0) {
                $sql     .= ' AND id != :id';
                $params[':id'] = $currentId;
            }

            $pdo->prepare($sql)->execute($params);
        }

        return true;
    }
}
