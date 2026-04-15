# Theme Lab Snapshot

The `/public_html/test/` playground includes a static snapshot viewer for
evaluating new UI designs. It reads `live-snapshot.json` by default and can also
read any JSON payload with the same shape via query string.

## Refresh snapshot data

Generate a live-like dataset from your configured database:

```bash
php bin/export-theme-snapshot.php
```

Optional env overrides control how many rows are exported for each section:

- `THEME_SNAPSHOT_TICKETS`
- `THEME_SNAPSHOT_CUSTOMERS`
- `THEME_SNAPSHOT_AGENTS`
- `THEME_SNAPSHOT_ACTIVITY`
- `THEME_SNAPSHOT_CALENDAR`

You can target a custom output file with:

```bash
php bin/export-theme-snapshot.php --output=public_html/test/live-snapshot.json
```

`make deploy` now runs snapshot generation on production after deploy, so the test lab
is populated automatically. You can also run it manually with:

```bash
make snapshot
```

### Using alternate snapshot source in the UI

By default the viewer loads `live-snapshot.json`, but you can test a different file
or endpoint with:

```
/test/index.html?snapshot=https://your-host.example/live-snapshot.json
```

Accepted values are any URL/path that returns the same JSON structure.

If `live-snapshot.json` is missing, the viewer uses `live-sample.json` as a fallback.
