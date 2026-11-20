# Intrabase framework

Intrabase is a multi-tenant PHP framework for intranets and business applications.
This repository holds the **framework base**: the kernel, authentication, users,
groups and permissions, and the generic machinery to build modules (lists, forms,
detail views, field components and a REST API). Applications such as mail, calendar,
drive or accounting are built on top of it as plugins and are not included here.

Intrabase is developed by [Opengea SCCL](https://www.opengea.org), a Catalan
workers' cooperative, and released under the
[GNU Affero General Public License v3.0](LICENSE).

## What is included

- **Kernel**: configuration, tenant resolution by host name, sessions, routing,
  error pages, a dependency container and an in-process event bus.
- **Access**: login with optional e-mail or TOTP second factor, trusted devices,
  session-conflict check, password recovery, rate limiting and a login log.
- **Users and permissions**: users, groups, per-module permissions and a permissions
  matrix, field-level access rules, per-person app access and organizations
  (entities) a user can switch between.
- **Module machinery**: declare a module as a PHP class with its fields and get
  list, search, filters, saved views, edit and detail pages, actions, hooks and
  audit log. About 45 field components (text, money, dates, selects, relations,
  files, pictures, multilingual text...).
- **REST API**: `/api/v1/*` with token or session authentication and an OpenAPI
  description of every module.
- **System app**: administration of all of the above, translations (Catalan,
  Spanish, English, Basque), SMTP, notifications, cron tasks and tenants.

## Requirements

- PHP 8.1 or later with `pdo_mysql`, `mbstring`, `openssl`, `json` and `session`
- MariaDB 10.6+ or MySQL 8+
- Composer, and Node.js only to rebuild the minified assets

## Installation

```bash
git clone https://github.com/opengea/intrabase-framework.git
cd intrabase-framework
composer install --no-dev

cp config/app.php.example config/app.php    # database settings and app_secret
php bin/install.php --admin-email=you@example.org --admin-name="Your Name" \
                    --organization="Your organization" --locale=en
```

The installer only runs on an empty database: it creates the kernel tables, the
first administrator (it asks for the password), a default organization, the
System app and the translations.

Point the web server's document root to `public/` and rewrite every request that
is not an existing file to `public/index.php`. For a quick local try:

```bash
php -S 127.0.0.1:8080 -t public public/index.php
```

Schedule the cron runner once per minute as the web server user:

```
* * * * * php /path/to/intrabase-framework/bin/cron.php
```

### Rebuilding the assets

`public/assets/js/*.min.js` and `public/assets/css/*.min.css` are built from
`src/assets/`:

```bash
npm install
npm run build
```

## Extending the framework

A **plugin** is a class implementing `Intrabase\Core\PluginInterface`, listed in
`config('plugins')`. It receives the application and the route registry:

```php
use Intrabase\Core\{Application, PluginInterface, RouteRegistry};
use Intrabase\View\LayoutExtensions;

final class HelloPlugin implements PluginInterface
{
    public function register(Application $app, RouteRegistry $routes): void
    {
        $routes->reserve('hello');
        $routes->auth('/hello', function (): void {
            echo 'Hello from a plugin';
        });
        LayoutExtensions::addUserMenuItem('/hello', 'fa-solid fa-hand', 'Hello');
    }
}
```

Other extension points:

| Where | What |
|---|---|
| `RouteRegistry::public()` / `auth()` | Routes before and after the login gate |
| `LayoutExtensions` | Scripts at the end of every page, avatar-menu entries, CSP sources |
| `ComponentRegistry::register()` | New field types |
| `CronRunner::registerTask()` | New cron task types (call it from a static `registerCron()` on the plugin) |
| `MenuConditions::register()` | Hide a module from the menu depending on the tenant's data |
| `AppAccess::registerAvailability()` | Hide an app for a profile or host |
| `EntityPlan::setResolver()` | Restrict apps by the organization's subscription plan |
| Event bus | `UserPasswordChanged`, `UserLoggedInWithPassword` and your own domain events |

Modules live in `modules/<family>/` and extend `Intrabase\Core\Module`; see the
System modules in `modules/sys/` for examples.

## Tests

```bash
composer install
vendor/bin/phpunit
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Licence

Copyright © Opengea SCCL and contributors.

Intrabase is free software: you can redistribute it and/or modify it under the
terms of the GNU Affero General Public License as published by the Free Software
Foundation, version 3 or (at your option) any later version. If you run a
modified version as a network service, you must offer its source code to its
users (section 13).

Third-party components keep their own licences: see [THIRD-PARTY.md](THIRD-PARTY.md).
