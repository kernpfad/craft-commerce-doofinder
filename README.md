# Commerce Doofinder

A Doofinder integration for Craft Commerce: real-time product/variant sync to a Doofinder search index plus a zero-downtime full-catalog reindex command. Doofinder's own drop-in search widget handles the front end.

The plugin's whole job is keeping the index in sync. It never runs inline in a customer-facing request.

## Requirements

- Craft CMS 5.0.0+
- Craft Commerce 5.0.0+
- PHP 8.2+
- A [Doofinder](https://www.doofinder.com) account: an API token and a search engine hash ID, both from the Doofinder Admin Panel

## Installation

```sh
composer require kernpfad/craft-commerce-doofinder
php craft plugin/install commerce-doofinder
```

## What it does

- **Real-time sync on variant save and delete.** Saving a variant pushes its Doofinder item to the index, grouped under the parent product through `group_id` / `group_leader` — Doofinder's documented convention for variants. Deleting a variant or a whole product removes the corresponding items. Every API call runs on the queue, so a Doofinder outage never blocks a product save.
- **Zero-downtime full reindex.** `php craft doofinder/reindex` builds a fresh index in a locked temporary index, pushes the catalog into it in chunks of 100 items (Doofinder's documented bulk limit), then swaps it in atomically. Search traffic keeps hitting the complete, still-live old index for the whole run.
- **Automatic `image_link`.** Set `imageFieldHandle` to an Assets field handle (on the product or the variant) and its first asset's URL is sent as `image_link` — checked on the variant first, falling back to the product. `imageTransformHandle` optionally applies a named image transform.
- **Availability and stock.** Every item includes `availability` (from Commerce's own `Purchasable::getIsAvailable()` — enabled/draft/out-of-stock-purchasing-allowed all accounted for) and `stock_quantity` (Commerce's aggregated available stock across all inventory locations for the current store) for inventory-tracked variants. Untracked variants omit `stock_quantity` rather than reporting a misleading `0`.
- **Custom field mapping.** Map any Craft product field handle to any Doofinder item key. Read from the product, applied to every variant of it.
- **Configurable queue component.** Route sync jobs to a dedicated Yii queue component instead of Craft's default queue. Falls back to the default queue, logged rather than thrown, if the configured component is unavailable.

No front-end work is required. Doofinder's "Layer" widget is a single drop-in script configured entirely from their Admin Panel, so this plugin builds no search UI of its own.

## Settings

Under **Settings → Plugins → Commerce Doofinder**:

| Setting | Default | Description |
|---|---|---|
| `searchZone` | `eu1` | Which regional Doofinder endpoint the account lives in — `eu1`, `us1` or `ap1`. Determines the API host. |
| `apiToken` | `null` | API token from Account → API Keys. Can be an environment variable alias, e.g. `$DOOFINDER_API_TOKEN`. |
| `searchEngineHashId` | `null` | Target search engine's hash ID, 32 hex characters. Can be an environment variable alias, e.g. `$DOOFINDER_SEARCH_ENGINE_HASH_ID`. |
| `indexName` | `product` | The index products and variants are synced into. |
| `queueComponentId` | `queue` | Yii application component the sync jobs are pushed to. |
| `fieldMappingRaw` | empty | Custom field mapping, one `handle=doofinderKey` per line. |
| `imageFieldHandle` | `null` | Assets field handle (product or variant) resolved into `image_link`. |
| `imageTransformHandle` | `null` | Optional named image transform applied to the asset above. |

## Console commands

| Command | Purpose |
|---|---|
| `php craft doofinder/reindex` | Full zero-downtime catalog reindex. |

## Limitations

- **No categories.** Commerce has no single universal category concept — a store's structure may be a category group, a Matrix field or product types. Map it through the field mapping if a suitable field exists.
- **`availability`/`stock_quantity` aren't part of Doofinder's reserved field set.** They're sent as plain custom fields (undocumented by Doofinder, but accepted — its item schema allows arbitrary extra keys) rather than fields Doofinder is guaranteed to facet/filter on out of the box; configure that on the Doofinder side if needed.

## License

Licensed under the [Craft License Agreement](LICENSE.md).

[Legal notice](https://kernpfad.dev/en/legal-notice) · [Privacy policy](https://kernpfad.dev/en/privacy-policy) · [Terms and conditions](https://kernpfad.dev/en/terms-and-conditions)
