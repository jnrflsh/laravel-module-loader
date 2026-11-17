<?php

namespace Jnrflsh\ModuleLoader;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Jnrflsh\ModuleLoader\Console\ModuleCacheCommand;
use Jnrflsh\ModuleLoader\Console\ModuleClearCommand;
use Livewire\Livewire;

/**
 * @phpstan-type ModuleGroup array{name: string, namespace: string, path: string}
 * @phpstan-type Module array{
 *     namespace: string,
 *     views: string|null,
 *     viewComponents: string|null,
 *     lang: string|null,
 *     livewireClasses: string|null,
 *     livewireViews: string|null,
 *     routes: list<string>,
 * }
 * @phpstan-type Manifest array{version: int, modules: list<Module>}
 */
class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Bumped whenever the shape of a module entry changes. A cached manifest
     * with another version is ignored, so a package update never boots against
     * entries it cannot read.
     */
    public const MANIFEST_VERSION = 2;

    private const MANIFEST = 'cache/modules.php';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/modules.php', 'modules');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/modules.php' => config_path('modules.php'),
        ], 'modules-config');

        if ($this->app->runningInConsole()) {
            $this->commands([ModuleCacheCommand::class, ModuleClearCommand::class]);
            $this->optimizes(optimize: 'module:cache', clear: 'module:clear', key: 'modules');
            $this->forgetManifestWhenOptimizing();
        }

        foreach ($this->modules() as $module) {
            $this->registerViews($module);
            $this->registerViewComponents($module);
            $this->registerTranslations($module);
            $this->registerLivewireComponents($module);
            $this->registerRoutes($module);
        }
    }

    /**
     * bootstrap/cache/modules.php, or the path in APP_MODULES_CACHE, resolved
     * the way Laravel resolves APP_CONFIG_CACHE and the other cache paths.
     */
    public function manifestPath(): string
    {
        $path = Env::get('APP_MODULES_CACHE');

        if (! is_string($path)) {
            return $this->app->bootstrapPath(self::MANIFEST);
        }

        return Str::startsWith($path, ['/', '\\']) ? $path : $this->app->basePath($path);
    }

    /**
     * Scan the module groups (the configured ones by default) and build the
     * manifest. Public so the `module:cache` command can call it. Paths are
     * relative to the base path, so a cached manifest stays valid if the
     * checkout moves.
     *
     * Discovery only looks at directories: it never loads a module's classes,
     * so a broken class cannot take down the boot.
     *
     * @param  list<ModuleGroup>|null  $groups
     * @return Manifest
     */
    public function discoverManifest(?array $groups = null): array
    {
        $modules = [];

        foreach ($groups ?? $this->configuredGroups() as $group) {
            $groupPath = base_path($group['path']);

            if (! File::isDirectory($groupPath)) {
                continue;
            }

            /** @var list<string> $modulePaths */
            $modulePaths = File::directories($groupPath);

            foreach ($modulePaths as $modulePath) {
                $modules[] = $this->discoverModule($group, basename($modulePath));
            }
        }

        return ['version' => self::MANIFEST_VERSION, 'modules' => $modules];
    }

    /**
     * `optimize` runs `module:cache` last, after `route:cache` has built its routes
     * from a fresh application that reads whatever manifest is on disk. Removing the
     * manifest up front makes that application discover the current modules, and
     * `module:cache` writes the new manifest at the end of the run.
     */
    private function forgetManifestWhenOptimizing(): void
    {
        Event::listen(function (CommandStarting $event) {
            if ($event->command === 'optimize' && ! $this->isExcludedFromOptimize($event)) {
                File::delete($this->manifestPath());
            }
        });
    }

    /**
     * `optimize --except=modules` skips `module:cache`, so the manifest stays as it is.
     */
    private function isExcludedFromOptimize(CommandStarting $event): bool
    {
        $except = $event->input->getOption('except');
        $excludedTasks = is_string($except) ? array_map('trim', explode(',', $except)) : [];

        return in_array('modules', $excludedTasks, true);
    }

    /**
     * The cached manifest's modules when it was written by this version of the
     * package, otherwise the modules discovered from the filesystem.
     *
     * @return list<Module>
     */
    private function modules(): array
    {
        $cachePath = $this->manifestPath();

        if (is_file($cachePath)) {
            $manifest = require $cachePath;

            if (is_array($manifest) && ($manifest['version'] ?? null) === self::MANIFEST_VERSION) {
                /** @var Manifest $manifest */
                return $manifest['modules'];
            }
        }

        return $this->discoverManifest()['modules'];
    }

    /**
     * @return list<ModuleGroup>
     */
    private function configuredGroups(): array
    {
        /** @var list<ModuleGroup> */
        return config('modules.groups', []);
    }

    /**
     * @param  ModuleGroup  $group
     * @return Module
     */
    private function discoverModule(array $group, string $directory): array
    {
        $modulePath = "{$group['path']}/{$directory}";
        // App\Catalog\BookShelf — PSR-4 maps the directory name verbatim.
        $classNamespace = "{$group['namespace']}\\{$directory}";

        return [
            // catalog.book-shelf — used for views, components, translations, and Livewire tags
            'namespace' => "{$group['name']}.".Str::kebab($directory),
            'views' => $this->existingDirectory($modulePath, 'resources/views'),
            'viewComponents' => $this->existingDirectory($modulePath, 'View/Components')
                ? "{$classNamespace}\\View\\Components"
                : null,
            'lang' => $this->existingDirectory($modulePath, 'lang'),
            'livewireClasses' => $this->existingDirectory($modulePath, 'Livewire')
                ? "{$classNamespace}\\Livewire"
                : null,
            'livewireViews' => $this->existingDirectory($modulePath, 'resources/views/livewire'),
            'routes' => $this->discoverRouteFiles($modulePath),
        ];
    }

    /**
     * @return list<string>
     */
    private function discoverRouteFiles(string $relativeModulePath): array
    {
        $routesPath = $this->existingDirectory($relativeModulePath, 'routes');

        if ($routesPath === null) {
            return [];
        }

        return array_map(
            fn (string $routeFile) => "{$routesPath}/".basename($routeFile),
            glob(base_path("{$routesPath}/*.php")) ?: [],
        );
    }

    /**
     * Each segment of $directory must match the name on disk exactly. A
     * case-insensitive filesystem (macOS) would otherwise accept `Resources/views`
     * for `resources/views` in development, and the module would lose its views
     * on a case-sensitive production server.
     */
    private function existingDirectory(string $relativeModulePath, string $directory): ?string
    {
        $path = base_path($relativeModulePath);

        foreach (explode('/', $directory) as $segment) {
            if (! File::isDirectory($path) || ! in_array($segment, scandir($path) ?: [], true)) {
                return null;
            }

            $path .= "/{$segment}";
        }

        return File::isDirectory($path) ? "{$relativeModulePath}/{$directory}" : null;
    }

    /**
     * app/Catalog/BookShelf/resources/views/pages/index.blade.php
     * → view('catalog.book-shelf::pages.index')
     *
     * The same namespace resolves anonymous components:
     * resources/views/components/forms/input.blade.php → <x-catalog.book-shelf::forms.input>
     * (Blade looks up `catalog.book-shelf::components.forms.input`, then `….index`).
     *
     * @param  Module  $module
     */
    private function registerViews(array $module): void
    {
        if ($module['views'] !== null) {
            $this->loadViewsFrom(base_path($module['views']), $module['namespace']);
        }
    }

    /**
     * app/Catalog/BookShelf/View/Components/SortMenu.php
     * → <x-catalog.book-shelf::sort-menu>
     *
     * @param  Module  $module
     */
    private function registerViewComponents(array $module): void
    {
        if ($module['viewComponents'] !== null) {
            Blade::componentNamespace($module['viewComponents'], $module['namespace']);
        }
    }

    /**
     * app/Catalog/BookShelf/lang/en/messages.php
     * → __('catalog.book-shelf::messages.key')
     *
     * @param  Module  $module
     */
    private function registerTranslations(array $module): void
    {
        if ($module['lang'] !== null) {
            $this->loadTranslationsFrom(base_path($module['lang']), $module['namespace']);
        }
    }

    /**
     * Livewire resolves `<livewire:catalog.book-shelf::filters.genre-filter>` itself, from
     * the class namespace (App\Catalog\BookShelf\Livewire\Filters\GenreFilter) or from the
     * view path (resources/views/livewire/filters/genre-filter.blade.php, a single-
     * or multi-file component). Both null registers nothing.
     *
     * @param  Module  $module
     */
    private function registerLivewireComponents(array $module): void
    {
        Livewire::addNamespace(
            $module['namespace'],
            viewPath: $module['livewireViews'] !== null ? base_path($module['livewireViews']) : null,
            classNamespace: $module['livewireClasses'],
        );
    }

    /**
     * app/Catalog/BookShelf/routes/web.php → loaded in the `web` middleware group.
     * Route files only declare routes — the middleware group and the URI prefix
     * come from the filename (`modules.route_middleware`, `modules.route_prefixes`).
     *
     * @param  Module  $module
     */
    private function registerRoutes(array $module): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        /** @var array<string, string|list<string>> $routeMiddleware */
        $routeMiddleware = config('modules.route_middleware', []);
        /** @var array<string, string> $routePrefixes */
        $routePrefixes = config('modules.route_prefixes', []);

        foreach ($module['routes'] as $relativeRouteFile) {
            $routeFile = base_path($relativeRouteFile);

            // A stale cached manifest can list a deleted file; requiring it would
            // fatal every boot, `module:clear` included.
            if (! is_file($routeFile)) {
                continue;
            }

            $filename = basename($routeFile);

            Route::group([
                'middleware' => $routeMiddleware[$filename] ?? [],
                'prefix' => $routePrefixes[$filename] ?? '',
            ], $routeFile);
        }
    }
}
