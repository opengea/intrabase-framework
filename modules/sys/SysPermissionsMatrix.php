<?php

declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;
use Intrabase\Core\Router;
use Intrabase\Security\Input;
use Intrabase\Security\Output;
use Intrabase\Security\Session;

/**
 * SysPermissionsMatrix — Visual Permission Matrix
 *
 * Renders a group × module matrix with checkboxes for each action.
 * Reads/writes ib_permissions. When no row exists for a (group, module),
 * the module's default flags apply (open access).
 *
 * Layout:
 *   - Left panel: group list
 *   - Right panel: table of all modules × browse/create/edit/delete/export
 */
class SysPermissionsMatrix extends Module
{
    public string $table = '';
    public string $key   = 'id';
    public string $title = 'Matriu de Permisos';
    public string $icon  = 'fa-solid fa-shield-halved';

    public bool $canAdd    = false;
    public bool $canEdit   = false;
    public bool $canDelete = false;
    public bool $canExport = false;

    // -------------------------------------------------------------------------
    // Entry point
    // -------------------------------------------------------------------------

    public function execute(): void
    {
        $this->applyPermissions();

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($method === 'POST') {
            header('Content-Type: application/json; charset=utf-8');
            $this->handlePost();
            return;
        }

        ob_start();
        $this->renderPage();
        $html = ob_get_clean();
        $this->renderLayout((string) $html);
    }

    // -------------------------------------------------------------------------
    // POST: save permissions for one group
    // -------------------------------------------------------------------------

    private function handlePost(): void
    {
        if (!$this->csrf->validateToken()) {
            echo json_encode(['ok' => false, 'error' => 'Token invàlid']);
            return;
        }

        // Changing permissions is changing who can do what (24/11/2026): only a
        // member of the admin group, and only if no rule (ib_permissions or a
        // read-only level on the app) takes edit rights on this module away.
        // Reaching the page is not enough: whoever gets the System app through a
        // group of their own could otherwise grant themselves everything.
        if (!$this->mayChangePermissions()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Accés denegat']);
            return;
        }

        $action  = Input::post('action', '');
        $groupId = (int) Input::post('group_id', '0');

        if ($groupId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Group ID invàlid']);
            return;
        }

        if ($action === 'save_group') {
            $this->actionSaveGroup($groupId);
        } elseif ($action === 'preset') {
            $this->actionPreset($groupId);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Acció desconeguda']);
        }
    }

    private function mayChangePermissions(): bool
    {
        if ($this->userReadOnly) {
            return false;
        }
        $session = $this->container->get(\Intrabase\Security\Session::class);
        $userId  = (int) ($session->userId() ?? 0);
        if ($userId <= 0) {
            return false;
        }
        $rows = $this->db->fetchAll(
            'SELECT g.id, g.name FROM ib_user_groups ug JOIN ib_groups g ON g.id = ug.group_id WHERE ug.user_id = :uid',
            ['uid' => $userId]
        );
        if (!in_array('admin', array_column($rows, 'name'), true)) {
            return false;
        }
        $rule = \Intrabase\Security\Permission::check(
            array_map('intval', array_column($rows, 'id')),
            \Intrabase\Core\Router::currentMod() ?: 'sys_permissions_matrix',
            'edit',
            $this->db
        );
        return $rule !== false;
    }

    private function actionSaveGroup(int $groupId): void
    {
        // perms is a JSON object: { "module_key": { "browse":1, "create":0, ... }, ... }
        $permsRaw = Input::post('perms', '{}');
        $perms    = json_decode($permsRaw, true);

        if (!is_array($perms)) {
            echo json_encode(['ok' => false, 'error' => 'Format invàlid']);
            return;
        }

        $pdo = $this->db->pdo();

        // Delete existing permissions for this group
        $del = $pdo->prepare('DELETE FROM ib_permissions WHERE group_id = :gid');
        $del->execute([':gid' => $groupId]);

        // Insert new permissions (only where not all defaults)
        $ins = $pdo->prepare(
            'INSERT INTO ib_permissions
                (group_id, module_key, can_browse, can_create, can_edit, can_delete, can_export)
             VALUES
                (:gid, :mk, :br, :cr, :ed, :dl, :ex)'
        );

        foreach ($perms as $moduleKey => $actions) {
            if (!is_array($actions)) {
                continue;
            }
            $moduleKey = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $moduleKey));
            // No rule for the matrix itself: a preset or a save must never leave
            // the admins unable to undo it (mayChangePermissions() reads it).
            if ($moduleKey === '' || $moduleKey === 'sys_permissions_matrix') {
                continue;
            }

            $ins->execute([
                ':gid' => $groupId,
                ':mk'  => $moduleKey,
                ':br'  => (int) ($actions['browse'] ?? 1),
                ':cr'  => (int) ($actions['create'] ?? 1),
                ':ed'  => (int) ($actions['edit']   ?? 1),
                ':dl'  => (int) ($actions['delete'] ?? 0),
                ':ex'  => (int) ($actions['export'] ?? 1),
            ]);
        }

        echo json_encode(['ok' => true, 'message' => 'Permisos desats correctament.']);
    }

    private function actionPreset(int $groupId): void
    {
        $preset = Input::post('preset', '');
        // admin = all allowed, readonly = only browse, none = all denied
        $presets = [
            'admin'    => [1, 1, 1, 1, 1],
            'readonly' => [1, 0, 0, 0, 1],
            'none'     => [0, 0, 0, 0, 0],
        ];

        if (!isset($presets[$preset])) {
            echo json_encode(['ok' => false, 'error' => 'Preset desconegut']);
            return;
        }

        [$br, $cr, $ed, $dl, $ex] = $presets[$preset];
        $modules = $this->discoverModuleKeys();

        $pdo = $this->db->pdo();
        $del = $pdo->prepare('DELETE FROM ib_permissions WHERE group_id = :gid');
        $del->execute([':gid' => $groupId]);

        $ins = $pdo->prepare(
            'INSERT INTO ib_permissions
                (group_id, module_key, can_browse, can_create, can_edit, can_delete, can_export)
             VALUES
                (:gid, :mk, :br, :cr, :ed, :dl, :ex)'
        );

        foreach ($modules as $mk) {
            if ($mk === 'sys_permissions_matrix') {
                continue;   // see actionSaveGroup()
            }
            $ins->execute([
                ':gid' => $groupId,
                ':mk'  => $mk,
                ':br'  => $br,
                ':cr'  => $cr,
                ':ed'  => $ed,
                ':dl'  => $dl,
                ':ex'  => $ex,
            ]);
        }

        echo json_encode(['ok' => true, 'message' => 'Preset aplicat a ' . count($modules) . ' mòduls.']);
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    private function renderPage(): void
    {
        $groups = $this->db->fetchAll(
            'SELECT id, name FROM ib_groups ORDER BY name ASC'
        );

        $activeGroupId = Input::getInt("group", (int)($groups[0]["id"] ?? 0));

        // Load modules and their permissions for selected group
        $modules = $this->discoverModulesMeta();
        $perms   = $this->loadGroupPerms($activeGroupId);

        $csrf       = $this->csrf->field();
        $csrfToken  = $this->csrf->token();
        $app        = Router::currentApp() ?: 'system';
        $baseUrl    = Output::e('/' . $app . '/sys_permissions_matrix');

        echo $this->css();

        // Groups sidebar
        $groupsHtml = '';
        foreach ($groups as $g) {
            $gid    = (int) $g['id'];
            $name   = Output::e((string) $g['name']);
            $active = $gid === $activeGroupId ? ' pm-group--active' : '';
            $url    = Output::e("/{$app}/sys_permissions_matrix?group={$gid}");
            $groupsHtml .= "<a href=\"{$url}\" class=\"pm-group{$active}\">"
                         . "<i class=\"fa-solid fa-users-gear\"></i> {$name}</a>\n";
        }

        // Active group info
        $activeGroup = current(array_filter($groups, fn($g) => (int)$g['id'] === $activeGroupId));
        $groupName   = $activeGroup ? Output::e((string)$activeGroup['name']) : '';

        // Modules table
        $rowsHtml = '';
        foreach ($modules as $prefix => $mods) {
            $prefixLabel = Output::e(ucfirst($prefix));
            $rowsHtml .= "<tr class=\"pm-prefix-row\"><td colspan=\"6\"><i class=\"fa-solid fa-folder\"></i> {$prefixLabel}</td></tr>\n";
            foreach ($mods as $mod) {
                $mk    = Output::e($mod['key']);
                $mname = Output::e($mod['name']);
                $micon = Output::e($mod['icon'] ?? 'fa-solid fa-circle');
                $p     = $perms[$mod['key']] ?? null;

                // Default permissions when no row exists
                $browse = $p !== null ? (bool) $p['can_browse'] : true;
                $create = $p !== null ? (bool) $p['can_create'] : true;
                $edit   = $p !== null ? (bool) $p['can_edit']   : true;
                $delete = $p !== null ? (bool) $p['can_delete'] : false;
                $export = $p !== null ? (bool) $p['can_export'] : true;

                $hasRule = $p !== null ? ' pm-has-rule' : '';
                $bCheck  = $browse ? ' checked' : '';
                $cCheck  = $create ? ' checked' : '';
                $eCheck  = $edit   ? ' checked' : '';
                $dCheck  = $delete ? ' checked' : '';
                $xCheck  = $export ? ' checked' : '';

                $rowsHtml .= <<<HTML
                <tr class="pm-mod-row{$hasRule}" data-key="{$mk}">
                    <td class="pm-mod-name-cell">
                        <i class="{$micon}"></i> {$mname}
                        <code class="pm-mod-key">{$mk}</code>
                    </td>
                    <td class="pm-cb-cell"><input type="checkbox" class="pm-cb" data-action="browse"{$bCheck}></td>
                    <td class="pm-cb-cell"><input type="checkbox" class="pm-cb" data-action="create"{$cCheck}></td>
                    <td class="pm-cb-cell"><input type="checkbox" class="pm-cb" data-action="edit"{$eCheck}></td>
                    <td class="pm-cb-cell"><input type="checkbox" class="pm-cb" data-action="delete"{$dCheck}></td>
                    <td class="pm-cb-cell"><input type="checkbox" class="pm-cb" data-action="export"{$xCheck}></td>
                </tr>
                HTML;
            }
        }

        $groupId = $activeGroupId;

        echo <<<HTML
        <div class="pm-wrap">
            <div class="ib-module-title">
                <i class="fa-solid fa-shield-halved"></i>
                Matriu de Permisos
            </div>

            <div class="pm-layout">
                <!-- Left: Groups -->
                <div class="pm-sidebar">
                    <div class="pm-sidebar-title">Grups d'usuari</div>
                    {$groupsHtml}
                </div>

                <!-- Right: Module matrix -->
                <div class="pm-main">
                    <div class="pm-toolbar">
                        <div class="pm-toolbar-left">
                            <span class="pm-group-badge"><i class="fa-solid fa-users-gear"></i> {$groupName}</span>
                            <input type="search" id="pm-search" class="pm-search" placeholder="Filtrar mòduls...">
                        </div>
                        <div class="pm-toolbar-right">
                            <button type="button" class="ib-btn ib-btn--sm" onclick="pmPreset('admin')">
                                <i class="fa-solid fa-unlock"></i> Tot permès
                            </button>
                            <button type="button" class="ib-btn ib-btn--sm" onclick="pmPreset('readonly')">
                                <i class="fa-solid fa-eye"></i> Només lectura
                            </button>
                            <button type="button" class="ib-btn ib-btn--sm ib-btn--danger-light" onclick="pmPreset('none')">
                                <i class="fa-solid fa-ban"></i> Tot denegat
                            </button>
                            <button type="button" class="ib-btn ib-btn--primary" id="pm-save-btn" onclick="pmSave()">
                                <i class="fa-solid fa-floppy-disk"></i> Desar Permisos
                            </button>
                        </div>
                    </div>

                    <div class="pm-hint">
                        <i class="fa-solid fa-circle-info"></i>
                        Les files <span class="pm-legend-rule"></span> en color lleuger indiquen que hi ha una regla explícita.
                        Les files sense color usaran els permisos per defecte del mòdul.
                    </div>

                    <div class="pm-table-wrap">
                        <table class="pm-table" id="pm-table">
                            <thead>
                                <tr>
                                    <th class="pm-th-mod">Mòdul</th>
                                    <th class="pm-th-action" title="Veure llistat">Veure</th>
                                    <th class="pm-th-action" title="Crear registres">Crear</th>
                                    <th class="pm-th-action" title="Editar registres">Editar</th>
                                    <th class="pm-th-action" title="Eliminar registres">Eliminar</th>
                                    <th class="pm-th-action" title="Exportar CSV">Export</th>
                                </tr>
                                <tr class="pm-select-all-row">
                                    <td><small style="color:var(--ib-text-muted)">Seleccionar tot:</small></td>
                                    <td class="pm-cb-cell"><input type="checkbox" class="pm-cb-all" data-action="browse" title="Tots veure"></td>
                                    <td class="pm-cb-cell"><input type="checkbox" class="pm-cb-all" data-action="create" title="Tots crear"></td>
                                    <td class="pm-cb-cell"><input type="checkbox" class="pm-cb-all" data-action="edit"   title="Tots editar"></td>
                                    <td class="pm-cb-cell"><input type="checkbox" class="pm-cb-all" data-action="delete" title="Tots eliminar"></td>
                                    <td class="pm-cb-cell"><input type="checkbox" class="pm-cb-all" data-action="export" title="Tots export"></td>
                                </tr>
                            </thead>
                            <tbody id="pm-tbody">
                                {$rowsHtml}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div id="pm-toast" class="pm-toast" style="display:none"></div>

        <script>
        const PM_CFG = {
            groupId: {$groupId},
            csrf: '{$csrfToken}',
            url: '{$baseUrl}'
        };

        // Select-all column checkboxes
        document.querySelectorAll('.pm-cb-all').forEach(cbAll => {
            cbAll.addEventListener('change', () => {
                const action = cbAll.dataset.action;
                document.querySelectorAll(`#pm-tbody .pm-cb[data-action="\${action}"]`).forEach(cb => {
                    cb.checked = cbAll.checked;
                });
            });
        });

        // Search/filter
        document.getElementById('pm-search').addEventListener('input', function() {
            const q = this.value.toLowerCase();
            document.querySelectorAll('#pm-tbody tr').forEach(tr => {
                if (tr.classList.contains('pm-prefix-row')) {
                    tr.style.display = '';
                    return;
                }
                const txt = tr.textContent.toLowerCase();
                tr.style.display = (!q || txt.includes(q)) ? '' : 'none';
            });
        });

        async function pmSave() {
            const btn = document.getElementById('pm-save-btn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Desant...';

            const perms = {};
            document.querySelectorAll('#pm-tbody .pm-mod-row').forEach(tr => {
                const key = tr.dataset.key;
                if (!key) return;
                perms[key] = {};
                tr.querySelectorAll('.pm-cb').forEach(cb => {
                    perms[key][cb.dataset.action] = cb.checked ? 1 : 0;
                });
            });

            try {
                const body = new URLSearchParams({
                    action: 'save_group',
                    _token: PM_CFG.csrf,
                    group_id: PM_CFG.groupId,
                    perms: JSON.stringify(perms)
                });
                const r = await fetch(PM_CFG.url, { method: 'POST', body });
                const j = await r.json();
                pmToast(j.ok ? j.message : (j.error || 'Error'), !j.ok);
                // Mark all rows as having explicit rules
                if (j.ok) {
                    document.querySelectorAll('#pm-tbody .pm-mod-row').forEach(tr => {
                        tr.classList.add('pm-has-rule');
                    });
                }
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Desar Permisos';
            }
        }

        async function pmPreset(preset) {
            const labels = { admin: 'Tot permès', readonly: 'Només lectura', none: 'Tot denegat' };
            if (!confirm(`Aplicar preset "\${labels[preset]}" a tots els mòduls d'aquest grup?`)) return;

            const r = await fetch(PM_CFG.url, {
                method: 'POST',
                body: new URLSearchParams({
                    action: 'preset',
                    _token: PM_CFG.csrf,
                    group_id: PM_CFG.groupId,
                    preset
                })
            });
            const j = await r.json();
            pmToast(j.ok ? j.message : (j.error || 'Error'), !j.ok);
            if (j.ok) location.reload();
        }

        function pmToast(msg, isErr = false) {
            const t = document.getElementById('pm-toast');
            t.textContent = msg;
            t.className = 'pm-toast' + (isErr ? ' pm-toast--error' : '');
            t.style.display = '';
            clearTimeout(t._timer);
            t._timer = setTimeout(() => { t.style.display = 'none'; }, 3500);
        }
        </script>
        HTML;
    }

    // -------------------------------------------------------------------------
    // Data helpers
    // -------------------------------------------------------------------------

    /**
     * Load existing permissions for a group: { module_key => row }
     */
    private function loadGroupPerms(int $groupId): array
    {
        if ($groupId <= 0) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT module_key, can_browse, can_create, can_edit, can_delete, can_export
               FROM ib_permissions
              WHERE group_id = :gid',
            ['gid' => $groupId]
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['module_key']] = $row;
        }
        return $result;
    }

    /**
     * Discover all modules grouped by prefix, returning key/name/icon.
     */
    private function discoverModulesMeta(): array
    {
        $result  = [];
        $modRoot = dirname(__DIR__);

        $dirs = glob($modRoot . '/*', GLOB_ONLYDIR) ?: [];
        sort($dirs);

        foreach ($dirs as $dir) {
            $prefix = basename($dir);
            $files  = glob($dir . '/*.php') ?: [];
            sort($files);

            foreach ($files as $file) {
                $basename = basename($file, '.php');
                $key = ltrim(strtolower(preg_replace('/([A-Z])/', '_$1', $basename)), '_');

                $src   = file_get_contents($file);
                $title = $this->extractProp($src, 'title') ?: $basename;
                $icon  = $this->extractProp($src, 'icon')  ?: 'fa-solid fa-circle';

                $result[$prefix][] = [
                    'key'  => $key,
                    'name' => $title,
                    'icon' => $icon,
                ];
            }
        }

        return $result;
    }

    /**
     * Get just the list of all module keys.
     */
    private function discoverModuleKeys(): array
    {
        $keys    = [];
        $modRoot = dirname(__DIR__);
        foreach (glob($modRoot . '/*/*.php') ?: [] as $file) {
            $basename = basename($file, '.php');
            $keys[]   = ltrim(strtolower(preg_replace('/([A-Z])/', '_$1', $basename)), '_');
        }
        return array_unique($keys);
    }

    private function extractProp(string $src, string $prop): string
    {
        if (preg_match(
            '/public\s+string\s+\$' . preg_quote($prop, '/') . '\s*=\s*[\'"]([^\'"]+)[\'"]/m',
            $src,
            $m
        )) {
            return trim($m[1]);
        }
        return '';
    }

    // -------------------------------------------------------------------------
    // CSS
    // -------------------------------------------------------------------------

    private function css(): string
    {
        return <<<CSS
        <style>
        .pm-wrap { padding: 1.25rem 1.5rem; }
        .pm-layout { display: grid; grid-template-columns: 200px 1fr; gap: 1.25rem; margin-top: 1rem; }

        /* Sidebar */
        .pm-sidebar { }
        .pm-sidebar-title {
            font-size: .78rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .04em; color: var(--ib-text-muted);
            padding: .5rem .75rem; margin-bottom: .5rem;
        }
        .pm-group {
            display: flex; align-items: center; gap: .5rem;
            padding: .5rem .75rem; border-radius: 8px;
            text-decoration: none; color: var(--ib-text-secondary);
            font-size: .85rem; transition: all .12s; margin-bottom: .15rem;
        }
        .pm-group:hover { background: var(--ib-hover); color: var(--ib-text-primary); }
        .pm-group--active { background: color-mix(in srgb, var(--ib-primary) 12%, transparent); color: var(--ib-primary); font-weight: 600; }

        /* Toolbar */
        .pm-toolbar {
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: .75rem; margin-bottom: 1rem;
        }
        .pm-toolbar-left { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; }
        .pm-toolbar-right { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
        .pm-group-badge {
            display: inline-flex; align-items: center; gap: .4rem;
            padding: .35rem .75rem; background: color-mix(in srgb, var(--ib-primary) 12%, transparent);
            color: var(--ib-primary); border-radius: 20px; font-size: .85rem; font-weight: 600;
        }
        .pm-search {
            padding: .4rem .85rem; border: 1px solid var(--ib-border); border-radius: 20px;
            background: var(--ib-input-bg); color: var(--ib-text-primary);
            font-size: .82rem; width: 200px;
        }

        /* Hint */
        .pm-hint {
            font-size: .78rem; color: var(--ib-text-muted);
            margin-bottom: .75rem; display: flex; align-items: center; gap: .4rem;
        }
        .pm-legend-rule {
            display: inline-block; width: 14px; height: 8px;
            background: color-mix(in srgb, var(--ib-primary) 8%, transparent);
            border-radius: 2px; border: 1px solid color-mix(in srgb, var(--ib-primary) 20%, transparent);
        }

        /* Table */
        .pm-table-wrap { overflow-x: auto; border-radius: 10px; border: 1px solid var(--ib-border); }
        .pm-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
        .pm-table thead th {
            background: var(--ib-hover); padding: .55rem .75rem;
            border-bottom: 2px solid var(--ib-border);
            text-align: left; font-size: .8rem; font-weight: 700;
            color: var(--ib-text-secondary); white-space: nowrap;
        }
        .pm-th-mod { width: auto; }
        .pm-th-action { width: 70px; text-align: center; }
        .pm-select-all-row td { background: var(--ib-hover); border-bottom: 1px solid var(--ib-border); padding: .35rem .75rem; }

        .pm-prefix-row td {
            background: color-mix(in srgb, var(--ib-text-muted) 8%, transparent);
            padding: .4rem .75rem; font-size: .75rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .05em; color: var(--ib-text-muted);
            border-bottom: 1px solid var(--ib-border);
        }
        .pm-mod-row {
            border-bottom: 1px solid var(--ib-border); transition: background .08s;
        }
        .pm-mod-row:hover { background: var(--ib-hover); }
        .pm-mod-row.pm-has-rule { background: color-mix(in srgb, var(--ib-primary) 5%, transparent); }
        .pm-mod-row.pm-has-rule:hover { background: color-mix(in srgb, var(--ib-primary) 10%, transparent); }

        .pm-mod-name-cell {
            padding: .5rem .75rem;
            display: flex; align-items: center; gap: .5rem;
            color: var(--ib-text-primary);
        }
        .pm-mod-name-cell i { color: var(--ib-primary); width: 14px; font-size: .8rem; flex-shrink: 0; }
        .pm-mod-key { font-size: .7rem; color: var(--ib-text-muted); background: var(--ib-hover); padding: .1rem .3rem; border-radius: 3px; margin-left: .25rem; }
        .pm-cb-cell { padding: .4rem; text-align: center; }
        .pm-cb, .pm-cb-all {
            width: 16px; height: 16px; cursor: pointer;
            accent-color: var(--ib-primary);
        }

        /* Button variants */
        .ib-btn--danger-light { color: #ef4444; border-color: #ef4444; background: transparent; }
        .ib-btn--danger-light:hover { background: #ef4444; color: #fff; }
        .ib-btn--sm { padding: .35rem .7rem; font-size: .8rem; }

        /* Toast */
        .pm-toast {
            position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 9999;
            padding: .65rem 1.25rem; border-radius: 8px; font-size: .88rem; font-weight: 500;
            background: #10b981; color: #fff; box-shadow: 0 4px 20px rgba(0,0,0,.25);
            animation: pmFadeIn .2s ease;
        }
        .pm-toast--error { background: #ef4444; }
        @keyframes pmFadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }

        @media (max-width: 700px) {
            .pm-layout { grid-template-columns: 1fr; }
            .pm-sidebar { display: flex; flex-wrap: wrap; gap: .3rem; }
        }
        </style>
        CSS;
    }
}
