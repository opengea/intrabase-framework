<?php

declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;
use Intrabase\Security\Input;

/**
 * sys_permissions — Granular Module Permissions
 *
 * Manages per-group, per-module action permissions stored in `ib_permissions`.
 *
 * Each row defines what a group can do with a specific module:
 * browse (view list), create (add records), edit, delete, export (CSV).
 *
 * If no row exists for a (group, module) pair, the module's default
 * permission flags apply (which are typically all-allowed).
 *
 * Module key format follows the `mod` query parameter: e.g. 'lib_files',
 * 'sys_users', 'obj_fam'.
 */
class SysPermissions extends Module
{
    public string $table = 'ib_permissions';
    public string $key   = 'id';
    public string $title = 'Permissions';
    public string $icon  = 'fa-solid fa-shield-halved';

    public array $fields     = ['id', 'group_id', 'module_key', 'can_browse', 'can_create', 'can_edit', 'can_delete', 'can_export'];
    public array $editFields = ['group_id', 'module_key', 'can_browse', 'can_create', 'can_edit', 'can_delete', 'can_export'];
    public array $searchFields = ['module_key'];

    public array $humans = [
        'id'         => 'ID',
        'group_id'   => 'Group',
        'module_key' => 'Module',
        'can_browse' => 'Browse',
        'can_create' => 'Create',
        'can_edit'   => 'Edit',
        'can_delete' => 'Delete',
        'can_export' => 'Export',
    ];

    public array $components = [
        'group_id'   => 'combo',
        'can_browse' => 'combo',
        'can_create' => 'combo',
        'can_edit'   => 'combo',
        'can_delete' => 'combo',
        'can_export' => 'combo',
    ];

    public array $defaultValues = [
        'can_browse' => 1,
        'can_create' => 1,
        'can_edit'   => 1,
        'can_delete' => 0,
        'can_export' => 1,
    ];

    public array $validates = ['group_id', 'module_key'];
    public string $orderBy  = 'module_key';

    public bool $canDuplicate = true;

    public function __construct(...$args)
    {
        parent::__construct(...$args);

        $this->addCombo('group_id', 'ib_groups', 'id', 'name');

        $yesNo = ['1' => 'Yes', '0' => 'No'];
        $this->combos['can_browse'] = ['data' => $yesNo];
        $this->combos['can_create'] = ['data' => $yesNo];
        $this->combos['can_edit']   = ['data' => $yesNo];
        $this->combos['can_delete'] = ['data' => $yesNo];
        $this->combos['can_export'] = ['data' => $yesNo];
    }

    /**
     * Prevent duplicate (group_id, module_key) combinations.
     * The unique DB constraint is the final guard, but this gives the user
     * a friendly validation error before a PDOException can propagate.
     */
    public function onPreSave(array &$data): bool
    {
        $groupId   = (int) ($data['group_id'] ?? 0);
        $moduleKey = trim((string) ($data['module_key'] ?? ''));

        if ($groupId === 0 || $moduleKey === '') {
            return true; // Let validateData() handle empty-field errors
        }

        // Current record ID (0 on create, >0 on update)
        $currentId = Input::getInt('id', 0);

        $existing = $this->db->fetchOne(
            'SELECT id FROM ib_permissions WHERE group_id = :g AND module_key = :m',
            ['g' => $groupId, 'm' => $moduleKey]
        );

        if ($existing !== null && (int) $existing['id'] !== $currentId) {
            throw new \InvalidArgumentException(
                'A permission rule already exists for this group and module combination.'
            );
        }

        return true;
    }
}
