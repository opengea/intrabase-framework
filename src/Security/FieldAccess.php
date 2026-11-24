<?php

declare(strict_types=1);

namespace Intrabase\Security;

use Intrabase\Core\Module;
use Intrabase\Database\Connection;

/**
 * Field-level rules of sys_field_access_rules (ib_sys_field_access_rules),
 * applied to a module for one user. Until 24/11/2026 the rules could be
 * written but nothing read them.
 *
 * For each field, only the rules of the user's groups count (group_name holds a
 * group name, or its id). No rule for any of their groups: no restriction. With
 * rules, a right is granted if ANY of those groups grants it (the union model of
 * ib_permissions):
 *   - can_read = 0 (or mask_value = 1, no masking is drawn yet): the field leaves
 *     the list, the detail, the form, the search, and the API responses; it
 *     cannot be written either.
 *   - can_write = 0: shown read-only and never written from a form or the API
 *     (server-side hooks can still set it).
 *   - hide_in_list = 1: out of the list only.
 */
final class FieldAccess
{
    public static function apply(Module $module, int $userId, string $moduleKey, Connection $db): void
    {
        if ($userId <= 0 || $moduleKey === '') {
            return;
        }
        try {
            $groups = $db->fetchAll(
                'SELECT g.id, g.name FROM ib_user_groups ug JOIN ib_groups g ON g.id = ug.group_id WHERE ug.user_id = :uid',
                ['uid' => $userId]
            );
            $names = [];
            foreach ($groups as $g) {
                $names[] = (string) $g['name'];
                $names[] = (string) $g['id'];
            }
            if ($names === []) {
                return;
            }
            $params = ['mk' => $moduleKey];
            $ph = [];
            foreach (array_values(array_unique($names)) as $i => $n) {
                $params['g' . $i] = $n;
                $ph[] = ':g' . $i;
            }
            $rows = $db->fetchAll(
                'SELECT field_name, can_read, can_write, hide_in_list, mask_value
                   FROM ib_sys_field_access_rules
                  WHERE is_active = 1 AND module_key = :mk AND group_name IN (' . implode(', ', $ph) . ')',
                $params
            );
        } catch (\Throwable) {
            return;   // no rules table on this install: nothing to apply
        }
        if ($rows === []) {
            return;
        }

        $perField = [];
        foreach ($rows as $r) {
            $f = (string) $r['field_name'];
            $read = (int) $r['can_read'] === 1 && (int) $r['mask_value'] !== 1;
            $perField[$f] ??= ['read' => false, 'write' => false, 'list' => false];
            $perField[$f]['read']  = $perField[$f]['read'] || $read;
            $perField[$f]['write'] = $perField[$f]['write'] || ($read && (int) $r['can_write'] === 1);
            $perField[$f]['list']  = $perField[$f]['list'] || ($read && (int) $r['hide_in_list'] !== 1);
        }

        $unreadable = $unwritable = $unlisted = [];
        foreach ($perField as $f => $p) {
            if (!$p['read']) {
                $unreadable[] = $f;
                continue;
            }
            if (!$p['write']) {
                $unwritable[] = $f;
            }
            if (!$p['list']) {
                $unlisted[] = $f;
            }
        }
        $module->restrictFields($unreadable, $unwritable, $unlisted);
    }
}
