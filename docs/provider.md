# Provider and manifest

## How it works

1. `discoverManifest()` walks `modules.groups` and returns `['version' => MANIFEST_VERSION, 'modules' => [...]]`. Each module entry has `namespace`, `views`, `viewComponents`, `lang`, `livewireClasses`, `livewireViews` and `routes`.
2. `modules()` returns the cached manifest's modules when the cache file exists and its `version` equals `MANIFEST_VERSION`. Otherwise it calls `discoverManifest()`.
3. `boot()` feeds each module entry to the `register*()` methods.

The manifest is the contract between discovery and registration, and it is also what `module:cache` serialises. Its shapes are the `@phpstan-type` aliases `ModuleGroup`, `Module` and `Manifest` on the provider. Rules for it:

- It must survive `var_export`: only scalars, arrays and `null`. No objects and no closures.
- Every path in it is relative to the application base path, so a cached manifest stays valid when the release directory moves. Resolve paths with `base_path()` at registration time, never at discovery time.
- Any change to a module entry's shape bumps `MANIFEST_VERSION`, so cache files from older package versions are ignored rather than misread.

## Invariants

- **Framework-only dependencies.** Import from `Illuminate\*` and `Livewire\*` only. Never reference a host application's namespaces, classes or paths. Anything app-specific belongs in `config/modules.php`.
- **Opt-in.** With no `groups` configured, nothing registers.
- **Discovery never loads a module's classes.** It only checks which directories exist. Livewire components are registered with `Livewire::addNamespace()` and resolved by Livewire itself, by name. Don't reintroduce class scanning or reflection.
- **Boot must never fatal.** Every boot `require`s the cache file and the route files, so:
  - `module:cache` writes atomically with `File::replace`, then `require`s the result and deletes it if that throws.
  - Route files that a stale manifest lists but that no longer exist are skipped.
  - Missing group directories are skipped.
- **Directory names match exactly.** `existingDirectory()` compares each segment with the name on disk, so a case-insensitive filesystem (macOS) can't accept `Resources/views` for `resources/views` in development and lose it on a case-sensitive server.
- **Class namespaces use the directory name verbatim** (`{group namespace}\{directory}`, no `Str::studly`), because PSR-4 maps directory names exactly. Only the tag namespace is kebab-cased.
- **Route files only declare routes.** The middleware comes from the filename via `modules.route_middleware`, and the URI prefix via `modules.route_prefixes` (`api.php` → `/api`). Route files are not loaded when Laravel's route cache is active.
- **`optimize` integration.** Both commands are registered with `optimizes()`, so they run with `optimize` and `optimize:clear`. `optimize` runs `module:cache` last, after `route:cache` has read the manifest on disk, so `forgetManifestWhenOptimizing()` deletes the manifest when `optimize` starts (unless `--except=modules`). Keep both.
- **`module:cache` respects a cached config.** `currentGroups()` reads the groups from the config cache on disk when there is one, so the manifest matches what production boots with.
