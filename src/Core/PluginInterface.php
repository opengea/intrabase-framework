<?php

declare(strict_types=1);

namespace Intrabase\Core;

/**
 * An application built on the framework (mail, calendar, drive...).
 *
 * Plugins are listed by class name in config('plugins'). The kernel creates
 * each one after bootstrapping and calls register() once per request, so a
 * plugin can add routes and wire its listeners without the kernel knowing
 * anything about it.
 *
 * Optional: a plugin may also define `public static function registerCron(): void`
 * to add its task types with CronRunner::registerTask(); bin/cron.php calls it
 * before running the due tasks.
 */
interface PluginInterface
{
    public function register(Application $app, RouteRegistry $routes): void;
}
