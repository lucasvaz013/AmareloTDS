# Operating with the ytds CLI

This page is the operator profile and the standard procedures for running a AmareloTDS instance through the [ytds CLI](ytds-cli.md). It is written so a human operator or an LLM agent can drive the instance safely, with a mission and hard limits, using the CLI's stable JSON contract instead of the panel.

## Agent profile

**Mission.** Operate the instance — inspect campaigns and reports, make safe changes, author new campaigns from templates — without ever clicking the panel or editing SQLite directly.

**Hard limits (never cross these):**

- **Dry run first.** Every mutation runs as a dry run by default. Read the printed diff or preview, confirm it is what you intend, and only then re-run with `--yes`.
- **One environment at a time, named explicitly.** Pass `--env stg` or `--env prod` on every remote command. Never assume the default. Treat `prod` as production: no experiments.
- **Never paste secrets.** The CLI masks `apikey`, CAPI tokens, and postback keys as `<redacted>`. Do not try to unmask them, and never put them in logs, chat, or commits.
- **Respect exit codes.** Stop and inspect on any non-zero exit. `5` means a domain conflict, `4` means auth/config, `3` means not found, `2` means bad input.
- **New campaigns are born safe.** A `create`d campaign has no domains and the author's dangerous defaults stripped. It stays off until you deliberately configure filters and domains.
- **Escalate, don't guess.** If a mutation's diff is larger or different than expected, do not `--yes` it. Investigate first.

**Contract.** stdout is result JSON, stderr is `{code, message, hint}`, exit codes are stable. See [ytds CLI](ytds-cli.md) for the full command and exit-code reference and [Admin API](admin-api.md) for the endpoint behind remote mode.

## Standard procedures

Each procedure lists the exact commands. Replace `stg` with the target environment and `<id>` with a real campaign id.

### 1. Health check

```
bin/ytds doctor                 # local instance: PHP, sqlite3, db schema, campaigns, geobases, version
bin/ytds version --env stg      # remote liveness: instance version + PHP
```

A non-zero exit or an `ok:false` doctor result means stop and investigate before doing anything else.

### 2. Inspect a campaign

```
bin/ytds campaigns list --env stg
bin/ytds campaign get <id> --env stg                    # narrow: id, name, domains, flow count
bin/ytds campaign get <id> --section black.flows --env stg
bin/ytds campaign get <id> --full --env stg             # whole settings, secrets redacted
```

### 3. Create a campaign from a template

```
bin/ytds campaign create --name "Q3 Offer" --from-template blank --env stg        # dry run
bin/ytds campaign create --name "Q3 Offer" --from-template blank --yes --env stg  # commit
```

The new campaign has no domains and no author defaults. It is off until you add domains and filters. Follow with procedures 4 and 6.

### 4. Add or replace domains

Domains are set as a full list; overlap with any other campaign is refused (exit 5).

```
bin/ytds campaign get <id> --section domains --env stg              # see current list
bin/ytds campaign domains <id> --set ytds.example.com --env stg     # dry run, shows before/after
bin/ytds campaign domains <id> --set ytds.example.com --yes --env stg
```

### 5. Map {link:N} destinations on a step

`{link:N}` slots live inside a flow step's folder. Edit them by patching the complete flow section, exactly as the panel posts it. Read the current flow, add the `links` entries, and apply.

```
bin/ytds campaign get <id> --section black.flows --env stg > flow.json
# edit flow.json: add {"n":1,"url":"https://checkout.example.com/?sub1={clickid}"} to the folder's links,
# then wrap it back as {"black":{"flows":[ ... ]}} in patch.json
bin/ytds campaign patch <id> --apply patch.json --env stg           # dry run, redacted diff
bin/ytds campaign patch <id> --apply patch.json --yes --env stg
```

See [Networks and Destinations](networks-and-destinations.md) for the `{link:N}` rules (cap 20, unique `n`, http(s) only).

### 6. Audit the author's dangerous defaults

Guardrail: a campaign must not filter on `RU,BG,SG`, redirect to `rolltrk.com`, or postback to `eu.roerads.com`. `create` guarantees this for new campaigns; audit existing ones:

```
bin/ytds campaign get <id> --full --env stg | grep -iE 'rolltrk|roerads|RU,BG,SG'
```

Any match must be replaced with `campaign patch` before the campaign carries traffic.

For a one-shot cleanup, `kill-defaults` removes all three if present (idempotent; dry run first):

```
bin/ytds campaign kill-defaults <id> --env stg          # dry run: lists what would be removed
bin/ytds campaign kill-defaults <id> --yes --env stg
```

To change a single field without hand-editing JSON, `patch --set` assigns dotted paths onto the current settings (repeatable, merged through the same validators):

```
bin/ytds campaign patch <id> --set uniqueness.ttl_hours=48 --set uniqueness.enabled=true --env stg
```

### 7. Pull statistics and clicks

```
bin/ytds stats --campaign <id> --from 01.08.26 --to 15.08.26 --env stg
bin/ytds clicks --campaign <id> --view allowed --limit 50 --env stg
bin/ytds clicks --view trafficback --env stg

# filter, project param columns, sort, and paginate:
bin/ytds clicks --campaign <id> --view leads --filter country:=:US --filter device:in:mobile,tablet \
  --filter-cond and --param subid --sort payout --dir desc --page 1 --limit 100 --env stg
bin/ytds clicks --campaign <id> --search <clickid-or-userid-substring> --env stg
```

Dates are `DD.MM.YY` in the campaign timezone; omit `--from`/`--to` for today. `--filter` is repeatable as `field:op:value` (operators `=`, `!=`, `in`, `not_in`, `is_null`, `is_not_null`; `param.KEY` targets a URL param); `--param KEY` adds that param as a `param.KEY` column; `--sort` accepts any column or `param.KEY`, with `--dir asc|desc`.

### 8. Clone, rename, delete

```
bin/ytds campaign clone <id> --name "Copy" --yes --env stg     # clone starts with no domains
bin/ytds campaign rename <id> --name "New Name" --yes --env stg
bin/ytds campaign delete <id> --yes --env stg                  # dry run first, without --yes
```

### 9. Manage the Networks and Destinations libraries

These are the global `{link:N}` building blocks (see [Networks and Destinations](networks-and-destinations.md)). A network is a reusable param pack; a destination is a base URL optionally bound to a network. Deleting a network leaves its destinations resolving to their base URL only.

```
bin/ytds networks list --env stg
bin/ytds networks add --name BuyGoods --params "subid={clickid}" --env stg          # dry run
bin/ytds networks add --name BuyGoods --params "subid={clickid}" --yes --env stg
bin/ytds networks update <id> --name "BuyGoods v2" --yes --env stg
bin/ytds networks delete <id> --yes --env stg

bin/ytds destinations list --env stg
bin/ytds destinations add --name Checkout --base-url checkout.example.com/a --network <id> --yes --env stg
bin/ytds destinations update <id> --base-url https://checkout.example.com/b --yes --env stg
bin/ytds destinations delete <id> --yes --env stg
```

### 10. Manage landing folders

Landings are folders under `caching/landings`. Upload extracts a ZIP (rejecting `../` and absolute entries; the ZIP root becomes the folder contents). Delete first reports which campaigns reference the folder.

```
bin/ytds landing list --env stg
bin/ytds landing upload promo --zip ./promo.zip --env stg        # dry run: validates name + zip
bin/ytds landing upload promo --zip ./promo.zip --yes --env stg
bin/ytds landing duplicate promo promo-v2 --yes --env stg
bin/ytds landing delete promo --env stg                          # dry run lists referencing campaigns
bin/ytds landing delete promo --yes --env stg
```

For a safe edit cycle, download the current STG landing, verify/unpack it, prepare exact counted replacements, preview the edit, commit it, then download again and compare the returned SHA-256. Whole-folder replacement follows the same dry-run/commit rule:

```
bin/ytds landing download promo --out ./promo-before.zip --env stg
bin/ytds landing verify --zip ./promo-before.zip
bin/ytds landing edit promo --file index.html --apply ./edits.json --env stg
bin/ytds landing edit promo --file index.html --apply ./edits.json --yes --env stg
bin/ytds landing pack ./promo-work --out ./promo-new.zip
bin/ytds landing replace promo --zip ./promo-new.zip --env stg
bin/ytds landing replace promo --zip ./promo-new.zip --yes --env stg
```

The edit manifest can alter delays, checkout `href`s, text/scripts, and insert `ytdsEvent()` or `ytdsConversion()` calls. Each exact anchor carries an `expected` count; any mismatch aborts the whole edit before a write.

## Scheduled health check

A cron on the operator's machine can watch an instance and alert on failure. See `cli/cron/ytds-health.sh`:

```
*/10 * * * * /path/to/ytds/cli/cron/ytds-health.sh stg >> /var/log/ytds-health.log 2>&1
```

The script runs `version` and `campaigns list` against the environment and exits non-zero (for the operator's alerting) if either fails.
