<?php

namespace Jnrflsh\ModuleLoader\Tests;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Jnrflsh\ModuleLoader\ModuleServiceProvider;
use Jnrflsh\ModuleLoader\Tests\Fixtures\Modules\NewsFeed\Livewire\Filters\StatusFilter;
use Jnrflsh\ModuleLoader\Tests\Fixtures\Modules\NewsFeed\Livewire\PostList;
use Livewire\Livewire;

class ModuleServiceProviderTest extends TestCase
{
    public function test_modules_are_discovered_from_the_configured_groups(): void
    {
        $this->assertSame(
            ['fixture.bare', 'fixture.legacy-feed', self::FIXTURE_NAMESPACE, 'fixture.old-layout'],
            collect($this->discoveredModules())->pluck('namespace')->sort()->values()->all(),
        );
    }

    public function test_no_modules_are_discovered_without_configured_groups(): void
    {
        config(['modules.groups' => []]);

        $this->assertSame([], $this->discoveredModules());
    }

    public function test_a_missing_group_directory_does_not_stop_the_groups_after_it(): void
    {
        config(['modules.groups' => [
            ['name' => 'gone', 'namespace' => 'Gone', 'path' => 'does/not/exist'],
            ...config('modules.groups'),
        ]]);

        $this->assertContains(self::FIXTURE_NAMESPACE, collect($this->discoveredModules())->pluck('namespace'));
    }

    public function test_the_discovered_manifest_carries_the_manifest_version(): void
    {
        $this->assertSame(ModuleServiceProvider::MANIFEST_VERSION, $this->provider()->discoverManifest()['version']);
    }

    public function test_register_merges_the_shipped_defaults_under_the_app_config(): void
    {
        config(['modules' => []]);

        $this->provider()->register();

        $this->assertSame([], config('modules.groups'));
        $this->assertSame(['web.php' => 'web', 'api.php' => 'api'], config('modules.route_middleware'));
        $this->assertSame(['api.php' => 'api'], config('modules.route_prefixes'));
    }

    public function test_the_config_is_publishable_under_the_modules_config_tag(): void
    {
        $this->provider()->boot();

        $this->assertSame(
            [realpath(__DIR__.'/../config/modules.php') => config_path('modules.php')],
            array_combine(
                array_map('realpath', array_keys(ServiceProvider::pathsToPublish(ModuleServiceProvider::class, 'modules-config'))),
                ServiceProvider::pathsToPublish(ModuleServiceProvider::class, 'modules-config'),
            ),
        );
    }

    public function test_discovered_manifest_stores_paths_relative_to_the_base_path(): void
    {
        $module = $this->discoveredModule(self::FIXTURE_NAMESPACE);

        $this->assertSame($this->fixtureModulePath('resources/views'), $module['views']);
        $this->assertSame($this->fixtureModulePath('lang'), $module['lang']);
        $this->assertSame($this->fixtureModulePath('resources/views/livewire'), $module['livewireViews']);
        $this->assertSame(self::FIXTURE_CLASS_NAMESPACE.'\\NewsFeed\\View\\Components', $module['viewComponents']);
        $this->assertSame(self::FIXTURE_CLASS_NAMESPACE.'\\NewsFeed\\Livewire', $module['livewireClasses']);
    }

    public function test_the_class_namespace_uses_the_module_directory_name_verbatim(): void
    {
        $this->assertSame(
            self::FIXTURE_CLASS_NAMESPACE.'\\legacyFeed\\View\\Components',
            $this->discoveredModule('fixture.legacy-feed')['viewComponents'],
        );

        $this->provider()->boot();

        $this->assertStringContainsString(
            '<p class="notice">Heads up</p>',
            (string) $this->blade('<x-fixture.legacy-feed::notice>Heads up</x-fixture.legacy-feed::notice>'),
        );
    }

    public function test_a_module_without_resources_has_empty_entries(): void
    {
        $this->assertSame(
            ['views' => null, 'viewComponents' => null, 'lang' => null, 'livewireClasses' => null, 'livewireViews' => null, 'routes' => []],
            array_diff_key($this->discoveredModule('fixture.bare'), ['namespace' => true]),
        );
    }

    public function test_the_old_capitalised_resources_and_routes_directories_are_not_discovered(): void
    {
        // On a case-insensitive filesystem (macOS) these would pass for resources/ and routes/
        // in development, then go missing on a case-sensitive production server.
        $this->assertSame(
            ['views' => null, 'lang' => null, 'livewireViews' => null, 'routes' => []],
            array_intersect_key($this->discoveredModule('fixture.old-layout'), array_flip(['views', 'lang', 'livewireViews', 'routes'])),
        );
    }

    public function test_discovery_never_loads_module_classes(): void
    {
        // Livewire/Unloadable.php extends a class that does not exist; loading it would throw.
        $this->assertNotNull($this->discoveredModule(self::FIXTURE_NAMESPACE));
        $this->assertFalse(class_exists(self::FIXTURE_CLASS_NAMESPACE.'\\NewsFeed\\Livewire\\Unloadable', false));
    }

    public function test_boot_registers_views_and_translations(): void
    {
        $this->provider()->boot();

        $this->assertTrue(view()->exists(self::FIXTURE_NAMESPACE.'::pages.index'));
        $this->assertSame('News feed', __(self::FIXTURE_NAMESPACE.'::messages.title'));
    }

    public function test_boot_registers_anonymous_and_class_based_blade_components(): void
    {
        $this->provider()->boot();

        $this->assertStringContainsString(
            '<span class="badge">New</span>',
            (string) $this->blade('<x-fixture.news-feed::forms.badge>New</x-fixture.news-feed::forms.badge>'),
        );
        $this->assertStringContainsString(
            '<div class="card">Body</div>',
            (string) $this->blade('<x-fixture.news-feed::card>Body</x-fixture.news-feed::card>'),
        );
        $this->assertStringContainsString(
            '<div class="alert">Saved</div>',
            (string) $this->blade('<x-fixture.news-feed::alert>Saved</x-fixture.news-feed::alert>'),
        );
    }

    public function test_boot_registers_class_based_livewire_components_by_dotted_kebab_case_tag(): void
    {
        $this->provider()->boot();

        $this->assertInstanceOf(PostList::class, Livewire::new(self::FIXTURE_NAMESPACE.'::post-list'));
        $this->assertInstanceOf(StatusFilter::class, Livewire::new(self::FIXTURE_NAMESPACE.'::filters.status-filter'));
    }

    public function test_boot_registers_single_file_livewire_components(): void
    {
        // Rendering a Livewire component encrypts its snapshot.
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->provider()->boot();

        Livewire::test(self::FIXTURE_NAMESPACE.'::counter')->assertSee('count: 1');
    }

    public function test_boot_prefers_the_cached_manifest_over_discovery(): void
    {
        $this->writeManifest([
            $this->manifestEntry('cached.only', ['lang' => $this->fixtureModulePath('lang')]),
        ]);

        $this->provider()->boot();

        $this->assertSame('News feed', __('cached.only::messages.title'));
        $this->assertFalse(view()->exists(self::FIXTURE_NAMESPACE.'::pages.index'));
    }

    public function test_boot_ignores_a_manifest_written_for_another_version(): void
    {
        $this->writeManifest([$this->manifestEntry('cached.only', [])], ModuleServiceProvider::MANIFEST_VERSION + 1);

        $this->provider()->boot();

        $this->assertTrue(view()->exists(self::FIXTURE_NAMESPACE.'::pages.index'));
    }

    public function test_boot_ignores_an_unversioned_manifest(): void
    {
        File::put($this->manifestPath(), '<?php return '.var_export([['namespace' => 'old.shape']], true).';');

        $this->provider()->boot();

        $this->assertTrue(view()->exists(self::FIXTURE_NAMESPACE.'::pages.index'));
    }

    public function test_route_files_load_inside_the_middleware_group_named_by_filename(): void
    {
        $this->provider()->boot();

        $this->assertContains('web', $this->route('modules-fixture/web')->middleware());
        $this->assertContains('api', $this->route('api/modules-fixture/api')->middleware());
        $this->assertSame([], $this->route('modules-fixture/plain')->middleware());
    }

    public function test_route_middleware_and_prefixes_come_from_config(): void
    {
        config([
            'modules.route_middleware' => ['plain.php' => ['web', 'throttle:60,1']],
            'modules.route_prefixes' => ['web.php' => 'portal'],
        ]);

        $this->provider()->boot();

        $this->assertSame(['web', 'throttle:60,1'], $this->route('modules-fixture/plain')->middleware());
        $this->assertSame([], $this->route('portal/modules-fixture/web')->middleware());
        $this->assertSame([], $this->route('modules-fixture/api')->middleware());
    }

    public function test_route_files_are_skipped_when_the_routes_are_cached(): void
    {
        $this->app->instance('routes.cached', true);

        $this->provider()->boot();

        $this->assertNull($this->findRoute('modules-fixture/web'));
    }

    public function test_a_route_file_missing_from_a_stale_manifest_does_not_stop_the_files_after_it(): void
    {
        $this->writeManifest([
            $this->manifestEntry('stale.routes', ['routes' => [
                $this->fixtureModulePath('routes/deleted.php'),
                $this->fixtureModulePath('routes/plain.php'),
            ]]),
        ]);

        $this->provider()->boot();

        $this->assertNotNull($this->findRoute('modules-fixture/plain'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function discoveredModules(): array
    {
        return $this->provider()->discoverManifest()['modules'];
    }

    /**
     * @return array<string, mixed>
     */
    private function discoveredModule(string $namespace): array
    {
        return collect($this->discoveredModules())->firstWhere('namespace', $namespace);
    }

    /**
     * @param  list<array<string, mixed>>  $modules
     */
    private function writeManifest(array $modules, int $version = ModuleServiceProvider::MANIFEST_VERSION): void
    {
        File::put($this->manifestPath(), '<?php return '.var_export(['version' => $version, 'modules' => $modules], true).';'.PHP_EOL);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function manifestEntry(string $namespace, array $overrides): array
    {
        return [
            'namespace' => $namespace,
            'views' => null,
            'viewComponents' => null,
            'lang' => null,
            'livewireClasses' => null,
            'livewireViews' => null,
            'routes' => [],
            ...$overrides,
        ];
    }

    private function findRoute(string $uri): ?RoutingRoute
    {
        return collect(Route::getRoutes()->getRoutes())->first(fn (RoutingRoute $route) => $route->uri() === $uri);
    }

    private function route(string $uri): RoutingRoute
    {
        $route = $this->findRoute($uri);

        $this->assertNotNull($route, "Route [{$uri}] is not registered.");

        return $route;
    }
}
