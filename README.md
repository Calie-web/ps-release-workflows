# release-kit

Tooling to publish signed releases of ThoumProduction PrestaShop modules, consumed
by `thoumproduction_updater`.

## One-time setup

1. Generate a signing key pair (offline):
   ```
   php keygen.php
   ```
   - Put the **PUBLIC** key in every shop's `TPU_TRUSTED_KEYS` (updater config).
   - Store the **SECRET** key as the GitHub secret `TPU_SIGNING_KEY` (org or repo).
     Never commit it.

2. Create a shared repo (e.g. `Calie-web/ps-release-workflows`) and copy the
   contents of this folder to its **root**, plus `release.yml` to
   `.github/workflows/release.yml`.

## Per module

1. Add `release.json` (copy `release.json.dist`, set `module` and the DB flags).
2. Add `.github/workflows/release.yml` (copy `caller.yml`).
3. Release:
   ```
   # bump $this->version and config.xml <version> + CHANGELOG, commit
   git tag v1.2.0 && git push origin v1.2.0
   ```

The workflow verifies tag == version, builds a reproducible ZIP (root = module),
computes SHA-256, writes and signs `metadata.json`, and publishes the Release with
`<module>-<version>.zip`, `metadata.json`, `metadata.json.sig`.

## Scripts

- `build.php <moduleDir> <release.json> <outDir>` — package + metadata
- `verify_version.php <moduleDir> <tag>` — tag == $this->version == config.xml
- `sign.php <file> <outSig>` — Ed25519 detached signature (needs `TPU_SIGNING_KEY`)
- `keygen.php` — generate a key pair

## Package contents & pruning

`build.php` ships the module's runtime files and `vendor/`, and prunes non-runtime
cruft **at any depth** (including inside `vendor/`) so packages stay small:

- Directories dropped anywhere: `.git`, `.github`, `.gitlab`, `.circleci`,
  `node_modules`, `.idea`, `.vscode`, `tests`, `Tests`, `test`, `docs`, `doc`,
  `examples`, `example`, `benchmarks`, `coverage`.
- Files dropped anywhere: `*.md`, `phpunit.xml(.dist)`, `phpstan.neon(.dist)`,
  `psalm.xml(.dist)`, `.php-cs-fixer*`, `.php_cs*`, `.gitignore`, `.gitattributes`,
  `.editorconfig`, `.travis.yml`, `.scrutinizer.yml`, `Makefile`, `.DS_Store`,
  `release.json`.

Combine this with `composer install --no-dev` (the workflow does) for the smallest
result. Tune per module in `release.json`:

- `"exclude"`: extra fnmatch patterns (matched on the full path and the basename),
  e.g. `["docs-site/*", "*.map"]`.
- `"keep"`: fnmatch patterns that force-include a path despite pruning, for the rare
  dependency that needs a normally-pruned file, e.g. `["vendor/acme/lib/data/*.md"]`.
