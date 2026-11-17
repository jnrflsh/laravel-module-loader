<?php

namespace Jnrflsh\ModuleLoader\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Jnrflsh\ModuleLoader\ModuleServiceProvider;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'module:clear')]
class ModuleClearCommand extends Command
{
    protected $signature = 'module:clear';

    protected $description = 'Remove the module cache file';

    public function handle(): int
    {
        /** @var ModuleServiceProvider $provider */
        $provider = $this->laravel->getProvider(ModuleServiceProvider::class);

        File::delete($provider->manifestPath());

        $this->components->info('Module cache cleared successfully.');

        return self::SUCCESS;
    }
}
