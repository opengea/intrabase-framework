<?php

declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;
use Intrabase\Security\Output;

/**
 * SysHookRules — Visual Hook Rules Editor
 *
 * Allows administrators to define rules that automatically execute on module
 * lifecycle events (onPreSave, onPostSave, onPreDelete, onPostDelete) without
 * writing PHP code.
 *
 * Use cases:
 *  - Auto-set a field value when a record is saved
 *  - Block a save if a condition is met
 *  - Send a notification when a record changes status
 */
class SysHookRules extends Module
{
    public string $table = 'ib_sys_hook_rules';
    public string $key   = 'id';
    public string $title = 'Regles de Ganxos';
    public string $icon  = 'fa-solid fa-bolt';

    public bool $canDuplicate = true;
    public bool $canExport    = true;

    public array $fields = [
        'id', 'rule_name', 'module_key', 'hook_type', 'action_type',
        'condition_field', 'is_active', 'sort_order', 'created_at',
    ];

    public array $editFields = [
        'rule_name', 'module_key', 'hook_type',
        'condition_field', 'condition_operator', 'condition_value',
        'action_type', 'action_field', 'action_value',
        'sort_order', 'is_active', 'description',
    ];

    public array $showFields = [
        'rule_name', 'module_key', 'hook_type',
        'condition_field', 'condition_operator', 'condition_value',
        'action_type', 'action_field', 'action_value',
        'sort_order', 'is_active', 'description', 'created_at',
    ];

    public array $searchFields = ['rule_name', 'module_key', 'action_value'];

    public array $humans = [
        'rule_name'          => 'Nom de la Regla',
        'module_key'         => 'Modul (slug)',
        'hook_type'          => 'Event del Ganxo',
        'condition_field'    => 'Camp de Condicio',
        'condition_operator' => 'Operador',
        'condition_value'    => 'Valor de Condicio',
        'action_type'        => "Tipus d'Accio",
        'action_field'       => 'Camp Desti (per set_value)',
        'action_value'       => 'Valor / Missatge',
        'sort_order'         => 'Ordre',
        'is_active'          => 'Activa',
        'description'        => 'Descripcio',
        'created_at'         => 'Creada',
    ];

    public array $components = [
        'hook_type'          => 'combo',
        'condition_operator' => 'combo',
        'action_type'        => 'combo',
        'is_active'          => 'combo',
        'description'        => 'textarea',
        'created_at'         => 'datetime',
    ];

    public array $validates = ['rule_name', 'module_key', 'hook_type', 'action_type'];
    public string $orderBy  = 'module_key ASC, sort_order ASC';

    public array $filterFields = [
        ['field' => 'hook_type',   'label' => 'Event',  'type' => 'select'],
        ['field' => 'action_type', 'label' => 'Accio',  'type' => 'select'],
        ['field' => 'is_active',   'label' => 'Activa', 'type' => 'select'],
    ];

    public function __construct(mixed ...$args)
    {
        parent::__construct(...$args);

        $this->combos['hook_type'] = ['data' => [
            'onPreSave'    => 'onPreSave — Abans de desar',
            'onPostSave'   => 'onPostSave — Despres de desar',
            'onPreDelete'  => "onPreDelete — Abans d'eliminar",
            'onPostDelete' => "onPostDelete — Despres d'eliminar",
        ]];

        $this->combos['condition_operator'] = ['data' => [
            ''               => '— (sense condicio)',
            'equals'         => 'Igual a (=)',
            'not_equals'     => 'No igual a',
            'contains'       => 'Conte',
            'is_empty'       => 'Esta buit',
            'is_not_empty'   => 'No esta buit',
            'greater_than'   => 'Major que (>)',
            'less_than'      => 'Menor que (<)',
        ]];

        $this->combos['action_type'] = ['data' => [
            'set_value'         => 'Establir valor de camp',
            'block'             => 'Bloquejar operacio',
            'send_notification' => 'Enviar notificacio',
        ]];

        $this->combos['is_active'] = ['data' => ['1' => 'Si', '0' => 'No']];
    }

    /**
     * Auto-set created_by on create.
     */
    public function onPreSave(array &$data): bool
    {
        if (empty($data['id']) || (int)($data['id']) === 0) {
            $data['created_by'] = $this->getUserAccount()['id'] ?? null;
        }

        // Default sort_order
        if (!isset($data['sort_order']) || $data['sort_order'] === '') {
            $data['sort_order'] = 0;
        }

        return true;
    }

    /**
     * Show help text in detail view explaining how rules work.
     */
    public function renderDetailExtra(array $record): string
    {
        $html  = '<div class="ib-alert ib-alert--info" style="margin-top:1rem">';
        $html .= '<div>';
        $html .= '<strong>Com funcionen les regles de ganxos:</strong><br>';
        $html .= '<ul style="margin:.5rem 0 0 1.2rem;line-height:1.8">';
        $html .= '<li><strong>onPreSave</strong>: S\'executa ABANS de desar. L\'accio <code>set_value</code> estableix el camp automaticament. L\'accio <code>block</code> cancel·la el desat mostrant un error.</li>';
        $html .= '<li><strong>onPostSave</strong>: S\'executa DESPRES de desar. Util per enviar notificacions.</li>';
        $html .= '<li><strong>onPreDelete</strong>: S\'executa ABANS d\'eliminar. L\'accio <code>block</code> evita l\'eliminacio.</li>';
        $html .= '<li><strong>onPostDelete</strong>: S\'executa DESPRES d\'eliminar.</li>';
        $html .= '</ul>';
        $html .= '<br><strong>Camp de condicio</strong>: deixa buit per aplicar sempre. Si poses un camp i operador, la regla s\'aplica nomes si la condicio es compleix.<br>';
        $html .= '<strong>Valor especial</strong>: pots usar <code>{{current_user_id}}</code> o <code>{{now}}</code> als valors d\'accio.';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }
}
