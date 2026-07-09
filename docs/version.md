# Version

Current release: **1.4.21-dev.0** (2026-07-09)

See [changelog.md](changelog.md) for full history.

## How versioning works

- `version.json` in the repository root is the authoritative installed version file. Stable releases use versions like `1.4.17`; development releases use prerelease versions like `1.4.17-dev.1`.
- `GET /api/version` returns the installed version from that file.
- The admin General settings route (`#/admin/settings/general`) shows the installed version, configured update channel, and a **Check for Updates** button. Stable checks use `version.json` from the public GitHub `main` branch by default. Development checks use `version.json` from the public GitHub `development` branch by default. `UPDATE_VERSION_URL` can override either source.
- Admin sessions also perform a silent background update check at most once per day via the notification center. Overlapping checks for the same admin are serialised server-side before fetching upstream metadata, and if a newer version is found, an in-app notification is created linking directly to `#/admin/settings/general`.
- When an update is available, an **Update Now** button opens a preflight checklist followed by a one-click updater that downloads, extracts, copies files, and runs database migrations automatically.
- The preflight now checks both writable directories and whether existing files are actually overwriteable by the PHP process. This matters on shared hosting, where PHP often cannot replace files owned by your account even if the directory itself appears writable.
- Release metadata may declare `minimum_update_from`. When set, the updater blocks direct upgrades from older installs to newer releases and tells the admin which bridge version to install first.
- The version metadata source can be overridden with `UPDATE_VERSION_URL`. The update channel can be overridden with `UPDATE_CHANNEL=stable` or `UPDATE_CHANNEL=development`. The updater package source can be overridden with `UPDATE_REPO_ZIP_URL` and `UPDATE_REPO_PREFIX`; otherwise it uses the public GitHub metadata to prefer the full release package for the latest version, falling back to the GitHub source zip if the package is unavailable and Composer is available.
- When a new stable release is ready, prepare the `docs/changelog.md` `Unreleased` section with the relevant notes, then run `make release` from `main`. That target refuses to run from other branches, creates a plain stable version such as `1.4.17`, updates release metadata files, converts the prepared `Unreleased` notes into the new changelog section, commits, tags `v1.4.17`, and pushes.
- When a development release is ready, run `make dev-release` from the `development` branch. That target creates or increments the prerelease version, for example `1.4.17-dev.1`, commits, tags `dev-v1.4.17-dev.1`, and pushes. GitHub Actions builds a full package for both stable and development tags.
- `make deploy` now runs `php bin/migrate.php` on the remote host after `composer install`, so numbered DB migrations are applied during normal rsync deployments as well as in-app updates.

## Release channels

Andrea Helpdesk supports two update channels:

- `stable` — production releases from `main`, tagged `vX.Y.Z`, with full packages named `andrea-helpdesk-X.Y.Z-full.zip`.
- `development` — prerelease builds from `development`, tagged `dev-vX.Y.Z-dev.N`, with full packages named `andrea-helpdesk-X.Y.Z-dev.N-full.zip`.

Stable and development stay on the same numeric version line. A normal sequence is:

```text
stable:      1.4.16
development: 1.4.17-dev.1
development: 1.4.17-dev.2
stable:      1.4.17
```

A stable install can opt into development from **Settings → General → Version & Updates**. A development install can change the setting back to stable, but the updater will never downgrade. If the installed version is `1.4.17-dev.2` and latest stable is `1.4.16`, the install waits until stable reaches `1.4.17` or newer.

## In-app updater

The updater (`src/Core/UpdateController.php`) performs the following steps:

1. **Preflight** (`GET /api/update/preflight`) — checks upgrade-path compatibility, ZipArchive extension, download capability, a PHP dependency update path, full release package availability when Composer is unavailable, write permissions on key directories including `vendor/`, overwriteability of existing files in those directories, temp directory, and disk space. Returns pass/fail per check with specific fix instructions.
2. **Run** (`POST /api/update/run`) — downloads the full GitHub release package for the configured update channel when available, otherwise falls back to the configured/source zip only when Composer is available, extracts it, copies files over the installation (skipping `.env`, `storage/`, `.git`, `install.lock`, `Makefile.local`, and `docs/videos`), updates PHP dependencies from packaged `vendor/` files or by running `composer install --no-dev --optimize-autoloader`, runs `schema.sql`, applies new migrations tracked in the `schema_migrations` DB table, and resets the opcode cache.

The full GitHub release package includes `vendor/`, so shared-hosting installs without Composer can still receive new PHP dependencies through the web updater as long as the PHP process can overwrite the application files. If Composer is unavailable and the full release package is not available yet, wait for the GitHub release workflow to finish and retry; the updater must not use the source archive in that case. If a custom `UPDATE_REPO_ZIP_URL` points at a source archive without `vendor/`, Composer must be installed and executable by PHP.

### Bridge Update Requirement

Version `1.4.9` is the updater bridge release. Installs older than `1.4.9` must update to `1.4.9` before updating to `1.4.10` or newer, because `1.4.9` adds support for full release packages and packaged `vendor/` dependencies.

Current and future releases can enforce this by setting these fields in `version.json`:

```json
{
  "minimum_update_from": "1.4.9",
  "minimum_update_reason": "Version 1.4.9 upgrades the updater so full release packages and packaged vendor dependencies can be installed safely."
}
```

This cannot be enforced retrospectively by updater code that is already installed on a pre-`1.4.9` host, because that old updater does not know about bridge metadata. Those hosts should be manually directed to update to `1.4.9` first.

Database migrations are tracked in `schema_migrations` (auto-created on first update or CLI migrate run). Migration `001_initial.sql` is always skipped as it is covered by `schema.sql`. New migrations are applied in filename order. The updater lock remains held until schema and migration work completes, preventing overlapping update runs from racing each other.
