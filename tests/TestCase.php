<?php

namespace Jnrflsh\ModuleLoader\Tests;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Jnrflsh\ModuleLoader\ModuleServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

/**
 * Each test swaps in the fixture group and a throwaway bootstrap path before
 * booting a fresh provider, so a host app's own module config never leaks in.
 */
abstract class TestCase extends OrchestraTestCase
{
    protected const FIXTURE_NAMESPACE = 'fixture.news-feed';

    protected const FIXTURE_CLASS_NAMESPACE = 'Jnrflsh\\ModuleLoader\\Tests\\Fixtures\\Modules';

    protected string $bootstrapPath;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.groups' => [[
                'name' => 'fixture',
                'namespace' => self::FIXTURE_CLASS_NAMESPACE,
                'path' => $this->fixtureGroupPath(),
            ]],
            'modules.route_middleware' => ['web.php' => 'web', 'api.php' => 'api'],
            'modules.route_prefixes' => ['api.php' => 'api'],
        ]);

        $this->bootstrapPath = sys_get_temp_dir().'/modules-package-'.Str::uuid();
        File::ensureDirectoryExists("{$this->bootstrapPath}/cache");
        $this->app->useBootstrapPath($this->bootstrapPath);
    }

    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, ModuleServiceProvider::class];
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->bootstrapPath);

        parent::tearDown();
    }

    protected function provider(): ModuleServiceProvider
    {
        return new ModuleServiceProvider($this->app);
    }

    protected function manifestPath(): string
    {
        return "{$this->bootstrapPath}/cache/modules.php";
    }

    /**
     * Group paths are relative to the base path, which is Testbench's skeleton rather
     * than an app checkout, so the fixtures are usually reached through `..` segments.
     */
    protected function fixtureGroupPath(): string
    {
        $from = explode('/', trim(realpath(base_path()), '/'));
        $to = explode('/', trim(realpath(__DIR__.'/Fixtures/Modules'), '/'));

        $common = 0;
        while (isset($from[$common], $to[$common]) && $from[$common] === $to[$common]) {
            $common++;
        }

        return implode('/', [
            ...array_fill(0, count($from) - $common, '..'),
            ...array_slice($to, $common),
        ]);
    }

    protected function fixtureModulePath(string $relativePath): string
    {
        return $this->fixtureGroupPath().'/NewsFeed/'.$relativePath;
    }
}
