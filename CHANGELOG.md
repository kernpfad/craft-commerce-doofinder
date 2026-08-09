# Release Notes for Commerce Doofinder

## 1.0.0 - Unreleased

- Initial release.
- Real-time sync of products/variants to a Doofinder search index on save/delete, grouped by product via `group_id`/`group_leader`.
- `php craft doofinder/reindex` — a zero-downtime full-catalog reindex using Doofinder's temporary-index + atomic-replace workflow.
- Custom field mapping — map any Craft product field to any Doofinder item custom field.
- Configurable queue component, same pattern as Commerce Klaviyo.
- Every Doofinder API call runs on the queue; a Doofinder outage never blocks a product save.
- Added explicit request timeouts (5s connect, 15s total; 60s for the bulk endpoint used by the reindex command) to the Doofinder API client. Craft's shared Guzzle client sets none, so an unresponsive Doofinder would have held a queue worker open until PHP's execution limit rather than failing and letting Craft retry.
- Fixed: drafts and revisions were being indexed as if they were real products. Because Craft creates a revision on every control-panel publish, an actively-edited store accumulated a junk entry in the live search index per edit — keyed to the draft/revision's element ID, never removed, and shown in real customer search results. Drafts, revisions, and multi-site propagation saves are now all skipped.
- Fixed: disabled products and variants remained in the Doofinder index after being turned off. Disabled variants are now removed on save; disabling a whole product removes every variant item even when Commerce does not fire separate variant save events.
- Fixed: a null product on variant delete could crash job queueing.
- Added: `FieldValueNormalizer` to flatten Craft field values (assets, options, categories, dates, etc.) into JSON-safe scalars before they are sent to Doofinder.
- Added: `declare(strict_types=1)` across all plugin and test source files.
- Added: automatic `image_link` resolution. New `imageFieldHandle` setting (checked on the variant first, falling back to the product) plus an optional `imageTransformHandle` for a named image transform.
- Added: `availability` and `stock_quantity` on every synced item, sourced from Commerce's own inventory system (`Purchasable::getIsAvailable()` / `getStock()`). Inventory-untracked variants omit `stock_quantity` rather than reporting a misleading `0`.
- Added: `apiToken` and `searchEngineHashId` now accept environment variable aliases (e.g. `$DOOFINDER_API_TOKEN`), resolved via `craft\helpers\App::parseEnv()`, so real credentials never have to be committed to project config. The settings screen now uses Craft's standard env-var-autosuggest field for both.
