<?php

declare(strict_types=1);

namespace Intrabase\Modules\Sys;

use Intrabase\Core\Module;

/**
 * sys_lang — Translation Management Module
 *
 * Allows editing all translation strings stored in ib_lang.
 */
class SysLang extends Module
{
    public string $table = 'ib_lang';
    public string $key   = 'id';
    public string $title = 'Translations';
    public string $icon  = 'fa-solid fa-language';

    public array $fields      = ['id', 'constant', 'en', 'ca', 'es', 'fr', 'de', 'pt'];
    public array $editFields  = ['constant', 'en', 'ca', 'es', 'fr', 'de', 'pt', 'it', 'eu'];
    public array $searchFields = ['constant', 'en', 'es', 'ca'];

    public array $humans = [
        'id'       => 'ID',
        'constant' => 'Constant Key',
        'en'       => 'English',
        'ca'       => 'Català',
        'es'       => 'Español',
        'fr'       => 'Français',
        'de'       => 'Deutsch',
        'pt'       => 'Português',
        'it'       => 'Italiano',
        'eu'       => 'Euskara',
    ];

    public array $components = [
        'en' => 'textarea',
        'ca' => 'textarea',
        'es' => 'textarea',
        'fr' => 'textarea',
        'de' => 'textarea',
        'pt' => 'textarea',
        'it' => 'textarea',
        'eu' => 'textarea',
    ];

    public array $validates = ['constant'];
    public string $orderBy = 'constant';

    public function __construct(...$args)
    {
        parent::__construct(...$args);

        $this->setGroup('key', [
            'label'  => 'Key',
            'icon'   => 'fa-solid fa-key',
            'fields' => ['constant'],
        ]);
        $this->setGroup('translations', [
            'label'  => 'Translations',
            'icon'   => 'fa-solid fa-language',
            'fields' => ['en', 'ca', 'es', 'fr', 'de', 'pt', 'it', 'eu'],
        ]);
    }

    /**
     * Normalize constant key to uppercase with underscores.
     */
    public function onPreSave(array &$data): bool
    {
        if (!empty($data['constant'])) {
            $data['constant'] = strtoupper(preg_replace('/[^A-Z0-9_]/i', '_', $data['constant']));
        }
        return true;
    }
}
