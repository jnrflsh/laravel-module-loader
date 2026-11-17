# Laravel Module Loader

Module discovery for Laravel apps that group their UI and HTTP code into modules, such as `app/Catalog/BookShelf/` or `app/Account/Profile/`. Each module's views, Blade components, translations, Livewire components and route files register under one namespace, `{group}.{kebab-module}::` (for example `catalog.book-shelf::`). No per-module wiring is needed.

## Requirements

- PHP 8.3+
- Laravel 13.30+
- Livewire 4.4.8+

## Installation

```bash
composer require jnrflsh/laravel-module-loader
```

Laravel auto-discovers the service provider. Publish the config, then list your module groups in it:

```bash
php artisan vendor:publish --tag=modules-config
```

Nothing registers until `config/modules.php` lists at least one group.

## Configuration

```php
// config/modules.php
return [
    'groups' => [
        ['name' => 'catalog', 'namespace' => 'App\\Catalog', 'path' => 'app/Catalog'],
        ['name' => 'api', 'namespace' => 'App\\Api', 'path' => 'app/Api'],
        ['name' => 'account', 'namespace' => 'App\\Account', 'path' => 'app/Account'],
    ],

    'route_middleware' => [
        'web.php' => 'web',
        'api.php' => 'api',
    ],

    'route_prefixes' => [
        'api.php' => 'api',
    ],
];
```

- **`groups`**: each entry is a top-level directory whose subdirectories are modules. `path` is relative to the application base path, `namespace` is that directory's PSR-4 namespace, and `name` is the first segment of every module namespace in the group.
- **`route_middleware`**: the middleware a module route file loads in, keyed by filename: a middleware group name or a list of middleware. Files not listed load without middleware. Neither group includes authentication, so protect routes inside the route file (`->middleware('auth')`).
- **`route_prefixes`**: the URI prefix for a module route file, keyed by filename. `api.php` routes get `/api`, as Laravel's own `routes/api.php` does. Files not listed load without a prefix.

## What a module may contain

With the config above, a module at `app/Catalog/BookShelf/` registers as `catalog.book-shelf::`:

| Type | Path in the module | Usage |
|---|---|---|
| View | `resources/views/pages/index.blade.php` | `view('catalog.book-shelf::pages.index')` |
| Blade component (anonymous) | `resources/views/components/forms/input.blade.php` | `<x-catalog.book-shelf::forms.input />` |
| Blade component (anonymous, index) | `resources/views/components/card/index.blade.php` | `<x-catalog.book-shelf::card />` |
| Blade component (class) | `View/Components/SortMenu.php` | `<x-catalog.book-shelf::sort-menu />` |
| Translations | `lang/en/messages.php` | `__('catalog.book-shelf::messages.key')` |
| Livewire component (class) | `Livewire/Filters/GenreFilter.php` | `<livewire:catalog.book-shelf::filters.genre-filter />` |
| Livewire component (single-file) | `resources/views/livewire/counter.blade.php` | `<livewire:catalog.book-shelf::counter />` |
| Web routes | `routes/web.php` | Loaded in the `web` middleware group |
| API routes | `routes/api.php` | Loaded in the `api` middleware group, under `/api` |

- Livewire resolves a tag by name, from the classes under `Livewire/` or the single- and multi-file components under `resources/views/livewire/`. Discovery never loads a module's classes, so an abstract base class under `Livewire/` is harmless as long as no tag names it.
- Directory names are matched exactly, on every filesystem. `Resources/views/` or `Routes/` are not discovered, even on macOS, where the filesystem would accept them, because a case-sensitive production server would not.
- Class namespaces use the module directory name exactly, as PSR-4 requires: `app/Catalog/BookShelf/` maps to `App\Catalog\BookShelf`.
- Route files are skipped when Laravel's route cache is active.

### Recommended layout

Directories the loader reads are lowercase, as in a Laravel app (`resources/views`, `lang`, `routes`). Directories that hold PHP classes are PascalCase, because PSR-4 maps them to namespace segments. Controllers, form requests and API resources go under `Http/`, as in `app/Http/`:

```
app/Catalog/BookShelf/
├── Http/
│   ├── Controllers/
│   ├── Requests/
│   └── Resources/       API resources
├── Livewire/
├── View/Components/
├── lang/
├── resources/views/
└── routes/
```

The loader never reads `Http/`. Composer autoloads it under the group's namespace, like any other class directory.

### Not included

The loader covers a module's UI and HTTP code. These stay with Laravel's own mechanisms:

- **Migrations, models and other domain code** belong in the app's domain layer, not in a UI module.
- **Service providers**: list them in `bootstrap/providers.php`.
- **Policies**: put `#[UsePolicy(BookPolicy::class)]` on the model, or call `Gate::policy()`.
- **Event listeners**: add the module directories to event discovery in `bootstrap/app.php`: `->withEvents(discover: [app_path('Listeners'), app_path('*/*/Listeners')])`.
- **Config**: keep it in the app's `config/`.
- **Generators**: `make:livewire`, `make:component` and the other `make:*` commands don't know about modules. Create the file in the module, or move it there afterwards.

### App setup

Module views live under `app/`, so tools that scan `app/` see them:

- **Larastan/PHPStan**: exclude them, or single-file Livewire components and other Blade files are analysed as PHP:
  ```neon
  parameters:
      excludePaths:
          - app/*/*/resources/views/*
  ```
- **Tailwind CSS v3**: add `./app/**/*.blade.php` to `content`. Tailwind v4 detects them on its own.

## Manifest cache

Without a cache, the groups are scanned on every request. In production, cache the manifest:

```bash
php artisan module:cache   # writes bootstrap/cache/modules.php
php artisan module:clear   # removes it
```

`php artisan optimize` runs `module:cache`, and `php artisan optimize:clear` runs `module:clear`. `optimize` removes the old manifest before it builds the route cache, so a single `optimize` per deploy picks up new modules and route files.

- When you run the cache commands one by one, run `module:cache` before `route:cache`. The route cache is built from the manifest on disk.
- A cached manifest hides new modules and files until you run `module:cache` again.
- Set `APP_MODULES_CACHE` to write the manifest somewhere other than `bootstrap/cache/modules.php`, like Laravel's `APP_CONFIG_CACHE` and `APP_ROUTES_CACHE`. A relative path is resolved from the base path.
- The manifest stores paths relative to the base path, so it stays valid when the release directory moves.
- The manifest carries a format version. A manifest written by a package version with another format is ignored, and the modules are scanned on every request until the next `module:cache`.
- The cache file is written atomically and loaded once after writing. If it cannot be loaded, it is deleted and the command fails.
- A route file that the manifest lists but that has since been deleted is skipped.

## Testing

```bash
composer install
composer test      # PHPUnit
composer lint      # Pint
composer analyse   # Larastan, level max
```

The tests run on Orchestra Testbench against a fixture module in `tests/Fixtures/Modules/`.

## License

MIT. See [LICENSE](LICENSE).
