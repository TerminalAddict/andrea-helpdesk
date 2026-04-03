# Version

Current release: **1.0.0** (2026-04-03)

See [changelog.md](changelog.md) for full history.

## How versioning works

- `version.json` in the repository root is the authoritative version file.
- `GET /api/version` returns the installed version from that file.
- The Settings → General tab shows the installed version and offers a **Check for Updates** button, which fetches `version.json` from the `main` branch on GitHub and compares.
- When a new release is ready, update `version.json`, `docs/version.md`, and `docs/changelog.md`, then commit and push. All installed instances will detect the new version on their next check.
