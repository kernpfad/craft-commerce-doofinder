# Craft Plugin Store — screenshots

Upload these PNGs in the Craft Console Plugin Store listing (carousel order).

**Folder:** `store-screenshots/`

| # | File | Shows |
|---|---|---|
| 1 | `01-setup-api.png` | Search zone, API token, hash ID, index name + Test connection |
| 2 | `02-field-mapping.png` | Custom field mapping table (Craft handle → Doofinder key) |
| 3 | `03-image-categories.png` | Image field / transform + categories field / auto-discover |
| 4 | `04-reindex-status.png` | Stale-hours threshold, cron example, last sync / reindex status |

**Recommended carousel:** 1 → 2 → 3 → 4.

**Icon (already in the plugin package):** `src/icon.svg` (square SVG). Nav mask: `src/icon-mask.svg`.

**Documentation URL:** https://kernpfad.dev/en/craft/plugins/craft-commerce-doofinder/docs/

Notes:
- Screenshots are English CP UI (matches Store / plugin default language).
- Cropped from a full settings page capture.
- For a fresh full-page reshoot from craft-lab: `SHOT_LANGS=en ./shoot.sh craft-commerce-doofinder` with `fullPage: true` on the settings shot in `shots.config.mjs`.
