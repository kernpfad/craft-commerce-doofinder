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
- Connection-Test via `GET .../indices/{name}/` (DF-04, s.u.)
- Limits: kein Categories-Default

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
| DF-04 | D | Connection-Test (Token + Hash ID + Index erreichbar) | ✅ erledigt |

Umgesetzt in dieser Iteration (DF-04):
- Recherche gegen die echte, offizielle Doofinder-Dokumentation (`docs.doofinder.com/api-reference/indices/get.md`) sowie den Quellcode des offiziellen PHP-Clients (`doofinder/php-doofinder`, `Index.php`-Resource) ergab den gesuchten leichtgewichtigen Endpoint: `GET /api/v2/search_engines/{hashid}/indices/{name}` — liefert Index-Metadaten (200), `401` bei fehlendem/ungültigem Token, `403` bei fehlender Berechtigung, `404` wenn der Index nicht existiert. Auth per `Authorization: Token {api-key}` funktioniert dafür genauso wie für alle anderen bereits implementierten Endpoints dieses Clients (nicht nur JWT, wie es der offizielle PHP-Client für Index-Operationen nutzt — beide Auth-Wege sind laut Doku zulässig).
- `DoofinderClient::getIndex()` (neuer, rein lesender Call, gleiches Auth-/Timeout-Verhalten wie die bestehenden Calls) + `CommerceDoofinder::testConnection()` + CP-Button ("Test connection" auf der Settings-Seite) + `php craft commerce-doofinder/test`.
- Live gegen den echten Doofinder-API-Endpoint verifiziert (mit absichtlich ungültigem Test-Token): Antwort war exakt der dokumentierte `401 not_authenticated`-Fehler — bestätigt URL, Auth-Header und Fehlerbehandlung Ende-zu-Ende gegen die echte API, nicht nur gegen einen Mock.
- Nebenbei behoben: README und `ReindexController`-Docblock nannten den Reindex-Befehl fälschlich `doofinder/reindex` statt `commerce-doofinder/reindex` (der tatsächliche, funktionierende Handle).

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

> P0 (DF-01–DF-04) ist komplett erledigt (siehe Status oben). Für die nächste Iteration eignen sich aus P1: DF-05 (Categories), DF-06 (`sale_price`) oder DF-07 (CP: letzter Fehler) — alle ohne offene Recherchefragen umsetzbar.

```markdown
Du arbeitest im Repo `kernpfad/craft-commerce-doofinder` (Craft 5 / Commerce 5).

## Ziel
P0 (DF-01–DF-04) ist erledigt. Payload-Builder, Settings, CatalogSyncService
und den bestehenden Connection-Test (`commerce-doofinder/test`) nicht regressieren.

## Kontext
- Handle: `commerce-doofinder`
- Sync pro Variant; `group_id` = Product ID; ein `group_leader`
- Reindex (`commerce-doofinder/reindex`) und Connection-Test (`commerce-doofinder/test`) existieren bereits
- Doofinder Management API v2, Zone aus Settings (`eu1`/`us1`/`ap1`)
- Index name muss exakt passen (oft `products`, nicht `product`)

## Qualitätsregeln
- Bestehende FieldMapper/DoofinderClient/CatalogSyncService Patterns nutzen
- Unit-Tests für neue Client-Calls (gemockter Guzzle-Client, wie in `DoofinderClientTest`)
- README Limits aktualisieren (welche gelöst)
- Keine Tokens committen; craft-lab `.env` nur lokal

## Test
php craft commerce-doofinder/test
php craft commerce-doofinder/reindex
# Doofinder Admin: Item hat image_link + stock_quantity + availability
```
