# AGENTS.md

## What this is

`jnrflsh/laravel-module-loader` (`Jnrflsh\ModuleLoader\`) is a standalone Laravel package. It scans configured module groups and registers each module's views, anonymous and class-based Blade components, translations, Livewire components (class-based and single/multi-file) and route files under one namespace, `{group}.{kebab-module}::`. A cacheable manifest (`module:cache` / `module:clear`) skips the scan in production.

User-facing behaviour is documented in `README.md`. Keep it in sync with every behaviour change.

## Commands

```bash
composer install
composer test                              # PHPUnit
vendor/bin/phpunit --filter=ModuleCache    # one class or test
composer lint                              # Pint, check only (vendor/bin/pint to fix)
composer analyse                           # Larastan, level max
```

All three must pass before a commit. There is no lock file (`composer.lock` is gitignored). CI runs PHP 8.3, 8.4 and 8.5, each with `--prefer-lowest` and with the newest allowed dependencies, plus Pint and Larastan on 8.5 and a weekly `composer audit`.

## Layout

| Path | Role |
|---|---|
| `src/ModuleServiceProvider.php` | Discovery, manifest loading, all registration, and the `optimize` hook |
| `src/Console/ModuleCacheCommand.php` | Writes the manifest (`bootstrap/cache/modules.php`, or `APP_MODULES_CACHE`) |
| `src/Console/ModuleClearCommand.php` | Deletes it |
| `config/modules.php` | Shipped defaults, merged under the app's config and publishable with the `modules-config` tag |
| `tests/Fixtures/Modules/NewsFeed/` | The main fixture module. It holds an example of every feature. |
| `tests/Fixtures/Modules/Bare/` | A module with no resources |
| `tests/Fixtures/Modules/legacyFeed/` | A lower-case directory name, for the verbatim class namespace |
| `tests/Fixtures/Modules/OldLayout/` | Capitalised `Resources/` and `Routes/`, which must not be discovered |
| `tests/TestCase.php` | Testbench base: fixture group config, a throwaway bootstrap path per test |
| `phpstan.neon.dist` | Larastan config (`src`, `config`, level max) |
| `.github/` | Workflows and the local `setup-composer` composite action |

## Conventions

- PHP 8.3: typed properties, parameters and returns. Array shapes go in the `@phpstan-type` aliases, not repeated inline.
- Imports at the top of the file. No inline fully qualified class names.
- Comments explain *why* (a constraint, an invariant, a surprise), never *what*. The `path → usage` examples in the `register*()` docblocks are the exception: keep them accurate. Examples use a made-up domain (`catalog.book-shelf::`), never a real application's.
- Dev-only files (tests, CI, tooling configs, `docs/`, this file) need an `export-ignore` line in `.gitattributes`.

## Rules

Read the matching file in `docs/` before you change these paths:

- [`docs/provider.md`](docs/provider.md) (`src/`, `config/`, `tests/`): the manifest contract and the invariants. Read it before changing discovery, registration or the commands.
- [`docs/testing.md`](docs/testing.md) (`tests/`): test style, fixtures and the `TestCase` helpers.
- [`docs/ci-and-dist.md`](docs/ci-and-dist.md) (`.github/`, `.gitattributes`, `.gitignore`): CI constraints and the dist archive.
