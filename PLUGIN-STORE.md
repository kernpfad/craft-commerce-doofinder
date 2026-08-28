# Craft Plugin Store copy — Commerce Doofinder

Paste into the [Craft Plugin Store](https://plugins.craftcms.com) developer portal. English is the store default.

**Documentation URL:** https://kernpfad.dev/en/craft/plugins/craft-commerce-doofinder/docs/

The fenced blocks below are the pasteable source (Markdown as the store field expects it). Copy the inner text, not the surrounding prose.

## Short description

```
Live Commerce catalog sync to Doofinder, zero-downtime reindex, and queued API calls — so search stays accurate without blocking product saves.
```

## Features

```markdown
- Live catalog sync (one Doofinder item per variant, grouped under the product)
- Queue-isolated API calls — a Doofinder outage never blocks a product save
- Zero-downtime full reindex (temporary index, then atomic swap)
- Images, category paths, stock, availability, and sale price
- Custom field mapping plus an agency payload hook
- No storefront UI — Doofinder’s Layer widget stays on the Doofinder side
```

## Long description

```markdown
**Commerce Doofinder** keeps a Doofinder search index in sync with Craft Commerce: one item per variant, grouped under the parent product, with queue-isolated API calls — so a Doofinder outage never blocks a product save. Doofinder’s own Layer widget handles the storefront; this plugin only manages the index.

- Live catalog sync (one Doofinder item per variant, grouped under the product)
- Queue-isolated API calls — a Doofinder outage never blocks a product save
- Zero-downtime full reindex (temporary index, then atomic swap)
- Images, category paths, stock, availability, and sale price
- Custom field mapping plus an agency payload hook
- No storefront UI — Doofinder’s Layer widget stays on the Doofinder side

### Live product / variant sync

Saving a variant upserts its Doofinder item with `group_id` / `group_leader` (Doofinder’s documented variant convention). Deletes, disables, future post dates, and expiry remove items from the index. Drafts, revisions, and multi-site propagation saves are skipped so the live index never fills with junk IDs.

### Zero-downtime full reindex

`php craft commerce-doofinder/reindex` builds a locked temporary index, pushes the catalog in chunks of 100 (Doofinder’s bulk limit), then swaps atomically. Search keeps hitting the complete old index until the swap. `--if-stale` (and a settings threshold) makes a nightly cron a safe safety net instead of a hammer.

### Images, categories, stock & sale price

Set an Assets field handle (variant first, then product) for `image_link`, optionally with a named transform. Categories become `Parent > Child > Leaf` breadcrumb paths from a Categories field or auto-discovery. Every item includes `availability` and `stock_quantity` from Commerce’s inventory system; untracked variants omit stock rather than reporting `0`. Promotional prices send `sale_price` when they are actually lower than the base price.

### Built for production Craft shops

- Custom field mapping via an editable CP table (Craft handle → Doofinder key)
- Agency hook `EVENT_MODIFY_ITEM_PAYLOAD` to reshape items before they leave Craft
- Env-based API token and search-engine hash ID for project config
- Connection test (CP button and `php craft commerce-doofinder/test`)
- Last sync / reindex status on the settings screen
- Dedicated queue component supported

**Requires** Craft CMS 5, Craft Commerce 5, PHP 8.2+, and a Doofinder account with an API token and search engine hash ID.
```
