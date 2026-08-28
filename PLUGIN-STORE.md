# Craft Plugin Store copy — Commerce Doofinder

Paste into the [Craft Plugin Store](https://plugins.craftcms.com) developer portal. English is the store default.

**Documentation URL:** https://kernpfad.dev/en/craft/plugins/craft-commerce-doofinder/docs/

The fenced blocks below are the pasteable source (Markdown as the store field expects it). Copy the inner text, not the surrounding prose.

## Name

```
Commerce Doofinder
```

## Description

```
Accurate Doofinder search for Craft Commerce — live as you edit, rebuilt without downtime, never in the way of a product save.
```

## Features

Each feature is a **Name** plus a one-line **Description** (English, store-facing).

```markdown
**Live catalog sync**
Variants land in Doofinder the moment you save them, grouped under the parent product the way Doofinder expects.

**Saves that never wait**
Every API call runs on the queue. If Doofinder is down, editors still publish.

**Reindex without downtime**
Rebuild the catalog in the background, then swap it in. Shoppers keep searching the complete index until the new one is ready.

**Ready for the Layer**
Images, category paths, stock, availability, and sale prices flow through so Doofinder’s widget has real catalog data to show.

**Mapped your way**
Point any Craft field at any Doofinder key in the control panel, or reshape items in project code before they leave Craft.

**Index only — Layer stays with Doofinder**
No search UI to theme or maintain. Doofinder’s Layer handles the storefront; this plugin keeps the index honest.
```

## Long description

```markdown
**Commerce Doofinder** keeps Doofinder search true to your Craft Commerce catalog. Save a product, and search follows — without downtime, and without making editors wait on an API.

**Live catalog sync**
Variants land in Doofinder the moment you save them, grouped under the parent product the way Doofinder expects. Deletes, disables, future post dates, and expiry drop out of the index. Drafts and revisions never get in.

**Saves that never wait**
Every Doofinder API call runs on the queue. If Doofinder is down, product saves still go through.

**Reindex without downtime**
`php craft commerce-doofinder/reindex` builds a locked temporary index, pushes the catalog in chunks, then swaps atomically. Shoppers keep hitting the complete old index until the new one is live. `--if-stale` makes a nightly cron a safety net, not a hammer.

**Ready for the Layer**
Set an Assets field for `image_link` (optional transform). Categories become `Parent > Child > Leaf` paths. Stock, availability, and sale price come from Commerce so Doofinder’s Layer has real catalog data to show.

**Mapped your way**
Map Craft handles to Doofinder keys in an editable CP table, or hook `EVENT_MODIFY_ITEM_PAYLOAD` to reshape items before they leave Craft. API token and search-engine hash ID can live in env vars.

**Index only — Layer stays with Doofinder**
No search UI to theme or maintain. Doofinder’s Layer handles the storefront; this plugin keeps the index honest.

Connection test, last sync / reindex status, and a dedicated queue component are on the settings screen.

**Requires** Craft CMS 5, Craft Commerce 5, PHP 8.2+, and a Doofinder account with an API token and search engine hash ID.
```
