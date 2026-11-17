<?php

namespace Jnrflsh\ModuleLoader\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Jnrflsh\ModuleLoader\ModuleServiceProvider;
use LogicException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * @phpstan-import-type ModuleGroup from ModuleServiceProvider
 */
#[AsCommand(name: 'module:cache')]
class ModuleCacheCommand extends Command
{
    protected $signature = 'module:cache';

    protected $description = 'Create a module cache file for faster module registration';

    public function handle(): int
    {
        /** @var ModuleServiceProvider $provider */
        $provider = $this->laravel->getProvider(ModuleServiceProvider::class);

        $manifestPath = $provider->manifestPath();

        // Atomic write, so a concurrent boot never requires a half-written manifest.
        File::replace(
            $manifestPath,
            '<?php return '.var_export($provider->discoverManifest($this->currentGroups()), true).';'.PHP_EOL,
        );

        // Every boot requires this file, so a broken one must not be left behind.
        try {
            require $manifestPath;
        } catch (Throwable $e) {
            File::delete($manifestPath);

            throw new LogicException('The module manifest could not be cached.', previous: $e);
        }

        $this->components->info('Modules cached successfully.');

        return self::SUCCESS;
    }

    /**
     * Within `optimize`, this process loaded its config before `config:cache`
     * rewrote the cache, so the groups are read from the file on disk.
     *
     * @return list<ModuleGroup>|null The groups from the config cache, or null for the loaded config.
     */
    private function currentGroups(): ?array
    {
        $cachedConfigPath = $this->laravel->getCachedConfigPath();

        if (! is_file($cachedConfigPath)) {
            return null;
        }

        /** @var array{modules?: array{groups?: list<ModuleGroup>}} $config */
        $config = require $cachedConfigPath;

        return $config['modules']['groups'] ?? [];
    }
}
