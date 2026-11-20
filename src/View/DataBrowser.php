<?php

declare(strict_types=1);

namespace Intrabase\View;

use Intrabase\Components\ComponentRegistry;
use Intrabase\Core\Module;
use Intrabase\Core\Router;
use Intrabase\Database\QueryBuilder;
use Intrabase\Security\Input;
use Intrabase\Security\Output;

/**
 * DataBrowser — Generic Table/List View
 *
 * Renders a paginated, sortable, searchable HTML table for any Module.
 * All user-supplied search values are properly escaped and parameterized.
 *
 * Features:
 * - Paginated listing with configurable rows/page
 * - Single-field text search (parameterized, safe)
 * - Column sort (ASC/DESC toggle)
 * - Advanced filters panel (date_range, datetime_range, text, select)
 * - Optional bulk actions (delete, export)
 * - Add / Edit / Delete action buttons
 * - Mobile-responsive via CSS classes
 *
 * @package Intrabase\View
 */
class DataBrowser
{
    private Module $module;

    /** @var array<string, mixed> */
    private array $config = [];

    /** @var array<string, array<string, string>> Preloaded combo labels [field => [value => label]] */
    private array $comboLabels = [];

    /** @var array{primary: array, secondary: array} Loaded saved views for this module */
    private array $views = ['primary' => [], 'secondary' => []];

    public function __construct()
    {
        $this->config = [
            'per_page_options' => [10, 25, 50, 100, 200],
        ];
    }

    /**
     * Render the browser for the given module.
     */
    public function render(Module $module): void
    {
        $this->module = $module;
        // La fitxa hi torna amb els mateixos paràmetres (pàgina, vista, filtres…)
        $module->rememberListQuery((string) Router::currentMod());

        // Load saved views for this module
        $this->views = $this->loadViews();

        $fields      = $this->resolveFields();
        $sortBy      = $this->resolveSortField($fields);
        $sortDir     = $this->resolveSortDir();
        $page        = max(1, Input::getInt('page', 1));
        $perPage     = $this->resolvePerPage();
        $search      = $this->searchQuery();
        $searchFields = $this->resolveSearchFields($fields);
        $filters     = $this->resolveFilters();

        // Pre-load combo labels BEFORE fetchData so combo search works on label text
        $this->preloadComboLabels(array_unique(array_merge($fields, $searchFields)));

        $result = $this->fetchData($fields, $sortBy, $sortDir, $page, $perPage, $search, $searchFields, $filters);

        // Per-page hook: the module can resolve icon/flag data for all the rows at once
        $this->module->onRowsLoaded($result['data']);

        $totals       = $this->fetchTotals($search, $searchFields, $filters);
        $this->output($result, $fields, $sortBy, $sortDir, $page, $perPage, $search, $searchFields, $filters, $totals);
    }

    // =========================================================================
    // Data fetching
    // =========================================================================

    /**
     * @return array{data: list<array<string,mixed>>, total: int, pages: int}
     */
    private function fetchData(
        array $fields,
        string $sortBy,
        string $sortDir,
        int $page,
        int $perPage,
        string $search,
        array $searchFields,
        array $filters
    ): array {
        $db  = $this->module->getDb();
        $qb  = $db->query()->from($this->module->table);

        // Select visible columns — always include the primary key for row actions
        $keyField   = $this->module->key;
        $allFields  = in_array($keyField, $fields, true) ? $fields : array_merge([$keyField], $fields);

        // Include extra columns required by components (e.g. usage_bar needs max_field, hosting_plan needs type/description)
        foreach ($fields as $f) {
            $fType = $this->module->components[$f] ?? 'text';
            $fOpts = $this->module->componentOptions[$f] ?? [];
            // Collect any 'extra_fields' declared by the component options
            $extraFields = (array) ($fOpts['extra_fields'] ?? []);
            if ($fType === 'usage_bar' && isset($fOpts['max_field'])) {
                $extraFields[] = $fOpts['max_field'];
            }
            foreach ($extraFields as $ef) {
                if ($ef !== '' && !in_array($ef, $allFields, true)) {
                    $allFields[] = $ef;
                }
            }
        }

        // Separate real columns from virtual fields
        $virtualDefs = $this->module->virtualFields ?? [];
        $realFields = array_filter($allFields, fn($f) => !isset($virtualDefs[$f]));
        $safeFields = $this->sanitizeFieldNames(array_values($realFields));

        // Append virtual field SQL expressions as "(expr) AS alias"
        foreach ($allFields as $f) {
            if (isset($virtualDefs[$f]) && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $f)) {
                $safeFields[] = '(' . $virtualDefs[$f] . ') AS ' . $f;
            }
        }

        if (!empty($safeFields)) {
            $qb = $qb->select($safeFields);
        }

        $qb = $this->applyListConditions($qb, $search, $searchFields, $filters);

        // Ordre del mòdul en SQL mentre l'usuari no tria cap columna (Module::$orderBySql)
        $qb = ($this->module->orderBySql !== '' && Input::get('sortby') === '')
            ? $qb->orderByRaw($this->module->orderBySql)
            : $qb->orderBy($sortBy, $sortDir);

        return $qb->paginate($page, $perPage);
    }

    /**
     * Everything that decides WHICH rows the list shows, in one place: base
     * WHERE, owner scope, saved views, search and advanced filters. fetchData()
     * adds columns, order and pagination on top; currentFilterIds() reuses it
     * so a bulk action on "the whole filter" touches exactly what is listed.
     */
    private function applyListConditions(QueryBuilder $qb, string $search, array $searchFields, array $filters): QueryBuilder
    {
        // Apply base WHERE (developer-controlled, not user input)
        if ($this->module->baseWhere) {
            $qb = $qb->whereRaw($this->module->baseWhere);
        }

        // Restricció de propietat (opt-in via $scopeUiByOwner): sense això el
        // llistat web ensenyava les files de tots els usuaris.
        if ($this->module->scopeUiByOwner) {
            $qb = $this->module->applyScope($qb, '_scope_lst');
        }

        // Apply saved view filters (v1 = primary, v2 = secondary)
        $qb = $this->applyViewFilters($qb);

        // Apply search filter (OR across all searchFields — parameterized, safe)
        if ($search !== '' && !empty($searchFields)) {
            $safeSearch = array_values(array_filter(
                $searchFields,
                fn($f) => (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $f)
            ));
            if (!empty($safeSearch)) {
                $searchBindings = [];
                $orConditions   = [];
                foreach ($safeSearch as $idx => $sf) {
                    // Check if the field has a registered component with custom searchFilter
                    $fieldType = $this->module->components[$sf] ?? 'text';
                    $customSearch = null;
                    if (ComponentRegistry::has($fieldType)) {
                        $component = ComponentRegistry::make($fieldType);
                        $options = $this->module->componentOptions[$sf] ?? [];
                        // Inject combo data into options so ComboComponent can search by label
                        if ($fieldType === 'combo') {
                            if (isset($this->module->combos[$sf]['data'])) {
                                $options['combo_data'] = $this->module->combos[$sf]['data'];
                            } elseif (isset($this->comboLabels[$sf])) {
                                $options['combo_data'] = $this->comboLabels[$sf];
                            }
                        }
                        $customSearch = $component->searchFilter($sf, $search, 'LIKE', $options);
                    }

                    if ($customSearch !== null) {
                        // Component provides custom WHERE — adapt params to named placeholders
                        $customWhere = $customSearch['where'];
                        $customParams = $customSearch['params'] ?? [];
                        $paramIdx = 0;
                        $adaptedWhere = preg_replace_callback('/\?/', function () use (&$paramIdx, $idx, $sf, $customParams, &$searchBindings) {
                            $paramName = "__cs_{$idx}_{$paramIdx}";
                            $searchBindings[$paramName] = $customParams[$paramIdx] ?? '';
                            $paramIdx++;
                            return ":{$paramName}";
                        }, $customWhere);
                        $orConditions[] = $adaptedWhere;
                    } else {
                        // Default LIKE search
                        $paramName = '__search_' . $idx;
                        $orConditions[]            = "`{$sf}` LIKE :{$paramName}";
                        $searchBindings[$paramName] = '%' . $search . '%';
                    }
                }
                $qb = $qb->where('(' . implode(' OR ', $orConditions) . ')', $searchBindings);
            }
        }

        // Apply advanced filters
        $qb = $this->applyFilters($qb, $filters);

        return $qb;
    }

    /**
     * Primary keys of EVERY row of the current list request (ignoring the
     * page). Used by the declarative bulk actions with 'scope' => 'filter'.
     *
     * @return int[]
     */
    public function currentFilterIds(Module $module): array
    {
        $this->module = $module;
        $this->views  = $this->loadViews();
        $fields       = $this->resolveFields();
        $searchFields = $this->resolveSearchFields($fields);
        $filters      = $this->resolveFilters();
        $this->preloadComboLabels(array_unique(array_merge($fields, $searchFields)));

        $key = $this->module->key;
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
            return [];
        }
        $qb  = $this->module->getDb()->query()->from($this->module->table)->select([$key]);
        $qb  = $this->applyListConditions($qb, $this->searchQuery(), $searchFields, $filters);
        $ids = [];
        foreach ($qb->get() as $row) {
            $ids[] = (int) ($row[$key] ?? 0);
        }
        return array_values(array_filter($ids, fn(int $v) => $v > 0));
    }

    /**
     * Apply validated advanced filters to a QueryBuilder instance.
     */
    private function applyFilters(QueryBuilder $qb, array $filters): QueryBuilder
    {
        foreach ($this->module->filterFields as $filterField => $filterType) {
            if (!is_string($filterField) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $filterField)) {
                continue;
            }
            $rawF = $filters[$filterField] ?? [];

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
                    $ph     = '__fls_' . $filterField;
                    $custom = $this->module->filterWhere[$filterField] ?? null;
                    if (is_string($custom) && str_contains($custom, '{v}')) {
                        // Un lloc per aparició: els placeholders no es poden repetir
                        $parts  = explode('{v}', $custom);
                        $sql    = array_shift($parts);
                        $params = [];
                        foreach ($parts as $i => $rest) {
                            $sql .= ":{$ph}_{$i}" . $rest;
                            $params["{$ph}_{$i}"] = $val;
                        }
                        $qb = $qb->where($sql, $params);
                    } else {
                        $qb = $qb->where("`{$filterField}` = :{$ph}", ["{$ph}" => $val]);
                    }
                }
            } elseif ($filterType === 'year') {
                $y = (string) ($rawF['v'] ?? '');
                if (preg_match('/^\d{4}$/', $y)) {
                    $col = self::yearColumn($filterField);
                    $ph  = '__fly_' . $col;
                    $qb = $qb->where("`{$col}` >= :{$ph}_a AND `{$col}` < :{$ph}_b", ["{$ph}_a" => $y . '-01-01', "{$ph}_b" => ((int) $y + 1) . '-01-01']);
                }
            }
        }

        return $qb;
    }

    // =========================================================================
    // HTML Output
    // =========================================================================

    private function output(
        array $result,
        array $fields,
        string $sortBy,
        string $sortDir,
        int $page,
        int $perPage,
        string $search,
        array $searchFields,
        array $filters,
        array $totals = []
    ): void {
        $total = $result['total'];
        $pages = $result['pages'];
        $data  = $result['data'];
        $mod   = \Intrabase\Core\Router::currentMod();
        $csrf  = $this->module->getCsrf();

        // Resolve base URL for this module (structured route or legacy query string)
        $baseUrl    = Output::e(Router::url($mod));
        $createUrl  = Output::e(Router::createUrl($mod));

        // Determine if we're using structured routes (non-legacy URL)
        $isStructured = str_starts_with(Router::url($mod), '/') && !str_starts_with(Router::url($mod), '/?');

        echo '<div class="ib-browser">';
        $bulkDone = Input::getInt('bulk_done', -1);
        if ($bulkDone >= 0) {
            echo '<div class="ib-alert ib-alert--info" role="status">'
               . Output::e(__('updated')) . ': ' . number_format($bulkDone, 0, ',', '.') . ' ' . Output::e(__('records'))
               . '</div>';
        }

        // Selection checkboxes: for the built-in delete and/or the declarative bulk actions
        $bulkDefs = ($this->module->canEdit && is_array($this->module->bulkActions)) ? $this->module->bulkActions : [];
        $canMulti = $this->module->canMultiselect && ($this->module->canDelete || $bulkDefs !== []);
        $backUrl  = (string) ($_SERVER['REQUEST_URI'] ?? Router::url($mod));
        $canReorder = $this->module->canReorder;

        // Columnes de sobretaula ($desktopFields, classe "desk") i essencials ($mobileFields,
        // classe "mob"; si no n'hi ha, les de sobretaula). Per defecte només es veuen les "desk";
        // si desborden, el JS passa a "mob"; totes només amb el commutador «Totes les columnes».
        $deskFields = array_values(array_filter((array) $this->module->desktopFields, 'is_string'));
        $mobFields  = array_values(array_filter((array) $this->module->mobileFields, 'is_string')) ?: $deskFields;
        $useDesk    = $deskFields !== [];
        $deskClass  = static function (string $field) use ($useDesk, $deskFields, $mobFields): string {
            if (!$useDesk) {
                return ' desk mob';
            }
            return (in_array($field, $deskFields, true) ? ' desk' : '') . (in_array($field, $mobFields, true) ? ' mob' : '');
        };

        // ---- Toolbar ----
        echo '<div class="ib-browser__toolbar">';

        // Left: Title
        echo '<div class="ib-browser__toolbar-left">';
        echo '<h2 class="ib-browser__title">';
        if ($this->module->icon) {
            echo '<i class="' . Output::e($this->module->icon) . '" aria-hidden="true"></i> ';
        }
        echo Output::e(__($this->module->title));
        echo '</h2>';
        echo '</div>';

        // Center: Search (també a la franja superior de l'app: Layout::offerTopSearch)
        ob_start();
        echo '<div class="ib-browser__toolbar-center">';
        if ($isStructured) {
            echo '<form class="ib-browser__search" method="get" action="' . $baseUrl . '">';
        } else {
            echo '<form class="ib-browser__search" method="get">';
            echo '<input type="hidden" name="mod" value="' . Output::attr($mod) . '">';
        }
        if ($perPage !== $this->module->perPage) {
            echo '<input type="hidden" name="per_page" value="' . $perPage . '">';
        }
        // Preserve view selections
        $v1 = $this->viewId('v1');
        $v2 = $this->viewId('v2');
        if ($v1 > 0 || $this->viewExplicit('v1')) {
            echo '<input type="hidden" name="v1" value="' . $v1 . '">';
        }
        if ($v2 > 0 || $this->viewExplicit('v2')) {
            echo '<input type="hidden" name="v2" value="' . $v2 . '">';
        }
        foreach ($filters as $fField => $fVal) {
            if (is_array($fVal)) {
                foreach ($fVal as $k => $v) {
                    if ($v !== '') {
                        echo '<input type="hidden" name="f[' . Output::attr($fField) . '][' . Output::attr($k) . ']" value="' . Output::attr($v) . '">';
                    }
                }
            }
        }
        echo '<i class="fa-solid fa-magnifying-glass ib-browser__search-icon" aria-hidden="true"></i>';
        echo '<input type="text" name="q" value="' . Output::attr($search) . '" '
           . 'placeholder="' . __('search_ph') . '" class="ib-input ib-input--search" autocomplete="off">';
        if ($search !== '') {
            if ($isStructured) {
                // q= buit explícit: sense, tornaria la cerca recordada a la sessió
                $clearParams = http_build_query(array_filter(['v1' => $v1 ?: null, 'v2' => $v2 ?: null, 'f' => $filters ?: null]) + ['q' => '']);
                $clearUrl    = $baseUrl . '?' . $clearParams;
            } else {
                $clearParams = http_build_query(array_filter(['mod' => $mod, 'v1' => $v1 ?: null, 'v2' => $v2 ?: null, 'f' => $filters ?: null]) + ['q' => '']);
                $clearUrl    = '?' . $clearParams;
            }
            echo '<a href="' . Output::attr($clearUrl) . '" class="ib-btn ib-btn--icon ib-btn--clear">'
               . '<i class="fa-solid fa-xmark" aria-hidden="true"></i></a>';
        }
        // Els filtres viuen DINS del cercador, a la dreta, i obren el panell
        // en un modal centrat (04/09/2026). Abans eren un <details> desplegable
        // sota la barra d'eines.
        if (!empty($this->module->filterFields)) {
            $activeF = $this->countActiveFilters($filters);
            echo '<button type="button" class="ib-browser__search-filters" data-ib-filters-open'
               . ' title="' . Output::attr(__('filters')) . '"'
               . ' aria-label="' . Output::attr(__('filters')) . '">'
               . '<i class="fa-solid fa-sliders" aria-hidden="true"></i>'
               . ($activeF > 0 ? '<span class="ib-filter__badge">' . $activeF . '</span>' : '')
               . '</button>';
        }
        echo '</form>';
        echo '</div>';
        $centerHtml = (string) ob_get_clean();
        echo $centerHtml;
        \Intrabase\View\Layout::offerTopSearch($centerHtml);

        // Vistes i filtres: van a la PRIMERA fila, amb el titol i el cercador,
        // no barrejats amb els botons (04/09/2026).
        // Sempre s'escriu, encara que el modul no tingui vistes: es el
        // contrapes que manté el cercador centrat a la seva fila.
        $viewSelector = $this->renderViewSelector($mod, $isStructured, $baseUrl);
        echo '<div class="ib-browser__toolbar-filters">' . $viewSelector . '</div>';
        \Intrabase\View\Layout::offerTopFilters($viewSelector);

        // Segona fila: botons d'accio.
        echo '<div class="ib-browser__toolbar-right">';
        if ($this->module->canAdd) {
            echo '<a href="' . $createUrl . '" class="ib-btn ib-btn--primary ib-browser__add">'
               . '<i class="fa-solid fa-plus" aria-hidden="true"></i> ' . __('add') . '</a>';
        }
        if ($this->module->canExport) {
            if ($isStructured) {
                $csvParams = http_build_query(array_filter([
                    'q'       => $search,
                    'sortby'  => $sortBy,
                    'sortdir' => $sortDir,
                    'v1'      => $v1 ?: null,
                    'v2'      => $v2 ?: null,
                    'f'       => $filters ?: null,
                ]));
                $csvBase = Router::actionUrl($mod, 'csv');
                $csvUrl  = $csvParams ? $csvBase . '?' . $csvParams : $csvBase;
            } else {
                $csvParams = http_build_query(array_filter([
                    'mod'     => $mod,
                    '_'       => 'csv',
                    'q'       => $search,
                    'sortby'  => $sortBy,
                    'sortdir' => $sortDir,
                    'v1'      => $v1 ?: null,
                    'v2'      => $v2 ?: null,
                    'f'       => $filters ?: null,
                ]));
                $csvUrl = '?' . $csvParams;
            }
            echo '<a href="' . Output::attr($csvUrl) . '" class="ib-btn ib-btn--secondary" title="' . __('export_csv') . '">'
               . '<i class="fa-solid fa-file-csv" aria-hidden="true"></i> ' . __('export_csv') . '</a>';
        }
        if ($this->module->toolbarExtra !== '') {
            echo $this->module->toolbarExtra;
        }
        // El commutador de columnes va a la SEGONA fila, a l'esquerra: es
        // guarda aqui i s'escriu fora del bloc de botons (el flex order nomes
        // val entre germans del mateix contenidor).
        $allColsHtml = '';
        if ($useDesk) {
            // Nomes la icona: el text queda al title i a un <span> per als
            // lectors de pantalla. La casella segueix existint (amagada) perque
            // es la que escolta el JS de columnes.
            $allColsHtml = '<label class="ib-browser__allcols" title="' . Output::attr(__('all_columns')) . '">'
               . '<input type="checkbox" class="ib-allcols-toggle" data-mod="' . Output::attr($mod) . '">'
               . '<i class="fa-solid fa-table-columns" aria-hidden="true"></i>'
               . '<span class="ib-browser__allcols-text">' . Output::e(__('all_columns')) . '</span>'
               . '</label>';
            // També a la franja superior, al costat de la configuració: el JS agafa el
            // primer .ib-allcols-toggle de la pàgina, que és el de la franja
            \Intrabase\View\Layout::offerTopTools($allColsHtml);
        }
        if ($canMulti) {
            $bulkPostUrl = $isStructured ? Router::url($mod) : '?mod=' . urlencode($mod);
            $hidden = static fn(string $n, string $v): string =>
                '<input type="hidden" name="' . $n . '" value="' . Output::attr($v) . '">';
            // Bulk bar: only visible while rows are selected (JS below the table).
            echo '<span id="ib-bulk-bar" style="display:none">';
            if ($this->module->canDelete) {
                echo '<form method="post" action="' . Output::attr($bulkPostUrl) . '" id="ib-bulk-form" style="display:inline">';
                echo $hidden('mod', $mod) . $hidden('_', 'bdl');
                echo '<input type="hidden" name="ids" class="ib-bulk-ids" id="ib-bulk-ids" value="">';
                echo $csrf->field();
                echo '<button type="submit" class="ib-btn ib-btn--danger ib-btn--confirm" '
                   . 'data-confirm="' . __('confirm_bulk_delete') . '">'
                   . '<i class="fa-solid fa-trash" aria-hidden="true"></i> '
                   . '<span class="ib-bulk-count" id="ib-bulk-count"></span> ' . __('delete') . '</button>';
                echo '</form>';
            }
            foreach ($bulkDefs as $name => $def) {
                if (!is_string($name) || !preg_match('/^[a-zA-Z0-9_]+$/', $name) || !is_array($def)) {
                    continue;
                }
                $label   = (string) ($def['label'] ?? $name);
                $icon    = (string) ($def['icon'] ?? '');
                $cls     = (string) ($def['class'] ?? 'ib-btn--secondary');
                $confirm = (string) ($def['confirm'] ?? ($label . ' (%d)?'));
                echo '<form method="post" action="' . Output::attr($bulkPostUrl) . '" class="ib-bulk-action" style="display:inline">';
                echo $hidden('mod', $mod) . $hidden('_', 'bulk') . $hidden('bulk_action', $name)
                   . $hidden('mode', 'selection') . $hidden('_back', $backUrl);
                echo '<input type="hidden" name="ids" class="ib-bulk-ids" value="">';
                echo $csrf->field();
                echo '<button type="submit" class="ib-btn ' . Output::attr($cls) . ' ib-btn--confirm" '
                   . 'data-confirm-tpl="' . Output::attr($confirm) . '" data-confirm="' . Output::attr(str_replace('%d', '', $confirm)) . '">'
                   . ($icon !== '' ? '<i class="fa-solid ' . Output::attr($icon) . '" aria-hidden="true"></i> ' : '')
                   . '<span class="ib-bulk-count"></span> ' . Output::e($label) . '</button>';
                echo '</form>';
            }
            echo '</span>';
        }
        // Bulk actions on the WHOLE current filter ('scope' => 'filter'): always visible.
        if ($bulkDefs !== [] && $total > 0) {
            $bulkPostUrl = $isStructured ? Router::url($mod) : '?mod=' . urlencode($mod);
            $hidden = static fn(string $n, string $v): string =>
                '<input type="hidden" name="' . $n . '" value="' . Output::attr($v) . '">';
            $totalFmt = number_format($total, 0, ',', '.');
            foreach ($bulkDefs as $name => $def) {
                if (!is_string($name) || !preg_match('/^[a-zA-Z0-9_]+$/', $name) || !is_array($def)
                    || ($def['scope'] ?? 'selection') !== 'filter') {
                    continue;
                }
                $label   = (string) ($def['label'] ?? $name);
                $icon    = (string) ($def['icon'] ?? '');
                $confirm = (string) ($def['confirm'] ?? ($label . ' (%d)?'));
                echo '<form method="post" action="' . Output::attr($bulkPostUrl) . '" class="ib-bulk-filter" style="display:inline">';
                echo $hidden('mod', $mod) . $hidden('_', 'bulk') . $hidden('bulk_action', $name)
                   . $hidden('mode', 'filter') . $hidden('_back', $backUrl);
                echo $csrf->field();
                echo '<button type="submit" class="ib-btn ib-btn--secondary ib-btn--confirm" '
                   . 'title="' . Output::attr($label . ': ' . __('all')) . '" '
                   . 'data-confirm="' . Output::attr(str_replace('%d', $totalFmt, $confirm)) . '">'
                   . ($icon !== '' ? '<i class="fa-solid ' . Output::attr($icon) . '" aria-hidden="true"></i> ' : '')
                   . Output::e($label) . ': ' . Output::e(__('all')) . ' (' . $totalFmt . ')</button>';
                echo '</form>';
            }
        }
        echo '</div>'; // toolbar-right

        if ($allColsHtml !== '') {
            echo '<div class="ib-browser__toolbar-allcols">' . $allColsHtml . '</div>';
        }

        echo '</div>'; // toolbar

        // ---- Advanced Filters Panel ----
        if (!empty($this->module->filterFields)) {
            echo $this->renderFilterPanel($mod, $filters, $search, $sortBy, $sortDir, $perPage);
        }

        // ---- Table ----
        echo '<div class="ib-browser__table-wrap' . ($useDesk ? ' ib-cols-desk' : '') . '">';
        $reorderAttr = '';
        if ($canReorder) {
            // Calculate the column index of the reorder field (for updating displayed values after drag)
            $reorderColIdx = array_search($this->module->reorderField, $fields);
            $reorderColOffset = ($canReorder ? 1 : 0) + ($canMulti ? 1 : 0); // grab + checkbox columns before data
            $reorderAttr = ' data-reorder="1"';
            if ($reorderColIdx !== false) {
                $reorderAttr .= ' data-reorder-col="' . ($reorderColIdx + $reorderColOffset) . '"';
            }
        }
        $deskAttr = $useDesk ? ' data-desk-cols="1"' : '';
        // Llistats que no es pleguen a les columnes de mòbil: scroll horitzontal
        if ($useDesk && !empty($this->module->keepDesktopColumns)) {
            $deskAttr .= ' data-keep-desk="1"';
        }
        echo '<table class="ib-table" data-module="' . Output::attr($mod) . '"' . $reorderAttr . $deskAttr . '>';

        // Header
        echo '<thead><tr>';
        if ($canReorder) {
            echo '<th class="ib-table__th ib-table__th--reorder desk mob" style="width:36px"></th>';
        }
        if ($canMulti) {
            echo '<th class="ib-table__th ib-table__th--check desk mob" style="width:36px">'
               . '<input type="checkbox" id="ib-select-all" title="' . __('select_all') . '">'
               . '</th>';
        }
        foreach ($fields as $field) {
            $fullLabel = __($this->module->humans[$field] ?? ucwords(str_replace('_', ' ', $field)));
            $label     = isset($this->module->abbrev[$field]) ? __($this->module->abbrev[$field]) : $fullLabel;
            // Una abreviatura no s'endevina («RO ID», «M.P.», «!»): el nom
            // sencer va al title, que es el que surt en passar-hi per sobre.
            $thTitle   = ($label !== $fullLabel && $fullLabel !== '')
                       ? ' title="' . Output::attr($fullLabel) . '"'
                       : '';
            $isSorted  = $field === $sortBy;
            $nextDir   = ($isSorted && $sortDir === 'ASC') ? 'DESC' : 'ASC';
            $sortIcon  = $isSorted ? ($sortDir === 'ASC' ? 'fa-solid fa-sort-up' : 'fa-solid fa-sort-down') : 'fa-solid fa-sort';
            if ($isStructured) {
                $sortParams = http_build_query(array_filter([
                    'sortby'   => $field,
                    'sortdir'  => $nextDir,
                    'page'     => 1,
                    'q'        => $search,
                    'per_page' => $perPage !== $this->module->perPage ? $perPage : null,
                    'v1'       => $v1 ?: null,
                    'v2'       => $v2 ?: null,
                    'f'        => $filters ?: null,
                ]));
                $sortHref = $baseUrl . '?' . $sortParams;
            } else {
                $sortParams = http_build_query(array_filter([
                    'mod'      => $mod,
                    'sortby'   => $field,
                    'sortdir'  => $nextDir,
                    'page'     => 1,
                    'q'        => $search,
                    'per_page' => $perPage !== $this->module->perPage ? $perPage : null,
                    'v1'       => $v1 ?: null,
                    'v2'       => $v2 ?: null,
                    'f'        => $filters ?: null,
                ]));
                $sortHref = '?' . $sortParams;
            }
            $nowrap = in_array($field, $this->module->noWrap ?? [], true) ? ' ib-table__th--nowrap' : '';
            $fType  = $this->module->components[$field] ?? 'text';
            $thAlign = in_array($fType, ['money', 'decimal', 'currency', 'percentage', 'integer', 'number'], true) ? ' ib-table__th--right' : '';
            $sortable = $this->module->canSort ?? true;
            $thStyle = isset($this->module->styles[$field]) ? ' style="' . Output::attr((string) $this->module->styles[$field]) . '"' : '';
            $thDesk  = $deskClass($field);
            echo "<th class=\"ib-table__th{$nowrap}{$thAlign}{$thDesk}\"{$thStyle}{$thTitle}>";
            if ($sortable) {
                echo "<a href=\"" . Output::attr($sortHref) . "\" class=\"ib-table__sort-link\">";
                echo Output::e($label);
                echo " <i class=\"{$sortIcon}\" aria-hidden=\"true\"></i>";
                echo '</a>';
            } else {
                echo "<span class=\"ib-table__sort-label\">" . Output::e($label) . '</span>';
            }
            echo '</th>';
        }
        // Per-row custom actions column (customRowActions; per-row visibility via rowActionVisible())
        $rowActions = array_values(array_filter((array) $this->module->customRowActions, fn($a) => !empty($a['action'])));
        if ($rowActions !== []) {
            echo '<th class="ib-table__th ib-table__th--actions ib-table__th--nowrap desk mob"><span class="ib-table__sort-label">' . Output::e(__('actions')) . '</span></th>';
        }
        echo '</tr></thead>';

        // Body
        echo '<tbody>';
        if (empty($data)) {
            $colCount   = count($fields) + ($canMulti ? 1 : 0) + ($canReorder ? 1 : 0) + ($rowActions !== [] ? 1 : 0);
            $emptyIcon  = $this->module->icon ?: 'fa-solid fa-inbox';
            $emptyMsg   = Output::e(__('no_records'));
            $ctaHtml    = '';
            if ($this->module->canAdd) {
                $ctaHtml = '<a href="' . Output::attr($createUrl) . '" class="ib-btn ib-btn--primary ib-btn--sm">'
                         . '<i class="fa-solid fa-plus" aria-hidden="true"></i> ' . Output::e(__('add')) . '</a>';
            }
            echo '<tr><td colspan="' . $colCount . '" class="ib-table__empty">';
            echo '<div class="ib-empty-state">';
            echo '<i class="' . Output::e($emptyIcon) . '" aria-hidden="true"></i>';
            echo '<p>' . $emptyMsg . '</p>';
            if ($ctaHtml) { echo $ctaHtml; }
            echo '</div>';
            echo '</td></tr>';
        } else {
            foreach ($data as $row) {
                $id = (int) ($row[$this->module->key] ?? 0);
                $editUrl = Router::editUrl($mod, $id);
                if ($search !== '') {
                    $editUrl .= (str_contains($editUrl, '?') ? '&' : '?') . '_q=' . urlencode($search);
                }
                $rowHref = Output::attr($editUrl);
                $extraRowClass = trim($this->module->rowClass($row));
                echo '<tr class="ib-table__row' . ($canReorder ? ' ib-table__row--reorderable' : '')
                   . ($extraRowClass !== '' ? ' ' . Output::attr($extraRowClass) : '')
                   . '" data-id="' . $id . '" data-href="' . $rowHref . '">';
                if ($canReorder) {
                    echo '<td class="ib-table__td ib-table__td--reorder desk mob">'
                       . '<i class="fa-solid fa-grip-vertical ib-reorder-handle" aria-hidden="true"></i>'
                       . '</td>';
                }
                if ($canMulti) {
                    echo '<td class="ib-table__td ib-table__td--check desk mob">'
                       . '<input type="checkbox" class="ib-row-check" value="' . $id . '">'
                       . '</td>';
                }

                foreach ($fields as $field) {
                    $rawValue = $row[$field] ?? '';
                    $display  = $this->renderCell($field, $rawValue, $row);
                    $nowrap   = in_array($field, $this->module->noWrap ?? [], true) ? ' ib-table__td--nowrap' : '';
                    $fType    = $this->module->components[$field] ?? 'text';
                    $align    = in_array($fType, ['money', 'decimal', 'currency', 'percentage', 'integer', 'number'], true) ? ' ib-table__td--right' : '';
                    $tdStyle  = isset($this->module->styles[$field]) ? ' style="' . Output::attr((string) $this->module->styles[$field]) . '"' : '';
                    $tdDesk   = $deskClass($field);
                    echo "<td class=\"ib-table__td{$nowrap}{$align}{$tdDesk}\"{$tdStyle}>{$display}</td>";
                }

                // Custom per-row actions (view/edit/delete stay in the detail view)
                if ($rowActions !== []) {
                    echo '<td class="ib-table__td ib-table__td--actions ib-table__td--nowrap desk mob">';
                    foreach ($rowActions as $ra) {
                        $raAction = (string) $ra['action'];
                        if (!$this->module->rowActionVisible($raAction, $row)) {
                            continue;
                        }
                        $raLabel   = Output::e(__((string) ($ra['label'] ?? $raAction)));
                        $raIcon    = Output::e((string) ($ra['icon'] ?? 'fa-solid fa-bolt'));
                        $raClass   = Output::e((string) ($ra['class'] ?? 'ib-btn--ghost'));
                        $raConfirm = (string) ($ra['confirm'] ?? '');
                        $raUrl     = Router::actionUrl($mod, $raAction, $id, Router::currentApp());
                        if (($ra['method'] ?? 'get') === 'post') {
                            echo '<form method="post" action="' . Output::attr($raUrl) . '" class="ib-row-action-form" style="display:inline;margin:0;">';
                            echo '<input type="hidden" name="mod" value="' . Output::attr($mod) . '">';
                            echo '<input type="hidden" name="_" value="' . Output::attr($raAction) . '">';
                            echo '<input type="hidden" name="id" value="' . $id . '">';
                            echo $csrf->field();
                            echo '<button type="submit" class="ib-btn ib-btn--icon ib-btn--sm ' . $raClass . ($raConfirm !== '' ? ' ib-btn--confirm' : '') . '"'
                               . ($raConfirm !== '' ? ' data-confirm="' . Output::attr(__($raConfirm)) . '"' : '')
                               . ' title="' . $raLabel . '" aria-label="' . $raLabel . '">'
                               . '<i class="' . $raIcon . '" aria-hidden="true"></i></button>';
                            echo '</form>';
                        } else {
                            $raTarget = ($ra['target'] ?? '') === '_blank' ? ' target="_blank"' : '';
                            echo '<a href="' . Output::attr($raUrl) . '" class="ib-btn ib-btn--icon ib-btn--sm ' . $raClass . '"'
                               . $raTarget . ' title="' . $raLabel . '" aria-label="' . $raLabel . '">'
                               . '<i class="' . $raIcon . '" aria-hidden="true"></i></a>';
                        }
                    }
                    echo '</td>';
                }

                echo '</tr>';
            }
        echo '</tbody>';
        }
        // Totals footer row (shown when module has $totalsFields and there is data)
        if (!empty($totals) && !empty($data)) {
            echo '<tfoot>';
            echo '<tr class="ib-table__foot-row">';
            if ($canReorder) {
                echo '<td class="ib-table__td desk mob"></td>';
            }
            if ($canMulti) {
                echo '<td class="ib-table__td desk mob"></td>';
            }
            $firstTotCol = true;
            foreach ($fields as $totField) {
                $tdDesk = $deskClass($totField);
                if ($firstTotCol) {
                    echo '<td class="ib-table__td ib-table__td--total-label' . $tdDesk . '"><strong>' . __("total") . '</strong></td>';
                    $firstTotCol = false;
                } elseif (isset($totals[$totField])) {
                    echo '<td class="ib-table__td ib-table__td--total' . $tdDesk . '"><strong>' . Output::money((float)$totals[$totField]) . '</strong></td>';
                } else {
                    echo '<td class="ib-table__td' . $tdDesk . '"></td>';
                }
            }
            if ($rowActions !== []) {
                echo '<td class="ib-table__td desk mob"></td>';
            }
            echo '</tr></tfoot>';
        }
        echo '</table>';

        if ($canMulti) {
            echo <<<'JS'
            <script>
            (function(){
                var all=document.getElementById('ib-select-all'),
                    bar=document.getElementById('ib-bulk-bar');
                if(!all||!bar)return;
                function cbs(){return document.querySelectorAll('.ib-row-check');}
                function sync(){
                    var c=cbs(),sel=[];
                    c.forEach(function(cb){if(cb.checked)sel.push(cb.value);});
                    var n=sel.length;
                    bar.style.display=n===0?'none':'inline';
                    bar.querySelectorAll('.ib-bulk-count').forEach(function(el){el.textContent=n;});
                    bar.querySelectorAll('.ib-bulk-ids').forEach(function(el){el.value=sel.join(',');});
                    bar.querySelectorAll('[data-confirm-tpl]').forEach(function(b){
                        b.setAttribute('data-confirm',b.getAttribute('data-confirm-tpl').replace('%d',n));
                    });
                    var allOn=n===c.length&&n>0;
                    all.checked=allOn;
                    all.indeterminate=n>0&&!allOn;
                }
                all.addEventListener('change',function(){
                    cbs().forEach(function(cb){cb.checked=all.checked;});
                    sync();
                });
                document.addEventListener('change',function(e){
                    if(e.target.classList.contains('ib-row-check'))sync();
                });
            })();
            </script>
JS;
        }

        // ---- Drag & Drop Reorder ----
        if ($canReorder && !empty($data)) {
            echo '<style>'
               . '.ib-table__td--reorder, .ib-table__th--reorder { width: 36px; text-align: center; padding: 0 4px; }'
               . '.ib-reorder-handle { color: var(--ib-text-muted); font-size: 0.875rem; cursor: grab; padding: 8px 4px; }'
               . '.ib-reorder-handle:hover { color: var(--ib-text); }'
               . '.ib-table__row--dragging { opacity: 0.5; background: var(--ib-primary-light) !important; }'
               . '.ib-table__row--dragging .ib-table__td { background: var(--ib-primary-light) !important; }'
               . '.ib-table__row--placeholder td { height: 3px; padding: 0; background: var(--ib-primary); border: none; }'
               . '</style>';
            $reorderUrl = Output::attr(Router::url($mod));
            $reorderOffset = ($page - 1) * $perPage;
            echo <<<JS
            <script>
            document.addEventListener('DOMContentLoaded', function(){
            (function(){
                var table = document.querySelector('.ib-table[data-reorder="1"]');
                if (!table) return;
                var tbody = table.querySelector('tbody');
                if (!tbody) return;
                var pageOffset = {$reorderOffset};
                var reorderCol = table.getAttribute('data-reorder-col');
                var reorderColIdx = reorderCol !== null ? parseInt(reorderCol, 10) : -1;

                ibDragReorder({
                    scope: tbody,
                    container: tbody,
                    handleSelector: '.ib-reorder-handle',
                    itemSelector: '.ib-table__row',
                    draggingClass: 'ib-table__row--dragging',
                    onCreatePlaceholder: function(dragItem) {
                        var tr = document.createElement('tr');
                        tr.className = 'ib-table__row--placeholder';
                        var td = document.createElement('td');
                        td.setAttribute('colspan', dragItem.querySelectorAll('td').length);
                        tr.appendChild(td);
                        return tr;
                    },
                    onReorder: function() {
                        if (reorderColIdx >= 0) {
                            tbody.querySelectorAll('.ib-table__row').forEach(function(row, idx) {
                                var cells = row.querySelectorAll('td');
                                if (cells[reorderColIdx]) cells[reorderColIdx].textContent = pageOffset + idx;
                            });
                        }
                        var ids = [];
                        tbody.querySelectorAll('.ib-table__row').forEach(function(r) {
                            var id = r.getAttribute('data-id');
                            if (id) ids.push(parseInt(id, 10));
                        });
                        fetch('{$reorderUrl}', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': ibGetCsrfToken() },
                            body: JSON.stringify({ _: 'reorder', ids: ids, offset: pageOffset })
                        }).catch(function(err) { console.error('Reorder error:', err); });
                    }
                });
            })();
            });
            </script>
JS;
        }

        echo '</div>'; // table-wrap

        // ---- Pagination & Record Count ----
        // Per-page selector (rendered in the footer area)
        $perPageHtml = $this->renderPerPage($perPage, $mod, $search, $sortBy, $sortDir, $filters);

        if ($pages > 1) {
            echo $this->renderPagination($page, $pages, $total, $perPage, $mod, $search, $sortBy, $sortDir, $filters, $perPageHtml);
        } elseif ($total > 0) {
            $rfrom = 1;
            $rto   = $total;
            $rinfo = __('showing_range') . ' ' . $rfrom . '-' . $rto . ' ' . __('of') . ' ' . $total;
            echo '<div class="ib-browser__count">' . Output::e($rinfo) . ' ' . $perPageHtml . '</div>';
        }

        // Custom CSS injection
        if ($this->module->customCss !== '') {
            echo '<style>' . $this->module->customCss . '</style>';
        }

        // onDocumentReady injection
        if ($this->module->onDocumentReady !== '') {
            echo "<script>document.addEventListener('DOMContentLoaded', function() {\n"
                 . $this->module->onDocumentReady . "\n});</script>";
        }

        echo '</div>'; // ib-browser
    }

    /**
     * Render the collapsible advanced filter panel.
     */
    private function renderFilterPanel(
        string $mod,
        array $filters,
        string $search,
        string $sortBy,
        string $sortDir,
        int $perPage = 25
    ): string {
        // Count active filters for the badge
        $activeCount = $this->countActiveFilters($filters);

        // El panell viu amagat al DOM i el JS el presta a IbModal quan es clica
        // la icona del cercador; en tancar torna aqui. Aixi el formulari conserva
        // els seus camps, els seus hidden i les seves accions.
        $html  = '<div class="ib-browser__filters" id="ib-filters-panel" hidden'
               . ' data-active-filters="' . $activeCount . '">';

        $filterBaseUrl = Router::url($mod);
        $filterIsStructured = str_starts_with($filterBaseUrl, '/') && !str_starts_with($filterBaseUrl, '/?');
        $filterAction  = $filterIsStructured ? $filterBaseUrl : '';
        if ($filterAction !== '') {
            $html .= '<form class="ib-filters__form" method="get" action="' . Output::attr($filterAction) . '">';
        } else {
            $html .= '<form class="ib-filters__form" method="get">';
            $html .= '<input type="hidden" name="mod" value="' . Output::attr($mod) . '">';
        }
        $html .= '<input type="hidden" name="q" value="' . Output::attr($search) . '">';
        $html .= '<input type="hidden" name="sortby" value="' . Output::attr($sortBy) . '">';
        $html .= '<input type="hidden" name="sortdir" value="' . Output::attr($sortDir) . '">';
        $html .= '<input type="hidden" name="page" value="1">';
        if ($perPage !== $this->module->perPage) {
            $html .= '<input type="hidden" name="per_page" value="' . $perPage . '">';
        }
        // Preserve view selections
        $fv1 = $this->viewId('v1');
        $fv2 = $this->viewId('v2');
        if ($fv1 > 0 || $this->viewExplicit('v1')) {
            $html .= '<input type="hidden" name="v1" value="' . $fv1 . '">';
        }
        if ($fv2 > 0 || $this->viewExplicit('v2')) {
            $html .= '<input type="hidden" name="v2" value="' . $fv2 . '">';
        }

        $html .= '<div class="ib-filters__fields">';

        foreach ($this->module->filterFields as $filterField => $filterType) {
            if (!is_string($filterField) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $filterField)) {
                continue;
            }
            $label    = $filterType === 'year' && !isset($this->module->humans[$filterField])
                ? __('filter_year')
                : __($this->module->humans[$filterField] ?? ucwords(str_replace('_', ' ', $filterField)));
            $rawF     = $filters[$filterField] ?? [];
            $isActive = $this->isFilterActive($filterField, $rawF, $filterType);
            $activeClass = $isActive ? ' ib-filter__field--active' : '';

            $html .= "<div class=\"ib-filter__field{$activeClass}\">";
            $html .= '<span class="ib-filter__label">' . Output::e($label) . '</span>';

            $fname = 'f[' . Output::attr($filterField) . ']';

            if ($filterType === 'date_range' || $filterType === 'datetime_range') {
                $from = Output::attr((string) ($rawF['from'] ?? ''));
                $to   = Output::attr((string) ($rawF['to']   ?? ''));
                $html .= '<div class="ib-filter__range">';
                $html .= '<input type="date" name="' . $fname . '[from]" value="' . $from . '" class="ib-input ib-input--date" placeholder="' . __('from') . '">';
                $html .= '<span class="ib-filter__range-sep">–</span>';
                $html .= '<input type="date" name="' . $fname . '[to]" value="' . $to . '" class="ib-input ib-input--date" placeholder="' . __('to') . '">';
                $html .= '</div>';
            } elseif ($filterType === 'text') {
                $val  = Output::attr((string) ($rawF['v'] ?? ''));
                $html .= '<input type="text" name="' . $fname . '[v]" value="' . $val . '" class="ib-input" placeholder="' . __('filter_ph') . '">';
            } elseif ($filterType === 'select') {
                $selected = (string) ($rawF['v'] ?? '');
                $options  = $this->module->filterOptions[$filterField] ?? [];
                $html .= '<select name="' . $fname . '[v]" class="ib-input">';
                $html .= '<option value="">' . __('all_option') . '</option>';
                foreach ($options as $optVal => $optLabel) {
                    $sel  = $selected === (string) $optVal ? ' selected' : '';
                    $html .= '<option value="' . Output::attr((string) $optVal) . '"' . $sel . '>'
                           . Output::e(is_array($optLabel) ? '' : __((string) $optLabel)) . '</option>';
                }
                $html .= '</select>';
            } elseif ($filterType === 'year') {
                // Any d'un camp de data: opcions de filterOptions (anys amb dades, del mòdul) o els últims 10.
                $selected = (string) ($rawF['v'] ?? '');
                $years = $this->module->filterOptions[$filterField] ?? [];
                if ($years === []) {
                    $cy = (int) date('Y');
                    $years = range($cy, $cy - 9);
                }
                $html .= '<select name="' . $fname . '[v]" class="ib-input">';
                $html .= '<option value="">' . __('all_option') . '</option>';
                foreach ($years as $yv) {
                    $yv = (string) $yv;
                    $html .= '<option value="' . Output::attr($yv) . '"' . ($selected === $yv ? ' selected' : '') . '>' . Output::e($yv) . '</option>';
                }
                $html .= '</select>';
            } elseif ($filterType === 'combo') {
                // FK combo filter: load options from module's combo config
                $selected   = (string) ($rawF['v'] ?? '');
                $options    = $this->comboFilterOptions($filterField);
                if (!empty($options)) {
                    $html .= '<select name="' . $fname . '[v]" class="ib-input">';
                    $html .= '<option value="">' . __('all_option') . '</option>';
                    foreach ($options as $optVal => $optLabel) {
                        $sel = $selected === (string)$optVal ? ' selected' : '';
                        $html .= '<option value="' . Output::attr((string)$optVal) . '"' . $sel . '>'
                               . Output::e(is_array($optLabel) ? '' : (string)$optLabel) . '</option>';
                    }
                    $html .= '</select>';
                } else {
                    // Fallback: text input
                    $html .= '<input type="text" name="' . $fname . '[v]" value="' . Output::attr($selected) . '" '
                           . 'class="ib-input" placeholder="' . __('filter_ph') . '">';
                }
            }

            $html .= '</div>'; // ib-filter__field
        }

        $html .= '</div>'; // ib-filters__fields

        $html .= '<div class="ib-filters__actions">';
        $html .= '<button type="submit" class="ib-btn ib-btn--primary ib-btn--sm">'
               . '<i class="fa-solid fa-filter" aria-hidden="true"></i> ' . __('apply') . '</button>';
        // Clear filters link (preserves views and search)
        if ($filterIsStructured) {
            $clrParams = array_filter(['q' => $search, 'v1' => $fv1 ?: null, 'v2' => $fv2 ?: null]);
            $clearUrl = !empty($clrParams) ? ($filterBaseUrl . '?' . http_build_query($clrParams)) : $filterBaseUrl;
        } else {
            $clearParams = http_build_query(array_filter(['mod' => $mod, 'q' => $search, 'v1' => $fv1 ?: null, 'v2' => $fv2 ?: null]));
            $clearUrl = '?' . $clearParams;
        }
        $html .= '<a href="' . Output::attr($clearUrl) . '" class="ib-btn ib-btn--ghost ib-btn--sm">'
               . '<i class="fa-solid fa-filter-circle-xmark" aria-hidden="true"></i> ' . __('clear') . '</a>';
        $html .= '</div>';

        $html .= '</form>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Count filters that have at least one active value.
     */
    private function countActiveFilters(array $filters): int
    {
        $count = 0;
        foreach ($this->module->filterFields as $filterField => $filterType) {
            // Skip numeric keys (non-associative array entries)
            if (!is_string($filterField)) {
                continue;
            }
            $rawF = $filters[$filterField] ?? [];
            if ($this->isFilterActive($filterField, $rawF, $filterType)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Columna d'un filtre 'year': la mateixa clau, o la clau sense el sufix «__year» quan el camp
     * també té un altre filtre (p. ex. 'donated_on' => 'date_range' i 'donated_on__year' => 'year').
     */
    public static function yearColumn(string $filterField): string
    {
        return (string) preg_replace('/__year$/', '', $filterField);
    }

    /**
     * Check if a specific filter has an active value.
     */
    private function isFilterActive(string $filterField, array $rawF, string $filterType): bool
    {
        if ($filterType === 'date_range' || $filterType === 'datetime_range') {
            $from = Input::sanitizeDate((string) ($rawF['from'] ?? ''));
            $to   = Input::sanitizeDate((string) ($rawF['to']   ?? ''));
            return $from !== '' || $to !== '';
        }

        $val = Input::sanitizeString((string) ($rawF['v'] ?? ''));
        return $val !== '';
    }

    /**
     * Render a single cell value according to the field's component type.
     *
     * ALL output is escaped.
     */
    /**
     * Parse a numeric value that may be in European format (1.234,56) to a float.
     */
    private function parseDecimal(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        $s = (string) $value;
        // Detect European format: has comma as decimal separator
        if (str_contains($s, ',') && str_contains($s, '.')) {
            // e.g. "1.234,56" or "-2.890,00" → remove dots, replace comma with dot
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif (str_contains($s, ',') && !str_contains($s, '.')) {
            // e.g. "1234,56" → replace comma with dot
            $s = str_replace(',', '.', $s);
        }
        return (float) $s;
    }

    private function renderCell(string $field, mixed $value, array $row): string
    {
        // Module-rendered cell (status icons, links...) takes precedence
        $custom = $this->module->renderListCell($field, $value, $row);
        if ($custom !== null) {
            return $custom;
        }

        $type = $this->module->components[$field] ?? 'text';

        // Delegate to registered component if available (skip combo/select — handled below with preloaded labels)
        if ($type !== 'combo' && $type !== 'select' && $type !== 'multicombo' && $type !== 'checklist' && ComponentRegistry::has($type)) {
            $component = ComponentRegistry::make($type);
            $options = $this->module->componentOptions[$field] ?? [];
            // Inject row values for components that need extra fields
            if ($type === 'usage_bar' && isset($options['max_field']) && isset($row[$options['max_field']])) {
                $options['max_value'] = (int) $row[$options['max_field']];
            }
            // For hosting_plan and similar: inject any declared row_fields
            foreach ((array) ($options['row_fields'] ?? []) as $optKey => $colName) {
                if (isset($row[$colName])) {
                    $options[$optKey] = $row[$colName];
                }
            }
            if (isset($row[$this->module->key])) {
                $options['row_id'] = (int) $row[$this->module->key];
            }
            $rowId = isset($row[$this->module->key]) ? (int) $row[$this->module->key] : null;
            return $component->display($field, $value, $options, $rowId);
        }

        return match ($type) {
            'date'       => '<span class="ib-cell--date">' . Output::date((string) $value) . '</span>',
            'datetime'   => '<span class="ib-cell--date">' . $this->renderDatetimeCell((string) $value) . '</span>',
            'money'      => (($mv = $this->parseDecimal($value)) < 0 ? '<span class="ib-cell--negative">' : '') . Output::money($mv) . ($mv < 0 ? '</span>' : ''),
            'percentage' => Output::e(number_format($this->parseDecimal($value), 2)) . '%',
            'integer'    => Output::e((int) $value),
            'bool',
            'boolean',
            'toggle',
            'checkbox'   => $this->renderBoolCell($value),
            'status_icon'=> $this->renderStatusIcon($field, (string) $value),
            'badge',
            'status'     => $this->renderBadgeCell($field, (string) $value),
            'file'       => $value ? '<a href="' . \Intrabase\Security\Output::attr(\Intrabase\Support\DownloadLink::url((string) $value)) . '" title="' . __('download') . '"><i class="fa-solid fa-file-arrow-down" aria-hidden="true"></i></a>' : '',
            'picture'    => $this->renderThumbnail((string) $value),
            'html'       => '<div class="ib-cell--html">' . Output::truncate(strip_tags((string) $value), 80) . '</div>',
            'url'        => $value ? '<a href="' . Output::url((string) $value) . '" target="_blank" rel="noopener">'
                                   . Output::truncate((string) $value, 40) . '</a>' : '',
            'email'      => $value ? '<a href="mailto:' . Output::attr((string) $value) . '">'
                                   . Output::e((string) $value) . '</a>' : '',
            'phone'      => $value ? '<a href="tel:' . Output::attr((string) $value) . '">' . Output::e((string) $value) . '</a>' : '',
            'color'      => $value ? '<span class="ib-color-swatch"><span class="ib-color-swatch__dot" style="background:' . Output::attr((string)$value) . '"></span> ' . Output::e((string)$value) . '</span>' : '',
            'time'       => strlen((string) $value) >= 5 ? substr((string) $value, 0, 5) : Output::e((string) $value),
            'decimal'    => $value !== '' && $value !== null ? (($dv = $this->parseDecimal($value)) < 0 ? '<span class="ib-cell--negative">' : '') . Output::money($dv) . ($dv < 0 ? '</span>' : '') : '',
            // Xifres amb decimals en format català (1.234,5); els enters (anys, codis) es deixen com són
            'number'     => preg_match('/^-?\d+\.(\d+)$/', trim((string) $value), $nm)
                                ? Output::e(number_format((float) $value, strlen($nm[1]), ',', '.'))
                                : Output::e((string) $value),
            'rating'     => (int)$value > 0 ? str_repeat('<i class="fa-solid fa-star" style="color:#f59e0b;font-size:.8rem" aria-hidden="true"></i>', min((int)$value, 5)) . ' ' . (int)$value . '/5' : '',
            'textarea'   => Output::e(Output::truncate(str_replace(["\r\n", "\n"], ' ', (string) $value), 80)),
            'combo',
            'select'     => $this->renderComboCell($field, (string) $value),
            'multicombo',
            'checklist'  => $this->renderMultiComboCell($field, (string) $value),
            default      => Output::e((string) $value),
        };
    }

    /**
     * Render a combo/select cell using preloaded labels (single value).
     * For status-like fields (named 'status', 'state', 'priority', 'severity', 'result')
     * with static data combos, auto-renders as a colored badge.
     */
    private function renderComboCell(string $field, string $value): string
    {
        if ($value === '') {
            return '';
        }

        // browser_raw: show the stored value instead of the combo label
        $opts = $this->module->componentOptions[$field] ?? [];
        if (!empty($opts['browser_raw'])) {
            return Output::e($value);
        }

        $label = isset($this->comboLabels[$field][$value])
            ? (string) $this->comboLabels[$field][$value]
            : $value;

        // Auto-badge for status-like fields with static data (FK fields stay as plain text)
        $isBadgeField = in_array($field, ['status', 'state', 'priority', 'severity', 'result', 'risk_level', 'category', 'type'], true)
            || str_ends_with($field, '_status')
            || str_ends_with($field, '_state')
            || str_ends_with($field, '_priority');
        $isStaticCombo = isset($this->module->combos[$field]['data']);

        $hasColorMap = isset($this->module->statusColors[$field]);
        if ($field === 'status' && $isStaticCombo) {
            return Output::kmsStatusBadge($value, __($label)); // etiquetes que són claus d'ib_lang
        }
        if (($isBadgeField || $hasColorMap) && $isStaticCombo) {
            $colorMap = $this->module->statusColors[$field] ?? [];
            $color    = $colorMap[$value] ?? $this->guessStatusColor($value);
            return "<span class=\"ib-badge ib-badge--{$color}\">" . Output::e($label) . '</span>';
        }

        return Output::e($label);
    }

    /**
     * Render a multicombo/checklist cell using preloaded labels (comma-separated values).
     * Splits the stored CSV string and resolves each value to its label.
     */
    private function renderMultiComboCell(string $field, string $value): string
    {
        if ($value === '') {
            return '';
        }
        $parts = array_filter(array_map('trim', explode(',', $value)));
        if (empty($parts)) {
            return '';
        }
        $labels = [];
        foreach ($parts as $v) {
            $labels[] = $this->comboLabels[$field][$v] ?? $v;
        }
        return Output::e(implode(', ', $labels));
    }

    /**
     * Pre-load combo labels for all visible combo/select fields.
     * Queries FK tables once per field (not per row) to avoid N+1 queries.
     *
     * @param string[] $fields
     */
    private function preloadComboLabels(array $fields): void
    {
        $this->comboLabels = [];
        foreach ($fields as $field) {
            $type = $this->module->components[$field] ?? 'text';
            if (!in_array($type, ['combo', 'select', 'multicombo', 'checklist', 'badge', 'status'], true)) {
                continue;
            }
            $config = $this->module->combos[$field] ?? null;
            if (!$config) {
                continue;
            }
            // Static data combo (e.g. select with fixed options)
            if (isset($config['data']) && is_array($config['data'])) {
                // Les etiquetes poden ser claus d'ib_lang (com ja fa DataEditor); un text
                // que no ho és torna igual.
                $this->comboLabels[$field] = array_map(fn($l) => __((string) $l), $config['data']);
                continue;
            }
            // DB-backed combo (FK lookup): load all options into memory once
            // Support both 'value' and 'label' keys (modules use 'label', addCombo() uses 'value')
            $labelKey = $config['value'] ?? $config['label'] ?? $config['labelField'] ?? $config['label_col'] ?? $config['label_field'] ?? $config['display'] ?? null;
            if (isset($config['table'], $config['key']) && $labelKey !== null) {
                try {
                    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $config['table']);
                    $key   = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $config['key']);
                    $val   = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $labelKey);
                    $where = (string) ($config['where'] ?? '');
                    if ($table === '' || $key === '' || $val === '') {
                        continue;
                    }
                    $sql = "SELECT `{$key}`, `{$val}` FROM `{$table}`";
                    if ($where !== '') {
                        // $where is developer-defined, not user input — safe to interpolate
                        $sql .= " WHERE {$where}";
                    }
                    $rows = $this->module->getDb()->fetchAll($sql);
                    $labels = [];
                    foreach ($rows as $row) {
                        $labels[(string) $row[$key]] = (string) $row[$val];
                    }
                    $this->comboLabels[$field] = $labels;
                } catch (\Throwable) {
                    // Silently skip — cell will show raw value as fallback
                }
            }
        }
    }

    private function renderDatetimeCell(string $value): string
    {
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return '';
        }
        // If time part is 00:00:00 or absent, show date only
        if (preg_match('/\b00:00(:00)?$/', $value) || strlen($value) <= 10) {
            return Output::date($value);
        }
        return Output::date($value, 'd/m/Y H:i');
    }

    private function renderBoolCell(mixed $value): string
    {
        $truthy = !empty($value) && $value !== '0' && $value !== 'false' && $value !== 'no';
        if ($truthy) {
            return '<span class="ib-badge ib-badge--success" title="' . __('yes') . '">'                 . '<i class="fa-solid fa-check" aria-hidden="true"></i></span>';
        }
        return '<span class="ib-badge ib-badge--neutral" title="' . __('no') . '">'             . '<i class="fa-solid fa-xmark" aria-hidden="true"></i></span>';
    }

    private function renderStatusIcon(string $field, string $value): string
    {
        $icons = $this->module->components[$field . '_icons'] ?? [];
        $icon  = $icons[$value] ?? 'fa-solid fa-circle';
        $label = Output::e($value);
        return "<i class=\"{$icon}\" title=\"{$label}\" aria-label=\"{$label}\"></i>";
    }

    /**
     * Render a badge/status pill.
     * Modules can define status color mappings via $module->statusColors[field][value] = 'success|warning|danger|info|neutral'.
     * Falls back to automatic color based on common status keywords.
     */
    private function renderBadgeCell(string $field, string $value): string
    {
        if ($value === '') {
            return '';
        }

        if ($field === 'status') {
            $lbl = $this->comboLabels[$field][$value] ?? $this->module->selects[$field][$value] ?? ucwords(str_replace('_', ' ', $value));
            return Output::kmsStatusBadge($value, __((string) $lbl));
        }
        // Module-defined color mapping
        $colorMap = $this->module->statusColors[$field] ?? [];
        $color    = $colorMap[$value] ?? $this->guessStatusColor($value);
        // Com a `status`: l'etiqueta del select (pot ser una clau d'ib_lang), no el valor cru
        $label    = Output::e(__((string) ($this->comboLabels[$field][$value] ?? $this->module->selects[$field][$value] ?? ucwords(str_replace('_', ' ', $value)))));
        return "<span class=\"ib-badge ib-badge--{$color}\">{$label}</span>";
    }

    /**
     * Guess a badge color from common status keyword patterns.
     */
    private function guessStatusColor(string $value): string
    {
        $v = strtolower($value);
        if (in_array($v, ['active', 'approved', 'paid', 'completed', 'done', 'confirmed', 'published', 'issued', 'reconciled', 'won', 'compliant', 'succeeded', 'success', 'passed', 'on_track'], true)) {
            return 'success';
        }
        if (in_array($v, ['pending', 'draft', 'new', 'in_progress', 'under_review', 'submitted', 'open', 'scheduled', 'planned', 'in_review', 'reviewing', 'processing', 'sent'], true)) {
            return 'info';
        }
        if (in_array($v, ['warning', 'at_risk', 'expiring_soon', 'overdue', 'delayed', 'on_hold', 'suspended', 'review', 'conditional', 'partial'], true)) {
            return 'warning';
        }
        if (in_array($v, ['error', 'failed', 'rejected', 'cancelled', 'expired', 'deleted', 'lost', 'non_compliant', 'critical', 'blocked', 'terminated', 'off_track'], true)) {
            return 'danger';
        }
        return 'neutral';
    }

    private function renderThumbnail(string $path): string
    {
        if (empty($path)) {
            return '';
        }
        $safe = Output::attr($path);
        return "<img class=\"ib-thumb\" src=\"{$safe}\" alt=\"\" loading=\"lazy\">";
    }

    private function renderPerPage(
        int $perPage,
        string $mod,
        string $search,
        string $sortBy,
        string $sortDir,
        array $filters = []
    ): string {
        $perPageOptions = $this->config['per_page_options'];
        $baseUrl = Router::url($mod);
        $isStructured = str_starts_with($baseUrl, '/') && !str_starts_with($baseUrl, '/?');
        $ppAction = $isStructured ? $baseUrl : '';

        $html = '<form class="ib-browser__perpage" method="get"'
              . ($ppAction !== '' ? ' action="' . Output::attr($ppAction) . '"' : '') . '>';
        if (!$isStructured) {
            $html .= '<input type="hidden" name="mod" value="' . Output::attr($mod) . '">';
        }
        $html .= '<input type="hidden" name="q" value="' . Output::attr($search) . '">';
        $html .= '<input type="hidden" name="sortby" value="' . Output::attr($sortBy) . '">';
        $html .= '<input type="hidden" name="sortdir" value="' . Output::attr($sortDir) . '">';
        $html .= '<input type="hidden" name="page" value="1">';
        // Preserve view selections
        $ppv1 = $this->viewId('v1');
        $ppv2 = $this->viewId('v2');
        if ($ppv1 > 0 || $this->viewExplicit('v1')) {
            $html .= '<input type="hidden" name="v1" value="' . $ppv1 . '">';
        }
        if ($ppv2 > 0 || $this->viewExplicit('v2')) {
            $html .= '<input type="hidden" name="v2" value="' . $ppv2 . '">';
        }
        foreach ($filters as $fField => $fVal) {
            if (is_array($fVal)) {
                foreach ($fVal as $fk => $fv) {
                    if ($fv !== '') {
                        $html .= '<input type="hidden" name="f[' . Output::attr($fField) . '][' . Output::attr($fk) . ']"'
                                . ' value="' . Output::attr($fv) . '">';
                    }
                }
            }
        }
        $html .= '<select name="per_page" class="ib-select ib-select--sm" onchange="this.form.submit()" aria-label="' . __('per_page') . '">';
        foreach ($perPageOptions as $opt) {
            $sel = $opt === $perPage ? ' selected' : '';
            $html .= '<option value="' . $opt . '"' . $sel . '>' . $opt . '</option>';
        }
        $html .= '</select>';
        $html .= ' <span class="ib-browser__perpage-label">' . __('per_page') . '</span>';
        $html .= '</form>';
        return $html;
    }

    private function renderPagination(
        int $page,
        int $pages,
        int $total,
        int $perPage,
        string $mod,
        string $search,
        string $sortBy,
        string $sortDir,
        array $filters = [],
        string $perPageHtml = ''
    ): string {
        $paginBaseUrl    = Router::url($mod);
        $paginStructured = str_starts_with($paginBaseUrl, '/') && !str_starts_with($paginBaseUrl, '/?');

        $defaultPerPage = $this->module->perPage;
        $pv1 = $this->viewId('v1');
        $pv2 = $this->viewId('v2');
        $sharedParams = array_filter([
            'q'        => $search,
            'sortby'   => $sortBy,
            'sortdir'  => $sortDir,
            'per_page' => $perPage !== $defaultPerPage ? $perPage : null,
            'v1'       => ($pv1 > 0 || $this->viewExplicit('v1')) ? (string) $pv1 : null,
            'v2'       => ($pv2 > 0 || $this->viewExplicit('v2')) ? (string) $pv2 : null,
            'f'        => $filters ?: null,
        ], static fn($v) => $v !== null && $v !== '' && $v !== []); // conserva v1=0 («tots els registres»)

        $buildPageUrl = function (int $p) use ($paginBaseUrl, $paginStructured, $mod, $sharedParams): string {
            $params = array_merge($sharedParams, ['page' => $p]);
            if ($paginStructured) {
                return $paginBaseUrl . '?' . http_build_query($params);
            }
            return '?' . http_build_query(array_merge(['mod' => $mod], $params));
        };

        $rangeFrom = (($page - 1) * $perPage) + 1;
        $rangeTo   = min($page * $perPage, $total);
        $rangeInfo = __('showing_range') . ' ' . $rangeFrom . '-' . $rangeTo . ' ' . __('of') . ' ' . $total;

        $html  = '<nav class="ib-pagination" aria-label="' . __('pagination') . '">';
        $html .= '<span class="ib-pagination__info">' . Output::e($rangeInfo) . '</span>';
        $html .= '<ul class="ib-pagination__list">';

        // Previous
        if ($page > 1) {
            $html .= '<li><a href="' . Output::attr($buildPageUrl($page - 1)) . '" class="ib-pagination__btn">&laquo;</a></li>';
        }

        // Page numbers (show window around current)
        $start = max(1, $page - 2);
        $end   = min($pages, $page + 2);

        if ($start > 1) {
            $html .= '<li><a href="' . Output::attr($buildPageUrl(1)) . '" class="ib-pagination__btn">1</a></li>';
            if ($start > 2) {
                $html .= '<li><span class="ib-pagination__ellipsis">…</span></li>';
            }
        }

        for ($p = $start; $p <= $end; $p++) {
            $active = $p === $page ? ' ib-pagination__btn--active' : '';
            $html .= '<li><a href="' . Output::attr($buildPageUrl($p)) . '" class="ib-pagination__btn' . $active . '">' . $p . '</a></li>';
        }

        if ($end < $pages) {
            if ($end < $pages - 1) {
                $html .= '<li><span class="ib-pagination__ellipsis">…</span></li>';
            }
            $html .= '<li><a href="' . Output::attr($buildPageUrl($pages)) . '" class="ib-pagination__btn">' . $pages . '</a></li>';
        }

        // Next
        if ($page < $pages) {
            $html .= '<li><a href="' . Output::attr($buildPageUrl($page + 1)) . '" class="ib-pagination__btn">&raquo;</a></li>';
        }

        $html .= '</ul>';
        if ($perPageHtml !== '') {
            $html .= $perPageHtml;
        }
        $html .= '</nav>';
        return $html;
    }

    // =========================================================================
    // Helper methods
    // =========================================================================

    /**
     * Determine which fields to display.
     *
     * @return string[]
     */
    private function resolveFields(): array
    {
        $fields = $this->module->fields ?: $this->module->showFields;

        // If fields is an associative array (field_name => config), extract just the keys
        if ($fields && !array_is_list($fields)) {
            $fields = array_keys($fields);
        }

        // Validate fields against actual table columns to prevent SQL errors
        // Virtual fields (defined in $module->virtualFields) are always allowed
        $virtualNames = array_keys($this->module->virtualFields ?? []);
        if ($fields && $this->module->table) {
            $tableColumns = $this->getTableColumns();
            if ($tableColumns) {
                $fields = array_filter($fields, fn($f) => in_array($f, $tableColumns, true) || in_array($f, $virtualNames, true));
            }
        }

        if (!$this->module->showKey) {
            $fields = array_filter($fields, fn($f) => $f !== $this->module->key);
        }

        return array_values(array_filter($fields, fn($f) => !in_array($f, $this->module->hidden ?? [], true)));
    }

    /**
     * Get actual column names from the module's database table.
     * Results are cached per table for the lifetime of this request.
     *
     * @return string[]|null  Column names, or null if table cannot be described.
     */
    private function getTableColumns(): ?array
    {
        static $cache = [];
        $table = $this->module->table;

        if (isset($cache[$table])) {
            return $cache[$table];
        }

        try {
            $db   = $this->module->getDb();
            $stmt = $db->pdo()->prepare("SHOW COLUMNS FROM `{$table}`");
            $stmt->execute();
            $cache[$table] = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'Field');
            return $cache[$table];
        } catch (\Throwable) {
            $cache[$table] = null;
            return null;
        }
    }

    /**
     * Validate and return the sort field.
     *
     * @param string[] $fields
     */
    private function resolveSortField(array $fields): string
    {
        // If module disables sorting, always use the module's fixed orderBy
        if (!($this->module->canSort ?? true)) {
            $moduleOrderBy = preg_replace('/\s+(ASC|DESC)$/i', '', $this->module->orderBy ?? '');
            return $moduleOrderBy ?: $this->module->key;
        }

        $requested = preg_replace('/[^a-zA-Z0-9_]/', '', Input::get('sortby'));

        // Module orderBy may include direction (e.g. 'position ASC') — extract just the column
        $moduleOrderBy = preg_replace('/\s+(ASC|DESC)$/i', '', $this->module->orderBy ?? '');

        // Validate against actual table columns (+ virtual fields) to prevent SQL errors
        $virtualNames = array_keys($this->module->virtualFields ?? []);
        $validColumns = array_merge($fields, [$this->module->key]);
        $tableColumns = $this->getTableColumns();
        if ($tableColumns) {
            $validColumns = array_values(array_unique(array_merge(
                array_intersect($validColumns, $tableColumns),
                array_intersect($validColumns, $virtualNames)
            )));
        }

        // Determine default: prefer module orderBy if it exists in the table, else first field or key
        $default = $this->module->key;
        if ($moduleOrderBy && ($tableColumns === null || in_array($moduleOrderBy, $tableColumns, true))) {
            $default = $moduleOrderBy;
        } elseif (!empty($fields)) {
            $default = $fields[0];
        }

        // Only allow known field names to prevent column-enumeration attacks
        if ($requested && in_array($requested, $validColumns, true)) {
            return $requested;
        }

        return $default;
    }

    private function resolveSortDir(): string
    {
        $requested = strtoupper(Input::get('sortdir'));
        if ($requested === 'DESC' || $requested === 'ASC') {
            return $requested;
        }
        // Honour direction embedded in module's orderBy (e.g. 'position ASC')
        if (preg_match('/\s+(ASC|DESC)$/i', $this->module->orderBy ?? '', $m)) {
            return strtoupper($m[1]);
        }
        // Honour module's orderDir property
        $moduleDir = strtoupper($this->module->orderDir ?? 'ASC');
        if ($moduleDir === 'DESC') {
            return 'DESC';
        }
        return 'ASC';
    }

    private function resolvePerPage(): int
    {
        $requested = Input::getInt('per_page', $this->module->perPage);
        $allowed   = $this->config['per_page_options'];
        return in_array($requested, $allowed, true) ? $requested : $this->module->perPage;
    }

    /**
     * Resolve which fields to search in for the current request.
     * Returns ALL configured searchFields by default for OR matching.
     * If the 'sf' param is provided and valid, returns only that field.
     *
     * @param  string[] $fields visible columns
     * @return string[]
     */
    private function resolveSearchFields(array $fields): array
    {
        $searchable = $this->module->searchFields ?: [$fields[0] ?? $this->module->key];
        $requested  = preg_replace('/[^a-zA-Z0-9_]/', '', Input::get('sf'));
        if ($requested && in_array($requested, $searchable, true)) {
            return [$requested];
        }
        return $searchable;
    }

    /**
     * Compute SUM totals for fields declared in $module->totalsFields.
     * Applies the same search/filter WHERE conditions as the main query.
     *
     * @param  string[] $searchFields
     * @param  array<string,array<string,string>> $filters
     * @return array<string, float>
     */
    private function fetchTotals(string $search, array $searchFields, array $filters): array
    {
        if (empty($this->module->totalsFields)) {
            return [];
        }

        $safeTotals = array_values(array_filter(
            $this->module->totalsFields,
            fn($f) => (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $f)
        ));
        if (empty($safeTotals)) {
            return [];
        }

        // Un camp pot dur la seva pròpia expressió de suma ($module->totalsExpr):
        // les columnes numèriques del KMS llegat són varchar i SUM() les llegeix
        // malament. Només s'accepten caràcters de funció SQL.
        $exprOf = function (string $f): string {
            $e = (string) ($this->module->totalsExpr[$f] ?? '');
            if ($e === '' || !preg_match('/^[A-Za-z0-9_`\'\.,\(\)\s><=\+\-\*\/]+$/', $e)) {
                return "`{$f}`";
            }
            return $e;
        };
        $selectParts = implode(', ', array_map(
            fn($f) => "COALESCE(SUM(" . $exprOf($f) . "), 0) AS `tot_{$f}`",
            $safeTotals
        ));
        $sql    = "SELECT {$selectParts} FROM `{$this->module->table}`";
        $params = [];
        $conds  = [];

        if ($this->module->baseWhere) {
            $conds[] = $this->module->baseWhere;
        }

        // Els totals del peu han de sumar només les files visibles.
        if ($this->module->scopeUiByOwner) {
            $scopeSql = $this->module->scopeSqlLiteral();
            if ($scopeSql !== '') {
                $conds[] = $scopeSql;
            }
        }

        // Apply saved view filters (v1 = primary, v2 = secondary)
        $v1Id = $this->viewId('v1');
        if ($v1Id > 0 && isset($this->views['primary'][$v1Id])) {
            $wc = \Intrabase\Support\ViewSqlGuard::filter((string) ($this->views['primary'][$v1Id]['where_clause'] ?? ''));
            if ($wc !== '') {
                $conds[] = '(' . $wc . ')';
            }
        }
        $v2Id = $this->viewId('v2');
        if ($v2Id > 0 && isset($this->views['secondary'][$v2Id])) {
            $wc = \Intrabase\Support\ViewSqlGuard::filter((string) ($this->views['secondary'][$v2Id]['where_clause'] ?? ''));
            if ($wc !== '') {
                $conds[] = '(' . $wc . ')';
            }
        }

        if ($search !== '' && !empty($searchFields)) {
            $safeSearch = array_values(array_filter(
                $searchFields,
                fn($f) => (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $f)
            ));
            if (!empty($safeSearch)) {
                // Use unique param names per field to avoid PDO duplicate named param error
                $orParts = [];
                foreach ($safeSearch as $idx => $sf) {
                    $paramName          = '__search_' . $idx;
                    $orParts[]          = "`{$sf}` LIKE :{$paramName}";
                    $params[$paramName] = '%' . $search . '%';
                }
                $conds[] = '(' . implode(' OR ', $orParts) . ')';
            }
        }

        foreach ($this->module->filterFields as $filterField => $filterType) {
            if (!is_string($filterField) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $filterField)) {
                continue;
            }
            $rawF = $filters[$filterField] ?? [];
            if ($filterType === 'date_range' || $filterType === 'datetime_range') {
                $from = Input::sanitizeDate((string)($rawF['from'] ?? ''));
                $to   = Input::sanitizeDate((string)($rawF['to'] ?? ''));
                $ph   = '__fld_' . $filterField;
                if ($from !== '') { $conds[] = "`{$filterField}` >= :{$ph}_from"; $params["{$ph}_from"] = $from; }
                if ($to   !== '') {
                    $suffix  = $filterType === 'datetime_range' ? '' : ' 23:59:59';
                    $conds[] = "`{$filterField}` <= :{$ph}_to";
                    $params["{$ph}_to"] = $to . $suffix;
                }
            } elseif ($filterType === 'text') {
                $val = Input::sanitizeString((string)($rawF['v'] ?? ''));
                if ($val !== '') {
                    $ph = '__flt_' . $filterField;
                    $conds[] = "`{$filterField}` LIKE :{$ph}";
                    $params[$ph] = '%' . $val . '%';
                }
            } elseif ($filterType === 'select' || $filterType === 'combo') {
                $val = Input::sanitizeString((string)($rawF['v'] ?? ''));
                if ($val !== '') {
                    $ph = '__fls_' . $filterField;
                    $conds[] = "`{$filterField}` = :{$ph}";
                    $params[$ph] = $val;
                }
            } elseif ($filterType === 'year') {
                $y = (string)($rawF['v'] ?? '');
                if (preg_match('/^\d{4}$/', $y)) {
                    $col = self::yearColumn($filterField);
                    $ph  = '__fly_' . $col;
                    $conds[] = "`{$col}` >= :{$ph}_a AND `{$col}` < :{$ph}_b";
                    $params["{$ph}_a"] = $y . '-01-01';
                    $params["{$ph}_b"] = ((int) $y + 1) . '-01-01';
                }
            }
        }

        if (!empty($conds)) {
            $sql .= ' WHERE ' . implode(' AND ', $conds);
        }

        try {
            $row = $this->module->getDb()->fetchOne($sql, $params);
            if (!$row) {
                return [];
            }
            $result = [];
            foreach ($safeTotals as $f) {
                $result[$f] = (float)($row["tot_{$f}"] ?? 0);
            }
            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    // =========================================================================
    // Saved Views
    // =========================================================================

    /**
     * Load saved views for the current module from ib_sys_views.
     *
     * @return array{primary: array<int, array>, secondary: array<int, array>}
     */
    /**
     * Vista activa (v1 = principal, v2 = secundària). Si l'adreça no en tria
     * cap, s'aplica la marcada com a predeterminada (ib_sys_views.is_default);
     * v=0 a l'adreça vol dir «tots els registres» i la desactiva.
     */
    private function viewId(string $param): int
    {
        if ($this->viewInUrl($param)) {
            $v = max(0, (int) $_GET[$param]);
            $this->module->listViewMemory(Router::currentMod(), $param, $v);
            return $v;
        }
        // La que s'havia triat abans en aquest mòdul (sessió), si encara existeix (06/11/2026)
        $remembered = $this->rememberedView($param);
        if ($remembered !== null) {
            return $remembered;
        }
        $type = $param === 'v2' ? 'secondary' : 'primary';
        foreach ($this->views[$type] ?? [] as $id => $view) {
            if (!empty($view['is_default'])) {
                return (int) $id;
            }
        }
        return 0;
    }

    /** Si hi ha una vista triada (encara que sigui 0 = «tots»): a l'adreça o recordada a la sessió. */
    private function viewExplicit(string $param): bool
    {
        return $this->viewInUrl($param) || $this->rememberedView($param) !== null;
    }

    /**
     * Text del cercador: el de l'adreça si hi és (encara que sigui buit, que l'esborra) i si no el
     * darrer que s'havia cercat en aquest mòdul (sessió, 06/11/2026).
     */
    private function searchQuery(): string
    {
        $mod = (string) Router::currentMod();
        if (isset($_GET['q']) && is_scalar($_GET['q'])) {
            $q = Input::get('q');
            $this->module->listSearchMemory($mod, $q);
            return $q;
        }
        return (string) ($this->module->listSearchMemory($mod) ?? '');
    }

    /** Si l'adreça porta la vista triada. */
    private function viewInUrl(string $param): bool
    {
        return isset($_GET[$param]) && is_scalar($_GET[$param]) && $_GET[$param] !== '';
    }

    /** Vista recordada per a aquest mòdul, si encara existeix (0 = «tots»); null si no n'hi ha. */
    private function rememberedView(string $param): ?int
    {
        $v = $this->module->listViewMemory(Router::currentMod(), $param);
        if ($v === null) {
            return null;
        }
        $type = $param === 'v2' ? 'secondary' : 'primary';
        return ($v === 0 || isset($this->views[$type][$v])) ? $v : null;
    }

    private function loadViews(): array
    {
        $views = ['primary' => [], 'secondary' => []];

        try {
            $db  = $this->module->getDb();
            $mod = Router::currentMod();
            $userAccount = $this->module->getUserAccount();
            $uid = (int) ($userAccount['id'] ?? 0);

            $rows = $db->fetchAll(
                "SELECT id, view_name, view_type, where_clause, is_default
                 FROM ib_sys_views
                 WHERE mod_name = :mod AND (is_shared = 1 OR created_by = :uid)
                 ORDER BY sort_order ASC, view_name ASC",
                ['mod' => $mod, 'uid' => $uid]
            );

            foreach ($rows as $r) {
                $type = $r['view_type'] === 'secondary' ? 'secondary' : 'primary';
                $views[$type][(int) $r['id']] = $r;
            }
        } catch (\Throwable) {
            // Silently ignore — views are optional
        }

        return $views;
    }

    /**
     * Apply saved view WHERE clauses to a QueryBuilder instance.
     */
    private function applyViewFilters(QueryBuilder $qb): QueryBuilder
    {
        $v1Id = $this->viewId('v1');
        if ($v1Id > 0 && isset($this->views['primary'][$v1Id])) {
            $wc = \Intrabase\Support\ViewSqlGuard::filter((string) ($this->views['primary'][$v1Id]['where_clause'] ?? ''));
            if ($wc !== '') {
                $qb = $qb->whereRaw('(' . $wc . ')');
            }
        }

        $v2Id = $this->viewId('v2');
        if ($v2Id > 0 && isset($this->views['secondary'][$v2Id])) {
            $wc = \Intrabase\Support\ViewSqlGuard::filter((string) ($this->views['secondary'][$v2Id]['where_clause'] ?? ''));
            if ($wc !== '') {
                $qb = $qb->whereRaw('(' . $wc . ')');
            }
        }

        return $qb;
    }

    /**
     * Opcions d'un filtre 'combo' (valor => etiqueta), de la configuració del combo del mòdul: estàtic
     * (traduït) o d'una taula.
     *
     * @return array<string, string>
     */
    private function comboFilterOptions(string $filterField): array
    {
        $comboConf = $this->module->combos[$filterField] ?? null;
        $options   = [];
        if ($comboConf) {
            if (isset($comboConf['data']) && is_array($comboConf['data'])) {
                // Static combo
                $options = array_map(fn($l) => __((string) $l), $comboConf['data']);
            } elseif (isset($comboConf['table'], $comboConf['key'])) {
                // DB-backed combo — also supports 'label' key
                try {
                    $tbl = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$comboConf['table']);
                    $k   = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$comboConf['key']);
                    $v   = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($comboConf['value'] ?? $comboConf['label'] ?? $comboConf['labelField'] ?? $comboConf['label_col'] ?? $comboConf['label_field'] ?? $comboConf['display'] ?? ''));
                    $wh  = (string)($comboConf['where'] ?? '');
                    if ($tbl !== '' && $k !== '' && $v !== '') {
                        $sql = "SELECT `{$k}`, `{$v}` FROM `{$tbl}`";
                        if ($wh !== '') $sql .= " WHERE {$wh}";
                        $sql .= " ORDER BY `{$v}` ASC LIMIT 500";
                        $rows = $this->module->getDb()->fetchAll($sql);
                        foreach ($rows as $row) {
                            $options[(string)$row[$k]] = (string)$row[$v];
                        }
                    }
                } catch (\Throwable) {}
            }
        }
        return $options;
    }

    /**
     * Filtres de la barra ($module->toolbarFilters, 16/11/2026): un desplegable per camp, amb la icona
     * del mòdul, que posa o treu f[camp][v] a l'adreça (pàgina 1) i conserva la resta de paràmetres.
     */
    private function renderToolbarFilters(): string
    {
        if (empty($this->module->toolbarFilters)) {
            return '';
        }
        $current = $this->resolveFilters();
        $html = '';
        foreach ($this->module->toolbarFilters as $field => $icon) {
            $type = $this->module->filterFields[$field] ?? null;
            if (!is_string($field) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
                continue;
            }
            if ($type === 'select') {
                $options = array_map(fn($l) => is_array($l) ? '' : __((string) $l), $this->module->filterOptions[$field] ?? []);
            } elseif ($type === 'combo') {
                $options = $this->comboFilterOptions($field);
            } else {
                continue;
            }
            if ($options === []) {
                continue;
            }
            $selected = (string) ($current[$field]['v'] ?? '');
            $label    = __($this->module->humans[$field] ?? ucwords(str_replace('_', ' ', $field)));
            $html .= '<div class="ib-view-wrap' . ($selected !== '' ? ' ib-view-wrap--active' : '') . '">';
            $html .= '<i class="' . Output::attr((string) $icon ?: 'fa-solid fa-filter') . ' ib-view-wrap__icon" aria-hidden="true"></i>';
            $html .= '<select class="ib-select ib-select--views" data-ib-toolbar-filter="' . Output::attr($field) . '"'
                   . ' title="' . Output::attr($label) . '" aria-label="' . Output::attr($label) . '">';
            $html .= '<option value="">' . Output::e($label) . ': ' . Output::e(__('all_option')) . '</option>';
            foreach ($options as $val => $optLabel) {
                $html .= '<option value="' . Output::attr((string) $val) . '"' . ($selected === (string) $val ? ' selected' : '') . '>'
                       . Output::e((string) $optLabel) . '</option>';
            }
            $html .= '</select></div>';
        }
        if ($html === '') {
            return '';
        }
        return $html . '<script>document.querySelectorAll("[data-ib-toolbar-filter]").forEach(function(s){'
            . 's.addEventListener("change",function(){var u=new URL(window.location.href),k="f["+s.dataset.ibToolbarFilter+"][v]";'
            . 'if(s.value)u.searchParams.set(k,s.value);else u.searchParams.delete(k);'
            . 'u.searchParams.set("page","1");window.location.href=u.toString();});});</script>';
    }

    /**
     * Render the view selector dropdowns for the toolbar.
     */
    private function renderViewSelector(string $mod, bool $isStructured, string $baseUrl): string
    {
        $hasPrimary   = !empty($this->views['primary']);
        $hasSecondary = !empty($this->views['secondary']);
        $toolbar      = $this->renderToolbarFilters();

        if (!$hasPrimary && !$hasSecondary) {
            return $toolbar === '' ? '' : '<div class="ib-view-selector">' . $toolbar . '</div>';
        }

        $v1 = $this->viewId('v1');
        $v2 = $this->viewId('v2');

        $html = '<div class="ib-view-selector">';

        // Build JS function for view change that preserves all other params
        $html .= '<script>function ibChangeView(selectEl,param){var url=new URL(window.location.href);'
                . 'url.searchParams.set(param,selectEl.value);url.searchParams.set("page","1");'
                . 'if(!selectEl.value)url.searchParams.set(param,"0");' // 0 = «tots»: no torna a la vista per defecte
                . 'window.location.href=url.toString();}</script>';

        if ($hasPrimary) {
            $activeClass = $v1 > 0 ? ' ib-view-wrap--active' : '';
            $html .= '<div class="ib-view-wrap' . $activeClass . '">';
            $html .= '<i class="fa-solid fa-filter ib-view-wrap__icon" aria-hidden="true"></i>';
            $html .= '<select class="ib-select ib-select--views" '
                    . 'onchange="ibChangeView(this,\'v1\')" '
                    . 'title="' . __('primary_view') . '">';
            $html .= '<option value="">' . Output::e(__('all_records')) . '</option>';
            foreach ($this->views['primary'] as $id => $view) {
                $sel = $v1 === $id ? ' selected' : '';
                $html .= '<option value="' . $id . '"' . $sel . '>' . Output::e($view['view_name']) . '</option>';
            }
            $html .= '</select>';
            $html .= '</div>';
        }

        if ($hasSecondary) {
            $activeClass = $v2 > 0 ? ' ib-view-wrap--active' : '';
            $html .= '<div class="ib-view-wrap' . $activeClass . '">';
            $html .= '<i class="fa-solid fa-calendar-days ib-view-wrap__icon" aria-hidden="true"></i>';
            $html .= '<select class="ib-select ib-select--views" '
                    . 'onchange="ibChangeView(this,\'v2\')" '
                    . 'title="' . __('secondary_view') . '">';
            $html .= '<option value="">' . Output::e(__('all_periods')) . '</option>';
            foreach ($this->views['secondary'] as $id => $view) {
                $sel = $v2 === $id ? ' selected' : '';
                $html .= '<option value="' . $id . '"' . $sel . '>' . Output::e($view['view_name']) . '</option>';
            }
            $html .= '</select>';
            $html .= '</div>';
        }

        $html .= $toolbar;
        $html .= '</div>';
        return $html;
    }

    /**
     * Read and validate advanced filter values from $_GET['f'].
     *
     * Only fields declared in $module->filterFields are accepted.
     * Values are NOT sanitized here — sanitization is done at query time.
     *
     * @return array<string, array<string, string>>
     */
    private function resolveFilters(): array
    {
        $rawFilters = $_GET['f'] ?? [];
        if (!is_array($rawFilters) || empty($this->module->filterFields)) {
            return [];
        }

        $result = [];
        foreach ($this->module->filterFields as $filterField => $filterType) {
            if (!is_string($filterField) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $filterField)) {
                continue;
            }
            $rawF = $rawFilters[$filterField] ?? [];
            if (!is_array($rawF)) {
                $rawF = ['v' => (string) $rawF];
            }

            // Sanitize keys and values (strip_tags, trim) — SQL injection prevented by parameterized queries
            $entry = [];
            foreach ($rawF as $k => $v) {
                $safeKey   = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $k);
                $safeValue = Input::sanitizeString((string) $v);
                if ($safeKey !== '') {
                    $entry[$safeKey] = $safeValue;
                }
            }

            if (!empty($entry)) {
                $result[$filterField] = $entry;
            }
        }

        return $result;
    }

    /**
     * Return only valid identifier strings from a field array.
     *
     * @param string[] $fields
     * @return string[]
     */
    private function sanitizeFieldNames(array $fields): array
    {
        return array_filter($fields, fn($f) => preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $f));
    }
}
