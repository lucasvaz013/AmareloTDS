# ytds CLI

`ytds` is a machine-facing client for operating a AmareloTDS instance: reading campaigns and reports, making safe mutations, and authoring new campaigns from versioned templates. It exists so an operator or an LLM agent works against a stable JSON contract instead of clicking the panel or editing SQLite by hand.

The CLI is not part of the deployed system. It lives in `bin/ytds` and `cli/` at the repository root, outside `code/`, so the auto-updater never ships it and it is never reachable over HTTP. It reuses the same `CampaignService` and validators as the admin panel, so the panel and the CLI are two doors to one write path.

## Requirements and layout

- PHP 8 CLI with the `sqlite3` extension (the same runtime the engine uses).
- `bin/ytds` — the executable entry point.
- `cli/` — dispatcher, bootstrap, remote transport, and the `doctor` checks.
- `code/campaignservice.php`, `code/adminops.php` — the shared read/mutation logic.
- `code/api/admin.php` — the HTTP endpoint the remote mode talks to.
- `code/templates/` — versioned campaign templates used by `create`.

Run it directly:

```
bin/ytds <command> [args] [flags]
```

## Two modes: local and remote

- **Local** (`--env local`, the default) runs the operation in-process against an instance database on the same machine. Use `--db <path>` to point at a specific SQLite file; without it, the CLI resolves the database from the instance settings. Local mode never creates a missing database — it reports `DB_NOT_FOUND` instead.
- **Remote** (`--env stg`, `--env prod`, or any configured name) proxies the operation to that instance's admin API over HTTPS. Reads are `GET`; mutations are `POST`. The instance must have the admin API enabled (see [Admin API](admin-api.md)).

### Remote configuration

Remote environments are read from `~/.config/ytds/config.json` (override the path with the `YTDS_CONFIG` environment variable). The file is per-operator and never committed:

```json
{
  "environments": {
    "stg":  { "url": "https://stg.example.com/api/admin.php", "token": "<adminApiToken>" },
    "prod": { "url": "https://tds.example.com/api/admin.php", "token": "<adminApiToken>" }
  }
}
```

The `token` is the instance's `adminApiToken` and is sent as `Authorization: Bearer`. Store the file `0600`; the token is never printed by the CLI.

## Output contract

- **stdout** carries only the result as pretty-printed JSON.
- **stderr** carries only a structured error object: `{ "code": "...", "message": "...", "hint": "..." }`.
- The two streams are never mixed: a successful run writes nothing to stderr, and an error writes nothing to stdout.

### Exit codes

| Code | Meaning |
|---|---|
| 0 | success |
| 1 | internal/environment error (`INTERNAL`, `SETTINGS_CORRUPT`, `TRANSPORT_ERROR`, `WRITE_FAILED`), or `doctor` reporting a failed check |
| 2 | input/conflict validation (`USAGE`, `INVALID_ARG`, `INVALID_CSV`, `INVALID_CURRENCY`, `COST_IMPORT_NOT_READY`, `COST_MATCH_AMBIGUOUS`, `UNKNOWN_ACTION`, `VALIDATION`, `RESOURCE_IN_USE`) |
| 3 | not found (`DB_NOT_FOUND`, `CAMPAIGN_NOT_FOUND`, `SECTION_NOT_FOUND`, `NETWORK_NOT_FOUND`, `DESTINATION_NOT_FOUND`, `LANDING_NOT_FOUND`) |
| 4 | auth/config (`AUTH_INVALID`, `API_DISABLED`, `CONFIG_MISSING`, `CONFIG_INVALID`) |
| 5 | domain conflict (`DOMAIN_CONFLICT`) |

## Safety model for mutations

Every mutation (`create`, `clone`, `rename`, `delete`, `domains`, `patch`, `kill-defaults`, `costs import`, and the `networks`/`destinations`/`landing` write verbs) is a **dry run by default**: it validates the request, checks domain overlap where relevant, and prints what would change, without writing. Add `--yes` to commit.

- Reads mask secrets: `apikey`, the legacy CAPI access token, every `capi.pixels[].access_token`, and postback keys come back as `<redacted>`.
- Writes read the raw stored settings, so a mutation can never persist `<redacted>` over a real secret.
- Domain changes are validated against every other campaign; an overlap is refused with exit 5 in both dry run and commit.
- Network and Destination deletion is refused with `RESOURCE_IN_USE` when a campaign Checkout Route still references the entry; the hint lists each campaign, flow, and step. The Networks/Destinations panel pages use the same check when a save would drop those ids.
- `create` builds from a versioned template with the author's three dangerous defaults already removed, and refuses any template that still references the author's trackers (Guardrail #9).

## Global flags

| Flag | Applies to | Meaning |
|---|---|---|
| `--env <name>` | all | `local` (default) or a configured remote environment |
| `--db <path>` | local | SQLite database file to operate on |
| `--yes` | mutations | commit instead of dry-run |

## Commands

### Reading

| Command | Description |
|---|---|
| `campaigns list` | narrow list of campaigns: `id`, `name`, `domains`, flow count |
| `campaign get <id>` | one campaign; `--section <dot.path>` returns a subtree, `--full` returns the whole (redacted) settings |
| `stats --campaign <id>` | aggregate metrics for a date window; `--from`/`--to` in `DD.MM.YY`, `--columns`, `--groupby` (including `network`) |
| `clicks --campaign <id>` | recent clicks (narrow columns include frozen `network_id`/`network`); `--view allowed\|blocked\|leads\|trafficback`, `--from`/`--to`, `--limit`, `--page`, `--sort <field>` (including `network` and `param.KEY`), `--dir asc\|desc`, repeatable `--filter field:op:value` (`network` values are ids) with `--filter-cond and\|or`, repeatable `--param KEY`, `--search <term>`, `--full` |
| `landing list` | landing folders with metadata |
| `landing download <name> --out <file.zip>` | stream one landing from local/STG/prod into a newly created, verified ZIP |
| `networks list` | global Networks library (`common.settings`) |
| `destinations list` | global Destinations with their network resolved into an effective URL |
| `version` | instance `version.txt` and PHP version |
| `doctor` | local environment health checks (local only) |

`clicks --view trafficback` needs no `--campaign`.

### Safe mutations

| Command | Description |
|---|---|
| `campaign clone <id> [--name <new>]` | duplicate a campaign; the clone starts with no domains |
| `campaign rename <id> --name <new>` | rename |
| `campaign delete <id>` | delete |
| `campaign domains <id> --set a.com,b.com` | replace the campaign's domain list (validated for overlap) |
| `campaign patch <id> (--apply <file.json> \| --set path=value ...)` | merge a settings fragment through the panel validators; `--apply` takes a whole section as JSON, repeatable `--set` assigns dotted paths onto the current settings (the two are mutually exclusive); dry run shows a redacted top-level diff |
| `campaign kill-defaults <id>` | remove the author's three dangerous defaults (country filter, redirect, postback) if present; idempotent |
| `costs import --file <meta.csv>` | reconcile historical Meta spend by report date plus exact `utm_campaign`; preview all matched/unmatched rows before `--yes` |
| `networks add\|update\|delete [<id>]` | manage the global Networks library (`--name`, `--params`) |
| `destinations add\|update\|delete [<id>]` | manage the global Destinations library (`--name`, `--base-url`, `--network <id>`) |
| `landing upload <name> --zip <file>` | extract a ZIP into a new landing folder (rejects `../` and absolute entries) |
| `landing edit <name> --file <relative-path> --apply <manifest.json>` | apply exact counted replacements to an existing file; dry run reports before/after SHA-256 and match counts |
| `landing replace <name> --zip <file>` | atomically replace an existing landing from a verified root-index ZIP |
| `landing duplicate <from> <to>` | copy a landing folder to a new name |
| `landing delete <name>` | delete a landing folder; dry run first lists the campaigns that reference it |

`patch` runs the same uniqueness, event, conversion, postback, CAPI, and flow validators as a panel save, then a recursive merge. It is the general tool for `{link:N}`, folder, and step edits: supply the complete section as the fragment, exactly as the panel would post it. An empty object or a JSON array is refused (it would wipe the settings).

`costs import` accepts a comma-separated Meta Ads Manager campaign report in USD with Portuguese headers `Nome da campanha`, `Valor gasto (USD)`, and `Início dos relatórios`. `Moeda`, `Identificação da campanha`, and `Cliques no link` are optional metadata. Blank campaign-name total rows are ignored, and duplicate date/name rows are combined. Matching uses the Meta report date in each TDS campaign timezone plus an exact `params.utm_campaign` value; a row matching zero or multiple TDS campaigns prevents commit. For each matched campaign-day, spend is distributed deterministically across every allowed TDS click at 1e-8 USD precision. The operation replaces that group's costs rather than incrementing them, so repeating the same import is idempotent and corrected reports reconcile prior values. All rows commit in one transaction.

For CAPI, `capi.pixels` is a complete replacement list of at most 20 `{pixel_id, access_token, test_event_code}` objects. The campaign-level `enabled` flag and status→event `map` are shared by every pixel. Always read the section, prepare the entire list, inspect the dry-run diff, and only then use `--yes`; partial list patches intentionally remove omitted pixels. The first pixel is mirrored into the legacy scalar fields for rollback compatibility.

`landing edit` is intentionally generic rather than containing offer-specific delay or checkout logic. Its manifest is an object with 1–100 exact replacements, applied in order:

```json
{
  "replacements": [
    {"search": "delaySeconds = 10", "replace": "delaySeconds = 3598", "expected": 1},
    {"search": "href=\"https://checkout.example\"", "replace": "href=\"{link:1}\"", "expected": 3},
    {"search": "</body>", "replace": "<script>ytdsEvent('cta_visible')</script></body>", "expected": 1}
  ]
}
```

Every `search` must occur exactly `expected` times in the current file or the entire operation fails with `REPLACEMENT_COUNT_MISMATCH` and writes nothing. This primitive can change text or scripts, alter a VSL delay, replace checkout `href`s, or insert `ytdsEvent()`/`ytdsConversion()` calls without introducing a separate one-off command. Files are capped at 20 MiB and committed through an atomic same-directory rename.

### Local ZIP utilities

| Command | Description |
|---|---|
| `landing pack <directory> --out <file.zip>` | create a root-content ZIP, excluding `.DS_Store`, `__MACOSX`, symlinks, and the output itself |
| `landing verify --zip <file.zip>` | reject unreadable, empty, duplicate, traversal, absolute, symlink, root-index-less, over-20,000-file, or over-2-GiB-uncompressed archives; return file count, bytes, and SHA-256 |

`pack` and `verify` are local-only and do not need a database. `download` and `pack` atomically refuse to overwrite an existing output path. Upload/replace also re-check the extracted tree before exposing it. A committed replacement reports `cleanup_pending=true` instead of falsely reporting that the swap failed if only deletion of its hidden backup remains pending; internal `.ytds_replace_*`/`.ytds_backup_*` folders never appear in `landing list`.

### Authoring

| Command | Description |
|---|---|
| `campaign create --name <name> [--from-template <name>]` | create a campaign from a versioned template (default `blank`) |

`create` starts the campaign with no domains, a freshly generated API key, and the instance timezone. The template (in `code/templates/`) ships with the author's country filter, redirect, and postback defaults removed, and `create` refuses a template that still references them.

## Examples

```
# read (local)
bin/ytds campaigns list
bin/ytds campaign get 5 --full
bin/ytds stats --campaign 5 --from 01.08.26 --to 15.08.26

# read (remote)
bin/ytds campaigns list --env stg
bin/ytds destinations list --env stg

# mutate: preview, then commit
bin/ytds campaign domains 5 --set ytds.example.com --env stg
bin/ytds campaign domains 5 --set ytds.example.com --yes --env stg

# reconcile a Meta campaign report (always inspect ready/summary/rows first)
bin/ytds costs import --file ./meta-campaigns.csv --env stg
bin/ytds costs import --file ./meta-campaigns.csv --yes --env stg

# author a new campaign
bin/ytds campaign create --name "Q3 Offer" --from-template blank --env stg          # dry run
bin/ytds campaign create --name "Q3 Offer" --from-template blank --yes --env stg     # commit

# friendly per-field edit and default cleanup
bin/ytds campaign patch 5 --set uniqueness.ttl_hours=48 --set uniqueness.enabled=true --env stg
bin/ytds campaign kill-defaults 5 --yes --env stg

# global libraries
bin/ytds networks add --name BuyGoods --params "subid={clickid}" --yes --env stg
bin/ytds destinations add --name Checkout --base-url checkout.example.com/a --network <id> --yes --env stg
bin/ytds landing upload promo --zip ./promo.zip --yes --env stg

# clicks with filters, param columns, and sorting
bin/ytds clicks --campaign 5 --view leads --filter country:=:US --filter device:in:mobile,tablet --param subid --sort payout --dir desc --env stg
```

See [Operating with the ytds CLI](operating-with-ytds.md) for agent workflows and standard procedures, and [Admin API](admin-api.md) for the endpoint that backs remote mode.
