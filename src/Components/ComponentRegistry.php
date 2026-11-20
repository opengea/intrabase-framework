<?php

declare(strict_types=1);

namespace Intrabase\Components;

/**
 * ComponentRegistry
 *
 * Central registry for all field components.
 *
 * Resolution priority (like KMS):
 *   1. Tenant-specific component file (client override)
 *   2. Built-in registered component class
 *
 * Usage — register:
 *   ComponentRegistry::register('phone', PhoneComponent::class);
 *
 * Usage — tenant override:
 *   ComponentRegistry::setTenantComponentsDir('/var/www/vhosts/dom/subdomains/sub/httpdocs/data/components');
 *   // Now if /data/components/my_custom.php exists and defines class my_custom
 *   // implementing ComponentInterface, it will be used instead of the built-in.
 *
 * Usage — resolve and use:
 *   $component = ComponentRegistry::make('phone');
 *   echo $component->render('phone_field', $value, $options);
 *
 * @package Intrabase\Components
 */
final class ComponentRegistry
{
    /** @var array<string, class-string<ComponentInterface>> */
    private static array $registry = [];

    /** @var bool Whether built-in components have been registered */
    private static bool $initialized = false;

    /** @var string|null Tenant-specific components directory */
    private static ?string $tenantComponentsDir = null;

    /** @var array<string, bool> Already loaded tenant component files */
    private static array $tenantLoaded = [];

    /**
     * Set the tenant-specific components directory.
     * Files in this directory override built-in components.
     *
     * Expected structure: {dir}/{component_type}.php
     * Each file must define a class that implements ComponentInterface.
     */
    public static function setTenantComponentsDir(string $path): void
    {
        self::$tenantComponentsDir = rtrim($path, '/');
    }

    /**
     * Register a component class for a given type name.
     *
     * @param string $type  Component type name (lowercase, e.g. 'phone')
     * @param class-string<ComponentInterface> $class
     */
    public static function register(string $type, string $class): void
    {
        self::boot();
        self::$registry[strtolower($type)] = $class;
    }

    /**
     * Check whether a component type is registered (built-in or tenant override).
     */
    public static function has(string $type): bool
    {
        self::boot();
        $key = strtolower($type);

        // Check tenant override first
        if (self::hasTenantComponent($key)) {
            return true;
        }

        return isset(self::$registry[$key]);
    }

    /**
     * Instantiate a component by type.
     *
     * Priority:
     *   1. Tenant-specific component file (client override)
     *   2. Built-in registered component class
     *
     * @param  string $type  Component type name
     * @return ComponentInterface
     * @throws \InvalidArgumentException If the type is not registered
     */
    public static function make(string $type): ComponentInterface
    {
        self::boot();
        $key = strtolower($type);

        // 1. Try tenant-specific component
        $tenantComponent = self::loadTenantComponent($key);
        if ($tenantComponent !== null) {
            return $tenantComponent;
        }

        // 2. Built-in component
        if (!isset(self::$registry[$key])) {
            throw new \InvalidArgumentException(
                "Component type '{$type}' is not registered."
            );
        }
        return new self::$registry[$key]();
    }

    /**
     * Return all registered type names.
     *
     * @return string[]
     */
    public static function types(): array
    {
        self::boot();
        $types = array_keys(self::$registry);

        // Add tenant-specific component types
        if (self::$tenantComponentsDir && is_dir(self::$tenantComponentsDir)) {
            foreach (glob(self::$tenantComponentsDir . '/*.php') ?: [] as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                if (!in_array($name, $types, true)) {
                    $types[] = $name;
                }
            }
        }

        return $types;
    }

    /**
     * Check if a tenant-specific component file exists.
     */
    private static function hasTenantComponent(string $type): bool
    {
        if (self::$tenantComponentsDir === null) {
            return false;
        }
        return is_file(self::$tenantComponentsDir . '/' . $type . '.php');
    }

    /**
     * Load and instantiate a tenant-specific component.
     *
     * The tenant component file should define a class with the same name as
     * the file (e.g. my_widget.php → class my_widget) that implements
     * ComponentInterface. This mirrors KMS behaviour where component files
     * define classes matching their filename.
     *
     * @return ComponentInterface|null  Null if no tenant override exists
     */
    private static function loadTenantComponent(string $type): ?ComponentInterface
    {
        if (self::$tenantComponentsDir === null) {
            return null;
        }

        $path = self::$tenantComponentsDir . '/' . $type . '.php';
        if (!is_file($path)) {
            return null;
        }

        // Include the file only once
        if (!isset(self::$tenantLoaded[$type])) {
            require_once $path;
            self::$tenantLoaded[$type] = true;
        }

        // The class name matches the filename (KMS convention)
        if (class_exists($type, false)) {
            $instance = new $type();
            if ($instance instanceof ComponentInterface) {
                return $instance;
            }
        }

        // Also try PascalCase class name
        $pascal = implode('', array_map('ucfirst', explode('_', $type))) . 'Component';
        if (class_exists($pascal, false)) {
            $instance = new $pascal();
            if ($instance instanceof ComponentInterface) {
                return $instance;
            }
        }

        return null;
    }

    /**
     * Register all built-in components (called once on first use).
     */
    private static function boot(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        // Core input types
        self::$registry['phone']         = PhoneComponent::class;
        self::$registry['readonly']      = ReadonlyFieldComponent::class;
        self::$registry['readonlyfield'] = ReadonlyFieldComponent::class;
        self::$registry['json']          = JsonEditorComponent::class;
        self::$registry['status_badge']  = StatusBadgeComponent::class;
        self::$registry['status_icon']   = StatusIconComponent::class;
        self::$registry['autocomplete']  = AutocompleteComponent::class;
        self::$registry['relation_autocomplete'] = RelationAutocompleteComponent::class;
        self::$registry['slug']          = SlugComponent::class;
        self::$registry['bytes']         = BytesComponent::class;
        self::$registry['usage_bar']     = UsageBarComponent::class;
        self::$registry['rating']        = RatingComponent::class;

        // Form inputs
        self::$registry['checkbox']      = CheckboxComponent::class;
        self::$registry['input']         = InputComponent::class;
        self::$registry['textarea']      = TextareaComponent::class;
        self::$registry['password']      = PasswordComponent::class;
        self::$registry['money']         = MoneyComponent::class;
        self::$registry['color']         = ColorComponent::class;
        self::$registry['colorpicker']   = ColorpickerComponent::class;
        self::$registry['datetime']      = DatetimeComponent::class;
        self::$registry['datetimepicker'] = DatetimepickerComponent::class;
        self::$registry['email_component'] = EmailComponent::class;
        self::$registry['email']         = EmailComponent::class;
        self::$registry['slider']        = SliderComponent::class;
        self::$registry['random']        = RandomComponent::class;
        self::$registry['week_days']     = WeekDaysComponent::class;
        self::$registry['tags']          = TagsComponent::class;

        // Rich text / code
        self::$registry['htmledit']      = HtmlEditComponent::class;
        self::$registry['wysiwyg']       = WysiwygComponent::class;
        self::$registry['multilang']     = MultilangComponent::class;
        self::$registry['cipher']        = CipherComponent::class;

        // Select / combo
        self::$registry['combo']         = ComboComponent::class;
        self::$registry['select']        = SelectComponent::class;
        self::$registry['selectliteral'] = SelectLiteralComponent::class;
        self::$registry['uniselect']     = UniselectComponent::class;
        self::$registry['xcombo']        = XcomboComponent::class;
        self::$registry['xref']          = XrefComponent::class;

        // Multi-select
        self::$registry['multiselect']   = MultiselectComponent::class;
        self::$registry['multiselect_by_groups'] = MultiselectByGroupsComponent::class;
        self::$registry['multiselect_pivot']     = MultiselectPivotComponent::class;
        self::$registry['multixref']     = MultixrefComponent::class;

        // Files / media
        self::$registry['file']          = FileComponent::class;
        self::$registry['picture']       = PictureComponent::class;
        self::$registry['thumbnail']     = ThumbnailComponent::class;
        self::$registry['video']         = VideoComponent::class;
        self::$registry['qrcode']        = QrcodeComponent::class;

        // Geo
        self::$registry['geolocalize']   = GeoLocalizeComponent::class;

        // Plugins add their own field types with ComponentRegistry::register().
    }
}
