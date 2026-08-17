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
| 2 | input validation (`USAGE`, `INVALID_ARG`, `UNKNOWN_ACTION`, `VALIDATION`) |
| 3 | not found (`DB_NOT_FOUND`, `CAMPAIGN_NOT_FOUND`, `SECTION_NOT_FOUND`, `NETWORK_NOT_FOUND`, `DESTINATION_NOT_FOUND`, `LANDING_NOT_FOUND`) |
| 4 | auth/config (`AUTH_INVALID`, `API_DISABLED`, `CONFIG_MISSING`, `CONFIG_INVALID`) |
| 5 | domain conflict (`DOMAIN_CONFLICT`) |

## Safety model for mutations

Every mutation (`create`, `clone`, `rename`, `delete`, `domains`, `patch`, `kill-defaults`, and the `networks`/`destinations`/`landing` write verbs) is a **dry run by default**: it validates the request, checks domain overlap where relevant, and prints what would change, without writing. Add `--yes` to commit.

- Reads mask secrets: `apikey`, the CAPI access token, and postback keys come back as `<redacted>`.
- Writes read the raw stored settings, so a mutation can never persist `<redacted>` over a real secret.
- Domain changes are validated against every other campaign; an overlap is refused with exit 5 in both dry run and commit.
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
| `stats --campaign <id>` | aggregate metrics for a date window; `--from`/`--to` in `DD.MM.YY`, `--columns`, `--groupby` |
| `clicks --campaign <id>` | recent clicks (narrow columns); `--view allowed\|blocked\|leads\|trafficback`, `--from`/`--to`, `--limit`, `--page`, `--sort <field>` (incl. `param.KEY`), `--dir asc\|desc`, repeatable `--filter field:op:value` with `--filter-cond and\|or`, repeatable `--param KEY` to project `param.*` columns, `--search <term>`, `--full` |
| `landing list` | landing folders with metadata |
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
| `networks add\|update\|delete [<id>]` | manage the global Networks library (`--name`, `--params`) |
| `destinations add\|update\|delete [<id>]` | manage the global Destinations library (`--name`, `--base-url`, `--network <id>`) |
| `landing upload <name> --zip <file>` | extract a ZIP into a new landing folder (rejects `../` and absolute entries) |
| `landing duplicate <from> <to>` | copy a landing folder to a new name |
| `landing delete <name>` | delete a landing folder; dry run first lists the campaigns that reference it |

`patch` runs the same uniqueness, event, conversion, postback, CAPI, and flow validators as a panel save, then a recursive merge. It is the general tool for `{link:N}`, folder, and step edits: supply the complete section as the fragment, exactly as the panel would post it. An empty object or a JSON array is refused (it would wipe the settings).

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
