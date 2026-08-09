# Commerce Doofinder — Roadmap & Agent-Prompt

**Package:** `kernpfad/craft-commerce-doofinder`  
**Handle:** `commerce-doofinder`

## Ist-Stand (kurz)

- Realtime Variant-Sync zur Doofinder Management API
- Full Reindex mit Temp-Index + atomic Swap (`commerce-doofinder/reindex`)
- Field Mapping Freitext; Queue-Isolation
- Limits: kein automatisches `image_link`, kein Stock/Availability, kein Categories-Default

## Warum das wehtut

Doofinder Minimal Fields erwarten u. a. `image_link`, oft `availability` / `stock_quantity`. Ohne Bild/Stock ist die Layer-Suche in Produktion oft unbrauchbar — das ist Klasse B, kein Kosmetik-Limit.

---

## Backlog

### P0

| ID | Klasse | Item |
|---|---|---|
| DF-01 | B | Settings: Image-Asset-Feld → `image_link` (URL-Auflösung inkl. Transformer optional) |
| DF-02 | B | `availability` + `stock_quantity` aus Commerce Inventory (tracked/untracked) |
| DF-03 | D | API Token / Hash ID via Env-Aliase |
| DF-04 | D | Connection-Test (Token + Hash ID + Index erreichbar) |

### P1

| ID | Klasse | Item |
|---|---|---|
| DF-05 | B | Categories aus Commerce Categories oder konfiguriertem Feld |
| DF-06 | B | `sale_price` Mapping wenn vorhanden |
| DF-07 | D | CP: letzter Reindex-/Sync-Fehler |
| DF-08 | D | Unpublished/deleted konsistent entfernen oder `not_published_in` |

### P2

| ID | Klasse | Item |
|---|---|---|
| DF-09 | D | Cron-freundlicher Schedule-Hinweis + optional `reindex --if-stale` |
| DF-10 | D | Payload-Mutator Events |
| DF-11 | B | Mapping-UI (Zeilen statt Freitext) |

### Nicht tun

- Doofinder Layer/JS in dieses Plugin ziehen
- Feed-URL statt API als einzigen Sync-Weg erzwingen (API ist Differentiator)

---

## Agent-Prompt (kopieren)

```markdown
Du arbeitest im Repo `kernpfad/craft-commerce-doofinder` (Craft 5 / Commerce 5).

## Ziel
P0: Image-Link, Stock/Availability, Env-Secrets, Connection-Test (DF-01–DF-04).

## Kontext
- Handle: `commerce-doofinder`
- Sync pro Variant; `group_id` = Product ID; ein `group_leader`
- Reindex existiert bereits — Payload-Builder und Settings erweitern, Reindex nicht regressieren
- Doofinder Management API v2, Zone aus Settings (`eu1`/`us1`/`ap1`)
- Index name muss exakt passen (oft `products`, nicht `product`)

## Anforderungen
1. **imageFieldHandle** (Settings + Twig): Asset-Feld am Product oder Variant → absolute `image_link`
2. **Stock:** tracked Variants → echte Menge + availability; untracked → sinnvoller Default (dokumentieren)
3. **Env:** `apiToken` / Hash ID dürfen `$DOOFINDER_*` Aliase sein
4. **Test-Command:** `php craft commerce-doofinder/test` prüft Credentials + Index

## Qualitätsregeln
- Bestehende FieldMapper/DoofinderClient/CatalogSyncService Patterns nutzen
- Unit-Tests für Mapping/Availability
- README Limits aktualisieren (welche gelöst)
- Keine Tokens committen; craft-lab `.env` nur lokal

## Test
php craft commerce-doofinder/test
php craft commerce-doofinder/reindex
# Doofinder Admin: Item hat image_link + stock_quantity
```
