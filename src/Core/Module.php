<?php

declare(strict_types=1);

namespace Intrabase\Core;

use Intrabase\Core\Router;
use Intrabase\Database\Connection;
use Intrabase\Database\QueryBuilder;
use Intrabase\Security\CSRF;
use Intrabase\Security\Input;
use Intrabase\Security\Output;
use Intrabase\Security\Session;
use Intrabase\Security\Permission;
use Intrabase\Support\Auditor;
use Intrabase\Support\NotificationEngine;

/**
 * Intrabase Base Module Class
 *
 * All modules extend this class. It provides:
 * - Field configuration (table, columns, types, labels)
 * - CRUD operations with prepared statements
 * - Secure HTML rendering for browser/editor/detailer
 * - Permission control
 * - Hook/event system (onBrowse, onEdit, onSave, onDelete, onDuplicate)
 * - Component system (text, combo, date, picture, checklist, etc.)
 * - Multi-language support
 * - Tab/group management
 *
 * @package Intrabase\Core
 */
abstract class Module
{
    // =========================================================================
    // Database & connection
    // =========================================================================

    protected Connection $db;

    /** Database table name */
    public string $table = '';

    /** Primary key column name */
    public string $key = 'id';

    // =========================================================================
    // Field definitions
    // =========================================================================

    /** Columns to display in browser list */
    public array $fields = [];

    /** Columns available in the edit form (defaults to $fields) */
    public array $editFields = [];

    /** Subset of $fields shown in the browser (all if empty) */
    public array $showFields = [];

    /** Extra fields shown only in the detail view (appended after the base list). */
    public array $detailFields = [];

    /** Fields that can be used in the search form */
    public array $searchFields = [];

    /** Numeric fields to SUM and display in the browser list footer totals row */
    public array $totalsFields = [];

    /**
     * Expressió SQL amb què sumar un camp de $totalsFields, quan `SUM(camp)` no
     * serveix. Passa amb les columnes del KMS llegat que guarden números com a
     * text: a legacy `import` column és varchar i hi conviuen
     * «18.15» i «-1.234,56», que MySQL sumaria com a 18 i -1.
     *
     * L'expressió l'escriu el mòdul (mai ve de l'usuari) i s'insereix dins d'un
     * SUM(); DataBrowser només accepta caràcters de funció SQL i rebutja la
     * resta.
     *
     * @var array<string, string> camp => expressió
     */
    public array $totalsExpr = [];

    /**
     * Columns that stay visible when the list is wider than its container
     * (the KMS `fields_desktop` + class `desk` rule): DataBrowser marks these
     * <th>/<td> with class "desk" and, when the table overflows, hides every
     * other column (intrabase.js/ibInitTableOverflow + intrabase.css). Empty =
     * no column hiding at all. The selection and actions columns always show.
     */
    public array $desktopFields = [];

    /** Fields to display on mobile view (subset of $fields) */
    public array $mobileFields = [];

    /**
     * Manté les columnes de sobretaula encara que no hi càpiguen, amb scroll
     * horitzontal, en lloc de plegar-se a les de mòbil.
     *
     * Per defecte, quan les columnes desborden l'amplada, el llistat es queda
     * amb les essencials ($mobileFields). Hi ha llistats que a la intranet del
     * KMS s'ensenyen sencers i amb barra de desplaçament —erp_contracts és
     * l'exemple— i plegar-los amaga informació que l'usuari espera veure.
     */
    public bool $keepDesktopColumns = false;

    /** Field column types from the RDBMS */
    public array $fieldTypes = [];

    /** Virtual (computed) fields: alias => SQL expression. Not stored in DB. */
    public array $virtualFields = [];

    // =========================================================================
    // UI labeling
    // =========================================================================

    /** Human-readable labels keyed by field name */
    public array $humans = [];

    /** Short abbreviations for narrow-column displays */
    public array $abbrev = [];

    /** Field-level help text / tooltip content */
    public array $comments = [];

    // =========================================================================
    // Component types
    // =========================================================================

    /**
     * Component type per field.
     *
     * Supported types:
     *   text, textarea, html, date, datetime, time,
     *   money, percentage, integer, combo, multicombo,
     *   picture, file, checklist, radiolist, status_icon,
     *   xref, email, url, phone, color, password
     *
     * @var array<string, string>
     */
    public array $components = [];

    /**
     * Component-specific options per field, passed to ComponentInterface methods.
     *
     * Structure:
     * [
     *   'field' => ['option_key' => 'value', ...]
     * ]
     *
     * @var array<string, array<string, mixed>>
     */
    public array $componentOptions = [];

    // =========================================================================
    // Relations & combos
    // =========================================================================

    /**
     * Combo-box data sources, keyed by field name.
     *
     * Structure:
     * [
     *   'field' => ['table' => '...', 'key' => '...', 'value' => '...', 'where' => '...']
     * ]
     *
     * @var array<string, array<string, string>>
     */
    public array $combos = [];

    /**
     * Cross-reference (many-to-many) link tables.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $xlists = [];

    /**
     * Multi cross-references.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $multiXrefs = [];

    // =========================================================================
    // Field modifiers
    // =========================================================================

    /** Fields hidden from browser and editor */
    public array $hidden = [];

    /** Read-only fields (shown but not editable) */
    public array $readonly = [];

    /** Required/validated fields */
    public array $validates = [];

    /** Custom validation rules per field */
    public array $validators = [];

    /** Default values per field */
    public array $defaultValues = [];

    /** Max character lengths per field */
    public array $maxLengths = [];

    /** Input size overrides per field */
    public array $inputSizes = [];

    // =========================================================================
    // Display styling
    // =========================================================================

    /** CSS classes per field */
    public array $classes = [];

    /** Inline CSS styles per field */
    public array $styles = [];

    /** Fields that should not wrap (nowrap) */
    public array $noWrap = [];

    /**
     * Camps booleans que es poden canviar DES DEL LLISTAT, sense obrir la
     * fitxa. La llista la declara el modul: mai s'accepta el camp que arribi
     * amb la peticio. Buida = el modul no ho permet.
     *
     * @var list<string>
     */
    public array $inlineToggle = [];

    // =========================================================================
    // Field groups (tabs in the editor)
    // =========================================================================

    /** @var array<string, array<string, mixed>> */
    protected array $groups = [];

    // =========================================================================
    // Audit
    // =========================================================================

    /**
     * Fields excluded from audit snapshots (passwords, tokens, secrets).
     * Subclasses may extend this list.
     *
     * @var string[]
     */
    protected array $sensitiveFields = ['password_hash', 'password', 'token', 'secret'];

    // =========================================================================
    // Permissions
    // =========================================================================

    public bool $canBrowse = true;
    public bool $canAdd    = true;
    public bool $canEdit   = true;
    public bool $canDelete = true;
    public bool $canExport = true;
    public bool $canImport = false;
    public bool $canSearch = true;
    public bool $canMultiselect = true;
    public bool $canDuplicate = false;

    /** After saving, stay on the edit form instead of redirecting to the browser */
    public bool $stayOnEdit = false;

    /** Enable drag-and-drop row reordering (requires a sort_order column) */
    public bool $canReorder = false;

    /** Column name used for row ordering when canReorder is true */
    public string $reorderField = 'sort_order';

    // =========================================================================
    // Advanced filters
    // =========================================================================

    /**
     * Fields available for advanced filtering in DataBrowser.
     *
     * Format: ['field_name' => 'date_range'|'datetime_range'|'text'|'select'|'year']
     * 'year': desplegable d'anys sobre un camp de data (opcions a filterOptions[clau]; si no, els últims 10).
     * Si el camp ja té un altre filtre, la clau porta el sufix «__year» (p. ex. 'donated_on__year').
     *
     * @var array<string, string>
     */
    public array $filterFields = [];

    /**
     * Options for 'select' type filter fields.
     *
     * Format: ['field_name' => ['value' => 'Label', ...]]
     *
     * @var array<string, array<string, string>>
     */
    public array $filterOptions = [];

    /**
     * Filtres a la barra del llistat, al costat de les vistes (16/11/2026): un desplegable per camp que
     * aplica el mateix filtre del panell (f[camp][v]) en triar-lo. El camp ha de ser a $filterFields
     * ('select' o 'combo', p. ex. via $advancedFilters).
     *
     * Format: ['field_name' => 'fa-solid fa-icona', ...]
     *
     * @var array<string, string>
     */
    public array $toolbarFilters = [];

    /**
     * Condició SQL pròpia d'un filtre 'select'/'combo' (19/11/2026), quan «camp = valor» no n'hi ha prou
     * (p. ex. un lead que té el segment com a principal O entre els altres). {v} és el lloc del valor
     * (pot sortir-hi més d'un cop). És SQL del codi, mai de la petició.
     *
     * Format: ['segment_id' => '(`segment_id` = {v} OR id IN (SELECT lead_id FROM … WHERE segment_id = {v}))']
     *
     * @var array<string, string>
     */
    public array $filterWhere = [];

    // =========================================================================
    // Browser configuration
    // =========================================================================

    /** Default field to order by */
    public string $orderBy = '';

    /** Default sort direction: 'ASC' or 'DESC' */
    public string $orderDir = 'ASC';

    /** Default records per page */
    public int $perPage = 100;

    /** Show primary key column in browser */
    public bool $showKey = false;

    /**
     * Extra HTML rendered in the browser toolbar after standard buttons.
     * Set this in onBrowse() or the constructor for custom action buttons.
     * The module is responsible for escaping any dynamic content.
     */
    public string $toolbarExtra = '';

    /**
     * Custom per-row action buttons rendered in the browser Actions column.
     *
     * Each entry:
     *   ['label' => string, 'icon' => string, 'class' => string,
     *    'action' => string, 'method' => 'get'|'post', 'confirm' => string|null]
     *
     * @var array<int, array<string, mixed>>
     */
    public array $customRowActions = [];

    /**
     * Custom action buttons rendered in the detail view header (next to Edit/Back).
     *
     * Each entry:
     *   [label => string, icon => string, class => string, action => string]
     *
     * @var array<int, array<string, string>>
     */
    public array $customDetailActions = [];

    /**
     * Declarative BULK actions on the list (25/09/2026).
     *
     * The DataBrowser shows one button per action while rows are selected; an
     * action with 'scope' => 'filter' also gets a button that applies it to
     * EVERY row of the current list request (search, advanced filters, saved
     * views, base WHERE and owner scope — exactly what is listed), which is what
     * reviewing a 170.000-row census needs. Keyed by action name:
     *
     *   'approve' => [
     *       'label'   => 'Aprovar',               // button text (already translated)
     *       'icon'    => 'fa-check',              // optional Font Awesome class
     *       'class'   => 'ib-btn--primary',       // optional button class
     *       'confirm' => 'Aprovar %d registres?', // optional; %d = number of rows
     *       'set'     => ['status' => 'actiu'],   // column => literal value applied
     *       'touch'   => 'updated_at',            // optional column set to NOW()
     *       'handler' => 'bulkApprove',           // instead of 'set': method(int[] $ids): int
     *       'scope'   => 'filter',                // 'selection' (default) | 'filter'
     *   ]
     *
     * Requires $canMultiselect and $canEdit. 'set' is a direct UPDATE in chunks
     * (no per-row hooks): declare a 'handler' when hooks or side effects matter.
     * Handled by handleBulkAction() (POST _=bulk).
     *
     * @var array<string, array<string, mixed>>
     */
    public array $bulkActions = [];

    // =========================================================================
    // Module metadata
    // =========================================================================

    /** Module display name */
    public string $title = '';

    /** Font Awesome icon class (e.g. 'fa-users') */
    public string $icon = 'fa-table';

    /** Optional WHERE clause appended to all queries */
    public string $baseWhere = '';

    /**
     * Ordre per defecte del llistat en SQL (p. ex. "FIELD(type, 'a', 'b'), number"),
     * per quan l'ordre no és una sola columna. S'aplica mentre l'usuari no tria cap
     * columna; és SQL del codi, mai de la petició.
     */
    public string $orderBySql = '';

    // -------------------------------------------------------------------------
    // API scope (opt-in tenant/user isolation for /api/v1/* listing)
    // -------------------------------------------------------------------------

    /**
     * Column name to scope `apiList()` by `$userAccount['entity_id']`.
     *
     * Set this in a subclass when the module's table has a per-entity
     * column (typically `entity_id`). When populated, `apiList()` adds
     * a null-safe equality predicate against the caller's entity.
     *
     * Must match /^[a-z_][a-z0-9_]*$/ (SQL-identifier safe).
     */
    public ?string $apiScopeByEntity = null;

    /**
     * Column name to scope `apiList()` by `$userAccount['id']`.
     *
     * Typical values: 'created_by', 'user_id'. See $apiScopeByEntity.
     */
    public ?string $apiScopeByUser = null;

    /**
     * Files compartides dins l'organització: amb una entitat activa, la frontera
     * és només l'entitat ($apiScopeByEntity) i qualsevol usuari d'aquella entitat
     * veu les files de tots. Sense entitat (abast personal, entity NULL) la
     * frontera d'usuari ($apiScopeByUser) continua sent estricta.
     *
     * Requereix tots dos $apiScopeBy*. Primer ús: campanyes i plantilles de
     * Mailing (30/10/2026).
     */
    public bool $shareWithinEntity = false;

    /** La frontera d'usuari s'aplica per a aquesta entitat activa? */
    private function userScopeApplies(?int $entityId): bool
    {
        if ($this->apiScopeByUser === null) {
            return false;
        }
        return !($this->shareWithinEntity && $this->apiScopeByEntity !== null && $entityId !== null);
    }

    // =========================================================================
    // JavaScript events (inline JS injected into the page)
    // =========================================================================

    /** JS code executed on $(document).ready() */
    public string $onDocumentReady = '';

    /**
     * Field-level change handlers.
     * Key = field name, Value = JS expression evaluated on change.
     *
     * @var array<string, string>
     */
    public array $onFieldChange = [];

    /** Custom CSS injected as <style> in the module page */
    public string $customCss = '';

    // =========================================================================
    // Context
    // =========================================================================

    /** @var array<string, mixed> */
    protected array $clientAccount = [];

    /** @var array<string, mixed> */
    protected array $userAccount = [];

    protected CSRF $csrf;
    protected Container $container;

    // =========================================================================
    // Constructor
    // =========================================================================

    /**
     * Sessió activa. Molts mòduls hi accedeixen com `$this->session->userId()`
     * dins dels hooks; sense declarar-la era una propietat dinàmica a null i
     * el desat petava amb "call to a member function on null".
     */
    protected ?Session $session = null;

    public function __construct(Connection $db, CSRF $csrf, Container $container)
    {
        $this->db        = $db;
        $this->csrf      = $csrf;
        $this->container = $container;

        try {
            $this->session = $container->get(Session::class);
        } catch (\Throwable) {
            $this->session = null; // CLI / tests sense sessió registrada
        }
    }

    /** @var array<string, string> Errors reportats pels hooks via addError() */
    protected array $hookErrors = [];

    /**
     * Compat: mòduls generats registren errors de validació als hooks amb
     * addError() i retornen false d'onPreSave. Els missatges s'incorporen a
     * l'excepció de create()/update().
     */
    protected function addError(string $field, string $message = ''): void
    {
        // Alguns mòduls criden addError('missatge') amb un sol argument.
        if ($message === '') {
            $this->hookErrors[] = $field;
            return;
        }
        $this->hookErrors[$field] = $message;
    }

    /** Missatge per a un onPreSave rebutjat, amb els errors dels hooks si n'hi ha. */
    private function preSaveRejectionMessage(): string
    {
        return $this->hookErrors
            ? implode('; ', $this->hookErrors)
            : 'Save cancelled by onPreSave hook';
    }

    /**
     * Called after the subclass constructor completes.
     * Auto-registers $this->selects entries into $this->combos so that
     * modules using the 'select' component type get proper dropdown rendering.
     *
     * Subclasses must call parent::__construct() first, then call this
     * after their own setup (or override with `afterSetup()`).
     */
    final public function registerSelects(): void
    {
        // If the subclass defines a public $selects property, register each
        // entry as a static data combo (skipping empty-string placeholder keys).
        if (!property_exists($this, 'selects')) {
            return;
        }
        foreach ($this->selects as $field => $data) {
            if (!isset($this->combos[$field]) && is_array($data)) {
                // Remove empty-string placeholder key ('— Select —') since
                // DataEditor always adds a blank option via __('select_default')
                $filtered = array_filter($data, fn($k) => $k !== '', ARRAY_FILTER_USE_KEY);
                $this->combos[$field] = ['data' => array_map('strval', $filtered)];
            }
            // Populate componentOptions so SelectComponent receives 'values'
            if (is_array($data) && !isset($this->componentOptions[$field]['values'])) {
                $this->componentOptions[$field]['values'] = $data;
            }
        }
    }



    /**
     * Converts $this->advancedFilters (new format used by all modules) into
     * the $filterFields and $filterOptions format expected by DataBrowser.
     *
     * Supports two formats for $advancedFilters:
     *   1. Associative: ['field' => ['label' => '...', 'type' => '...', 'options' => [...]]]
     *   2. Indexed:     [['name' => 'field', 'label' => '...', 'type' => '...', 'options' => [...]]]
     *                   ('field' => 'x' is accepted as an alias of 'name' => 'x')
     */
    final public function registerAdvancedFilters(): void
    {
        // Support both property and method forms of advancedFilters
        $filters = [];
        if (property_exists($this, 'advancedFilters') && !empty($this->advancedFilters)) {
            $filters = $this->advancedFilters;
        } elseif (method_exists($this, 'advancedFilters')) {
            $filters = $this->advancedFilters();
        }
        if (empty($filters)) {
            return;
        }

        foreach ($filters as $key => $config) {
            if (!is_array($config)) {
                continue;
            }

            // Detect format: indexed (numeric key with 'name' — or 'field', the
            // key several modules actually use, e.g. kos_actors; without this
            // alias their filters were silently never registered) vs associative
            if (is_int($key) && (isset($config['name']) || isset($config['field']))) {
                $fieldName = (string) ($config['name'] ?? $config['field']);
            } else {
                $fieldName = (string) $key;
            }

            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $fieldName)) {
                continue;
            }

            $type = $config['type'] ?? 'text';

            // Only register if not already set (don't override manual filterFields)
            if (!isset($this->filterFields[$fieldName])) {
                $this->filterFields[$fieldName] = $type;
            }

            // Register options for select-type filters
            if ($type === 'select' && !empty($config['options'])) {
                if (!isset($this->filterOptions[$fieldName])) {
                    $this->filterOptions[$fieldName] = $config['options'];
                }
            }

            // Register combo config for combo-type filters.
            // Supports two patterns:
            //   1. Top-level: ['type'=>'combo', 'table'=>'...', 'key'=>'...', 'label'=>'...']
            //   2. Nested:    ['type'=>'combo', 'combo'=>['table'=>'...', 'key'=>'...', 'label'=>'...']]
            if ($type === 'combo' && !isset($this->combos[$fieldName])) {
                // Check for nested 'combo' sub-key first (pattern 2)
                $comboConfig = $config['combo'] ?? null;
                if ($comboConfig === null && isset($config['table'], $config['key'])) {
                    // Top-level pattern (pattern 1)
                    $comboConfig = $config;
                }
                if ($comboConfig !== null && isset($comboConfig['table'])) {
                    // Support both formats:
                    // Format A: key='id', label='name'  (standard)
                    // Format B: value='id', display='name'  (some modules use this)
                    $keyCol   = $comboConfig['key'] ?? $comboConfig['value'] ?? 'id';
                    $labelCol = $comboConfig['label']
                        ?? $comboConfig['labelField']
                        ?? $comboConfig['label_col']
                        ?? $comboConfig['label_field']
                        ?? $comboConfig['display']
                        ?? (isset($comboConfig['value']) && !isset($comboConfig['key']) ? 'name' : ($comboConfig['value'] ?? 'name'));
                    $this->combos[$fieldName] = [
                        'table' => $comboConfig['table'],
                        'key'   => $keyCol,
                        'label' => $labelCol,
                        'where' => $comboConfig['where'] ?? '',
                    ];
                }
            }
        }
    }

    // =========================================================================
    // DB overrides for physical modules
    // =========================================================================

    /**
     * Apply field-level overrides from ib_sys_mod_attributes.
     *
     * When a physical PHP module also has a configuration entry in ib_sys_mods,
     * this method reads the DB attributes and overrides visibility, labels,
     * components, readonly, etc. — allowing the admin to customize a physical
     * module without editing the PHP file.
     *
     * Called by ModuleLoader after instantiating a physical module.
     */
    final public function applyDbOverrides(): void
    {
        try {
            $pdo = $this->db->pdo();

            // Find module name from class: Intrabase\Modules\Projects\Projects → projects
            $class = static::class;
            $short = substr($class, strrpos($class, '\\') + 1);
            // PascalCase → snake_case
            $moduleKey = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $short));

            $stmt = $pdo->prepare(
                "SELECT id FROM ib_sys_mods WHERE name = :name AND status = 'active' LIMIT 1"
            );
            $stmt->execute([':name' => $moduleKey]);
            $modRow = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$modRow) {
                return; // No DB config for this module
            }

            $modId = (int) $modRow['id'];

            $attrStmt = $pdo->prepare(
                'SELECT field_name, field_label, abbrev, field_type, field_options,
                        default_value, is_required, is_searchable, is_readonly,
                        show_in_list, show_in_edit, show_in_detail,
                        sort_order, field_group, help_text, max_length,
                        no_wrap, css_class, on_change_js, is_totals,
                        component_type, component_options
                   FROM ib_sys_mod_attributes
                  WHERE mod_id = :mod_id
                  ORDER BY sort_order ASC, id ASC'
            );
            $attrStmt->execute([':mod_id' => $modId]);
            $attributes = $attrStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            if (empty($attributes)) {
                return;
            }

            // Rebuild field lists from DB attributes
            $newFields      = [];
            $newShowFields  = [];
            $newEditFields  = [];
            $newDetailFields = [];
            $newSearchFields = [];

            foreach ($attributes as $attr) {
                $fn = $attr['field_name'];
                $newFields[] = $fn;

                // Labels
                if ($attr['field_label'] !== null && $attr['field_label'] !== '') {
                    $this->humans[$fn] = $attr['field_label'];
                }
                if ($attr['abbrev'] !== null && $attr['abbrev'] !== '') {
                    $this->abbrev[$fn] = $attr['abbrev'];
                }

                // Visibility
                if ((int)$attr['show_in_list']) {
                    $newShowFields[] = $fn;
                }
                if ((int)$attr['show_in_edit']) {
                    $newEditFields[] = $fn;
                }
                if ((int)$attr['show_in_detail']) {
                    $newDetailFields[] = $fn;
                }
                if ((int)$attr['is_searchable']) {
                    $newSearchFields[] = $fn;
                }

                // Readonly
                if ((int)$attr['is_readonly'] && !in_array($fn, $this->readonly, true)) {
                    $this->readonly[] = $fn;
                } elseif (!(int)$attr['is_readonly']) {
                    $this->readonly = array_values(array_diff($this->readonly, [$fn]));
                }

                // Required
                if ((int)$attr['is_required'] && !in_array($fn, $this->validates, true)) {
                    $this->validates[] = $fn;
                }

                // Components — component_type from DB takes priority
                $compType = $attr['component_type'] ?? '';
                if ($compType !== '') {
                    $this->components[$fn] = $compType;
                    // Apply component options if present
                    $compOpts = $attr['component_options'] ?? '';
                    if ($compOpts !== '') {
                        $decoded = json_decode($compOpts, true);
                        if (is_array($decoded)) {
                            $this->componentOptions[$fn] = $decoded;
                        }
                    }
                } else {
                    // Fallback: map field_type to component
                    $dbType = $attr['field_type'] ?? '';
                    if ($dbType !== '' && $dbType !== 'text') {
                        $component = match ($dbType) {
                            'textarea'   => 'textarea',
                            'html'       => 'html',
                            'integer'    => 'integer',
                            'decimal'    => 'decimal',
                            'money'      => 'money',
                            'percentage' => 'percentage',
                            'date'       => 'date',
                            'datetime'   => 'datetime',
                            'time'       => 'time',
                            'email'      => 'email',
                            'url'        => 'url',
                            'phone'      => 'phone',
                            'color'      => 'color',
                            'combo'      => 'combo',
                            'select'     => 'combo',
                            'checkbox'   => 'checklist',
                            'picture'    => 'picture',
                            'file'       => 'file',
                            'rating'     => 'rating',
                            'slug'       => 'slug',
                            'json'       => 'json',
                            'password'   => 'password',
                            'hidden'     => 'hidden',
                            default      => null,
                        };
                        if ($component !== null) {
                            $this->components[$fn] = $component;
                        }
                    }
                }

                // Help text / comments
                if ($attr['help_text'] !== null && $attr['help_text'] !== '') {
                    $this->comments[$fn] = $attr['help_text'];
                }

                // Max length
                if ((int)($attr['max_length'] ?? 0) > 0) {
                    $this->maxLengths[$fn] = (int)$attr['max_length'];
                }

                // No wrap
                if ((int)$attr['no_wrap'] && !in_array($fn, $this->noWrap, true)) {
                    $this->noWrap[] = $fn;
                }

                // CSS class
                if ($attr['css_class'] !== null && $attr['css_class'] !== '') {
                    $this->classes[$fn] = $attr['css_class'];
                }

                // onFieldChange JS
                if ($attr['on_change_js'] !== null && $attr['on_change_js'] !== '') {
                    $this->onFieldChange[$fn] = $attr['on_change_js'];
                }

                // Totals
                if ((int)$attr['is_totals'] && !in_array($fn, $this->totalsFields, true)) {
                    $this->totalsFields[] = $fn;
                }

                // Default value
                if ($attr['default_value'] !== null && $attr['default_value'] !== '') {
                    $this->defaultValues[$fn] = $attr['default_value'];
                }
            }

            // Override field lists
            // DataBrowser uses $fields as the display list (showFields is fallback only),
            // so set $fields to the visible-in-list subset.
            $this->showFields   = $newShowFields;
            $this->fields       = !empty($newShowFields) ? $newShowFields : $newFields;
            $this->editFields   = $newEditFields;
            $this->detailFields = $newDetailFields;
            if (!empty($newSearchFields)) {
                $this->searchFields = $newSearchFields;
            }

        } catch (\Throwable $e) {
            // Don't break the module if DB override fails
            error_log('[Intrabase] applyDbOverrides error: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // Setup helpers
    // =========================================================================

    /**
     * Define a tab/group of fields for the editor.
     *
     * @param string               $name   Group identifier
     * @param array<string, mixed> $config Group configuration:
     *   - 'label'  => string   Display label
     *   - 'icon'   => string   FA icon class
     *   - 'fields' => string[] List of field names
     */
    public function setGroup(string $name, array $config): void
    {
        $this->groups[$name] = $config;
    }

    /**
     * Add a combo-box data source for a field.
     *
     * @param string $field Target field name
     * @param string $table Source table
     * @param string $key   Key column in source table
     * @param string $value Display column in source table
     * @param string $where Optional WHERE clause (parameterized with :param notation)
     */
    public function addCombo(
        string $field,
        string $table,
        string $key,
        string $value,
        string $where = ''
    ): void {
        $this->combos[$field] = compact('table', 'key', 'value', 'where');
    }

    /**
     * Set the UI component type for a field, optionally with configuration.
     *
     * This is a convenience method used by modules in their setup() method.
     *
     * For combo type, config keys: table, key, display (label column), orderBy,
     *   where, placeholder, required.
     * For select type, config is an associative array of value => label options.
     * For other types (wysiwyg, date, email, url, phone, checkbox, textarea, etc.),
     *   config is optional and ignored (only the type is registered).
     *
     * @param string               $type   Component type
     * @param string               $field  Field name
     * @param array<string, mixed> $config Optional configuration
     */
    public function setComponent(string $type, string $field, array $config = []): void
    {
        $this->components[$field] = $type;

        // Store component-specific options for ComponentRegistry components
        if (!empty($config)) {
            $this->componentOptions[$field] = $config;
        }

        if ($type === 'combo' && !empty($config)) {
            // Register into $this->combos for DataEditor/DataBrowser to use.
            // Support both 'display' and 'label' as the label column key.
            $labelCol = $config['label']
                ?? $config['display']
                ?? $config['labelField']
                ?? $config['label_col']
                ?? $config['label_field']
                ?? 'name';
            $this->combos[$field] = [
                'table'       => $config['table']       ?? '',
                'key'         => $config['key']         ?? 'id',
                'label'       => $labelCol,
                'where'       => $config['where']       ?? '',
                'orderBy'     => $config['orderBy']     ?? $labelCol,
                'placeholder' => $config['placeholder'] ?? '',
                'required'    => $config['required']    ?? false,
            ];
        } elseif ($type === 'select' && !empty($config)) {
            // Register static options for DataBrowser combo filter.
            if (!isset($this->filterOptions[$field])) {
                $this->filterOptions[$field] = $config;
            }
        }
    }

    /**
     * Set a default value for a field in the edit form (used when creating new records).
     *
     * @param string $field Field name
     * @param mixed  $value Default value
     */
    public function defaultValue(string $field, mixed $value): void
    {
        $this->defaultValues[$field] = $value;
    }

    // =========================================================================
    // Hook methods (override in subclass)
    // =========================================================================

    /** Called before rendering the browse list */
    public function onBrowse(): void {}

    /** Called before rendering the edit form */
    public function onEdit(int $id): void {}

    /** Called before rendering the add form */
    public function onAdd(): void {}

    /**
     * Called before saving a record.
     * Return false to cancel the save operation, or throw a RuntimeException
     * with an error message.
     *
     * @param array<string, mixed> $data Form data (already validated and sanitized)
     */
    public function onPreSave(array &$data): bool
    {
        return true;
    }

    /**
     * Called after a record has been saved.
     *
     * @param int                  $id   Saved record primary key
     * @param array<string, mixed> $data Saved data
     */
    public function onPostSave(int $id, array $data): void {}

    /**
     * Called before deleting a record.
     * Return false to cancel deletion.
     *
     * @param int $id Record primary key
     */
    public function onPreDelete(int $id): bool
    {
        return true;
    }

    /** Called after a record has been deleted */
    public function onPostDelete(int $id): void {}

    /**
     * Called before duplicating a record.
     *
     * @param array<string, mixed> $data Original record data (modify in place to change defaults)
     */
    public function onDuplicate(array &$data): void {}

    // -------------------------------------------------------------------------
    // Browser (list) hooks — afegits el 03/09/2026 per al port del llistat de
    // factures del KMS (icones d'estat, accions per fila, estil de fila).
    // -------------------------------------------------------------------------

    /**
     * Called by DataBrowser with the rows of the current page, before any cell
     * is rendered. Lets a module resolve per-page data (status icons, flags)
     * with ONE query per page instead of one per row.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public function onRowsLoaded(array $rows): void {}

    /**
     * Extra CSS class(es) for a browser row, e.g. to highlight inconsistent
     * records. Return '' for none. Pair it with $customCss.
     *
     * @param array<string, mixed> $row
     */
    public function rowClass(array $row): string
    {
        return '';
    }

    /**
     * Custom HTML for a browser cell. Return null to keep the default
     * rendering for the component type. The module is responsible for
     * escaping whatever it returns.
     *
     * @param array<string, mixed> $row
     */
    public function renderListCell(string $field, mixed $value, array $row): ?string
    {
        return null;
    }

    /**
     * Whether a $customRowActions entry applies to a given row (per-row
     * visibility, like checkFunction in the KMS customOptions).
     *
     * @param array<string, mixed> $row
     */
    public function rowActionVisible(string $action, array $row): bool
    {
        return true;
    }

    // =========================================================================
    // CRUD operations (all using prepared statements)
    // =========================================================================

    /**
     * Fetch a single record by primary key.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $qb = $this->db->query()
            ->from($this->table)
            ->where("{$this->key} = :id", ['id' => $id])
            ->limit(1);

        // Amb $scopeUiByOwner, el detall (i per tant l'edició i l'esborrat, que
        // passen per aquí) queda restringit al propietari — abans qualsevol id
        // d'altri era accessible escrivint-lo a la URL.
        if ($this->scopeUiByOwner) {
            $qb = $this->applyScope($qb);
        }

        return $qb->first() ?: null;
    }

    /**
     * Cerca sense cap restricció de propietat. Reservat per a camins interns
     * que legítimament han de veure files d'altres usuaris (motors de
     * notificacions, tasques de cron, migracions). No usar-la en camins que
     * responguin a una petició de l'usuari.
     */
    public function findUnscoped(int $id): ?array
    {
        return $this->db->query()
            ->from($this->table)
            ->where("{$this->key} = :id", ['id' => $id])
            ->limit(1)
            ->first() ?: null;
    }

    /**
     * Fetch a single record by primary key applying the same opt-in
     * tenant/user scope as apiList() ($apiScopeByEntity / $apiScopeByUser).
     *
     * Used by the /api/v1 single-record routes (show/update/destroy) so a
     * caller cannot reach another tenant's record by guessing its id. A
     * record outside the caller's scope returns null — indistinguishable
     * from a missing id (404, never 403).
     *
     * Modules with neither property set behave exactly like find().
     *
     * @return array<string, mixed>|null
     */
    /**
     * Restringeix també la interfície web (llistat, detall, edició, esborrat i
     * exportació) al propietari de la fila, fent servir les mateixes columnes
     * que $apiScopeByUser / $apiScopeByEntity.
     *
     * És OPT-IN deliberat: moltes taules tenen `user_id` com a simple
     * referència (l'empleat vinculat, qui va crear el registre) i no com a
     * frontera de propietat — activar-ho a cegues buidaria aquests mòduls.
     * Activa'l només quan les files siguin dades personals de cada usuari
     * (notificacions, comptes de correu, dispositius, sessions, preferències).
     */
    public bool $scopeUiByOwner = false;

    /** Només lectura per a la persona actual en aquesta app (UserAppAccess); el posa applyPermissions(). */
    protected bool $userReadOnly = false;

    /**
     * Predicat de propietat del mòdul ($apiScopeByEntity / $apiScopeByUser).
     *
     * És la mateixa restricció que apliquen apiList()/findScoped() a l'API.
     * Declarar aquestes propietats vol dir "cada fila pertany a un usuari o
     * entitat", i per tant també ha de valer a la interfície web: sense això
     * el llistat i el detall mostraven files d'altres usuaris.
     *
     * @return array{sql: string[], params: array<string, mixed>}
     */
    public function scopePredicate(string $prefix = '_scope'): array
    {
        $sql    = [];
        $params = [];
        $entityId = $this->activeEntityId();

        // Compartit dins l'entitat: les files de l'entitat activa són de tots;
        // les personals (entitat NULL), només del seu usuari.
        if ($this->apiScopeByUser !== null && !$this->userScopeApplies($entityId)) {
            self::assertIdentifier($this->apiScopeByUser, '$apiScopeByUser');
            self::assertIdentifier($this->apiScopeByEntity, '$apiScopeByEntity');
            $sql[] = "({$this->apiScopeByEntity} = :{$prefix}_eid"
                   . " OR ({$this->apiScopeByEntity} IS NULL AND {$this->apiScopeByUser} = :{$prefix}_uid))";
            $params["{$prefix}_eid"] = $entityId;
            $params["{$prefix}_uid"] = $this->scopeUserId();
            return ['sql' => [implode(' AND ', $sql)], 'params' => $params];
        }

        // Usuari: frontera de seguretat, sempre estricta.
        if ($this->apiScopeByUser !== null) {
            self::assertIdentifier($this->apiScopeByUser, '$apiScopeByUser');
            $sql[] = "{$this->apiScopeByUser} = :{$prefix}_uid";
            $params["{$prefix}_uid"] = $this->scopeUserId();
        }

        // Entitat: només si se'n pot resoldre una d'activa. Les files sense
        // entitat (NULL) són personals/globals i es veuen sempre — filtrar-les
        // amagaria dades pròpies legítimes.
        if ($this->apiScopeByEntity !== null && $entityId !== null) {
            self::assertIdentifier($this->apiScopeByEntity, '$apiScopeByEntity');
            $sql[] = "({$this->apiScopeByEntity} = :{$prefix}_eid"
                   . " OR {$this->apiScopeByEntity} IS NULL)";
            $params["{$prefix}_eid"] = $entityId;
        }

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * Usuari per al qual s'avalua la propietat.
     *
     * Prioritat: el compte injectat amb setUserAccount() (context API i camí
     * normal de mòdul) i, si no n'hi ha, la sessió activa. El fallback importa:
     * hi ha camins que carreguen un mòdul sense injectar-li el compte (p. ex.
     * PreferencesController amb sys_mail_accounts) i sense això el predicat
     * quedava `user_id = 0` i no trobava mai el registre.
     */
    private function scopeUserId(): int
    {
        $fromAccount = (int) ($this->userAccount['id'] ?? 0);
        if ($fromAccount > 0) {
            return $fromAccount;
        }
        try {
            return (int) ($this->session?->userId() ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Entitat activa: la del compte (context API, ve del token) o la que hi ha
     * a la sessió del navegador. L'array d'usuari de sessió NO porta entity_id
     * — l'entitat activa es desa a part com a `_ib_active_entity`.
     */
    private function activeEntityId(): ?int
    {
        $fromAccount = $this->userAccount['entity_id'] ?? null;
        if ($fromAccount !== null && $fromAccount !== '') {
            return (int) $fromAccount;
        }
        try {
            $active = $this->session?->get('_ib_active_entity');
        } catch (\Throwable) {
            $active = null;
        }
        if (is_array($active) && !empty($active['id'])) {
            return (int) $active['id'];
        }
        return null;
    }

    /**
     * Versió literal del predicat per a camins que construeixen SQL cru sense
     * bindings (p. ex. la fila de totals del DataBrowser). Els valors són
     * enters o NULL, mai text d'usuari.
     */
    public function scopeSqlLiteral(): string
    {
        $parts = [];
        $entityId = $this->activeEntityId();
        if ($this->apiScopeByUser !== null && !$this->userScopeApplies($entityId)) {
            self::assertIdentifier($this->apiScopeByUser, '$apiScopeByUser');
            self::assertIdentifier($this->apiScopeByEntity, '$apiScopeByEntity');
            return "({$this->apiScopeByEntity} = " . $entityId
                 . " OR ({$this->apiScopeByEntity} IS NULL AND {$this->apiScopeByUser} = " . $this->scopeUserId() . '))';
        }
        if ($this->apiScopeByUser !== null) {
            self::assertIdentifier($this->apiScopeByUser, '$apiScopeByUser');
            $parts[] = "{$this->apiScopeByUser} = " . $this->scopeUserId();
        }
        if ($this->apiScopeByEntity !== null && $entityId !== null) {
            self::assertIdentifier($this->apiScopeByEntity, '$apiScopeByEntity');
            $parts[] = "({$this->apiScopeByEntity} = " . $entityId
                     . " OR {$this->apiScopeByEntity} IS NULL)";
        }
        return $parts ? '(' . implode(' AND ', $parts) . ')' : '';
    }

    /** Aplica scopePredicate() a un QueryBuilder (immutable: retorna el clon). */
    public function applyScope(QueryBuilder $qb, string $prefix = '_scope'): QueryBuilder
    {
        ['sql' => $sql, 'params' => $params] = $this->scopePredicate($prefix);
        foreach ($sql as $condition) {
            // Cada condició rep els paràmetres que hi apareixen (n'hi pot haver més d'un).
            $bind = array_filter(
                $params,
                fn($k) => preg_match('/:' . preg_quote($k, '/') . '\b/', $condition) === 1,
                ARRAY_FILTER_USE_KEY
            );
            $qb = $qb->where($condition, $bind);
        }
        return $qb;
    }

    public function findScoped(int $id): ?array
    {
        $qb = $this->db->query()
            ->from($this->table)
            ->where("{$this->key} = :id", ['id' => $id])
            ->limit(1);

        // QueryBuilder is immutable: where() returns a clone, so reassign.
        // whereRaw() takes no bindings — use where() for parameterized scope.
        if ($this->apiScopeByEntity !== null) {
            self::assertIdentifier($this->apiScopeByEntity, '$apiScopeByEntity');
            $entityId = $this->userAccount['entity_id'] ?? null;
            // <=> is MySQL's null-safe equality: if both sides are NULL it matches.
            $qb = $qb->where(
                "{$this->apiScopeByEntity} <=> :_api_scope_eid",
                ['_api_scope_eid' => $entityId]
            );
        }
        $acctEntity = $this->userAccount['entity_id'] ?? null;
        if ($this->userScopeApplies($acctEntity === null || $acctEntity === '' ? null : (int) $acctEntity)) {
            self::assertIdentifier($this->apiScopeByUser, '$apiScopeByUser');
            $userId = (int) ($this->userAccount['id'] ?? 0);
            $qb = $qb->where(
                "{$this->apiScopeByUser} = :_api_scope_uid",
                ['_api_scope_uid' => $userId]
            );
        }

        return $qb->first() ?: null;
    }

    /**
     * Fetch all records matching optional conditions.
     *
     * @param array<string, mixed> $conditions Column => value pairs (exact match)
     * @return array<int, array<string, mixed>>
     */
    public function all(array $conditions = []): array
    {
        $qb = $this->db->query()->from($this->table);

        foreach ($conditions as $col => $val) {
            $placeholder = str_replace('.', '_', $col);
            $qb->where("{$col} = :{$placeholder}", [$placeholder => $val]);
        }

        if ($this->baseWhere) {
            $qb->whereRaw($this->baseWhere);
        }

        if ($this->orderBy) {
            $qb->orderBy($this->orderBy, $this->orderDir);
        }

        return $qb->get();
    }

    /**
     * Insert a new record.
     *
     * @param  array<string, mixed> $data Column => value pairs
     * @return int                        New record primary key
     */
    public function create(array $data): int
    {
        $data = $this->sanitizeData($data);

        if (!$this->runDynamicHooks('onPreSave', $data)) {
            throw new \RuntimeException($this->dynamicBlockMessage ?? 'Save blocked by hook rule');
        }
        if (!$this->onPreSave($data)) {
            throw new \RuntimeException($this->preSaveRejectionMessage());
        }

        $id = $this->db->query()->insert($this->table, $data);
        $this->onPostSave($id, $data);
        $this->runDynamicHooks('onPostSave', $data, $id);

        $this->auditLog('create', $id, [], $data);

        // Trigger automated notifications (non-blocking)
        $fullRecord = $this->find($id) ?: array_merge($data, [$this->key => $id]);
        try { $notifUserId = $this->container->get(Session::class)->userId() ?? 0; } catch (\Throwable) { $notifUserId = 0; }
        $this->triggerNotifications('record_created', $fullRecord, [], $notifUserId);

        return $id;
    }

    /**
     * Update an existing record.
     *
     * @param  int                  $id   Primary key
     * @param  array<string, mixed> $data Column => value pairs
     * @return bool
     */
    public function update(int $id, array $data): bool
    {
        $before = $this->find($id);
        // find() ja aplica la propietat del mòdul: si no hi ha registre visible
        // per a aquest usuari, no es pot modificar (abans l'UPDATE s'executava
        // igualment sobre la fila d'un altre).
        if ($before === null && $this->scopeUiByOwner && $this->scopePredicate()['sql'] !== []) {
            throw new \RuntimeException('Record not found');
        }
        $data   = $this->sanitizeData($data);

        if (!$this->runDynamicHooks('onPreSave', $data, $id)) {
            throw new \RuntimeException($this->dynamicBlockMessage ?? 'Save blocked by hook rule');
        }
        if (!$this->onPreSave($data)) {
            throw new \RuntimeException($this->preSaveRejectionMessage());
        }

        $result = $this->db->query()
            ->update($this->table, $data, "{$this->key} = :__pk", ['__pk' => $id]);

        $this->onPostSave($id, $data);
        $this->runDynamicHooks('onPostSave', $data, $id);

        $this->auditLog('update', $id, $before ?? [], $data);

        // Trigger automated notifications (non-blocking)
        $fullRecord = $this->find($id) ?: array_merge($data, [$this->key => $id]);
        $changedFields = array_keys(array_filter($data, fn($v, $k) => isset($before[$k]) && (string)$before[$k] !== (string)$v, ARRAY_FILTER_USE_BOTH));
        $event = in_array('status', $changedFields, true) ? 'status_changed' : 'record_updated';
        try { $notifUserId = $this->container->get(Session::class)->userId() ?? 0; } catch (\Throwable) { $notifUserId = 0; }
        $this->triggerNotifications($event, $fullRecord, $changedFields, $notifUserId);

        return $result;
    }

    /**
     * Delete a record by primary key.
     *
     * @param  int  $id Primary key
     * @return bool
     */
    public function delete(int $id): bool
    {
        $emptyData = [];
        if (!$this->runDynamicHooks('onPreDelete', $emptyData, $id)) {
            throw new \RuntimeException($this->dynamicBlockMessage ?? 'Delete blocked by hook rule');
        }
        if (!$this->onPreDelete($id)) {
            throw new \RuntimeException('Delete cancelled by onPreDelete hook');
        }

        $before = $this->find($id);
        // Mateixa comprovació que a update(): sense registre visible per a
        // aquest usuari no s'esborra res.
        if ($before === null && $this->scopeUiByOwner && $this->scopePredicate()['sql'] !== []) {
            throw new \RuntimeException('Record not found');
        }

        $result = $this->db->query()
            ->delete($this->table, "{$this->key} = :__pk", ['__pk' => $id]);

        $this->onPostDelete($id);
        $emptyData2 = [];
        $this->runDynamicHooks('onPostDelete', $emptyData2, $id);

        $this->auditLog('delete', $id, $before ?? [], []);

        return $result;
    }

    /**
     * Duplicate a record.
     *
     * @param  int $id Source record primary key
     * @return int     New record primary key
     */
    public function duplicate(int $id): int
    {
        $record = $this->find($id);
        if (!$record) {
            throw new \RuntimeException("Record {$id} not found");
        }

        unset($record[$this->key]);
        $this->onDuplicate($record);

        // Auto-suffix UNIQUE columns to avoid constraint violations
        $pdo = $this->db->pdo();
        $indexes = $pdo->query("SHOW INDEX FROM `{$this->table}` WHERE Non_unique = 0")?->fetchAll(\PDO::FETCH_ASSOC) ?? [];
        $uniqueCols = [];
        foreach ($indexes as $idx) {
            $col = $idx['Column_name'] ?? '';
            if ($col !== '' && $col !== $this->key) {
                $uniqueCols[$col] = true;
            }
        }
        foreach (array_keys($uniqueCols) as $col) {
            if (isset($record[$col]) && is_string($record[$col]) && $record[$col] !== '') {
                $base = preg_replace('/_copy\d*$/', '', $record[$col]);
                $candidate = $base . '_copy';
                $n = 1;
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$this->table}` WHERE `{$col}` = ?");
                $stmt->execute([$candidate]);
                while ((int) $stmt->fetchColumn() > 0) {
                    $n++;
                    $candidate = $base . '_copy' . $n;
                    $stmt->execute([$candidate]);
                }
                $record[$col] = $candidate;
            }
        }

        return $this->create($record);
    }

    // =========================================================================
    // Request execution
    // =========================================================================

    /**
     * Execute the module action for the current request
     */
    public function execute(): void
    {
        // For POST requests, prefer $_POST['_'] over $_GET['_'] so that
        // structured-route forms (action="/app/mod/save") work correctly
        // when the Router maps the path segment but the form also sends _ in POST body.
        $action = Input::get('_');
        $jsonBody = null;
        if ($action === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            // Check JSON body for action (used by AJAX endpoints like reorder)
            $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
            if (str_contains($contentType, 'application/json')) {
                $jsonBody = json_decode(file_get_contents('php://input'), true);
                if (is_array($jsonBody) && isset($jsonBody['_'])) {
                    $action = preg_replace('/[^a-z0-9_]/', '', (string) $jsonBody['_']);
                }
            }
            if ($action === '') {
                $action = Input::post('_', 'db');
            }
        }
        if ($action === '') {
            $action = 'db';
        }

        // Map structured route actions to legacy codes
        if ($action === 'db') {
            $routeAction = \Intrabase\Core\Router::currentAction();
            $action = match ($routeAction) {
                'create'         => 'e',
                'view'           => 'd',
                'edit'           => 'e',
                'delete'         => 'dl',
                'save'           => 'sv',
                'export','csv'  => 'csv',
                'duplicate'      => 'dp',
                default           => 'db',
            };
        }

        // Apply granular permissions from ib_permissions (if any rows defined)
        $this->applyPermissions();

        // Enforce browse permission — deny access to the entire module
        if (!$this->canBrowse) {
            $this->renderAccessDenied();
            return;
        }

        // Només lectura per persona (UserAppAccess): l'editor mostra la fitxa i les accions que
        // escriuen es deneguen. Només per a aquests usuaris: la resta de mòduls no canvien.
        if ($this->userReadOnly) {
            if ($action === 'e') {
                if (\Intrabase\Core\Router::currentId() > 0) {
                    $this->renderDetailer();
                } else {
                    $this->renderAccessDenied();
                }
                return;
            }
            if (in_array($action, ['sv', 'dl', 'bdl', 'bulk', 'dp', 'reorder', 'toggle'], true)) {
                http_response_code(403);
                $this->renderAccessDenied();
                return;
            }
        }

        // Server-side check of the granular permissions (ib_permissions): until
        // 20/11/2026 the flags only hid buttons, and a crafted request could
        // create, edit or delete without the right.
        if (!$this->actionAllowed($action)) {
            http_response_code(403);
            $this->renderAccessDenied();
            return;
        }

        match ($action) {
            'db'  => $this->renderBrowser(),
            'e'   => $this->renderEditor(),
            'd'   => $this->renderDetailer(),
            'sv'  => $this->handleSave(),
            'dl'  => $this->handleDelete(),
            'bdl' => $this->handleBulkDelete(),
            'bulk' => $this->handleBulkAction(),
            'dp'  => $this->handleDuplicate(),
            'csv' => $this->handleExport(),
            'reorder' => $this->handleReorder($jsonBody),
            'toggle'  => $this->handleInlineToggle($jsonBody),
            'relation_autocomplete' => $this->handleRelationAutocomplete(),
            default => $this->renderBrowser(),
        };
    }

    /**
     * Load and apply permission overrides from ib_permissions for the current
     * user and module. No-op if the user has no groups or if no rows are defined.
     */
    /**
     * Whether the current permission flags allow an action of execute().
     */
    private function actionAllowed(string $action): bool
    {
        $id = \Intrabase\Core\Router::currentId();
        if ($id <= 0) {
            $id = (int) (Input::post($this->key ?: 'id') ?? 0);
        }
        return match ($action) {
            'e'                         => $id > 0 ? true : $this->canAdd,   // id > 0: the editor shows read-only fields itself
            'sv'                        => $id > 0 ? $this->canEdit : $this->canAdd,
            'dl', 'bdl'                 => $this->canDelete,
            'dp'                        => $this->canDuplicate && $this->canAdd,
            'bulk', 'reorder', 'toggle' => $this->canEdit,
            'csv'                       => $this->canExport,
            default                     => true,
        };
    }

    /**
     * Names of the columns that must never leave the server (API responses,
     * audit snapshots): the module's $sensitiveFields plus anything that
     * looks like a credential.
     */
    public function isSensitiveField(string $field): bool
    {
        return in_array($field, $this->sensitiveFields, true)
            || preg_match('/(^|_)(password|passwd|pass|secret|private_key|api_key|token_hash|totp_secret|imap_password|smtp_password)(_|$)/i', $field) === 1;
    }

    /** @var list<string> Fields this user may not write (FieldAccess): sanitizeData() drops them. */
    protected array $fieldWriteDenied = [];

    /**
     * Narrow the field lists for the current user (sys_field_access_rules, see
     * Security\FieldAccess). Unreadable fields leave every list and the API
     * responses; unwritable ones become read-only and are never written from
     * user input; unlisted ones only leave the list. The key is never touched.
     *
     * @param list<string> $unreadable
     * @param list<string> $unwritable
     * @param list<string> $unlisted
     */
    public function restrictFields(array $unreadable, array $unwritable, array $unlisted = []): void
    {
        $keep = [$this->key];
        $unreadable = array_values(array_diff($unreadable, $keep));
        $unwritable = array_values(array_diff($unwritable, $keep));
        $unlisted   = array_values(array_diff($unlisted, $keep));
        if ($unreadable === [] && $unwritable === [] && $unlisted === []) {
            return;
        }
        // An empty editFields means "the same as fields": pin it before narrowing.
        if ($this->editFields === []) {
            $this->editFields = $this->fields;
        }
        if ($this->showFields === [] && $this->detailFields === [] && $unlisted !== []) {
            $this->showFields = $this->fields;   // the detail keeps what only the list loses
        }
        $drop = static fn(array $list, array $out): array => array_values(array_diff($list, $out));
        $this->fields       = $drop($this->fields, array_merge($unreadable, $unlisted));
        $this->editFields   = $drop($this->editFields, $unreadable);
        $this->showFields   = $drop($this->showFields, $unreadable);
        $this->detailFields = $drop($this->detailFields, $unreadable);
        $this->searchFields = $drop($this->searchFields, $unreadable);
        $this->sensitiveFields  = array_values(array_unique(array_merge($this->sensitiveFields, $unreadable)));
        $this->readonly         = array_values(array_unique(array_merge($this->readonly, $unwritable)));
        $this->fieldWriteDenied = array_values(array_unique(array_merge($this->fieldWriteDenied, $unreadable, $unwritable)));
    }

    protected function applyPermissions(): void
    {
        $session = $this->container->get(Session::class);
        if (!$session->isAuthenticated()) {
            return;
        }

        $moduleKey = \Intrabase\Core\Router::currentMod();
        if ($moduleKey === '') {
            return;
        }

        $userId = $session->userId();

        $rows = $this->db->fetchAll(
            'SELECT group_id FROM ib_user_groups WHERE user_id = :uid',
            ['uid' => $userId]
        );
        $groupIds = array_column($rows, 'group_id');
        $groupIds = array_map('intval', $groupIds);

        if (!empty($groupIds)) {
            Permission::applyToModule($this, $groupIds, $moduleKey, $this->db);
        }
        \Intrabase\Security\FieldAccess::apply($this, (int) $userId, $moduleKey, $this->db);

        // Permís de lectura per persona a l'app (UserAppAccess, pestanya Usuaris de l'empresa).
        $appKey = \Intrabase\Core\Router::currentApp();
        if ($appKey !== '' && \Intrabase\Support\UserAppAccess::currentLevel($this->db, $session, $appKey) === 'read') {
            $this->canAdd = false;
            $this->canEdit = false;
            $this->canDelete = false;
            $this->canDuplicate = false;
            $this->canImport = false;
            $this->userReadOnly = true;
        }
    }

    /**
     * Render an access-denied message within the layout.
     */
    private function renderAccessDenied(): void
    {
        $content = '<div class=ib-alert ib-alert--danger>' .
                   '<strong>Access Denied</strong> — You do not have permission to access this module.' .
                   '</div>';
        $this->renderLayout($content);
    }

    private function renderBrowser(): void
    {
        $this->onBrowse();
        // A module may draw its own list (cards, feed…) instead of the DataBrowser
        // table: renderCustomList() returns the HTML, or null to keep the table.
        // ?view=table always shows the table (bulk actions, export, filters).
        if (Input::get('view') !== 'table') {
            $custom = $this->renderCustomList();
            if ($custom !== null) {
                $this->renderLayout($custom);
                return;
            }
        }
        $browser = $this->container->get(\Intrabase\View\DataBrowser::class);
        $content = $this->capture(fn() => $browser->render($this));
        $this->renderLayout($content);
    }

    /**
     * Custom list view (25/09/2026). Override to return the HTML of the module's
     * own list page (cards, feed…); null keeps the standard DataBrowser table.
     * The table stays reachable at ?view=table. See modules/kos/KosCalls.php.
     */
    protected function renderCustomList(): ?string
    {
        return null;
    }

    private function renderEditor(): void
    {
        $id = Input::getInt('id', 0);
        if ($id === 0) { $id = \Intrabase\Core\Router::currentId(); }
        $this->onEdit($id);
        $editor = $this->container->get(\Intrabase\View\DataEditor::class);
        $content = $this->capture(fn() => $editor->render($this, $id));
        $this->renderLayout($content);
    }

    private function renderDetailer(): void
    {
        $id = Input::getInt('id', 0);
        if ($id === 0) { $id = \Intrabase\Core\Router::currentId(); }
        $detailer = $this->container->get(\Intrabase\View\DataDetailer::class);
        $content = $this->capture(fn() => $detailer->render($this, $id));
        $this->renderLayout($content);
    }

    /**
     * Capture output of a callable, cleaning the buffer even on exception.
     */
    private function capture(callable $fn): string
    {
        ob_start();
        try {
            $fn();
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }

    protected function renderLayout(string $content): void
    {
        $session = $this->container->get(\Intrabase\Security\Session::class);
        $db      = $this->container->get(\Intrabase\Database\Connection::class);
        $layout  = new \Intrabase\View\Layout($session, $this->csrf, $db);
        $layout->render($content, [
            'title'      => $this->title,
            'breadcrumb' => [['label' => $this->title]],
        ]);
    }

    private function handleSave(): void
    {
        // Validate CSRF token
        if (!$this->csrf->validateToken()) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid CSRF token']);
            return;
        }

        $id   = Input::postInt('id', 0);
        $mod  = \Intrabase\Core\Router::currentMod() ?: (Input::post('mod') ?: Input::get('mod'));
        $data = $this->extractPostData();

        // Active editor tab (see DataEditor) — re-appended to every redirect back
        // to the form so saving does not throw the user back to the first tab.
        $tab = (string) Input::post('_tab');
        $tabHash = preg_match('/^[A-Za-z0-9_-]{1,64}$/', $tab) ? '#' . $tab : '';

        // Optional caller-supplied return URL (e.g. the editor embedded in
        // /preferences). Same-site relative paths only; {id} = saved record.
        $returnUrl = trim((string) Input::post('_return'));
        $returnFor = static function (int $savedId) use ($returnUrl): string {
            if ($returnUrl === '' || $returnUrl[0] !== '/' || str_starts_with($returnUrl, '//')) {
                return '';
            }
            $url = str_replace('{id}', (string) $savedId, $returnUrl);
            return preg_match('#^/[^\s"\'<>\\\\]*$#', $url) ? $url : '';
        };

        // Run field validation before attempting save
        $errors = $this->validateData($data);
        if (!empty($errors)) {
            $session = $this->container->get(Session::class);
            $session->flash('error', implode(' · ', $errors));
            $back = $returnFor($id) ?: ($id > 0 ? Router::editUrl($mod, $id) : Router::createUrl($mod));
            header('Location: ' . $back . $tabHash);
            exit;
        }

        $isDuplicate = !empty($_POST['_duplicate']);

        // Read-only fields are never written back on an UPDATE. The form still
        // posts them (readonly inputs submit), and round-tripping a value through
        // its display format corrupts it: on erp_finance_banks_transactions
        // (11/10/2026) the KMS text "27.698,03" came back as 0 and the datetime
        // as NULL. Hooks can still set them in onPreSave.
        if ($id > 0 && !$isDuplicate && $this->readonly) {
            $data = array_diff_key($data, array_flip($this->readonly));
        }

        try {
            if ($id > 0 && !$isDuplicate) {
                $this->update($id, $data);
            } else {
                $id = $this->create($data);
            }

            // Redirect after save
            if ($this->stayOnEdit && !$isDuplicate) {
                // Stay on edit form
                $session = $this->container->get(Session::class);
                $session->flash('success', __('saved'));
                $editUrl = $returnFor($id) ?: Router::editUrl($mod, $id);
                header('Location: ' . $editUrl . $tabHash);
                exit;
            }
            // Torna al llistat tal com era (pàgina, vista, filtres, ordenació)
            $flag = $isDuplicate ? 'duplicated' : 'saved';
            header('Location: ' . $this->listReturnUrl($mod, [$flag => 1]));
            exit;
        } catch (\InvalidArgumentException $e) {
            // Validation error — redirect back to the form with a flash message
            $session = $this->container->get(Session::class);
            $session->flash('error', $e->getMessage());
            $back = $returnFor($id) ?: ($id > 0 ? Router::editUrl($mod, $id) : Router::createUrl($mod));
            header('Location: ' . $back . $tabHash);
            exit;
        } catch (\Throwable $e) {
            // Unexpected error — redirect back with a generic message
            error_log('[SAVE ERROR] ' . $this->table . ' id=' . $id . ' error=' . $e->getMessage() . ' data=' . json_encode($data));
            $session = $this->container->get(Session::class);
            $session->flash('error', 'Save failed: ' . $e->getMessage());
            $back = $returnFor($id) ?: ($id > 0 ? Router::editUrl($mod, $id) : Router::createUrl($mod));
            header('Location: ' . $back . $tabHash);
            exit;
        }
    }

    private function handleDelete(): void
    {
        if (!$this->csrf->validateToken()) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid CSRF token']);
            return;
        }

        $id = Input::postInt('id', 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid ID']);
            return;
        }

        try {
            $this->delete($id);
            $modForDelete = \Intrabase\Core\Router::currentMod() ?: Input::post('mod') ?: Input::get('mod');
            header('Location: ' . $this->listReturnUrl((string) $modForDelete, ['deleted' => 1]));
            exit;
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    private function handleBulkDelete(): void
    {
        if (!$this->canMultiselect || !$this->canDelete) {
            http_response_code(403);
            return;
        }
        if (!$this->csrf->validateToken()) {
            http_response_code(403);
            return;
        }

        $raw = Input::post('ids', '');
        $ids = array_filter(array_map('intval', explode(',', $raw)), fn(int $v) => $v > 0);

        $deleted = 0;
        foreach ($ids as $id) {
            try {
                $this->delete($id);
                $deleted++;
            } catch (\Throwable) {
                // skip records that can't be deleted (hooks blocking, etc.)
            }
        }

        $mod = Router::currentMod() ?: Input::post('mod') ?: Input::get('mod');
        header('Location: ' . $this->listReturnUrl((string) $mod, ['deleted' => $deleted]));
        exit;
    }

    /**
     * Declarative bulk action (see $bulkActions). POST from the DataBrowser:
     *   _=bulk · bulk_action=<name> · mode=selection|filter · ids=1,2,3 · _back=<list URL>
     * Redirects back to the list with ?bulk_done=<rows changed>.
     */
    private function handleBulkAction(): void
    {
        if (!$this->canMultiselect || !$this->canEdit || $this->bulkActions === []) {
            http_response_code(403);
            return;
        }
        if (!$this->csrf->validateToken()) {
            http_response_code(403);
            return;
        }
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', (string) Input::post('bulk_action', ''));
        $def  = $this->bulkActions[$name] ?? null;
        if (!is_array($def)) {
            http_response_code(400);
            return;
        }
        $mode = (Input::post('mode', 'selection') === 'filter' && ($def['scope'] ?? 'selection') === 'filter')
            ? 'filter' : 'selection';

        if ($mode === 'filter') {
            // The very same predicate the user is looking at (DataBrowser builds it).
            $ids = (new \Intrabase\View\DataBrowser())->currentFilterIds($this);
        } else {
            $ids = array_values(array_filter(
                array_map('intval', explode(',', (string) Input::post('ids', ''))),
                fn(int $v) => $v > 0
            ));
        }

        $affected = 0;
        if ($ids !== []) {
            $handler = (string) ($def['handler'] ?? '');
            if ($handler !== '' && method_exists($this, $handler)) {
                foreach (array_chunk($ids, 500) as $chunk) {
                    $affected += (int) $this->{$handler}($chunk);
                }
            } elseif (!empty($def['set']) && is_array($def['set'])) {
                $affected = $this->bulkSet($ids, $def['set'], (string) ($def['touch'] ?? ''));
            }
        }

        // Back to the list the user came from (same filters), never off-site.
        $back = (string) Input::post('_back', '');
        if ($back === '' || $back[0] !== '/' || str_starts_with($back, '//')) {
            $mod  = Router::currentMod() ?: Input::post('mod') ?: Input::get('mod');
            $back = Router::url($mod);
        }
        $back = (string) preg_replace('/([?&])bulk_done=\d*(&|$)/', '$1', $back);
        $back = rtrim($back, '?&');
        header('Location: ' . $back . (str_contains($back, '?') ? '&' : '?') . 'bulk_done=' . $affected);
        exit;
    }

    /**
     * UPDATE $set (column => literal value) on the given primary keys, in chunks
     * of 1000. Column names are validated as identifiers, values are bound.
     * Returns the number of rows actually changed.
     *
     * @param int[]                $ids
     * @param array<string, mixed> $set
     */
    protected function bulkSet(array $ids, array $set, string $touch = ''): int
    {
        $assign = [];
        $params = [];
        foreach ($set as $col => $val) {
            if (!is_string($col) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) {
                continue;
            }
            $assign[]             = "`{$col}` = :set_{$col}";
            $params["set_{$col}"] = $val;
        }
        if ($touch !== '' && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $touch)) {
            $assign[] = "`{$touch}` = NOW()";
        }
        if ($assign === [] || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $this->key)) {
            return 0;
        }
        $table    = str_contains($this->table, '.') ? $this->table : "`{$this->table}`";
        $affected = 0;
        foreach (array_chunk($ids, 1000) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $st = $this->db->execute(
                "UPDATE {$table} SET " . implode(', ', $assign) . " WHERE `{$this->key}` IN ({$in})",
                $params
            );
            $affected += $st->rowCount();
        }
        return $affected;
    }

    private function handleDuplicate(): void
    {
        if (!$this->csrf->validateToken()) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid CSRF token']);
            return;
        }

        $id = Input::postInt('id', 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid ID']);
            return;
        }

        try {
            $newId = $this->duplicate($id);
            $modForDup = \Intrabase\Core\Router::currentMod() ?: Input::post('mod') ?: Input::get('mod');
            header('Location: ' . $this->listReturnUrl((string) $modForDup, ['duplicated' => 1]));
            exit;
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    // =========================================================================
    // Drag & Drop Reorder
    // =========================================================================

    /**
     * Handle AJAX reorder request.
     * Expects JSON body: { "ids": [3, 1, 5, 2, ...] }
     * Updates the reorderField (sort_order) for each row.
     */
    /**
     * Canvi d'un camp booleà des del llistat.
     *
     * L'equivalent del KMS (share/lib/ajax/objWrite.php) munta la consulta
     * concatenant `$_POST['mod']`, `$_POST['id']` i el valor, sense token, ni
     * permisos, ni llista blanca, i saltant-se els hooks. Aqui no: el camp ha
     * d'estar declarat a `$inlineToggle`, l'id es un enter, el valor nomes pot
     * ser 0 o 1, i l'escriptura passa per update(), que sanea i executa els
     * hooks igual que una desada normal.
     */
    private function handleInlineToggle(?array $jsonBody = null): void
    {
        header('Content-Type: application/json');

        if (!$this->csrf->validateToken()) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid CSRF token']);
            return;
        }
        if (!$this->canEdit) {
            http_response_code(403);
            echo json_encode(['error' => 'Edit not allowed']);
            return;
        }

        $input = $jsonBody ?? json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = [];
        }
        $id    = (int) ($input['id'] ?? 0);
        $field = (string) ($input['field'] ?? '');
        $value = !empty($input['value']) ? '1' : '0';

        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid ID']);
            return;
        }
        if ($field === '' || !in_array($field, $this->inlineToggle, true)) {
            http_response_code(403);
            echo json_encode(['error' => 'Field not toggleable']);
            return;
        }

        // find() aplica l'scope de propietari del modul: una fila que l'usuari
        // no podria ni veure tampoc es pot canviar des d'aqui.
        if ($this->find($id) === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Record not found']);
            return;
        }

        try {
            $this->update($id, [$field => $value]);
            echo json_encode(['ok' => true, 'value' => $value]);
        } catch (\Throwable $e) {
            error_log('[inline toggle] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Update failed']);
        }
    }

    private function handleReorder(?array $jsonBody = null): void
    {
        if (!$this->canReorder) {
            http_response_code(403);
            echo json_encode(['error' => 'Reorder not allowed']);
            return;
        }

        if (!$this->csrf->validateToken()) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid CSRF token']);
            return;
        }

        $input = $jsonBody ?? json_decode(file_get_contents('php://input'), true);
        $ids = $input['ids'] ?? [];
        $offset = max(0, (int) ($input['offset'] ?? 0));

        if (!is_array($ids) || empty($ids)) {
            http_response_code(400);
            echo json_encode(['error' => 'No IDs provided']);
            return;
        }

        // Validate reorder field name
        $field = preg_replace('/[^a-zA-Z0-9_]/', '', $this->reorderField);
        $key   = preg_replace('/[^a-zA-Z0-9_]/', '', $this->key);

        try {
            foreach ($ids as $position => $id) {
                $id = (int) $id;
                if ($id <= 0) continue;
                $this->db->update(
                    $this->table,
                    [$field => $offset + $position],
                    "`{$key}` = :id",
                    ['id' => $id]
                );
            }

            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    // =========================================================================
    // Relation Autocomplete AJAX
    // =========================================================================

    /**
     * Handle AJAX autocomplete requests for relation_autocomplete components.
     *
     * GET params:
     *   q      — search term
     *   table  — related table name
     *   column — column to search
     *   key    — primary key column (default: id)
     *   limit  — max results (default: 10)
     *   where  — extra WHERE clause (optional)
     */
    private function handleRelationAutocomplete(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $q      = trim(Input::get('q'));
        $table  = preg_replace('/[^a-zA-Z0-9_]/', '', Input::get('table'));
        $column = preg_replace('/[^a-zA-Z0-9_]/', '', Input::get('column'));
        $key    = preg_replace('/[^a-zA-Z0-9_]/', '', Input::get('key')) ?: 'id';
        $limit  = min(50, max(1, (int) (Input::get('limit') ?: 10)));
        // El WHERE extra és el configurat al mòdul (componentOptions), MAI el que arriba
        // per la URL: abans s'enganxava tal qual a l'SQL (injecció per a qualsevol usuari
        // amb accés al mòdul). El JS el continua enviant i s'ignora. 20/10/2026.
        $where  = '';

        if ($table === '' || $column === '' || $q === '') {
            echo json_encode([]);
            return;
        }

        // Validate that this field is configured as relation_autocomplete in this module
        $allowed = false;
        foreach ($this->components as $field => $type) {
            if ($type === 'relation_autocomplete') {
                $opts = $this->componentOptions[$field] ?? [];
                if (($opts['table'] ?? '') === $table && ($opts['column'] ?? '') === $column) {
                    $allowed = true;
                    $where   = (string) ($opts['where'] ?? '');
                    break;
                }
            }
        }
        if (!$allowed) {
            http_response_code(403);
            echo json_encode(['error' => 'Not allowed']);
            return;
        }

        try {
            $sql = "SELECT `{$key}`, `{$column}` FROM `{$table}` WHERE `{$column}` LIKE :q";
            $params = ['q' => '%' . $q . '%'];

            if ($where !== '') {
                // Only allow safe where clauses configured in the module
                $sql .= " AND ({$where})";
            }

            $sql .= " ORDER BY `{$column}` ASC LIMIT {$limit}";

            $rows = $this->db->fetchAll($sql, $params);
            $results = [];
            foreach ($rows as $row) {
                $results[] = [
                    'id'    => $row[$key],
                    'label' => $row[$column],
                ];
            }
            echo json_encode($results);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Search failed']);
        }
    }

    // =========================================================================
    // CSV Export
    // =========================================================================

    /**
     * Stream all matching records as a CSV download.
     *
     * - Respects current search filter and sort order (no pagination)
     * - Prevents CSV injection by prefixing dangerous values with a tab
     * - All output is streamed directly to stdout; method exits at end
     */
    private function handleExport(): void
    {
        if (!$this->canExport) {
            http_response_code(403);
            echo 'Export not allowed';
            exit;
        }

        $fields      = $this->showFields ?: $this->fields;
        $keyField    = $this->key;
        $allFields   = in_array($keyField, $fields, true) ? $fields : array_merge([$keyField], $fields);
        $safeFields  = array_filter($allFields, fn($f) => preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $f));

        $search      = Input::get('q');
        $sortByRaw   = preg_replace('/[^a-zA-Z0-9_]/', '', Input::get('sortby'));
        $sortBy      = ($sortByRaw && in_array($sortByRaw, $safeFields, true)) ? $sortByRaw : ($this->orderBy ?: $keyField);
        $sortDir     = strtoupper(Input::get('sortdir')) === 'DESC' ? 'DESC' : 'ASC';
        // Use all searchFields (same as DataBrowser) for consistent export results
        $searchFieldsRaw = !empty($this->searchFields) ? $this->searchFields : [$fields[0] ?? ''];
        $safeSearchFields = array_values(array_filter(
            $searchFieldsRaw,
            fn($f) => (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $f)
        ));

        $qb = $this->db->query()->from($this->table);

        if (!empty($safeFields)) {
            $qb = $qb->select(array_values($safeFields));
        }

        if ($this->baseWhere) {
            $qb = $qb->whereRaw($this->baseWhere);
        }

        // L'exportació CSV ha de veure exactament el mateix que el llistat.
        if ($this->scopeUiByOwner) {
            $qb = $this->applyScope($qb, '_scope_exp');
        }

        if ($search !== '' && !empty($safeSearchFields)) {
            // Use unique param names per field to avoid PDO duplicate named param error
            $searchBindings = [];
            $orConditions   = [];
            foreach ($safeSearchFields as $idx => $sf) {
                $paramName = '__search_' . $idx;
                $orConditions[]            = "`{$sf}` LIKE :{$paramName}";
                $searchBindings[$paramName] = '%' . $search . '%';
            }
            $qb = $qb->where('(' . implode(' OR ', $orConditions) . ')', $searchBindings);
        }

        // Apply advanced filters (mirrors DataBrowser filter logic)
        $rawFilters = $_GET['f'] ?? [];
        if (is_array($rawFilters) && !empty($this->filterFields)) {
            foreach ($this->filterFields as $filterField => $filterType) {
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $filterField)) {
                    continue;
                }
                $rawF = $rawFilters[$filterField] ?? [];
                if (!is_array($rawF)) {
                    $rawF = ['v' => (string) $rawF];
                }
                if ($filterType === 'date_range' || $filterType === 'datetime_range') {
                    $from = Input::sanitizeDate((string) ($rawF['from'] ?? ''));
                    $to   = Input::sanitizeDate((string) ($rawF['to']   ?? ''));
                    $ph   = '__fld_' . $filterField;
                    if ($from !== '') {
                        $qb = $qb->where("`{$filterField}` >= :{$ph}_from", ["{$ph}_from" => $from]);
                    }
                    if ($to !== '') {
                        $suffix = $filterType === 'datetime_range' ? '' : ' 23:59:59';
                        $qb = $qb->where("`{$filterField}` <= :{$ph}_to", ["{$ph}_to" => $to . $suffix]);
                    }
                } elseif ($filterType === 'text') {
                    $val = Input::sanitizeString((string) ($rawF['v'] ?? ''));
                    if ($val !== '') {
                        $ph = '__flt_' . $filterField;
                        $qb = $qb->where("`{$filterField}` LIKE :{$ph}", ["{$ph}" => '%' . $val . '%']);
                    }
                } elseif ($filterType === 'select' || $filterType === 'combo') {
                    $val = Input::sanitizeString((string) ($rawF['v'] ?? ''));
                    if ($val !== '') {
                        $ph = '__fls_' . $filterField;
                        $qb = $qb->where("`{$filterField}` = :{$ph}", ["{$ph}" => $val]);
                    }
                } elseif ($filterType === 'year') {
                    $y = (string) ($rawF['v'] ?? '');
                    if (preg_match('/^\d{4}$/', $y)) {
                        $col = \Intrabase\View\DataBrowser::yearColumn($filterField);
                        $ph  = '__fly_' . $col;
                        $qb = $qb->where("`{$col}` >= :{$ph}_a AND `{$col}` < :{$ph}_b", ["{$ph}_a" => $y . '-01-01', "{$ph}_b" => ((int) $y + 1) . '-01-01']);
                    }
                }
            }
        }

        $qb = $qb->orderBy($sortBy, $sortDir);
        $rows = $qb->get();

        // Stream CSV headers
        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $this->table) . '_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        // BOM per compatibilitat UTF-8 amb Excel
        echo "\xEF\xBB\xBF";

        $out = fopen('php://output', 'w');

        // Capçalera: etiquetes llegibles
        $headers = array_map(
            fn($f) => $this->humans[$f] ?? ucwords(str_replace('_', ' ', $f)),
            array_values($safeFields)
        );
        fputcsv($out, $headers);

        // Files de dades
        foreach ($rows as $row) {
            $csvRow = [];
            foreach (array_values($safeFields) as $field) {
                $value = (string) ($row[$field] ?? '');
                // Prevenir CSV injection: prefixar cel.les que comencin amb caracters perillosos
                if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                    $value = "\t" . $value;
                }
                $csvRow[] = $value;
            }
            fputcsv($out, $csvRow);
        }

        fclose($out);
        exit;
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * Extract and sanitize POST data matching known edit fields
     *
     * @return array<string, mixed>
     */
    protected function extractPostData(): array
    {
        $fields = $this->editFields ?: $this->fields;
        $data = [];

        foreach ($fields as $field) {
            $type = $this->components[$field] ?? 'text';

            if ($type === 'checkbox' || $type === 'boolean') {
                // Unchecked checkboxes don't appear in POST; hidden input sends "0".
                // Normalise to integer 1 or 0.
                $raw        = $_POST[$field] ?? '0';
                $data[$field] = (int)(bool) $raw;
            } elseif ($type === 'multicombo' || $type === 'checklist') {
                // These use name="field[]" → $_POST[$field] is an array or absent.
                $raw        = $_POST[$field] ?? [];
                $data[$field] = implode(',', array_filter(array_map('trim', (array) $raw)));
            } elseif (isset($_POST[$field])) {
                $data[$field] = $this->sanitizeFieldValue($field, $_POST[$field]);
            }
        }

        return $data;
    }

    /**
     * Sanitize a single field value based on its component type
     */
    protected function sanitizeFieldValue(string $field, mixed $value): mixed
    {
        $type = $this->components[$field] ?? 'text';

        return match ($type) {
            'integer',
            'rating'     => $this->sanitizeNumericValue($field, $value, false),
            'money',
            'percentage',
            'percent',
            'decimal'    => $this->sanitizeNumericValue($field, $value, true),
            'checkbox',
            'boolean',
            'toggle'     => (int)(bool) $value,
            'multicombo',
            'checklist'  => is_array($value)
                            ? implode(',', array_filter(array_map('trim', $value)))
                            : Input::sanitizeString((string) $value),
            'combo',
            'fk'         => $this->sanitizeComboValue($field, $value),
            'date'       => $this->emptyToNullIfNullable($field, Input::sanitizeDate((string) $value)),
            'datetime'   => $this->emptyToNullIfNullable($field, Input::sanitizeDatetime((string) $value)),
            'time'       => $this->emptyToNullIfNullable($field, $this->sanitizeTimeValue((string) $value)),
            'email'      => filter_var((string) $value, FILTER_SANITIZE_EMAIL),
            'url'        => filter_var((string) $value, FILTER_SANITIZE_URL),
            'html',
            'wysiwyg'    => Input::sanitizeHtml((string) $value),
            'json'       => $this->sanitizeJsonValue($field, $value),
            // Passwords: only trim, never strip_tags (would mangle special chars)
            'password'   => trim((string) $value),
            'hidden'     => (string) $value,
            'number',
            'relation_autocomplete' => trim((string) $value) === '' ? null : (int) $value,
            default      => Input::sanitizeString((string) $value),
        };
    }

    /**
     * Numeric components: a blank input means "no value", not zero. It maps to
     * NULL when the column accepts it (a silent 0 corrupts data); on NOT NULL
     * columns the legacy cast to 0/0.0 is kept.
     */
    private function sanitizeNumericValue(string $field, mixed $value, bool $float): int|float|null
    {
        $raw = is_string($value) ? trim($value) : $value;
        if (($raw === '' || $raw === null) && $this->columnIsNullable($field)) {
            return null;
        }
        if ($float) {
            // Accept a comma as decimal separator from human input ("12,50")
            if (is_string($raw) && preg_match('/^-?\d+,\d+$/', $raw)) {
                $raw = str_replace(',', '.', $raw);
            }
            return (float) $raw;
        }
        return (int) $raw;
    }

    /**
     * Combo/FK components: a blank selection means "none" and must become NULL
     * on nullable columns — '' coerced to 0 breaks FK constraints (error 1452).
     * Table-backed combos carry integer keys; static data combos keep strings.
     */
    private function sanitizeComboValue(string $field, mixed $value): mixed
    {
        $v = is_array($value)
            ? implode(',', array_filter(array_map('trim', $value)))
            : trim((string) $value);

        if ($v === '') {
            return $this->columnIsNullable($field) ? null : '';
        }

        $cfg = $this->combos[$field] ?? null;
        if (is_array($cfg) && isset($cfg['table']) && preg_match('/^-?\d+$/', $v)) {
            $iv = (int) $v;
            return ($iv === 0 && $this->columnIsNullable($field)) ? null : $iv;
        }

        return Input::sanitizeString($v);
    }

    /**
     * JSON components: store only a re-encoded valid JSON document; blank or
     * undecodable input degrades to NULL (nullable) or '' — never strip_tags,
     * which would mangle the payload.
     */
    private function sanitizeJsonValue(string $field, mixed $value): ?string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: null;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return $this->columnIsNullable($field) ? null : '';
        }
        $decoded = json_decode($raw, true);
        if ($decoded === null && strtolower($raw) !== 'null') {
            return $this->columnIsNullable($field) ? null : '';
        }
        return json_encode($decoded, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Sanitize a time string to HH:MM:SS. Returns empty string if invalid.
     */
    private function sanitizeTimeValue(string $value): string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim($value), $m)) {
            $h = (int) $m[1];
            $i = (int) $m[2];
            $s = (int) ($m[3] ?? 0);
            if ($h < 24 && $i < 60 && $s < 60) {
                return sprintf('%02d:%02d:%02d', $h, $i, $s);
            }
        }
        return '';
    }

    /**
     * Blank date/datetime/time inputs become NULL on nullable columns instead
     * of '' (which MySQL would coerce to a zero date with only a warning).
     */
    private function emptyToNullIfNullable(string $field, string $value): ?string
    {
        return ($value === '' && $this->columnIsNullable($field)) ? null : $value;
    }

    /** @var array<string, array<string, bool>> Per-table column nullability cache */
    private static array $nullableColsCache = [];

    /**
     * Whether the underlying table column accepts NULL. Cached per table for
     * the request. Virtual modules without a physical table resolve to false.
     */
    protected function columnIsNullable(string $field): bool
    {
        if (!isset(self::$nullableColsCache[$this->table])) {
            $map = [];
            try {
                $cols = $this->db->pdo()
                    ->query("SHOW COLUMNS FROM `{$this->table}`")
                    ?->fetchAll(\PDO::FETCH_ASSOC) ?? [];
                foreach ($cols as $c) {
                    $map[$c['Field']] = (($c['Null'] ?? '') === 'YES');
                }
            } catch (\Throwable) {
                // No physical table (computed/analytics modules)
            }
            self::$nullableColsCache[$this->table] = $map;
        }
        return self::$nullableColsCache[$this->table][$field] ?? false;
    }

    /**
     * Sanitize all values in a data array
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function sanitizeData(array $data): array
    {
        // Fields a field-access rule keeps this user from writing (FieldAccess).
        if ($this->fieldWriteDenied !== []) {
            $data = array_diff_key($data, array_flip($this->fieldWriteDenied));
        }
        $sanitized = [];
        foreach ($data as $field => $value) {
            $sanitized[$field] = $this->sanitizeFieldValue($field, $value);
        }
        return $sanitized;
    }

    // =========================================================================
    // Audit helpers
    // =========================================================================

    /**
     * Write an audit log entry for a CRUD operation.
     *
     * Retrieves the Session from the container (safe to call even during API
     * requests where no browser session exists — user_id will be 0).
     * Sensitive fields are stripped from the snapshots before writing.
     * Failures are silently caught so audit never breaks the main operation.
     *
     * @param string               $action  create | update | delete
     * @param int                  $id      Affected record primary key
     * @param array<string, mixed> $before  Record state before the operation
     * @param array<string, mixed> $after   Record state after the operation
     */
    private function auditLog(string $action, int $id, array $before, array $after): void
    {
        try {
            $session = $this->container->get(Session::class);
            Auditor::log(
                $action,
                $this->table,
                $id,
                $this->stripSensitiveFields($before),
                $this->stripSensitiveFields($after),
                $this->db,
                $session
            );
        } catch (\Throwable) {
            // Audit failure must never interrupt the main operation
        }
    }

    /**
     * Remove sensitive fields from a data snapshot before auditing.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function stripSensitiveFields(array $data): array
    {
        foreach ($this->sensitiveFields as $field) {
            unset($data[$field]);
        }
        return $data;
    }

    // =========================================================================
    // Accessors
    // =========================================================================

    /** @param array<string, mixed> $account */
    public function setClientAccount(array $account): void
    {
        $this->clientAccount = $account;
    }

    /** @param array<string, mixed> $account */
    public function setUserAccount(array $account): void
    {
        $this->userAccount = $account;
    }

    /** @return array<string, array<string, mixed>> */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /** @return array<string, mixed> */
    public function getUserAccount(): array
    {
        return $this->userAccount;
    }

    /** @return array<string, mixed> */
    public function getClientAccount(): array
    {
        return $this->clientAccount;
    }

    public function getDb(): Connection
    {
        return $this->db;
    }

    public function getCsrf(): CSRF
    {
        return $this->csrf;
    }

    /** Paràmetres de l'adreça del llistat que no s'han de recordar (avisos d'una sola vegada). */
    private const LIST_QS_SKIP = ['saved', 'deleted', 'duplicated', 'bulk_done', 'mod', 'app', 'action', 'id', 'page_partial'];

    /**
     * Vista triada al llistat (v1 principal, v2 secundària), recordada per mòdul a la sessió (06/11/2026):
     * en tornar al llistat sense ?v1/?v2 a l'adreça, es manté la que s'havia triat. Amb $set la desa
     * (0 = «tots els registres»); sense, torna la recordada o null.
     */
    public function listViewMemory(string $mod, string $param, ?int $set = null): ?int
    {
        if ($this->session === null || $mod === '' || !in_array($param, ['v1', 'v2'], true)) {
            return null;
        }
        try {
            $all = $this->session->get('_ib_list_views');
            $all = is_array($all) ? $all : [];
            if ($set !== null) {
                $all[$mod][$param] = max(0, $set);
                $this->session->set('_ib_list_views', $all);
                return $all[$mod][$param];
            }
            return isset($all[$mod][$param]) ? (int) $all[$mod][$param] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Cerca del llistat (?q=), recordada per mòdul a la sessió (06/11/2026), com les vistes
     * (listViewMemory). Amb $set la desa ('' = sense cerca); sense, torna la recordada o null.
     */
    public function listSearchMemory(string $mod, ?string $set = null): ?string
    {
        if ($this->session === null || $mod === '') {
            return null;
        }
        try {
            $all = $this->session->get('_ib_list_search');
            $all = is_array($all) ? $all : [];
            if ($set !== null) {
                $all[$mod] = mb_substr($set, 0, 200);
                $this->session->set('_ib_list_search', $all);
                return $all[$mod];
            }
            return isset($all[$mod]) && is_string($all[$mod]) ? $all[$mod] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Recorda l'adreça del llistat (pàgina, cerca, vista, filtres, ordenació)
     * per tornar-hi des de la fitxa: en tornar enrere, cancel·lar, desar o
     * eliminar. La crida DataBrowser cada cop que pinta el llistat. Els
     * paràmetres interns («_…») i els avisos d'una sola vegada no es desen.
     */
    public function rememberListQuery(string $mod): void
    {
        if ($this->session === null || $mod === '') {
            return;
        }
        $keep = [];
        foreach ($_GET as $k => $v) {
            if (!is_string($k) || $k === '' || $k[0] === '_' || in_array($k, self::LIST_QS_SKIP, true)) {
                continue;
            }
            $keep[$k] = $v;
        }
        try {
            $all = $this->session->get('_ib_list_qs');
            $all = is_array($all) ? $all : [];
            $all[$mod] = $keep;
            $this->session->set('_ib_list_qs', $all);
        } catch (\Throwable) {
        }
    }

    /**
     * Adreça de tornada al llistat amb els paràmetres que tenia, més $extra
     * (p. ex. ['saved' => 1]). Sense res desat, el llistat net.
     *
     * @param array<string, scalar> $extra
     */
    public function listReturnUrl(string $mod, array $extra = []): string
    {
        $saved = [];
        try {
            $all   = $this->session?->get('_ib_list_qs');
            $saved = is_array($all) && is_array($all[$mod] ?? null) ? $all[$mod] : [];
        } catch (\Throwable) {
        }
        $params = array_merge($saved, $extra);
        $url    = Router::url($mod);
        if ($params === []) {
            return $url;
        }
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
    }


    // =========================================================================
    // Auto-code generation helpers
    // =========================================================================

    /**
     * Generate the next sequential code for this module's records.
     *
     * Uses MAX() on the numeric suffix of existing codes — safer than COUNT(*)
     * because deleted records or gaps don't cause collisions.
     *
     * Examples:
     *   nextSeq('CLI', 'client_code')          → 'CLI-0001'
     *   nextSeq('EINV', 'invoice_number', 4, '2026') → 'EINV-2026-0001'
     *
     * @param string      $prefix    Code prefix (e.g. 'CLI', 'EINV')
     * @param string      $codeField Column name holding the code
     * @param int         $pad       Padding width for numeric part (default 4)
     * @param string|null $year      Year segment to include in code (e.g. '2026')
     * @return string
     */
    protected function nextSeq(
        string $prefix,
        string $codeField,
        int $pad = 4,
        ?string $year = null
    ): string {
        $pdo   = $this->db->pdo();
        $table = $this->table;

        if ($year !== null) {
            // Pattern: PREFIX-YYYY-NNNN → extract last numeric segment
            $like = $prefix . '-' . $year . '-%';
            $stmt = $pdo->prepare(
                "SELECT MAX(CAST(SUBSTRING_INDEX(`{$codeField}`, '-', -1) AS UNSIGNED))
                    FROM `{$table}`
                   WHERE `{$codeField}` LIKE ?"
            );
            $stmt->execute([$like]);
            $max = (int) $stmt->fetchColumn();
            return $prefix . '-' . $year . '-' . str_pad((string)($max + 1), $pad, '0', STR_PAD_LEFT);
        }

        // Pattern: PREFIX-NNNN → extract last numeric segment
        $like = $prefix . '-%';
        $stmt = $pdo->prepare(
            "SELECT MAX(CAST(SUBSTRING_INDEX(`{$codeField}`, '-', -1) AS UNSIGNED))
                FROM `{$table}`
               WHERE `{$codeField}` LIKE ?"
        );
        $stmt->execute([$like]);
        $max = (int) $stmt->fetchColumn();
        return $prefix . '-' . str_pad((string)($max + 1), $pad, '0', STR_PAD_LEFT);
    }
    // =========================================================================
    // API helpers
    // =========================================================================

    /**
     * Return the module's machine name (derived from class name).
     * E.g. SysUsers → sys_users
     */
    public function getModuleName(): string
    {
        $class = (new \ReflectionClass($this))->getShortName();
        // CamelCase → snake_case
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $class) ?? $class);
    }

    /**
     * Run dynamic hook rules defined in ib_sys_hook_rules.
     * Called automatically from create/update/delete before/after subclass hooks.
     *
     * @param string  $hookType  onPreSave|onPostSave|onPreDelete|onPostDelete
     * @param array   $data      Record data (pass by reference for set_value actions)
     * @param int     $id        Record ID (0 for new records)
     * @return bool false = block the operation
     */
    protected function runDynamicHooks(string $hookType, array &$data, int $id = 0): bool
    {
        try {
            $mod   = $this->getModuleName();
            $rules = $this->db->fetchAll(
                "SELECT * FROM ib_sys_hook_rules
                  WHERE module_key = ? AND hook_type = ? AND is_active = 1
                  ORDER BY sort_order ASC",
                [$mod, $hookType]
            );
        } catch (\Throwable $e) {
            // Table may not exist — silently skip
            return true;
        }

        foreach ($rules as $rule) {
            // Evaluate condition (if any)
            if (!empty($rule['condition_field']) && !empty($rule['condition_operator'])) {
                if (!$this->evaluateDynamicCondition($rule, $data)) {
                    continue; // condition not met, skip this rule
                }
            }

            // Execute action
            switch ($rule['action_type']) {
                case 'set_value':
                    if (!empty($rule['action_field'])) {
                        $data[$rule['action_field']] = $this->resolveDynamicValue(
                            $rule['action_value'] ?? ''
                        );
                    }
                    break;

                case 'block':
                    $msg = !empty($rule['action_value'])
                        ? $rule['action_value']
                        : 'Operacio bloquejada per una regla del sistema.';
                    // Store message so it can be retrieved by the caller
                    $this->dynamicBlockMessage = $msg;
                    return false;

                case 'send_notification':
                    // Basic notification: create entry in ib_sys_notifications
                    $this->sendDynamicNotification($rule, $data, $id);
                    break;
            }
        }
        return true;
    }

    /** @var string|null Last block message from a dynamic rule */
    private ?string $dynamicBlockMessage = null;

    /**
     * Evaluate a dynamic condition against current data.
     */
    private function evaluateDynamicCondition(array $rule, array $data): bool
    {
        $field    = $rule['condition_field'];
        $operator = $rule['condition_operator'];
        $expected = $rule['condition_value'] ?? '';
        $actual   = (string)($data[$field] ?? '');

        return match ($operator) {
            'equals'       => $actual === $expected,
            'not_equals'   => $actual !== $expected,
            'contains'     => str_contains($actual, $expected),
            'is_empty'     => $actual === '' || $actual === null,
            'is_not_empty' => $actual !== '' && $actual !== null,
            'greater_than' => is_numeric($actual) && is_numeric($expected) && (float)$actual > (float)$expected,
            'less_than'    => is_numeric($actual) && is_numeric($expected) && (float)$actual < (float)$expected,
            default        => true,
        };
    }

    /**
     * Resolve special value tokens like {{now}} and {{current_user_id}}.
     */
    private function resolveDynamicValue(string $value): string
    {
        $value = str_replace('{{now}}', date('Y-m-d H:i:s'), $value);
        $value = str_replace('{{today}}', date('Y-m-d'), $value);
        $userId = $this->getUserAccount()['id'] ?? 0;
        $value = str_replace('{{current_user_id}}', (string)$userId, $value);
        return $value;
    }

    /**
     * Send a basic system notification for a dynamic rule.
     */
    private function sendDynamicNotification(array $rule, array $data, int $id): void
    {
        try {
            $userId = $this->getUserAccount()['id'] ?? null;
            $msg    = $this->resolveDynamicValue($rule['action_value'] ?? 'Regla executada');
            $mod    = $this->getModuleName();
            $this->db->execute(
                "INSERT INTO ib_sys_notifications (user_id, type, title, message, link, is_read, created_at)
                 VALUES (?, 'hook_rule', ?, ?, ?, 0, NOW())",
                [$userId, 'Regla: ' . ($rule['rule_name'] ?? ''), $msg, "/{$mod}/{$id}"]
            );
        } catch (\Throwable $e) {
            // Ignore notification failures
        }
    }


    /** @return string[] */
    public function getFields(): array
    {
        return $this->fields;
    }

    /** @return string[] */
    public function getEditFields(): array
    {
        return $this->editFields ?: $this->fields;
    }

    public function getOrderBy(): string
    {
        return $this->orderBy ?: $this->key;
    }

    public function getOrderDir(): string
    {
        return $this->orderDir;
    }

    public function getPerPage(): int
    {
        return $this->perPage;
    }

    public function canAdd(): bool    { return $this->canAdd; }
    public function canEdit(): bool   { return $this->canEdit; }
    public function canDelete(): bool { return $this->canDelete; }

    /**
     * Paginated list for API use.
     *
     * @param array<string, mixed> $filters   Field => value filters (exact match)
     * @return array{data: array<int, mixed>, total: int, page: int, per_page: int, pages: int}
     */
    public function apiList(
        int $page = 1,
        int $perPage = 25,
        string $search = '',
        string $orderBy = '',
        string $orderDir = 'ASC',
        array $filters = []
    ): array {
        $qb = $this->db->query()->from($this->table);

        if ($this->baseWhere) {
            $qb->whereRaw($this->baseWhere);
        }

        // Tenant/user scoping — opt-in via $apiScopeByEntity / $apiScopeByUser.
        // See the comment above those properties for the contract.
        // QueryBuilder is immutable: where() returns a clone, so reassign —
        // and whereRaw() takes no bindings, so parameterized predicates must
        // go through where().
        if ($this->apiScopeByEntity !== null) {
            self::assertIdentifier($this->apiScopeByEntity, '$apiScopeByEntity');
            $entityId = $this->userAccount['entity_id'] ?? null;
            // <=> is MySQL's null-safe equality: if both sides are NULL it matches.
            $qb = $qb->where(
                "{$this->apiScopeByEntity} <=> :_api_scope_eid",
                ['_api_scope_eid' => $entityId]
            );
        }
        $acctEntity = $this->userAccount['entity_id'] ?? null;
        if ($this->userScopeApplies($acctEntity === null || $acctEntity === '' ? null : (int) $acctEntity)) {
            self::assertIdentifier($this->apiScopeByUser, '$apiScopeByUser');
            $userId = (int) ($this->userAccount['id'] ?? 0);
            $qb = $qb->where(
                "{$this->apiScopeByUser} = :_api_scope_uid",
                ['_api_scope_uid' => $userId]
            );
        }

        // Search across searchFields. Unique placeholder per field: named
        // placeholders cannot repeat when PDO emulation is off.
        if ($search !== '' && $this->searchFields) {
            $conditions = [];
            $params     = [];
            foreach (array_values($this->searchFields) as $i => $f) {
                self::assertIdentifier($f, '$searchFields');
                $ph = "_api_search{$i}";
                $conditions[] = "{$f} LIKE :{$ph}";
                $params[$ph]  = '%' . $search . '%';
            }
            $qb = $qb->where('(' . implode(' OR ', $conditions) . ')', $params);
        }

        // Field filters
        foreach ($filters as $field => $value) {
            self::assertIdentifier((string) $field, 'apiList filter');
            $placeholder = '_f_' . $field;
            $qb = $qb->where("{$field} = :{$placeholder}", [$placeholder => $value]);
        }

        // Ordenació tolerant: alguns mòduls declaren $orderBy amb la direcció
        // dins la cadena ("sort_order ASC") o el deixen buit — abans l'ORDER BY
        // era un no-op silenciós i aquests valors mai s'havien validat.
        $ob = trim($orderBy !== '' ? $orderBy : $this->getOrderBy());
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s+(ASC|DESC)$/i', $ob, $m)) {
            $ob = $m[1];
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $ob)) {
            $ob = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $this->key) ? $this->key : 'id';
        }
        $qb = $qb->orderBy($ob, $orderDir);

        return $qb->paginate($page, $perPage);
    }

    /**
     * Handle a custom route action (e.g. /app/mod/{id}/print).
     * Calls onCustomAction($action, $id) if the subclass defines it.
     * Falls back to the browser view.
     */
    protected function handleCustomAction(string $action): void
    {
        $id = \Intrabase\Core\Router::currentId();
        if (method_exists($this, 'onCustomAction')) {
            $this->onCustomAction($action, $id);
        } else {
            $this->renderBrowser();
        }
    }

    /**
     * Validate data against $validates and $validators rules.
     *
     * @param  array<string, mixed> $data
     * @return array<string, string> Field => error message (empty if valid)
     */
    public function validateData(array $data): array
    {
        $errors = [];

        foreach ($this->validates as $field => $rules) {
            // Support both flat ['name','email'] and associative ['name' => ['required']]
            if (is_int($field)) {
                $field = $rules;
                $rules = ['required'];
            }
            if (is_string($rules)) {
                $rules = [$rules];
            }

            $value = $data[$field] ?? null;

            if (in_array('required', $rules, true)) {
                if ($value === null || $value === '' || (is_array($value) && count($value) === 0)) {
                    $label = $this->humans[$field] ?? $field;
                    $errors[$field] = __("val_required", $label);
                    continue;
                }
            }

            // Apply inline rules (email, url, etc.) from $validates
            $label = $this->humans[$field] ?? $field;
            foreach ($rules as $rule) {
                if ($rule === 'required') continue;
                if ($rule === 'email' && $value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field] = __("val_email", $label);
                } elseif ($rule === 'url' && $value && !filter_var($value, FILTER_VALIDATE_URL)) {
                    $errors[$field] = __("val_url", $label);
                }
            }
        }

        foreach ($this->validators as $field => $rules) {
            if (!empty($errors[$field])) {
                continue; // Already failed required check
            }
            $value = $data[$field] ?? null;
            $label = $this->humans[$field] ?? $field;

            if (is_string($rules)) {
                $rules = [$rules];
            }

            foreach ($rules as $rule) {
                if ($rule === 'email' && $value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field] = __("val_email", $label);
                } elseif ($rule === 'url' && $value && !filter_var($value, FILTER_VALIDATE_URL)) {
                    $errors[$field] = __("val_url", $label);
                } elseif (str_starts_with($rule, 'max:') && strlen((string)($value ?? '')) > (int)substr($rule, 4)) {
                    $errors[$field] = __("val_max_chars", $label, (int)substr($rule, 4));
                } elseif (str_starts_with($rule, 'min:') && strlen((string)($value ?? '')) < (int)substr($rule, 4)) {
                    $errors[$field] = __("val_min_chars", $label, (int)substr($rule, 4));
                }
            }
        }

        return $errors;
    }
    // =========================================================================
    // Notification helpers
    // =========================================================================

    /**
     * Derive the module key from the table name (strip ib_ prefix).
     * e.g. ib_erp_invoices -> erp_invoices
     */
    protected function moduleKey(): string
    {
        return preg_replace('/^ib_/', '', $this->table);
    }

    /**
     * Trigger NotificationEngine for a module event (non-blocking; errors are logged).
     *
     * @param string $event         record_created|record_updated|status_changed
     * @param array  $record        Full record array after save
     * @param array  $changedFields Keys that changed (for status_changed)
     * @param int    $userId        Acting user ID
     */
    protected function triggerNotifications(
        string $event,
        array  $record,
        array  $changedFields = [],
        int    $userId        = 0,
    ): void {
        try {
            $engine = new NotificationEngine($this->db);
            $engine->trigger($this->moduleKey(), $event, $record, $userId, $changedFields);
        } catch (\Throwable $e) {
            error_log('[Module::triggerNotifications] ' . $e->getMessage());
        }
    }

    /**
     * Reject anything that is not a plain SQL identifier. Prevents callers
     * from sneaking SQL into the $apiScopeBy* properties at subclass time.
     */
    private static function assertIdentifier(string $name, string $label): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $name)) {
            throw new \InvalidArgumentException(
                "{$label} must be a valid SQL identifier, got: '{$name}'"
            );
        }
    }

}