<?php

declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;
use Intrabase\Database\Connection;
use Intrabase\Security\CSRF;
use Intrabase\Core\Container;
use Intrabase\Security\Session;

/**
 * sys_app_settings — Configuració Global de l'Aplicació
 *
 * Key-value settings store with type enforcement, validation and encryption flags.
 * Supports: string, integer, boolean, json, url, email, color types.
 * Read-only settings (is_readonly=1) cannot be modified via UI.
 * Values are validated against validation_regex if provided.
 * Type-specific format validation: url, email, color (#RRGGBB).
 * canAdd=true, canEdit=true, canDelete=false.
 * canExport=false (may contain secrets).
 *
 * Table: ib_sys_app_settings
 */
class SysAppSettings extends Module
{
    public string $table    = 'ib_sys_app_settings';
    public string $key      = 'id';
    public string $title    = 'Configuració de l\'Aplicació';
    public string $icon     = 'fa-solid fa-gear';
    public string $orderBy  = 'category';
    public string $orderDir = 'ASC';

    public bool $canAdd    = true;
    public bool $canEdit   = true;
    public bool $canDelete = false;
    public bool $canExport = false;

    public array $fields = [
        'id',
        'setting_key',
        'label',
        'setting_value',
        'setting_type',
        'category',
        'is_encrypted',
        'is_readonly',
        'is_active',
    ];

    public array $showFields = [
        'setting_key',
        'label',
        'setting_value',
        'setting_type',
        'category',
        'description',
        'default_value',
        'validation_regex',
        'is_encrypted',
        'is_readonly',
        'is_active',
        'created_at',
        'updated_at',
    ];

    public array $editFields = [
        'setting_key',
        'label',
        'setting_value',
        'setting_type',
        'category',
        'description',
        'default_value',
        'validation_regex',
        'is_encrypted',
        'is_readonly',
        'is_active',
    ];

    public array $searchFields = ['setting_key', 'label', 'description', 'category'];

    public array $humans = [
        'id'               => 'ID',
        'setting_key'      => 'Clau',
        'setting_value'    => 'Valor',
        'setting_type'     => 'Tipus',
        'category'         => 'Categoria',
        'label'            => 'Etiqueta',
        'description'      => 'Descripció',
        'is_encrypted'     => 'Xifrat',
        'is_readonly'      => 'Només Lectura',
        'validation_regex' => 'Regex de Validació',
        'default_value'    => 'Valor per Defecte',
        'is_active'        => 'Actiu',
        'created_at'       => 'Creat',
        'updated_at'       => 'Actualitzat',
    ];

    public array $components = [
        'setting_type'     => 'select',
        'category'         => 'select',
        'description'      => 'textarea',
        'is_encrypted'     => 'checkbox',
        'is_readonly'      => 'checkbox',
        'is_active'        => 'checkbox',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];

    public array $selects = [
        'setting_type' => [
            'string'       => 'Text (string)',
            'integer'      => 'Enter (integer)',
            'boolean'      => 'Booleà (boolean)',
            'json'         => 'JSON',
            'url'          => 'URL',
            'email'        => 'Email',
            'color'        => 'Color (#RRGGBB)',
        ],
        'category' => [
            'general'       => 'General',
            'security'      => 'Seguretat',
            'email'         => 'Email',
            'notifications' => 'Notificacions',
            'appearance'    => 'Aparença',
            'features'      => 'Funcionalitats',
            'integrations'  => 'Integracions',
            'other'         => 'Altre',
        ],
    ];

    public array $validates  = ['setting_key', 'setting_type', 'category'];
    public array $readonly   = ['created_at', 'updated_at'];
    public array $hidden     = ['updated_at'];

    public array $filterFields = [
        'category'     => 'select',
        'setting_type' => 'select',
        'is_active'    => 'select',
        'is_readonly'  => 'select',
    ];

    public array $filterOptions = [
        'category' => [
            'general'       => 'General',
            'security'      => 'Seguretat',
            'email'         => 'Email',
            'notifications' => 'Notificacions',
            'appearance'    => 'Aparença',
            'features'      => 'Funcionalitats',
            'integrations'  => 'Integracions',
            'other'         => 'Altre',
        ],
        'setting_type' => [
            'string'  => 'string',
            'integer' => 'integer',
            'boolean' => 'boolean',
            'json'    => 'json',
            'url'     => 'url',
            'email'   => 'email',
            'color'   => 'color',
        ],
        'is_active'   => ['1' => 'Sí', '0' => 'No'],
        'is_readonly' => ['1' => 'Sí', '0' => 'No'],
    ];

    public function __construct(Connection $db, CSRF $csrf, Container $container)
    {
        parent::__construct($db, $csrf, $container);

        $this->defaultValues['setting_type'] = 'string';
        $this->defaultValues['category']     = 'general';
        $this->defaultValues['is_encrypted'] = '0';
        $this->defaultValues['is_readonly']  = '0';
        $this->defaultValues['is_active']    = '1';

        $this->setGroup('setting_info', [
            'label'  => 'Paràmetre',
            'icon'   => 'fa-solid fa-key',
            'fields' => ['setting_key', 'label', 'category', 'setting_type'],
        ]);

        $this->setGroup('value_group', [
            'label'  => 'Valor',
            'icon'   => 'fa-solid fa-pen',
            'fields' => ['setting_value', 'default_value', 'validation_regex'],
        ]);

        $this->setGroup('options_group', [
            'label'  => 'Opcions',
            'icon'   => 'fa-solid fa-sliders',
            'fields' => ['is_encrypted', 'is_readonly', 'is_active'],
        ]);

        $this->setGroup('description_group', [
            'label'     => 'Descripció',
            'icon'      => 'fa-solid fa-circle-info',
            'fields'    => ['description'],
            'collapsed' => true,
        ]);
    }

    public function onPreSave(array &$data): bool
    {
        $isCreate = empty($data[$this->key]) || (int) $data[$this->key] === 0;

        // For existing records: check if_readonly
        if (!$isCreate) {
            $currentId = (int) $data[$this->key];
            try {
                $existing = $this->db->fetchOne(
                    'SELECT is_readonly FROM ib_sys_app_settings WHERE id = :id',
                    [':id' => $currentId]
                );
                if ($existing && (int) $existing['is_readonly'] === 1) {
                    throw new \RuntimeException(
                        'Aquest paràmetre és de només lectura i no es pot modificar mitjançant la interfície d\'usuari.'
                    );
                }
            } catch (\RuntimeException $e) {
                throw $e;
            } catch (\Throwable) {}
        }

        // Validate setting_key format: alphanumeric, dots, underscores
        $settingKey = trim((string) ($data['setting_key'] ?? ''));
        if ($settingKey === '') {
            throw new \RuntimeException('La clau del paràmetre és obligatòria.');
        }
        if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $settingKey)) {
            throw new \RuntimeException(
                'La clau del paràmetre només pot contenir lletres, números, guions, guions baixos i punts.'
            );
        }
        $data['setting_key'] = $settingKey;

        $settingType  = (string) ($data['setting_type'] ?? 'string');
        $settingValue = (string) ($data['setting_value'] ?? '');

        // Validate against custom regex if provided
        $validationRegex = trim((string) ($data['validation_regex'] ?? ''));
        if ($validationRegex !== '' && $settingValue !== '') {
            if (@preg_match($validationRegex, '') === false) {
                throw new \RuntimeException('L\'expressió regular de validació no és vàlida.');
            }
            if (!preg_match($validationRegex, $settingValue)) {
                throw new \RuntimeException(
                    'El valor no compleix el patró de validació: ' . htmlspecialchars($validationRegex, ENT_QUOTES)
                );
            }
        }

        // Type-specific validations
        if ($settingValue !== '') {
            switch ($settingType) {
                case 'integer':
                    if (!preg_match('/^-?\d+$/', $settingValue)) {
                        throw new \RuntimeException('El valor ha de ser un número enter.');
                    }
                    break;

                case 'boolean':
                    if (!in_array(strtolower($settingValue), ['0', '1', 'true', 'false', 'yes', 'no'], true)) {
                        throw new \RuntimeException('El valor booleà ha de ser: 0, 1, true, false, yes o no.');
                    }
                    break;

                case 'url':
                    if (!filter_var($settingValue, FILTER_VALIDATE_URL)) {
                        throw new \RuntimeException('El valor ha de ser una URL vàlida (ex: https://exemple.com).');
                    }
                    break;

                case 'email':
                    if (!filter_var($settingValue, FILTER_VALIDATE_EMAIL)) {
                        throw new \RuntimeException('El valor ha de ser una adreça de correu electrònic vàlida.');
                    }
                    break;

                case 'color':
                    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $settingValue)) {
                        throw new \RuntimeException('El valor de color ha de tenir el format #RRGGBB (ex: #FF5733).');
                    }
                    break;

                case 'json':
                    if (json_decode($settingValue) === null && json_last_error() !== JSON_ERROR_NONE) {
                        throw new \RuntimeException('El valor ha de ser JSON vàlid.');
                    }
                    break;
            }
        }

        return true;
    }
}
