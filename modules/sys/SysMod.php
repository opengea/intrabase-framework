<?php

declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;
use Intrabase\Core\Router;
use Intrabase\Security\Input;
use Intrabase\Security\Output;

/**
 * SysMod — Visual Low-Code Module Builder
 *
 * Comprehensive UI for creating and managing dynamic modules without writing PHP.
 * Builds on top of ib_sys_mods + ib_sys_mod_attributes + ib_sys_mod_actions.
 *
 * Features:
 *  - Create/edit dynamic modules with all settings in one page
 *  - Manage fields inline (add/remove, configure types)
 *  - Auto-create or update DB table from field definitions
 *  - Assign module to an app's sidebar
 *  - Module-level: perPage, baseWhere, showKey, JS events, custom CSS
 *  - Field-level: readonly, abbrev, help_text, max_length, noWrap, css_class, onChangeJS, totals
 */
class SysMod extends Module
{
    public string $table = '';
    public string $key   = 'id';
    public string $title = 'Constructor de Mòduls';
    public string $icon  = 'fa-solid fa-wand-magic-sparkles';

    public bool $canAdd    = false;
    public bool $canEdit   = false;
    public bool $canDelete = false;
    public bool $canExport = false;

    /** Human-readable names for each field type */
    private const FIELD_TYPES = [
        'text'       => 'Text curt (varchar)',
        'textarea'   => 'Text llarg',
        'html'       => 'Editor HTML (WYSIWYG)',
        'integer'    => 'Número enter',
        'decimal'    => 'Número decimal',
        'money'      => 'Import monetari',
        'percentage' => 'Percentatge (%)',
        'date'       => 'Data',
        'datetime'   => 'Data i hora',
        'time'       => 'Hora',
        'email'      => 'Email',
        'url'        => 'URL / Enllaç',
        'phone'      => 'Telèfon',
        'color'      => 'Color (hex)',
        'select'     => "Selector d'opcions",
        'combo'      => 'Selector FK (taula)',
        'checkbox'   => 'Casella de verificació',
        'rating'     => 'Valoració (estrelles)',
        'file'       => 'Fitxer adjunt',
        'picture'    => 'Imatge',
        'slug'       => 'Slug (URL amigable)',
        'json'       => 'JSON',
        'hidden'     => 'Ocult',
        'password'   => 'Contrasenya',
    ];

    /** MySQL column type for each field type */
    private const DB_TYPES = [
        'text'       => 'VARCHAR(255)',
        'textarea'   => 'TEXT',
        'html'       => 'LONGTEXT',
        'integer'    => 'INT',
        'decimal'    => 'DECIMAL(12,2)',
        'money'      => 'DECIMAL(12,2)',
        'percentage' => 'DECIMAL(5,2)',
        'date'       => 'DATE',
        'datetime'   => 'DATETIME',
        'time'       => 'TIME',
        'email'      => 'VARCHAR(300)',
        'url'        => 'VARCHAR(500)',
        'phone'      => 'VARCHAR(30)',
        'color'      => 'VARCHAR(20)',
        'select'     => 'VARCHAR(100)',
        'combo'      => 'INT UNSIGNED',
        'checkbox'   => 'TINYINT(1)',
        'rating'     => 'TINYINT UNSIGNED',
        'file'       => 'VARCHAR(500)',
        'picture'    => 'VARCHAR(500)',
        'slug'       => 'VARCHAR(255)',
        'json'       => 'JSON',
        'hidden'     => 'VARCHAR(255)',
        'password'   => 'VARCHAR(255)',
    ];

    // -------------------------------------------------------------------------
    // Entry point
    // -------------------------------------------------------------------------

    public function execute(): void
    {
        $this->applyPermissions();

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($method === 'POST') {
            header('Content-Type: application/json; charset=utf-8');
            $this->handlePost(Input::post('action', ''));
            return;
        }

        $action = Router::currentAction();
        $modId  = Router::currentId();

        // Fallback to legacy query params
        if ($action === 'list' && Input::get('view', '') !== '') {
            $action = Input::get('view', 'list');
            $modId  = $modId ?: (int) Input::get('id', '0');
        }

        ob_start();
        match ($action) {
            'create' => $this->renderModuleForm(null, []),
            'edit'   => $this->renderEditPage($modId),
            default  => $this->renderListPage(),
        };
        $html = ob_get_clean();
        $this->renderLayout((string) $html);
    }

    // -------------------------------------------------------------------------
    // POST dispatcher
    // -------------------------------------------------------------------------

    private function handlePost(string $action): void
    {
        if (!$this->csrf->validateToken()) {
            echo json_encode(['ok' => false, 'error' => 'Token de seguretat invàlid']);
            return;
        }

        match ($action) {
            'save_module'    => $this->actionSaveModule(),
            'delete_module'  => $this->actionDeleteModule(),
            'save_field'     => $this->actionSaveField(),
            'delete_field'   => $this->actionDeleteField(),
            'reorder_fields' => $this->actionReorderFields(),
            'create_table'   => $this->actionCreateTable(),
            'add_to_sidebar' => $this->actionAddToSidebar(),
            'sync_fields'   => $this->actionSyncFields(),
            default          => (function() { echo json_encode(['ok' => false, 'error' => 'Acció desconeguda']); })(),
        };
    }

    // -------------------------------------------------------------------------
    // POST: Save module (all settings)
    // -------------------------------------------------------------------------

    private function actionSaveModule(): void
    {
        $id    = (int) Input::post('id', '0');
        $name  = strtolower(preg_replace('/[^a-z0-9_]+/', '_', trim(Input::post('name', ''))));
        $label = trim(Input::post('label', ''));
        $table = strtolower(preg_replace('/[^a-z0-9_]+/', '_', trim(Input::post('table_name', ''))));
        $icon  = trim(Input::post('icon_class', 'fa-solid fa-database'));
        $desc  = trim(Input::post('description', ''));
        $order = trim(Input::post('order_by', 'id DESC'));

        // New fields
        $perPage   = max(1, (int) Input::post('per_page', '25'));
        $baseWhere = trim(Input::post('base_where', ''));
        $showKey   = (int)(bool) Input::post('show_key', '0');
        $onReady   = trim(Input::post('on_document_ready', ''));
        $customCss = trim(Input::post('custom_css', ''));

        $canCreate = (int)(bool) Input::post('can_create',      '1');
        $canEdit   = (int)(bool) Input::post('can_edit',        '1');
        $canDel    = (int)(bool) Input::post('can_delete',      '1');
        $canExp    = (int)(bool) Input::post('can_export',      '1');
        $canDup    = (int)(bool) Input::post('can_duplicate',   '0');
        $canImp    = (int)(bool) Input::post('can_import',      '0');
        $canMulti  = (int)(bool) Input::post('can_multiselect', '1');
        $status    = Input::post('status', 'active') === 'active' ? 'active' : 'inactive';

        if ($name === '' || $label === '' || $table === '') {
            echo json_encode(['ok' => false, 'error' => 'Nom, etiqueta i taula són obligatoris.']);
            return;
        }

        if (!str_starts_with($table, 'ib_')) {
            $table = 'ib_' . $table;
        }

        $pdo = $this->db->pdo();
        $params = [
            ':n' => $name, ':l' => $label, ':t' => $table, ':i' => $icon,
            ':d' => $desc ?: null, ':o' => $order, ':pp' => $perPage,
            ':bw' => $baseWhere ?: null, ':sk' => $showKey,
            ':cc' => $canCreate, ':ce' => $canEdit, ':cd' => $canDel,
            ':cx' => $canExp, ':cdup' => $canDup, ':cimp' => $canImp,
            ':cms' => $canMulti, ':s' => $status,
            ':odr' => $onReady ?: null, ':ccss' => $customCss ?: null,
        ];

        if ($id > 0) {
            $params[':id'] = $id;
            $stmt = $pdo->prepare(
                'UPDATE ib_sys_mods SET name=:n, label=:l, table_name=:t, icon_class=:i,
                  description=:d, order_by=:o, per_page=:pp, base_where=:bw, show_key=:sk,
                  can_create=:cc, can_edit=:ce, can_delete=:cd,
                  can_export=:cx, can_duplicate=:cdup, can_import=:cimp, can_multiselect=:cms,
                  on_document_ready=:odr, custom_css=:ccss, status=:s WHERE id=:id'
            );
            $stmt->execute($params);
            echo json_encode(['ok' => true, 'id' => $id, 'name' => $name]);
        } else {
            $chk = $pdo->prepare('SELECT id FROM ib_sys_mods WHERE name = :n LIMIT 1');
            $chk->execute([':n' => $name]);
            if ($chk->fetchColumn()) {
                echo json_encode(['ok' => false, 'error' => "Ja existeix un mòdul amb la clau '{$name}'."]);
                return;
            }
            $stmt = $pdo->prepare(
                'INSERT INTO ib_sys_mods (name, label, table_name, icon_class, description,
                  order_by, per_page, base_where, show_key,
                  can_create, can_edit, can_delete, can_export, can_duplicate,
                  can_import, can_multiselect, on_document_ready, custom_css, status)
                  VALUES (:n, :l, :t, :i, :d, :o, :pp, :bw, :sk,
                  :cc, :ce, :cd, :cx, :cdup, :cimp, :cms, :odr, :ccss, :s)'
            );
            $stmt->execute($params);
            $newId = (int) $pdo->lastInsertId();
            echo json_encode(['ok' => true, 'id' => $newId, 'name' => $name]);
        }
    }

    // -------------------------------------------------------------------------
    // POST: Delete module
    // -------------------------------------------------------------------------

    private function actionDeleteModule(): void
    {
        $id = (int) Input::post('id', '0');
        if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'ID invàlid']); return; }

        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM ib_sys_mod_attributes WHERE mod_id = :id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM ib_sys_mod_actions    WHERE mod_id = :id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM ib_sys_mods           WHERE id     = :id')->execute([':id' => $id]);
        echo json_encode(['ok' => true]);
    }

    // -------------------------------------------------------------------------
    // POST: Save field (all attributes)
    // -------------------------------------------------------------------------

    private function actionSaveField(): void
    {
        $fid    = (int) Input::post('field_id', '0');
        $mid    = (int) Input::post('mod_id',   '0');
        $fname  = strtolower(preg_replace('/[^a-z0-9_]+/', '_', trim(Input::post('field_name', ''))));
        $flabel = trim(Input::post('field_label', ''));
        $ftype  = Input::post('field_type', 'text');
        $fopts  = trim(Input::post('field_options', ''));
        $fdflt  = trim(Input::post('default_value', ''));
        $freq   = (int)(bool) Input::post('is_required',   '0');
        $fsch   = (int)(bool) Input::post('is_searchable', '0');
        $fro    = (int)(bool) Input::post('is_readonly',   '0');
        $fsil   = (int)(bool) Input::post('show_in_list',  '1');
        $fsie   = (int)(bool) Input::post('show_in_edit',  '1');
        $fsid   = (int)(bool) Input::post('show_in_detail','1');
        $fso    = (int) Input::post('sort_order', '0');
        $fgrp   = trim(Input::post('field_group', ''));
        $regex  = trim(Input::post('validation_regex', ''));
        $notes  = trim(Input::post('notes', ''));

        // New attributes
        $abbrev    = trim(Input::post('abbrev', ''));
        $helpText  = trim(Input::post('help_text', ''));
        $maxLen    = (int) Input::post('max_length', '0');
        $noWrap    = (int)(bool) Input::post('no_wrap', '0');
        $cssClass  = trim(Input::post('css_class', ''));
        $onChangeJs = trim(Input::post('on_change_js', ''));
        $isTotals  = (int)(bool) Input::post('is_totals', '0');

        // Component
        $componentType = trim(Input::post('component_type', ''));
        $componentOpts = trim(Input::post('component_options', ''));

        if ($mid <= 0 || $fname === '' || $flabel === '') {
            echo json_encode(['ok' => false, 'error' => 'Mòdul, nom del camp i etiqueta són obligatoris.']);
            return;
        }

        if (!array_key_exists($ftype, self::FIELD_TYPES)) {
            $ftype = 'text';
        }

        $pdo = $this->db->pdo();
        $params = [
            ':mid' => $mid, ':fn' => $fname, ':fl' => $flabel, ':ft' => $ftype,
            ':fo' => $fopts ?: null, ':dv' => $fdflt ?: null,
            ':req' => $freq, ':sch' => $fsch, ':ro' => $fro,
            ':sil' => $fsil, ':sie' => $fsie, ':sid' => $fsid,
            ':so' => $fso, ':fg' => $fgrp ?: null,
            ':vr' => $regex ?: null, ':notes' => $notes ?: null,
            ':ab' => $abbrev ?: null, ':ht' => $helpText ?: null,
            ':ml' => $maxLen > 0 ? $maxLen : null, ':nw' => $noWrap,
            ':cc' => $cssClass ?: null, ':ocj' => $onChangeJs ?: null,
            ':it' => $isTotals,
            ':cpt' => $componentType ?: null, ':cpo' => $componentOpts ?: null,
        ];

        if ($fid > 0) {
            $params[':id'] = $fid;
            $stmt = $pdo->prepare(
                'UPDATE ib_sys_mod_attributes SET
                  field_name=:fn, field_label=:fl, field_type=:ft, field_options=:fo,
                  default_value=:dv, is_required=:req, is_searchable=:sch, is_readonly=:ro,
                  show_in_list=:sil, show_in_edit=:sie, show_in_detail=:sid,
                  sort_order=:so, field_group=:fg,
                  validation_regex=:vr, notes=:notes,
                  abbrev=:ab, help_text=:ht, max_length=:ml, no_wrap=:nw,
                  css_class=:cc, on_change_js=:ocj, is_totals=:it,
                  component_type=:cpt, component_options=:cpo
                  WHERE id=:id AND mod_id=:mid'
            );
            $stmt->execute($params);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO ib_sys_mod_attributes
                  (mod_id, field_name, field_label, field_type, field_options,
                   default_value, is_required, is_searchable, is_readonly,
                   show_in_list, show_in_edit, show_in_detail,
                   sort_order, field_group, validation_regex, notes,
                   abbrev, help_text, max_length, no_wrap,
                   css_class, on_change_js, is_totals,
                   component_type, component_options)
                  VALUES (:mid, :fn, :fl, :ft, :fo, :dv, :req, :sch, :ro,
                   :sil, :sie, :sid, :so, :fg, :vr, :notes,
                   :ab, :ht, :ml, :nw, :cc, :ocj, :it, :cpt, :cpo)'
            );
            $stmt->execute($params);
            $fid = (int) $pdo->lastInsertId();
        }

        echo json_encode(['ok' => true, 'field_id' => $fid]);
    }

    // -------------------------------------------------------------------------
    // POST: Delete field / Reorder fields
    // -------------------------------------------------------------------------

    private function actionDeleteField(): void
    {
        $id  = (int) Input::post('id',     '0');
        $mid = (int) Input::post('mod_id', '0');
        if ($id <= 0 || $mid <= 0) { echo json_encode(['ok' => false, 'error' => 'ID invàlid']); return; }
        $this->db->pdo()
            ->prepare('DELETE FROM ib_sys_mod_attributes WHERE id = :id AND mod_id = :mid')
            ->execute([':id' => $id, ':mid' => $mid]);
        echo json_encode(['ok' => true]);
    }

    private function actionReorderFields(): void
    {
        $order = Input::post('order', '');
        $ids   = json_decode($order, true);
        if (!is_array($ids)) { echo json_encode(['ok' => false, 'error' => 'Format invàlid']); return; }
        $stmt = $this->db->pdo()->prepare('UPDATE ib_sys_mod_attributes SET sort_order = :so WHERE id = :id');
        foreach ($ids as $pos => $id) {
            $stmt->execute([':so' => (int)$pos * 10, ':id' => (int)$id]);
        }
        echo json_encode(['ok' => true]);
    }

    // -------------------------------------------------------------------------
    // POST: Create/update DB table
    // -------------------------------------------------------------------------

    private function actionCreateTable(): void
    {
        $mid = (int) Input::post('mod_id', '0');
        if ($mid <= 0) { echo json_encode(['ok' => false, 'error' => 'Module ID invàlid']); return; }

        $pdo  = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT * FROM ib_sys_mods WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $mid]);
        $mod  = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$mod) { echo json_encode(['ok' => false, 'error' => 'Mòdul no trobat']); return; }

        $tbl = $mod['table_name'];

        $stmt = $pdo->prepare('SELECT * FROM ib_sys_mod_attributes WHERE mod_id = :mid ORDER BY sort_order ASC, id ASC');
        $stmt->execute([':mid' => $mid]);
        $fields = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $tableExists = false;
        try {
            $check = $pdo->query("SHOW TABLES LIKE '" . addslashes($tbl) . "'");
            $tableExists = ($check !== false && $check->rowCount() > 0);
        } catch (\Throwable) {}

        if ($tableExists) {
            $existingCols = [];
            foreach ($pdo->query("SHOW COLUMNS FROM `{$tbl}`")->fetchAll(\PDO::FETCH_ASSOC) as $col) {
                $existingCols[] = $col['Field'];
            }
            $alters = [];
            foreach ($fields as $f) {
                $fn = $f['field_name'];
                if (!in_array($fn, $existingCols, true)) {
                    $dbType  = self::DB_TYPES[$f['field_type']] ?? 'VARCHAR(255)';
                    $null    = $f['is_required'] ? 'NOT NULL' : 'NULL';
                    $default = $this->sqlDefault($f);
                    $alters[] = "ADD COLUMN `{$fn}` {$dbType} {$null} {$default}";
                }
            }
            if (!empty($alters)) {
                $sql = "ALTER TABLE `{$tbl}` " . implode(', ', $alters);
                try {
                    $pdo->exec($sql);
                    echo json_encode(['ok' => true, 'action' => 'altered', 'sql' => $sql, 'added' => count($alters)]);
                } catch (\Throwable $e) {
                    echo json_encode(['ok' => false, 'error' => 'Error SQL: ' . $e->getMessage()]);
                }
            } else {
                echo json_encode(['ok' => true, 'action' => 'no_changes', 'message' => 'La taula ja té totes les columnes definides.']);
            }
        } else {
            $cols   = ["`id` INT UNSIGNED NOT NULL AUTO_INCREMENT"];
            foreach ($fields as $f) {
                $fn      = $f['field_name'];
                $dbType  = self::DB_TYPES[$f['field_type']] ?? 'VARCHAR(255)';
                $null    = $f['is_required'] ? 'NOT NULL' : 'NULL';
                $default = $this->sqlDefault($f);
                $cols[]  = "`{$fn}` {$dbType} {$null} {$default}";
            }
            $cols[] = "`created_by`  INT UNSIGNED NULL DEFAULT NULL";
            $cols[] = "`created_at`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP";
            $cols[] = "`updated_at`  DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
            $cols[] = "PRIMARY KEY (`id`)";

            $sql = "CREATE TABLE IF NOT EXISTS `{$tbl}` (\n  "
                 . implode(",\n  ", $cols)
                 . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            try {
                $pdo->exec($sql);
                echo json_encode(['ok' => true, 'action' => 'created', 'sql' => $sql]);
            } catch (\Throwable $e) {
                echo json_encode(['ok' => false, 'error' => 'Error SQL: ' . $e->getMessage()]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // POST: Add module to app sidebar
    // -------------------------------------------------------------------------

    private function actionAddToSidebar(): void
    {
        $mid    = (int) Input::post('mod_id',     '0');
        $appKey = trim(Input::post('app_key',     ''));
        $group  = trim(Input::post('group_name',  'Personalitzats'));

        if ($mid <= 0 || $appKey === '') {
            echo json_encode(['ok' => false, 'error' => 'Paràmetres invàlids']);
            return;
        }

        $pdo  = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT name, label, icon_class FROM ib_sys_mods WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $mid]);
        $mod  = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$mod) { echo json_encode(['ok' => false, 'error' => 'Mòdul no trobat']); return; }

        $appStmt = $pdo->prepare('SELECT id, modules FROM ib_apps WHERE keyname = :k LIMIT 1');
        $appStmt->execute([':k' => $appKey]);
        $app = $appStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$app) { echo json_encode(['ok' => false, 'error' => 'App no trobada']); return; }

        $modules = json_decode((string)$app['modules'], true) ?: [];

        foreach ($modules as $grp) {
            foreach ((array)($grp['modules'] ?? []) as $m) {
                if (($m['key'] ?? '') === $mod['name']) {
                    echo json_encode(['ok' => false, 'error' => "El mòdul '{$mod['name']}' ja és al sidebar de '{$appKey}'."]);
                    return;
                }
            }
        }

        $found = false;
        foreach ($modules as &$grp) {
            if (($grp['group'] ?? '') === $group) {
                $grp['modules'][] = ['key' => $mod['name'], 'name' => $mod['label'], 'icon' => $mod['icon_class']];
                $found = true;
                break;
            }
        }
        unset($grp);

        if (!$found) {
            $modules[] = [
                'group'   => $group,
                'icon'    => 'fa-solid fa-puzzle-piece',
                'modules' => [['key' => $mod['name'], 'name' => $mod['label'], 'icon' => $mod['icon_class']]],
            ];
        }

        $upd = $pdo->prepare('UPDATE ib_apps SET modules = :m WHERE id = :id');
        $upd->execute([':m' => json_encode($modules, JSON_UNESCAPED_UNICODE), ':id' => $app['id']]);

        echo json_encode(['ok' => true, 'message' => "Mòdul afegit al grup '{$group}' de l'app '{$appKey}'."]);
    }

    // -------------------------------------------------------------------------
    // POST: Sync fields from DB columns + PHP source
    // -------------------------------------------------------------------------

    private function actionSyncFields(): void
    {
        $mid = (int) Input::post('mod_id', '0');
        if ($mid <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Module ID invàlid']);
            return;
        }

        $pdo  = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT * FROM ib_sys_mods WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $mid]);
        $mod  = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$mod) {
            echo json_encode(['ok' => false, 'error' => 'Mòdul no trobat']);
            return;
        }

        $tableName = $mod['table_name'];
        $moduleKey = $mod['name'];

        // Extract PHP config from physical module file
        $phpConfig = $this->extractModuleConfig($moduleKey);

        // Get existing attributes (with current component_type)
        $existingAttrs = [];
        $stmt = $pdo->prepare('SELECT id, field_name, component_type FROM ib_sys_mod_attributes WHERE mod_id = :mid');
        $stmt->execute([':mid' => $mid]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $ea) {
            $existingAttrs[$ea['field_name']] = [
                'id' => (int) $ea['id'],
                'component_type' => $ea['component_type'],
            ];
        }

        // Discover DB columns
        $dbColumns = [];
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `" . addslashes($tableName) . "`")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($cols as $col) {
                $dbColumns[$col['Field']] = $col;
            }
        } catch (\Throwable) {
            // Table may not exist yet
        }

        // Build master field list: PHP $fields first, then extra DB columns
        $phpFields    = $phpConfig['fields'] ?? [];
        $editFields   = array_flip($phpConfig['editFields'] ?? []);
        $showFields   = array_flip($phpConfig['showFields'] ?? []);
        $searchFields = array_flip($phpConfig['searchFields'] ?? []);
        $validates    = array_flip($phpConfig['validates'] ?? []);
        $readonlySet  = array_flip($phpConfig['readonly'] ?? []);
        $hiddenSet    = array_flip($phpConfig['hidden'] ?? []);
        $totalsSet    = array_flip($phpConfig['totalsFields'] ?? []);
        $noWrapSet    = array_flip($phpConfig['noWrap'] ?? []);
        $humans       = $phpConfig['humans'] ?? [];
        $components   = $phpConfig['components'] ?? [];
        $abbrevMap    = $phpConfig['abbrev'] ?? [];
        $maxLengths   = $phpConfig['maxLengths'] ?? [];
        $comments     = $phpConfig['comments'] ?? [];
        $classes      = $phpConfig['classes'] ?? [];
        $groups       = $phpConfig['groups'] ?? [];

        // field -> group map
        $fieldGroupMap = [];
        foreach ($groups as $grpKey => $grp) {
            foreach ($grp['fields'] as $gf) {
                $fieldGroupMap[$gf] = $grp['label'];
            }
        }

        $skipFields = ['id', 'created_at', 'updated_at', 'created_by', 'updated_by'];
        $allFields = [];

        foreach ($phpFields as $f) {
            if (!in_array($f, $skipFields, true)) {
                $allFields[$f] = true;
            }
        }
        foreach (array_keys($dbColumns) as $colName) {
            if (!in_array($colName, $skipFields, true) && !isset($allFields[$colName])) {
                $allFields[$colName] = true;
            }
        }

        $hasShowFields = !empty($phpConfig['showFields']);
        $hasEditFields = !empty($phpConfig['editFields']);

        $sortOrder = 0;
        $inserted  = 0;
        $updated   = 0;

        // Prepare update statement for existing fields' component_type
        $updComp = $pdo->prepare(
            'UPDATE ib_sys_mod_attributes SET component_type = :cpt, component_options = :cpo WHERE id = :id'
        );

        foreach ($allFields as $fieldName => $_) {
            $sortOrder += 10;

            // Update component_type for existing fields if PHP defines one and DB is empty
            if (isset($existingAttrs[$fieldName])) {
                $phpComp = $components[$fieldName] ?? null;
                if ($phpComp !== null && ($existingAttrs[$fieldName]['component_type'] === null || $existingAttrs[$fieldName]['component_type'] === '')) {
                    $compOptsArr = $phpConfig['componentOptions'][$fieldName] ?? null;
                    $compOpts = is_array($compOptsArr) ? json_encode($compOptsArr, JSON_UNESCAPED_UNICODE) : null;
                    $updComp->execute([
                        ':cpt' => $phpComp,
                        ':cpo' => $compOpts,
                        ':id'  => $existingAttrs[$fieldName]['id'],
                    ]);
                    $updated++;
                }
                continue;
            }

            // Determine field type
            $fieldType = 'text';
            if (isset($components[$fieldName])) {
                $fieldType = $this->componentToFieldType($components[$fieldName]);
            } elseif (isset($dbColumns[$fieldName])) {
                $fieldType = $this->dbTypeToFieldType($dbColumns[$fieldName]['Type']);
            }

            $isInFields = in_array($fieldName, $phpFields, true);
            $showInList = $hasShowFields ? isset($showFields[$fieldName]) : $isInFields;
            $showInEdit = $hasEditFields ? isset($editFields[$fieldName]) : $isInFields;
            $isHidden   = isset($hiddenSet[$fieldName]);

            if ($isHidden) {
                $fieldType  = 'hidden';
                $showInList = false;
                $showInEdit = false;
            }

            // Resolve component_type from PHP $components array
            $compType = $components[$fieldName] ?? null;
            $compOpts = null;
            if ($compType !== null) {
                // Check if there are componentOptions defined via setComponent()
                $compOptsArr = $phpConfig['componentOptions'][$fieldName] ?? null;
                if (is_array($compOptsArr)) {
                    $compOpts = json_encode($compOptsArr, JSON_UNESCAPED_UNICODE);
                }
            }

            $ins = $pdo->prepare(
                'INSERT INTO ib_sys_mod_attributes
                 (mod_id, field_name, field_label, field_type, is_required, is_searchable,
                  is_readonly, show_in_list, show_in_edit, show_in_detail, sort_order,
                  field_group, abbrev, help_text, max_length, no_wrap, css_class, is_totals,
                  component_type, component_options)
                 VALUES (:mid, :fn, :fl, :ft, :req, :sch, :ro, :sil, :sie, :sid, :so,
                  :fg, :ab, :ht, :ml, :nw, :cc, :it, :cpt, :cpo)'
            );
            $ins->execute([
                ':mid' => $mid,
                ':fn'  => $fieldName,
                ':fl'  => $humans[$fieldName] ?? ucfirst(str_replace('_', ' ', $fieldName)),
                ':ft'  => $fieldType,
                ':req' => isset($validates[$fieldName]) ? 1 : 0,
                ':sch' => isset($searchFields[$fieldName]) ? 1 : 0,
                ':ro'  => isset($readonlySet[$fieldName]) ? 1 : 0,
                ':sil' => $showInList ? 1 : 0,
                ':sie' => $showInEdit ? 1 : 0,
                ':sid' => 1,
                ':so'  => $sortOrder,
                ':fg'  => $fieldGroupMap[$fieldName] ?? null,
                ':ab'  => $abbrevMap[$fieldName] ?? null,
                ':ht'  => $comments[$fieldName] ?? null,
                ':ml'  => isset($maxLengths[$fieldName]) ? (int)$maxLengths[$fieldName] : null,
                ':nw'  => isset($noWrapSet[$fieldName]) ? 1 : 0,
                ':cc'  => $classes[$fieldName] ?? null,
                ':it'  => isset($totalsSet[$fieldName]) ? 1 : 0,
                ':cpt' => $compType,
                ':cpo' => $compOpts,
            ]);
            $inserted++;
        }

        $total = count($allFields);
        $existing = count($existingAttrs);
        $parts = [];
        if ($inserted > 0) $parts[] = "{$inserted} camps nous afegits";
        if ($updated > 0)  $parts[] = "{$updated} components actualitzats";
        $detail = !empty($parts) ? implode(', ', $parts) : 'sense canvis';
        echo json_encode([
            'ok'      => true,
            'message' => "Sincronització completada: {$detail}. Total: {$total} camps ({$existing} ja existien).",
        ]);
    }

    // -------------------------------------------------------------------------
    // PHP source extraction helpers
    // -------------------------------------------------------------------------

    private function extractModuleConfig(string $moduleKey): array
    {
        $modRoot = dirname(__DIR__, 2) . '/modules';
        $pascal  = implode('', array_map('ucfirst', explode('_', $moduleKey)));
        $prefix  = explode('_', $moduleKey)[0];

        $candidates = [
            $modRoot . '/' . $prefix . '/' . $pascal . '.php',
            $modRoot . '/' . $prefix . '/' . $moduleKey . '.php',
        ];

        $src = '';
        foreach ($candidates as $path) {
            if (is_file($path)) {
                $src = file_get_contents($path);
                break;
            }
        }

        if ($src === '') {
            return [];
        }

        $config = [];

        // Simple string properties
        foreach (['table', 'title', 'icon', 'orderBy', 'orderDir'] as $prop) {
            if (preg_match('/public\s+string\s+\$' . preg_quote($prop, '/') . '\s*=\s*[\'"]([^\'"]+)[\'"]/m', $src, $m)) {
                $config[$prop] = trim($m[1]);
            }
        }

        // Int/bool properties
        foreach (['perPage'] as $prop) {
            if (preg_match('/public\s+int\s+\$' . preg_quote($prop) . '\s*=\s*(\d+)/m', $src, $m)) {
                $config[$prop] = (int)$m[1];
            }
        }
        foreach (['canAdd', 'canEdit', 'canDelete', 'canExport', 'canDuplicate', 'canImport'] as $prop) {
            if (preg_match('/public\s+bool\s+\$' . preg_quote($prop) . '\s*=\s*(true|false)/mi', $src, $m)) {
                $config[$prop] = strtolower($m[1]) === 'true' ? 1 : 0;
            }
        }

        // Array properties
        foreach (['fields', 'editFields', 'showFields', 'searchFields', 'validates',
                   'readonly', 'hidden', 'detailFields', 'totalsFields', 'noWrap'] as $prop) {
            if (preg_match('/public\s+array\s+\$' . preg_quote($prop) . '\s*=\s*\[([^\]]*)\]/ms', $src, $m)) {
                preg_match_all("/['\"]([^'\"]+)['\"]/", $m[1], $items);
                if (!empty($items[1])) $config[$prop] = $items[1];
            }
        }

        // Associative array properties
        foreach (['humans', 'components', 'abbrev', 'maxLengths', 'comments', 'classes'] as $prop) {
            if (preg_match('/public\s+array\s+\$' . preg_quote($prop) . '\s*=\s*\[([^\]]*)\]/ms', $src, $m)) {
                $result = [];
                preg_match_all("/['\"]([^'\"]+)['\"]\s*=>\s*['\"]([^'\"]+)['\"]/", $m[1], $pairs, PREG_SET_ORDER);
                foreach ($pairs as $pair) {
                    $result[$pair[1]] = $pair[2];
                }
                if (!empty($result)) $config[$prop] = $result;
            }
        }

        // setGroup() calls
        if (preg_match_all("/\\\$this->setGroup\(\s*['\"]([^'\"]+)['\"],\s*\[([^\]]*\[[^\]]*\][^\]]*)\]/ms", $src, $matches, PREG_SET_ORDER)) {
            $groups = [];
            foreach ($matches as $m) {
                $body = $m[2];
                $label = '';
                $fields = [];
                if (preg_match("/['\"]label['\"]\s*=>\s*['\"]([^'\"]+)['\"]/", $body, $lm)) {
                    $label = $lm[1];
                }
                if (preg_match("/['\"]fields['\"]\s*=>\s*\[([^\]]*)\]/", $body, $fm)) {
                    preg_match_all("/['\"]([^'\"]+)['\"]/", $fm[1], $fItems);
                    $fields = $fItems[1] ?? [];
                }
                if (!empty($fields)) {
                    $groups[$m[1]] = ['label' => $label ?: $m[1], 'fields' => $fields];
                }
            }
            if (!empty($groups)) $config['groups'] = $groups;
        }

        return $config;
    }

    private function componentToFieldType(string $component): string
    {
        return match ($component) {
            'textarea'    => 'textarea',
            'html'        => 'html',
            'integer'     => 'integer',
            'decimal'     => 'decimal',
            'money'       => 'money',
            'percentage'  => 'percentage',
            'date'        => 'date',
            'datetime'    => 'datetime',
            'time'        => 'time',
            'email'       => 'email',
            'url'         => 'url',
            'phone'       => 'phone',
            'color'       => 'color',
            'combo'       => 'combo',
            'multicombo'  => 'combo',
            'checklist'   => 'checkbox',
            'radiolist'   => 'select',
            'picture'     => 'picture',
            'file'        => 'file',
            'rating'      => 'rating',
            'slug'        => 'slug',
            'json'        => 'json',
            'password'    => 'password',
            'status_icon' => 'select',
            'xref'        => 'combo',
            default       => 'text',
        };
    }

    private function dbTypeToFieldType(string $mysqlType): string
    {
        $type = strtolower($mysqlType);
        if (str_contains($type, 'tinyint(1)')) return 'checkbox';
        if (str_contains($type, 'int'))        return 'integer';
        if (str_contains($type, 'decimal') || str_contains($type, 'float') || str_contains($type, 'double')) return 'decimal';
        if ($type === 'date')                  return 'date';
        if (str_contains($type, 'datetime') || str_contains($type, 'timestamp')) return 'datetime';
        if ($type === 'time')                  return 'time';
        if (str_contains($type, 'text') || str_contains($type, 'longtext') || str_contains($type, 'mediumtext')) return 'textarea';
        if (str_contains($type, 'json'))       return 'json';
        if (str_contains($type, 'enum'))       return 'select';
        return 'text';
    }

    // -------------------------------------------------------------------------
    // List page
    // -------------------------------------------------------------------------

    private function renderListPage(): void
    {
        $app   = Router::currentApp() ?: 'system';
        $token = $this->csrf->token();

        $pdo  = $this->db->pdo();
        $mods = $pdo->query(
            "SELECT m.*, COUNT(a.id) AS field_count
               FROM ib_sys_mods m
               LEFT JOIN ib_sys_mod_attributes a ON a.mod_id = m.id
               GROUP BY m.id
               ORDER BY m.label ASC"
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $rows  = '';
        foreach ($mods as $m) {
            $id    = (int) $m['id'];
            $name  = Output::e($m['name']);
            $label = Output::e($m['label']);
            $table = Output::e($m['table_name']);
            $icon  = Output::e($m['icon_class']);
            $fcount = (int) $m['field_count'];
            $badge  = $m['status'] === 'active'
                ? '<span class="ib-badge ib-badge--success">Actiu</span>'
                : '<span class="ib-badge ib-badge--secondary">Inactiu</span>';
            $editUrl = Output::e("/{$app}/sys_mod/{$id}/edit");
            $previewUrl = Output::e("/{$app}/{$m['name']}");

            $rows .= <<<HTML
            <tr>
                <td class="ib-table__td"><i class="{$icon}"></i></td>
                <td class="ib-table__td"><a href="{$editUrl}"><strong>{$label}</strong></a></td>
                <td class="ib-table__td"><code>{$name}</code></td>
                <td class="ib-table__td"><code>{$table}</code></td>
                <td class="ib-table__td ib-text-center">{$fcount}</td>
                <td class="ib-table__td">{$badge}</td>
                <td class="ib-table__td ib-table__td--actions">
                    <a href="{$editUrl}" class="ib-btn ib-btn--sm ib-btn--secondary" title="Editar"><i class="fa-solid fa-pen"></i></a>
                    <a href="{$previewUrl}" class="ib-btn ib-btn--sm ib-btn--outline" title="Previsualitzar" target="_blank"><i class="fa-solid fa-eye"></i></a>
                    <button type="button" class="ib-btn ib-btn--sm ib-btn--danger" title="Eliminar"
                            onclick="delMod({$id},'{$label}','{$token}')"><i class="fa-solid fa-trash"></i></button>
                </td>
            </tr>
            HTML;
        }

        $createUrl = Output::e("/{$app}/sys_mod/create");
        $count     = count($mods);
        $empty     = $count === 0
            ? '<tr><td colspan="7" class="ib-empty-state" style="padding:3rem;text-align:center;color:var(--ib-text-muted)">Encara no hi ha mòduls. Fes clic a "Nou Mòdul" per crear-ne un.</td></tr>'
            : '';

        echo $this->css() . <<<HTML
        <div class="ib-browser">
            <div class="ib-browser__toolbar">
                <h2 class="ib-browser__title">
                    <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>
                    Constructor de Mòduls
                    <span class="ib-badge ib-badge--neutral">{$count}</span>
                </h2>
                <div class="ib-browser__toolbar-right">
                    <a href="{$createUrl}" class="ib-btn ib-btn--primary">
                        <i class="fa-solid fa-plus"></i> Nou Mòdul
                    </a>
                </div>
            </div>

            <div class="ib-builder-desc">
                <p style="margin:0;color:var(--ib-text-muted);font-size:.875rem;line-height:1.6">
                    Crea i gestiona mòduls CRUD. Defineix camps, tipus, permisos, filtres, JS events i més.
                </p>
            </div>

            <div class="ib-browser__table-wrap">
                <table class="ib-table">
                    <thead>
                        <tr>
                            <th class="ib-table__th" style="width:36px"></th>
                            <th class="ib-table__th">Nom visible</th>
                            <th class="ib-table__th">Clau (slug)</th>
                            <th class="ib-table__th">Taula BD</th>
                            <th class="ib-table__th">Camps</th>
                            <th class="ib-table__th">Estat</th>
                            <th class="ib-table__th" style="width:120px">Accions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$rows}
                        {$empty}
                    </tbody>
                </table>
            </div>
        </div>
        <script>
        async function delMod(id, label, tok) {
            if (!confirm('Eliminar el mòdul "' + label + '"?\nAquesta acció no es pot desfer.')) return;
            const r = await fetch(window.location.pathname, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=delete_module&id=' + id + '&_token=' + encodeURIComponent(tok)
            });
            const res = await r.json();
            if (res.ok) location.reload(); else alert(res.error || 'Error');
        }
        </script>
        HTML;
    }

    // -------------------------------------------------------------------------
    // Edit page
    // -------------------------------------------------------------------------

    private function renderEditPage(int $modId): void
    {
        if ($modId <= 0) { header('Location: ' . Router::url('sys_mod') . '?view=list'); exit; }

        $pdo  = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT * FROM ib_sys_mods WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $modId]);
        $mod = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$mod) { header('Location: ' . Router::url('sys_mod') . '?view=list'); exit; }

        $stmt = $pdo->prepare('SELECT * FROM ib_sys_mod_attributes WHERE mod_id = :mid ORDER BY sort_order ASC, id ASC');
        $stmt->execute([':mid' => $modId]);
        $fields = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $this->renderModuleForm($mod, $fields);
    }

    // -------------------------------------------------------------------------
    // Create / Edit form — organised in TABS
    // -------------------------------------------------------------------------

    private function renderModuleForm(?array $mod, array $fields): void
    {
        $app    = Router::currentApp() ?: 'system';
        $token  = $this->csrf->token();
        $csrf   = $this->csrf->field();
        $isEdit = ($mod !== null);
        $modId  = $isEdit ? (int)$mod['id'] : 0;
        $title  = $isEdit ? 'Editar Mòdul' : 'Nou Mòdul';
        $listUrl = Output::e("/{$app}/sys_mod");

        // ── Module values ────────────────────────────────────────────────
        $v = fn(string $k, string $d = '') => Output::e((string)($mod[$k] ?? $d));
        $vName   = $v('name');
        $vLabel  = $v('label');
        $vTable  = $v('table_name', 'ib_');
        $vIcon   = $v('icon_class', 'fa-solid fa-database');
        $vDesc   = $v('description');
        $vOrder  = $v('order_by', 'id DESC');
        $vStat   = $mod['status'] ?? 'active';
        $vPerPage = (int)($mod['per_page'] ?? 25);
        $vBaseWhere = Output::e((string)($mod['base_where'] ?? ''));
        $vOnReady = Output::e((string)($mod['on_document_ready'] ?? ''));
        $vCustomCss = Output::e((string)($mod['custom_css'] ?? ''));

        $chk = fn(string $k, bool $d = true) => (bool)($mod[$k] ?? $d) ? ' checked' : '';
        $cc  = $chk('can_create');
        $ce  = $chk('can_edit');
        $cd  = $chk('can_delete');
        $cx  = $chk('can_export');
        $cdup = $chk('can_duplicate', false);
        $cimp = $chk('can_import', false);
        $cms = $chk('can_multiselect');
        $csk = $chk('show_key', false);

        // ── Fields table rows ────────────────────────────────────────────
        $fieldRows = '';
        foreach ($fields as $f) {
            $fid   = (int)$f['id'];
            $fname = Output::e($f['field_name']);
            $flbl  = Output::e($f['field_label']);
            $ftype = Output::e($f['field_type']);
            $fgrp  = Output::e($f['field_group'] ?? '');
            $fcomp = Output::e($f['component_type'] ?? '');
            $req   = $f['is_required']  ? '<i class="fa-solid fa-asterisk" style="color:var(--ib-danger,#ef4444);font-size:.7em" title="Obligatori"></i>' : '';
            $ro    = $f['is_readonly']  ? '<i class="fa-solid fa-lock" style="color:var(--ib-text-muted);font-size:.7em" title="Només lectura"></i>' : '';
            $lst   = $f['show_in_list'] ? '<i class="fa-solid fa-check" style="color:var(--ib-success,#10b981)"></i>' : '';
            $compBadge = $fcomp !== '' ? '<span class="ib-badge ib-badge--outline" style="font-size:.72rem;background:var(--ib-bg);"><i class="fa-solid fa-puzzle-piece" style="font-size:.65em;margin-right:3px"></i>' . $fcomp . '</span>' : '';
            $fieldRows .= <<<HTML
            <tr id="frow-{$fid}" data-id="{$fid}">
                <td class="ib-drag-handle" title="Arrossega per ordenar"><i class="fa-solid fa-grip-vertical ib-text-muted"></i></td>
                <td><code style="font-size:.82rem">{$fname}</code></td>
                <td>{$flbl}</td>
                <td><span class="ib-badge ib-badge--outline" style="font-size:.75rem">{$ftype}</span></td>
                <td>{$compBadge}</td>
                <td>{$req} {$ro}</td>
                <td>{$lst}</td>
                <td><small class="ib-text-muted">{$fgrp}</small></td>
                <td>
                    <button type="button" class="ib-btn ib-btn--sm ib-btn--secondary" onclick="editField({$fid})"><i class="fa-solid fa-pen"></i></button>
                    <button type="button" class="ib-btn ib-btn--sm ib-btn--danger" onclick="delField({$fid},{$modId})"><i class="fa-solid fa-trash"></i></button>
                </td>
            </tr>
            HTML;
        }

        $fcount = count($fields);
        $emptyFields = ($fcount === 0)
            ? '<tr id="empty-fields-row"><td colspan="9" style="text-align:center;padding:2rem;color:var(--ib-text-muted);font-size:.87rem">Afegeix el primer camp amb el botó <strong>+ Camp</strong>.</td></tr>'
            : '';

        // Type <select> options
        $typeOpts = '';
        foreach (self::FIELD_TYPES as $val => $lbl) {
            $typeOpts .= '<option value="' . Output::e($val) . '">' . Output::e($lbl) . '</option>';
        }

        // Apps dropdown
        $apps = $this->db->pdo()
            ->query('SELECT keyname, name FROM ib_apps WHERE is_active = 1 ORDER BY sort_order ASC')
            ->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $appOpts = '<option value="">— Selecciona una app —</option>';
        foreach ($apps as $a) {
            $appOpts .= '<option value="' . Output::e($a['keyname']) . '">' . Output::e($a['name']) . '</option>';
        }

        $hideSection = $isEdit ? '' : 'style="display:none"';
        $tableBtn    = $isEdit
            ? '<button type="button" class="ib-btn ib-btn--outline" id="btnCT" onclick="createTable(' . $modId . ')"><i class="fa-solid fa-database"></i> Crear/Actualitzar Taula BD</button>'
            : '';
        $previewBtn = $isEdit
            ? '<a href="/' . Output::e($app) . '/' . Output::e($mod['name'] ?? '') . '" class="ib-btn ib-btn--ghost" target="_blank"><i class="fa-solid fa-eye"></i> Previsualitzar</a>'
            : '';

        $sidebarSection = $isEdit ? <<<HTML
        <div class="ib-card ib-builder-card">
            <h3 class="ib-builder-card-title"><i class="fa-solid fa-bars-staggered"></i> Afegir al Sidebar</h3>
            <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end">
                <div style="display:flex;flex-direction:column;gap:.25rem;flex:1;min-width:160px">
                    <label style="font-size:.82rem;font-weight:500;color:var(--ib-text-secondary)">App</label>
                    <select id="sidebarApp" class="ib-builder-input">{$appOpts}</select>
                </div>
                <div style="display:flex;flex-direction:column;gap:.25rem;flex:1;min-width:160px">
                    <label style="font-size:.82rem;font-weight:500;color:var(--ib-text-secondary)">Nom del grup</label>
                    <input type="text" id="sidebarGroup" class="ib-builder-input" value="Personalitzats">
                </div>
                <button type="button" class="ib-btn ib-btn--secondary" onclick="addSidebar({$modId})">
                    <i class="fa-solid fa-plus"></i> Afegir
                </button>
            </div>
        </div>
        HTML : '';

        // JSON for fields data (for the edit modal)
        $fieldsJson = json_encode(
            array_combine(array_column($fields, 'id'), $fields),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?: '{}';

        echo $this->css() . <<<HTML
        <div class="ib-browser">
            <!-- Header -->
            <div class="ib-browser__toolbar">
                <h2 class="ib-browser__title">
                    <a href="{$listUrl}" class="ib-btn ib-btn--ghost ib-btn--sm"><i class="fa-solid fa-arrow-left"></i></a>
                    <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>
                    {$title}
                </h2>
                <div class="ib-browser__toolbar-right">
                    <button type="button" class="ib-btn ib-btn--primary" onclick="saveMod({$modId})">
                        <i class="fa-solid fa-floppy-disk"></i> Desar
                    </button>
                    {$tableBtn}
                    {$previewBtn}
                </div>
            </div>

            <!-- ═══════════ MODULE TABS ═══════════ -->
            <div class="ib-tabs" style="border-bottom:1px solid var(--ib-border)">
                <nav class="ib-tabs__nav" id="modTabs">
                    <a href="#" class="ib-tabs__nav-item ib-tabs__nav-item--active" data-tab="tab-def" onclick="switchTab(event,'tab-def')"><i class="fa-solid fa-cube"></i> Definició</a>
                    <a href="#" class="ib-tabs__nav-item" data-tab="tab-fields" onclick="switchTab(event,'tab-fields')"><i class="fa-solid fa-table-columns"></i> Camps <span class="ib-badge ib-badge--secondary" id="fieldCount">{$fcount}</span></a>
                    <a href="#" class="ib-tabs__nav-item" data-tab="tab-perms" onclick="switchTab(event,'tab-perms')"><i class="fa-solid fa-shield-halved"></i> Permisos</a>
                    <a href="#" class="ib-tabs__nav-item" data-tab="tab-browser" onclick="switchTab(event,'tab-browser')"><i class="fa-solid fa-table-list"></i> Navegador</a>
                    <a href="#" class="ib-tabs__nav-item" data-tab="tab-js" onclick="switchTab(event,'tab-js')"><i class="fa-solid fa-code"></i> JS / CSS</a>
                    <a href="#" class="ib-tabs__nav-item" data-tab="tab-sidebar" onclick="switchTab(event,'tab-sidebar')"><i class="fa-solid fa-bars-staggered"></i> Sidebar</a>
                </nav>
            </div>

            <!-- ═══ TAB: Definició ═══ -->
            <div class="mod-tab-panel" id="tab-def">
                <div class="ib-card ib-builder-card">
                    <div class="ib-builder-grid">
                        <div class="ib-builder-field">
                            <label>Nom visible <span class="ib-req">*</span></label>
                            <input type="text" id="mod_label" class="ib-builder-input" value="{$vLabel}" placeholder="Ex: Leads comercials">
                        </div>
                        <div class="ib-builder-field">
                            <label>Clau (slug) <span class="ib-req">*</span></label>
                            <input type="text" id="mod_name" class="ib-builder-input" value="{$vName}"
                                   placeholder="Ex: crm_leads" oninput="sanitizeSlug(this)">
                            <small>Minúscules, sense espais ni accents. Únic al sistema.</small>
                        </div>
                        <div class="ib-builder-field">
                            <label>Taula BD <span class="ib-req">*</span></label>
                            <input type="text" id="mod_table" class="ib-builder-input" value="{$vTable}"
                                   placeholder="ib_crm_leads" oninput="sanitizeSlug(this)">
                            <small>Ha de tenir prefix <code>ib_</code>.</small>
                        </div>
                        <div class="ib-builder-field">
                            <label>Icona (Font Awesome)</label>
                            <input type="text" id="mod_icon" class="ib-builder-input" value="{$vIcon}" placeholder="fa-solid fa-database">
                            <small>Ex: <code>fa-solid fa-star</code> &nbsp; <a href="https://fontawesome.com/icons" target="_blank" rel="noopener">Cercar icones ↗</a></small>
                        </div>
                        <div class="ib-builder-field ib-builder-field--full">
                            <label>Descripció</label>
                            <textarea id="mod_desc" class="ib-builder-input" rows="2">{$vDesc}</textarea>
                        </div>
                        <div class="ib-builder-field">
                            <label>Estat</label>
                            <select id="mod_status" class="ib-builder-input">
                                <option value="active"   {$this->sel($vStat,'active')}>Actiu</option>
                                <option value="inactive" {$this->sel($vStat,'inactive')}>Inactiu</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ TAB: Camps ═══ -->
            <div class="mod-tab-panel" id="tab-fields" style="display:none" {$hideSection}>
                <div class="ib-card ib-builder-card">
                    <h3 class="ib-builder-card-title">
                        <i class="fa-solid fa-table-columns"></i> Camps
                        <div style="margin-left:auto; display:flex; gap:6px">
                            <button type="button" class="ib-btn ib-btn--sm ib-btn--secondary" id="btnSyncFields"
                                    onclick="syncFields({$modId})" title="Llegeix columnes BD + fitxer PHP i afegeix camps nous">
                                <i class="fa-solid fa-rotate"></i> Sincronitzar
                            </button>
                            <button type="button" class="ib-btn ib-btn--sm ib-btn--primary"
                                    onclick="openFieldModal(0,{$modId})">
                                <i class="fa-solid fa-plus"></i> Camp
                            </button>
                        </div>
                    </h3>
                    <div style="overflow-x:auto">
                        <table class="ib-table ib-table--compact" id="fTable">
                            <thead>
                                <tr>
                                    <th style="width:28px"></th>
                                    <th style="text-align:left">Nom BD</th>
                                    <th style="text-align:left">Etiqueta</th>
                                    <th style="text-align:left">Tipus</th>
                                    <th style="text-align:left">Component</th>
                                    <th title="Obligatori / Readonly" style="width:60px;text-align:left">Flags</th>
                                    <th title="Visible al llistat" style="width:60px;text-align:left">Llistat</th>
                                    <th style="text-align:left">Grup</th>
                                    <th style="width:90px;text-align:left">Accions</th>
                                </tr>
                            </thead>
                            <tbody id="fList">
                                {$fieldRows}
                                {$emptyFields}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═══ TAB: Permisos ═══ -->
            <div class="mod-tab-panel" id="tab-perms" style="display:none">
                <div class="ib-card ib-builder-card">
                    <h3 class="ib-builder-card-title"><i class="fa-solid fa-shield-halved"></i> Permisos del mòdul</h3>
                    <div class="ib-builder-perms-grid">
                        <label class="ib-perm-item"><input type="checkbox" id="can_create"{$cc}> <i class="fa-solid fa-plus"></i> Crear registres</label>
                        <label class="ib-perm-item"><input type="checkbox" id="can_edit"{$ce}> <i class="fa-solid fa-pen"></i> Editar registres</label>
                        <label class="ib-perm-item"><input type="checkbox" id="can_delete"{$cd}> <i class="fa-solid fa-trash"></i> Eliminar registres</label>
                        <label class="ib-perm-item"><input type="checkbox" id="can_export"{$cx}> <i class="fa-solid fa-file-export"></i> Exportar (CSV/Excel)</label>
                        <label class="ib-perm-item"><input type="checkbox" id="can_duplicate"{$cdup}> <i class="fa-solid fa-copy"></i> Duplicar registres</label>
                        <label class="ib-perm-item"><input type="checkbox" id="can_import"{$cimp}> <i class="fa-solid fa-file-import"></i> Importar (CSV)</label>
                        <label class="ib-perm-item"><input type="checkbox" id="can_multiselect"{$cms}> <i class="fa-solid fa-check-double"></i> Selecció múltiple</label>
                    </div>
                </div>
            </div>

            <!-- ═══ TAB: Navegador ═══ -->
            <div class="mod-tab-panel" id="tab-browser" style="display:none">
                <div class="ib-card ib-builder-card">
                    <h3 class="ib-builder-card-title"><i class="fa-solid fa-table-list"></i> Configuració del navegador</h3>
                    <div class="ib-builder-grid">
                        <div class="ib-builder-field">
                            <label>Ordenació per defecte</label>
                            <input type="text" id="mod_order" class="ib-builder-input" value="{$vOrder}" placeholder="id DESC">
                            <small>Format: <code>columna ASC</code> o <code>columna DESC</code></small>
                        </div>
                        <div class="ib-builder-field">
                            <label>Registres per pàgina</label>
                            <input type="number" id="mod_per_page" class="ib-builder-input" value="{$vPerPage}" min="1" max="500">
                        </div>
                        <div class="ib-builder-field ib-builder-field--full">
                            <label>Filtre base (WHERE)</label>
                            <input type="text" id="mod_base_where" class="ib-builder-input" value="{$vBaseWhere}"
                                   placeholder="Ex: status = 'active' AND tenant_id = 1">
                            <small>Clàusula WHERE afegida a totes les consultes. Deixar buit per sense filtre.</small>
                        </div>
                        <div class="ib-builder-field">
                            <label style="visibility:hidden">_</label>
                            <label class="ib-perm-item"><input type="checkbox" id="show_key"{$csk}> Mostrar columna ID al llistat</label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ TAB: JS / CSS ═══ -->
            <div class="mod-tab-panel" id="tab-js" style="display:none">
                <div class="ib-card ib-builder-card">
                    <h3 class="ib-builder-card-title"><i class="fa-solid fa-code"></i> JavaScript i CSS personalitzat</h3>
                    <div class="ib-builder-grid">
                        <div class="ib-builder-field ib-builder-field--full">
                            <label>onDocumentReady (JavaScript)</label>
                            <textarea id="mod_on_ready" class="ib-builder-input ib-code-input" rows="6" placeholder="// Codi JS executat al carregar la pàgina del mòdul&#10;console.log('Module loaded');">{$vOnReady}</textarea>
                            <small>S'executarà dins <code>$(document).ready()</code>. Accés a <code>$</code> (jQuery).</small>
                        </div>
                        <div class="ib-builder-field ib-builder-field--full">
                            <label>CSS personalitzat</label>
                            <textarea id="mod_custom_css" class="ib-builder-input ib-code-input" rows="6" placeholder="/* Estils CSS propis del mòdul */&#10;.ib-table__td { font-size: .85rem; }">{$vCustomCss}</textarea>
                            <small>S'injectarà com a <code>&lt;style&gt;</code> dins la pàgina del mòdul.</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ TAB: Sidebar ═══ -->
            <div class="mod-tab-panel" id="tab-sidebar" style="display:none">
                {$sidebarSection}
            </div>
        </div>

        <!-- ═══════════ FIELD MODAL ═══════════ -->
        <div id="fModal" class="ib-modal-overlay" style="display:none" onclick="if(event.target===this)closeModal()">
            <div class="ib-modal">
                <div class="ib-modal-header">
                    <h3 id="fModalTitle"><i class="fa-solid fa-table-columns"></i> Camp</h3>
                    <button type="button" class="ib-modal-close" onclick="closeModal()">×</button>
                </div>
                <div class="ib-modal-body">
                    <input type="hidden" id="fm_fid" value="0">
                    <input type="hidden" id="fm_mid" value="{$modId}">

                    <!-- Field modal tabs -->
                    <div class="ib-tabs" style="margin-bottom:1rem;border-bottom:1px solid var(--ib-border)">
                        <nav class="ib-tabs__nav" style="gap:0">
                            <a href="#" class="ib-tabs__nav-item ib-tabs__nav-item--active" data-tab="fmt-basic" onclick="switchTab(event,'fmt-basic')">Bàsic</a>
                            <a href="#" class="ib-tabs__nav-item" data-tab="fmt-display" onclick="switchTab(event,'fmt-display')">Visualització</a>
                            <a href="#" class="ib-tabs__nav-item" data-tab="fmt-advanced" onclick="switchTab(event,'fmt-advanced')">Avançat</a>
                            <a href="#" class="ib-tabs__nav-item" data-tab="fmt-component" onclick="switchTab(event,'fmt-component')"><i class="fa-solid fa-puzzle-piece"></i> Component</a>
                        </nav>
                    </div>

                    <!-- Field tab: Bàsic -->
                    <div class="mod-tab-panel" id="fmt-basic">
                        <div class="ib-builder-grid">
                            <div class="ib-builder-field">
                                <label>Nom del camp (columna BD) <span class="ib-req">*</span></label>
                                <input type="text" id="fm_name" class="ib-builder-input" placeholder="ex: due_date"
                                       oninput="sanitizeSlug(this)">
                            </div>
                            <div class="ib-builder-field">
                                <label>Etiqueta visible <span class="ib-req">*</span></label>
                                <input type="text" id="fm_label" class="ib-builder-input" placeholder="ex: Data límit">
                            </div>
                            <div class="ib-builder-field">
                                <label>Tipus de camp</label>
                                <select id="fm_type" class="ib-builder-input" onchange="onFmTypeChange(this.value)">
                                    {$typeOpts}
                                </select>
                            </div>
                            <div class="ib-builder-field">
                                <label>Grup / Pestanya</label>
                                <input type="text" id="fm_group" class="ib-builder-input" placeholder="ex: Adreça, Pagament">
                                <small>Agrupa camps en pestanyes al formulari.</small>
                            </div>
                            <div class="ib-builder-field ib-builder-field--full" id="fm_opts_wrap" style="display:none">
                                <label>Opcions del camp</label>
                                <textarea id="fm_opts" class="ib-builder-input" rows="3"
                                          placeholder="Format select: val1:Etiqueta1,val2:Etiqueta2&#10;Format combo (FK): {&quot;table&quot;:&quot;ib_users&quot;,&quot;key&quot;:&quot;id&quot;,&quot;value&quot;:&quot;name&quot;}"></textarea>
                                <small id="fm_opts_hint">Per a 'select': opció:Etiqueta. Per a 'combo': JSON amb table/key/value.</small>
                            </div>
                            <div class="ib-builder-field">
                                <label>Valor per defecte</label>
                                <input type="text" id="fm_default" class="ib-builder-input" placeholder="">
                            </div>
                            <div class="ib-builder-field">
                                <label>Ordre</label>
                                <input type="number" id="fm_order" class="ib-builder-input" value="0" min="0" step="10">
                            </div>
                            <div class="ib-builder-field">
                                <label>Flags</label>
                                <div class="ib-field-flags">
                                    <label><input type="checkbox" id="fm_req"> Obligatori</label>
                                    <label><input type="checkbox" id="fm_sch"> Cercable</label>
                                    <label><input type="checkbox" id="fm_ro"> Només lectura</label>
                                    <label><input type="checkbox" id="fm_totals"> Sumar totals</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Field tab: Visualització -->
                    <div class="mod-tab-panel" id="fmt-display" style="display:none">
                        <div class="ib-builder-grid">
                            <div class="ib-builder-field">
                                <label>Visible al llistat</label>
                                <label class="ib-perm-item"><input type="checkbox" id="fm_sil" checked> Mostrar al llistat (browser)</label>
                            </div>
                            <div class="ib-builder-field">
                                <label>Visible al formulari</label>
                                <label class="ib-perm-item"><input type="checkbox" id="fm_sie" checked> Mostrar al formulari d'edició</label>
                            </div>
                            <div class="ib-builder-field">
                                <label>Visible al detall</label>
                                <label class="ib-perm-item"><input type="checkbox" id="fm_sid" checked> Mostrar a la vista detall</label>
                            </div>
                            <div class="ib-builder-field">
                                <label>No wrap</label>
                                <label class="ib-perm-item"><input type="checkbox" id="fm_nw"> No trencar línia (nowrap)</label>
                            </div>
                            <div class="ib-builder-field">
                                <label>Abreviatura (capçalera curta)</label>
                                <input type="text" id="fm_abbrev" class="ib-builder-input" placeholder="ex: Qty" maxlength="50">
                                <small>Text curt per columnes estretes al llistat.</small>
                            </div>
                            <div class="ib-builder-field">
                                <label>Classe CSS</label>
                                <input type="text" id="fm_css_class" class="ib-builder-input" placeholder="ex: ib-text-center text-bold">
                            </div>
                            <div class="ib-builder-field ib-builder-field--full">
                                <label>Text d'ajuda (tooltip)</label>
                                <input type="text" id="fm_help_text" class="ib-builder-input" placeholder="Text que apareixerà com a tooltip al formulari">
                            </div>
                        </div>
                    </div>

                    <!-- Field tab: Avançat -->
                    <div class="mod-tab-panel" id="fmt-advanced" style="display:none">
                        <div class="ib-builder-grid">
                            <div class="ib-builder-field">
                                <label>Validació (regex)</label>
                                <input type="text" id="fm_regex" class="ib-builder-input" placeholder="ex: /^[0-9]{9}$/">
                            </div>
                            <div class="ib-builder-field">
                                <label>Longitud màxima</label>
                                <input type="number" id="fm_maxlen" class="ib-builder-input" value="0" min="0">
                                <small>0 = sense límit.</small>
                            </div>
                            <div class="ib-builder-field ib-builder-field--full">
                                <label>onFieldChange (JavaScript)</label>
                                <textarea id="fm_onchange" class="ib-builder-input ib-code-input" rows="3"
                                          placeholder="// JS executat quan el camp canvia de valor&#10;// 'value' conté el nou valor"></textarea>
                                <small>S'executarà amb la variable <code>value</code> disponible.</small>
                            </div>
                            <div class="ib-builder-field ib-builder-field--full">
                                <label>Notes internes</label>
                                <input type="text" id="fm_notes" class="ib-builder-input" placeholder="">
                            </div>
                        </div>
                    </div>

                    <!-- Field tab: Component -->
                    <div class="mod-tab-panel" id="fmt-component" style="display:none">
                        <div class="ib-builder-grid">
                            <div class="ib-builder-field ib-builder-field--full">
                                <label>Component de renderització</label>
                                <select id="fm_component_type" class="ib-builder-input" onchange="onComponentTypeChange(this.value)">
                                    <option value="">— Cap (utilitza el tipus de camp) —</option>
                                    {$this->componentTypeOptions()}
                                </select>
                                <small>Sobreescriu el renderitzat per defecte del camp amb un component especialitzat.</small>
                            </div>
                            <div class="ib-builder-field ib-builder-field--full" id="fm_component_opts_wrap" style="display:none">
                                <label>Paràmetres del component (JSON)</label>
                                <textarea id="fm_component_options" class="ib-builder-input ib-code-input" rows="6"
                                          placeholder='{"table":"ib_taula","key":"id","value":"nom"}'></textarea>
                                <small id="fm_component_help">Opcions específiques del component en format JSON.</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="ib-modal-footer">
                    <button type="button" class="ib-btn ib-btn--ghost" onclick="closeModal()">Cancel·lar</button>
                    <button type="button" class="ib-btn ib-btn--primary" onclick="saveField()">
                        <i class="fa-solid fa-floppy-disk"></i> Desar Camp
                    </button>
                </div>
            </div>
        </div>

        <script>
        const TOK   = {$this->jsq($token)};
        const MOD_ID= {$modId};
        const BASE  = '/{$app}/sys_mod';
        const FDATA = {$fieldsJson};

        function switchTab(e, tabId) {
            e.preventDefault();
            const nav = e.target.closest('.ib-tabs__nav');
            const container = nav.closest('.ib-browser') || nav.closest('.ib-modal-body') || document;
            nav.querySelectorAll('.ib-tabs__nav-item').forEach(t => t.classList.remove('ib-tabs__nav-item--active'));
            e.target.closest('.ib-tabs__nav-item').classList.add('ib-tabs__nav-item--active');
            // Determine scope: if inside modal, only toggle modal panels
            const scope = nav.closest('.ib-modal-body') || nav.closest('.ib-browser') || document;
            const prefix = tabId.startsWith('fmt-') ? 'fmt-' : 'tab-';
            scope.querySelectorAll('.mod-tab-panel').forEach(p => {
                if (p.id.startsWith(prefix)) p.style.display = p.id === tabId ? '' : 'none';
            });
        }

        function sanitizeSlug(el) {
            el.value = el.value.toLowerCase().replace(/[^a-z0-9_]/g, '_');
        }
        function g(id) { return document.getElementById(id)?.value ?? ''; }
        function s(id, v) { const el = document.getElementById(id); if (el) el.value = v; }
        function c(id, v) { const el = document.getElementById(id); if (el) el.checked = !!v; }

        async function api(data) {
            const r = await fetch(window.location.pathname, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({...data, _token: TOK})
            });
            return r.json();
        }

        // ── Save module (all tabs) ───────────────────────────────────────
        async function saveMod(id) {
            const res = await api({
                action: 'save_module', id,
                name:              g('mod_name'),
                label:             g('mod_label'),
                table_name:        g('mod_table'),
                icon_class:        g('mod_icon'),
                description:       g('mod_desc'),
                order_by:          g('mod_order'),
                status:            g('mod_status'),
                per_page:          g('mod_per_page'),
                base_where:        g('mod_base_where'),
                show_key:          document.getElementById('show_key').checked ? 1 : 0,
                can_create:        document.getElementById('can_create').checked ? 1 : 0,
                can_edit:          document.getElementById('can_edit').checked ? 1 : 0,
                can_delete:        document.getElementById('can_delete').checked ? 1 : 0,
                can_export:        document.getElementById('can_export').checked ? 1 : 0,
                can_duplicate:     document.getElementById('can_duplicate').checked ? 1 : 0,
                can_import:        document.getElementById('can_import').checked ? 1 : 0,
                can_multiselect:   document.getElementById('can_multiselect').checked ? 1 : 0,
                on_document_ready: g('mod_on_ready'),
                custom_css:        g('mod_custom_css'),
            });
            if (res.ok) {
                toast('Mòdul desat correctament.', 'success');
                if (!id && res.id) {
                    setTimeout(() => window.location.href = BASE + '/' + res.id + '/edit', 800);
                }
            } else {
                toast(res.error || 'Error desant', 'error');
            }
        }

        // ── Create/update table ──────────────────────────────────────────
        async function createTable(modId) {
            const btn = document.getElementById('btnCT');
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processant...'; }
            const res = await api({ action: 'create_table', mod_id: modId });
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-database"></i> Crear/Actualitzar Taula BD'; }
            if (res.ok) {
                const msg = res.action === 'created' ? 'Taula creada!' :
                            res.action === 'altered' ? (res.added + ' columna(s) afegida(s).') :
                            res.message || 'Sense canvis.';
                toast(msg, 'success');
            } else {
                toast(res.error || 'Error creant taula', 'error');
            }
        }

        // ── Sidebar ──────────────────────────────────────────────────────
        async function addSidebar(modId) {
            const appKey = g('sidebarApp');
            const group  = g('sidebarGroup') || 'Personalitzats';
            if (!appKey) { toast("Selecciona una app.", 'error'); return; }
            const res = await api({ action: 'add_to_sidebar', mod_id: modId, app_key: appKey, group_name: group });
            toast(res.ok ? (res.message || 'Afegit!') : (res.error || 'Error'), res.ok ? 'success' : 'error');
        }

        // ── Sync fields ─────────────────────────────────────────────────
        async function syncFields(modId) {
            const btn = document.getElementById('btnSyncFields');
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sincronitzant...'; }
            const res = await api({ action: 'sync_fields', mod_id: modId });
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-rotate"></i> Sincronitzar'; }
            if (res.ok) {
                toast(res.message || 'Sincronització completada', 'success');
                setTimeout(() => { location.hash = 'tab-fields'; location.reload(); }, 800);
            } else {
                toast(res.error || 'Error sincronitzant camps', 'error');
            }
        }

        // ── Field modal ─────────────────────────────────────────────────
        function openFieldModal(fid, mid) {
            document.getElementById('fm_fid').value = fid;
            document.getElementById('fm_mid').value = mid;

            // Reset to first tab
            const modalNav = document.querySelector('#fModal .ib-tabs__nav');
            if (modalNav) {
                modalNav.querySelectorAll('.ib-tabs__nav-item').forEach((t,i) => {
                    t.classList.toggle('ib-tabs__nav-item--active', i === 0);
                });
            }
            document.querySelectorAll('#fModal .mod-tab-panel').forEach((p,i) => p.style.display = i === 0 ? '' : 'none');

            if (fid > 0 && FDATA[fid]) {
                const fd = FDATA[fid];
                document.getElementById('fModalTitle').innerHTML = '<i class="fa-solid fa-pen"></i> Editar Camp';
                s('fm_name',      fd.field_name);
                s('fm_label',     fd.field_label);
                s('fm_type',      fd.field_type);
                s('fm_group',     fd.field_group || '');
                s('fm_opts',      fd.field_options || '');
                s('fm_default',   fd.default_value || '');
                s('fm_regex',     fd.validation_regex || '');
                s('fm_order',     fd.sort_order ?? 0);
                s('fm_notes',     fd.notes || '');
                // New fields
                s('fm_abbrev',    fd.abbrev || '');
                s('fm_help_text', fd.help_text || '');
                s('fm_maxlen',    fd.max_length || 0);
                s('fm_css_class', fd.css_class || '');
                s('fm_onchange',  fd.on_change_js || '');
                // Checkboxes
                c('fm_req',    fd.is_required);
                c('fm_sch',    fd.is_searchable);
                c('fm_ro',     fd.is_readonly);
                c('fm_sil',    fd.show_in_list);
                c('fm_sie',    fd.show_in_edit ?? 1);
                c('fm_sid',    fd.show_in_detail ?? 1);
                c('fm_nw',     fd.no_wrap);
                c('fm_totals', fd.is_totals);
                // Component
                s('fm_component_type', fd.component_type || '');
                s('fm_component_options', fd.component_options || '');
                onComponentTypeChange(fd.component_type || '');
            } else {
                document.getElementById('fModalTitle').innerHTML = '<i class="fa-solid fa-plus"></i> Nou Camp';
                ['fm_name','fm_label','fm_group','fm_opts','fm_default','fm_regex','fm_notes','fm_abbrev','fm_help_text','fm_css_class','fm_onchange'].forEach(id => s(id,''));
                s('fm_type', 'text');
                s('fm_order', Object.keys(FDATA).length * 10);
                s('fm_maxlen', 0);
                c('fm_req', false); c('fm_sch', false); c('fm_ro', false); c('fm_totals', false);
                c('fm_sil', true); c('fm_sie', true); c('fm_sid', true); c('fm_nw', false);
                s('fm_component_type', ''); s('fm_component_options', '');
                onComponentTypeChange('');
            }
            onFmTypeChange(g('fm_type'));
            document.getElementById('fModal').style.display = 'flex';
        }

        function editField(fid) { openFieldModal(fid, MOD_ID); }
        function closeModal() { document.getElementById('fModal').style.display = 'none'; }

        function onComponentTypeChange(type) {
            const wrap = document.getElementById('fm_component_opts_wrap');
            if (wrap) wrap.style.display = type ? '' : 'none';
        }

        function onFmTypeChange(type) {
            const needs = ['select', 'combo'].includes(type);
            document.getElementById('fm_opts_wrap').style.display = needs ? 'block' : 'none';
            const hint = document.getElementById('fm_opts_hint');
            if (hint) {
                hint.textContent = type === 'combo'
                    ? 'JSON: {"table":"ib_taula","key":"id","value":"nom"}'
                    : 'Format: valor:Etiqueta,valor2:Etiqueta2';
            }
        }

        async function saveField() {
            const res = await api({
                action:           'save_field',
                field_id:         g('fm_fid'),
                mod_id:           g('fm_mid'),
                field_name:       g('fm_name'),
                field_label:      g('fm_label'),
                field_type:       g('fm_type'),
                field_group:      g('fm_group'),
                field_options:    g('fm_opts'),
                default_value:    g('fm_default'),
                validation_regex: g('fm_regex'),
                sort_order:       g('fm_order'),
                notes:            g('fm_notes'),
                // New
                abbrev:        g('fm_abbrev'),
                help_text:     g('fm_help_text'),
                max_length:    g('fm_maxlen'),
                css_class:     g('fm_css_class'),
                on_change_js:  g('fm_onchange'),
                is_required:   document.getElementById('fm_req').checked ? 1 : 0,
                is_searchable: document.getElementById('fm_sch').checked ? 1 : 0,
                is_readonly:   document.getElementById('fm_ro').checked ? 1 : 0,
                show_in_list:  document.getElementById('fm_sil').checked ? 1 : 0,
                show_in_edit:  document.getElementById('fm_sie').checked ? 1 : 0,
                show_in_detail:document.getElementById('fm_sid').checked ? 1 : 0,
                no_wrap:       document.getElementById('fm_nw').checked ? 1 : 0,
                is_totals:     document.getElementById('fm_totals').checked ? 1 : 0,
                component_type:    g('fm_component_type'),
                component_options: g('fm_component_options'),
            });
            if (res.ok) {
                toast('Camp desat.', 'success');
                closeModal();
                setTimeout(() => { location.hash = 'tab-fields'; location.reload(); }, 600);
            } else {
                toast(res.error || 'Error', 'error');
            }
        }

        async function delField(fid, mid) {
            if (!confirm('Eliminar aquest camp?')) return;
            const res = await api({ action: 'delete_field', id: fid, mod_id: mid });
            if (res.ok) {
                document.getElementById('frow-' + fid)?.remove();
                const cnt = document.getElementById('fieldCount');
                if (cnt) cnt.textContent = Math.max(0, parseInt(cnt.textContent) - 1);
                toast('Camp eliminat.', 'success');
            } else {
                toast(res.error || 'Error', 'error');
            }
        }

        // ── Auto-fill from label ─────────────────────────────────────────
        const lblEl   = document.getElementById('mod_label');
        const nameEl  = document.getElementById('mod_name');
        const tableEl = document.getElementById('mod_table');
        if (lblEl && nameEl && !nameEl.value) {
            lblEl.addEventListener('input', () => {
                const slug = lblEl.value.toLowerCase()
                    .normalize('NFD').replace(/\p{Mn}/gu, '')
                    .replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
                if (!nameEl.dataset.edited)  nameEl.value  = slug;
                if (!tableEl.dataset.edited) tableEl.value = 'ib_' + slug;
            });
        }
        if (nameEl)  nameEl.addEventListener('input',  () => nameEl.dataset.edited  = '1');
        if (tableEl) tableEl.addEventListener('input', () => tableEl.dataset.edited = '1');

        // ── Toast ─────────────────────────────────────────────────────────
        function toast(msg, type = 'success') {
            const el = document.createElement('div');
            el.className = 'ib-toast ib-toast--' + type;
            el.textContent = msg;
            document.body.appendChild(el);
            requestAnimationFrame(() => el.classList.add('ib-toast--show'));
            setTimeout(() => { el.classList.remove('ib-toast--show'); setTimeout(() => el.remove(), 300); }, 3500);
        }

        // Restore tab from URL hash
        if (location.hash && location.hash.startsWith('#tab-')) {
            const tabId = location.hash.substring(1);
            const tabLink = document.querySelector('.ib-tabs__nav-item[data-tab="' + tabId + '"]');
            if (tabLink) tabLink.click();
        }
        </script>
        HTML;
    }

    // -------------------------------------------------------------------------
    // SQL helpers
    // -------------------------------------------------------------------------

    private function sqlDefault(array $field): string
    {
        $type = $field['field_type'] ?? 'text';
        $dflt = trim((string)($field['default_value'] ?? ''));

        if ($dflt !== '') {
            $safe = str_replace("'", "''", $dflt);
            return "DEFAULT '{$safe}'";
        }

        return match ($type) {
            'checkbox' => 'DEFAULT 0',
            'integer', 'combo', 'rating' => 'DEFAULT NULL',
            'decimal', 'money', 'percentage' => 'DEFAULT NULL',
            default => 'DEFAULT NULL',
        };
    }

    // -------------------------------------------------------------------------
    // Tiny helpers
    // -------------------------------------------------------------------------

    /**
     * Generate <option> tags for all registered components.
     */
    private function componentTypeOptions(): string
    {
        $types = \Intrabase\Components\ComponentRegistry::types();
        sort($types);
        $html = '';
        foreach ($types as $type) {
            $escaped = Output::e($type);
            $html .= '<option value="' . $escaped . '">' . $escaped . '</option>';
        }
        return $html;
    }

    private function sel(string $current, string $val): string
    {
        return $current === $val ? ' selected' : '';
    }

    private function jsq(string $s): string
    {
        return json_encode($s);
    }

    // -------------------------------------------------------------------------
    // CSS
    // -------------------------------------------------------------------------

    private function css(): string
    {
        return <<<CSS
        <style>
        .ib-builder-desc {
            padding: var(--ib-space-sm) var(--ib-space-md);
            border-bottom: 1px solid var(--ib-border);
        }
        .ib-builder-card {
            padding: var(--ib-space-lg) var(--ib-space-md); margin: var(--ib-space-md);
        }
        .ib-builder-card-title {
            display: flex; align-items: center; gap: var(--ib-space-xs); margin: 0 0 var(--ib-space-md);
            font-size: 1rem; font-weight: 600; color: var(--ib-text);
        }
        .ib-builder-grid { display: grid; grid-template-columns: 1fr 1fr; gap: var(--ib-space-sm); }
        .ib-builder-field { display: flex; flex-direction: column; gap: 3px; }
        .ib-builder-field--full { grid-column: 1 / -1; }
        .ib-builder-field > label { font-size: .8rem; font-weight: 500; color: var(--ib-text-muted); }
        .ib-builder-field > small { font-size: .75rem; color: var(--ib-text-muted); }
        .ib-builder-input {
            padding: var(--ib-space-xs) var(--ib-space-sm); border: 1px solid var(--ib-border);
            border-radius: var(--ib-radius); background: var(--ib-surface); color: var(--ib-text);
            font-size: .875rem; width: 100%; box-sizing: border-box;
            transition: border-color var(--ib-transition); resize: vertical;
        }
        .ib-builder-input:focus { outline: none; border-color: var(--ib-primary); box-shadow: 0 0 0 3px color-mix(in srgb, var(--ib-primary) 12%, transparent); }
        .ib-code-input { font-family: 'Fira Code', 'Cascadia Code', 'Consolas', monospace; font-size: .82rem; line-height: 1.5; tab-size: 4; }
        .ib-builder-perms-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: var(--ib-space-sm);
        }
        .ib-perm-item {
            display: flex; align-items: center; gap: .45rem; cursor: pointer; font-size: .875rem;
            padding: var(--ib-space-xs) var(--ib-space-sm); border-radius: var(--ib-radius);
            border: 1px solid var(--ib-border); transition: background .15s;
        }
        .ib-perm-item:hover { background: var(--ib-bg); }
        .ib-field-flags { display: flex; flex-direction: column; gap: .4rem; padding-top: .2rem; }
        .ib-field-flags label { display: flex; align-items: center; gap: .4rem; font-size: .87rem; cursor: pointer; }
        .ib-req { color: var(--ib-danger, #ef4444); }
        .ib-drag-handle { cursor: grab; }
        /* Tabs */
        .mod-tab-panel { animation: fadeIn .15s ease; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        /* Modal */
        .ib-modal-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 9999;
            display: flex; align-items: center; justify-content: center; padding: var(--ib-space-sm);
        }
        .ib-modal {
            background: var(--ib-surface); border: 1px solid var(--ib-border);
            border-radius: var(--ib-radius-lg); width: min(780px, 100%); max-height: 90vh; overflow: auto;
            box-shadow: var(--ib-shadow-lg, 0 24px 80px rgba(0,0,0,.4));
        }
        .ib-modal-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: var(--ib-space-md) var(--ib-space-lg); border-bottom: 1px solid var(--ib-border);
        }
        .ib-modal-header h3 { margin: 0; font-size: 1rem; font-weight: 600; display: flex; align-items: center; gap: var(--ib-space-xs); }
        .ib-modal-close {
            background: none; border: none; font-size: 1.5rem; cursor: pointer;
            color: var(--ib-text-muted); padding: 0 .25rem; line-height: 1;
        }
        .ib-modal-close:hover { color: var(--ib-text); }
        .ib-modal-body { padding: var(--ib-space-lg); }
        .ib-modal-footer {
            padding: var(--ib-space-sm) var(--ib-space-lg); border-top: 1px solid var(--ib-border);
            display: flex; justify-content: flex-end; gap: var(--ib-space-sm);
        }
        /* Toast */
        .ib-toast {
            position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 20000;
            background: var(--ib-surface); border: 1px solid var(--ib-border);
            border-radius: var(--ib-radius); padding: var(--ib-space-sm) var(--ib-space-md); max-width: 380px;
            box-shadow: var(--ib-shadow-lg, 0 8px 32px rgba(0,0,0,.25)); font-size: .875rem;
            opacity: 0; transform: translateY(10px); transition: opacity .2s, transform .2s;
        }
        .ib-toast--show { opacity: 1; transform: translateY(0); }
        .ib-toast--success { border-left: 4px solid var(--ib-success, #10b981); }
        .ib-toast--error   { border-left: 4px solid var(--ib-danger,  #ef4444); }
        @media (max-width: 640px) { .ib-builder-grid { grid-template-columns: 1fr; } }
        </style>
        CSS;
    }
}
