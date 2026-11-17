<?php

namespace Jnrflsh\ModuleLoader\Tests\Console;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Jnrflsh\ModuleLoader\Console\ModuleCacheCommand;
use Jnrflsh\ModuleLoader\Console\ModuleClearCommand;
use Jnrflsh\ModuleLoader\Tests\TestCase;
use LogicException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class ModuleCacheCommandTest extends TestCase
{
    public function test_module_cache_writes_the_discovered_manifest(): void
    {
        $this->artisan('module:cache')
            ->expectsOutputToContain('Modules cached successfully.')
            ->assertExitCode(0);

        $this->assertSame($this->provider()->discoverManifest(), require $this->manifestPath());
    }

    public function test_a_fresh_boot_registers_the_modules_from_the_cached_manifest(): void
    {
        $this->artisan('module:cache')->assertExitCode(0);
        config(['modules.groups' => []]);

        $this->provider()->boot();

        $this->assertTrue(view()->exists(self::FIXTURE_NAMESPACE.'::pages.index'));
        $this->assertContains('modules-fixture/web', collect(Route::getRoutes()->getRoutes())->map->uri());
    }

    public function test_a_manifest_that_cannot_be_loaded_is_removed_and_the_command_fails(): void
    {
        File::partialMock()
            ->shouldReceive('replace')
            ->once()
            ->andReturnUsing(fn (string $path) => file_put_contents($path, '<?php return [;'));

        try {
            $this->withoutMockingConsoleOutput()->artisan('module:cache');
            $this->fail('module:cache did not fail on an unloadable manifest.');
        } catch (LogicException $e) {
            $this->assertSame('The module manifest could not be cached.', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->manifestPath());
    }

    public function test_module_clear_removes_the_manifest(): void
    {
        $this->artisan(ModuleCacheCommand::class)->assertExitCode(0);
        $this->assertFileExists($this->manifestPath());

        $this->artisan('module:clear')
            ->expectsOutputToContain('Module cache cleared successfully.')
            ->assertExitCode(0);

        $this->assertFileDoesNotExist($this->manifestPath());
    }

    public function test_module_clear_succeeds_without_a_manifest(): void
    {
        $this->artisan(ModuleClearCommand::class)->assertExitCode(0);
    }

    public function test_module_cache_and_clear_run_with_optimize_and_optimize_clear(): void
    {
        $this->assertSame('module:cache', ServiceProvider::$optimizeCommands['modules'] ?? null);
        $this->assertSame('module:clear', ServiceProvider::$optimizeClearCommands['modules'] ?? null);
    }

    public function test_module_cache_reads_the_groups_from_the_config_cache_on_disk(): void
    {
        File::put($this->app->getCachedConfigPath(), '<?php return '.var_export(['modules' => ['groups' => config('modules.groups')]], true).';');
        config(['modules.groups' => []]);

        $this->artisan('module:cache')->assertExitCode(0);

        $this->assertNotSame([], (require $this->manifestPath())['modules']);
    }

    public function test_module_cache_finds_no_modules_when_the_config_cache_has_no_groups(): void
    {
        File::put($this->app->getCachedConfigPath(), '<?php return [];');

        $this->artisan('module:cache')->assertExitCode(0);

        $this->assertSame([], (require $this->manifestPath())['modules']);
    }

    public function test_optimize_removes_the_manifest_before_route_cache_reads_it(): void
    {
        $this->artisan('module:cache')->assertExitCode(0);

        $this->startCommand('optimize');

        $this->assertFileDoesNotExist($this->manifestPath());
    }

    public function test_other_commands_leave_the_manifest_in_place(): void
    {
        $this->artisan('module:cache')->assertExitCode(0);

        $this->startCommand('route:cache');

        $this->assertFileExists($this->manifestPath());
    }

    public function test_optimize_keeps_the_manifest_when_modules_are_excluded(): void
    {
        $this->artisan('module:cache')->assertExitCode(0);

        $this->startCommand('optimize', ['--except' => 'views, modules']);

        $this->assertFileExists($this->manifestPath());
    }

    public function test_app_modules_cache_moves_the_manifest_to_an_absolute_path(): void
    {
        $path = "{$this->bootstrapPath}/elsewhere/modules.php";
        File::ensureDirectoryExists(dirname($path));
        $_SERVER['APP_MODULES_CACHE'] = $path;

        $this->artisan('module:cache')->assertExitCode(0);

        $this->assertFileExists($path);
        $this->assertFileDoesNotExist($this->manifestPath());
    }

    public function test_a_relative_app_modules_cache_resolves_from_the_base_path(): void
    {
        $_SERVER['APP_MODULES_CACHE'] = 'storage/modules.php';

        $this->assertSame(base_path('storage/modules.php'), $this->provider()->manifestPath());
    }

    protected function tearDown(): void
    {
        unset($_SERVER['APP_MODULES_CACHE']);

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $options
     */
    private function startCommand(string $command, array $options = []): void
    {
        $input = new ArrayInput($options);

        if ($command === 'optimize') {
            $input->bind($this->app[Kernel::class]->all()['optimize']->getDefinition());
        }

        event(new CommandStarting($command, $input, new NullOutput));
    }
}
