# Testing

- Write tests first. Each behaviour gets a `test_*` method named as a sentence (`test_a_route_file_missing_from_a_stale_manifest_does_not_stop_the_files_after_it`).
- Cover new module features by adding files to the `NewsFeed` fixture. Add a new fixture module only for something a module-level property decides, as `Bare/` and `legacyFeed/` do.
- Group paths are relative to Testbench's skeleton base path, so always reach fixtures through `fixtureGroupPath()` / `fixtureModulePath()` and never hard-code paths.
- Each test gets its own bootstrap path in the system temp directory, which is deleted in `tearDown`. Write cache files there through `manifestPath()`.
- Use `provider()` to get a fresh provider when a test needs to boot again after changing config or the cache.
- Rendering a Livewire component needs an `app.key`. Set one in the test.
