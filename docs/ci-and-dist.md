# CI and the dist archive

- Dev-only files (tests, CI, tooling configs, `docs/`, `AGENTS.md`) need an `export-ignore` line in `.gitattributes` so they stay out of the dist archive.
- CI uses no third-party actions beyond `actions/checkout`: inline shell or a composite action under `.github/actions/`. CI jobs run in `php:*-cli` containers, which ship without git. Install git before `actions/checkout`, or it downloads the GitHub tarball, which leaves out every `export-ignore` path (`.github`, `tests`).
