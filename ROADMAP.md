# Commerce Doofinder — Roadmap & Agent-Prompt

**Package:** `kernpfad/craft-commerce-doofinder`  
**Handle:** `commerce-doofinder`

## Ist-Stand (kurz)

- Realtime Variant-Sync zur Doofinder Management API
- Full Reindex mit Temp-Index + atomic Swap (`commerce-doofinder/reindex`)
- Field Mapping Freitext; Queue-Isolation
- Automatisches `image_link` (Settings: `imageFieldHandle` + optional `imageTransformHandle`)
- `availability` + `stock_quantity` aus Commerce Inventory (`Purchasable::getIsAvailable()`/`getStock()`)
- `apiToken`/`searchEngineHashId` als Env-Alias (`$DOOFINDER_*`, via `App::parseEnv()`)
- Limits: kein Categories-Default, kein eingebauter Connection-Test

## Warum das wehtut

Doofinder Minimal Fields erwarten u. a. `image_link`, oft `availability` / `stock_quantity` — inzwischen gelöst (DF-01/DF-02). Verifiziert gegen den echten `craftcms/commerce`-Quellcode (5.0.0–5.7.1, API stabil über die ganze `^5.0.0`-Spanne): `Purchasable::$inventoryTracked`, `getStock()` (bereits über alle Inventory-Locations des Stores aggregiert), `hasStock()`, `getIsAvailable()` (Variant-Override berücksichtigt Draft/Revision/Produktstatus zusätzlich).

---

## Backlog

### P0

| ID | Klasse | Item | Status |
|---|---|---|---|
| ~~DF-01~~ | B | Settings: Image-Asset-Feld → `image_link` (URL-Auflösung inkl. Transformer optional) | ✅ erledigt |
| ~~DF-02~~ | B | `availability` + `stock_quantity` aus Commerce Inventory (tracked/untracked) | ✅ erledigt |
| ~~DF-03~~ | D | API Token / Hash ID via Env-Aliase | ✅ erledigt |
| DF-04 | D | Connection-Test (Token + Hash ID + Index erreichbar) | offen — kein dokumentierter leichtgewichtiger GET-Endpoint in der Doofinder Management API gefunden, braucht noch einen kurzen Recherche-Schritt |

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
P0-Rest: Connection-Test (DF-04). DF-01–DF-03 (Image-Link, Stock/Availability,
Env-Secrets) sind bereits erledigt — Payload-Builder, Settings und
CatalogSyncService nicht regressieren.

## Kontext
- Handle: `commerce-doofinder`
- Sync pro Variant; `group_id` = Product ID; ein `group_leader`
- Reindex existiert bereits — Payload-Builder und Settings erweitern, Reindex nicht regressieren
- Doofinder Management API v2, Zone aus Settings (`eu1`/`us1`/`ap1`)
- Index name muss exakt passen (oft `products`, nicht `product`)
- Kein dokumentierter leichtgewichtiger GET-Endpoint für Credentials-/Connectivity-Checks bekannt — vor der Implementierung gegen die echte Doofinder Management-API-Referenz verifizieren (nicht raten), z. B. ob `GET .../indices/{name}/` Index-Metadaten liefert.

## Anforderungen
1. **Test-Command:** `php craft doofinder/test` (oder Connection-Test-Action im CP) prüft Credentials + Index, ohne Nebenwirkungen auf den Live-Index

## Qualitätsregeln
- Bestehende FieldMapper/DoofinderClient/CatalogSyncService Patterns nutzen
- Unit-Tests für den neuen Client-Call (gemockter Guzzle-Client, wie in `DoofinderClientTest`)
- README Limits aktualisieren (welche gelöst)
- Keine Tokens committen; craft-lab `.env` nur lokal

## Test
php craft doofinder/test
php craft doofinder/reindex
# Doofinder Admin: Item hat image_link + stock_quantity + availability
```
