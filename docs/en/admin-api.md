# Admin API

The admin API is a small JSON endpoint for operating an instance remotely: reading campaigns and reports and making safe mutations. It backs the remote mode of the [ytds CLI](ytds-cli.md).

It lives at `api/admin.php`, deliberately outside the renamed hex admin path, so a client can reach it with only a token — it never needs to know the secret panel path. It returns JSON only and never returns the admin token, password, or admin path.

## Enabling and authentication

The API is **disabled by default**. It turns on only when `adminApiToken` is set in the instance `settings.local.php` (a masked secret, edited as `www-data`). Until a token is configured, every request returns `404` with `{"code":"API_DISABLED"}`.

Once enabled, every request must carry the token:

```
Authorization: Bearer <adminApiToken>
```

A missing or wrong token returns `401` with `{"code":"AUTH_INVALID"}`. The comparison is constant-time.

## Methods

- **Reads use `GET`.** They are safe and idempotent.
- **Mutations use `POST`.** A mutation issued as `GET` is refused with `405`, so a write can never happen through a safe verb.

Parameters travel in the query string in both cases. Two actions also read a `POST` body: `campaign.patch` and `campaign.set` take a JSON body, and `landing.upload` takes the raw ZIP bytes.

## Response shape

- Success: HTTP `200` and the result object — the same JSON the CLI prints.
- Error: the failing HTTP status and `{ "code": "...", "message": "...", "hint": "..." }`.

Codes match the CLI: `INVALID_ARG`/`UNKNOWN_ACTION`/`VALIDATION` (400), `AUTH_INVALID`/`API_DISABLED` (401/404), `CAMPAIGN_NOT_FOUND`/`SECTION_NOT_FOUND`/`NETWORK_NOT_FOUND`/`DESTINATION_NOT_FOUND`/`LANDING_NOT_FOUND` (404), `DOMAIN_CONFLICT` (409), `METHOD_NOT_ALLOWED` (405), `WRITE_FAILED`/`INTERNAL` (500).

Campaign settings are returned with secrets masked (`apikey`, CAPI access token, postback keys become `<redacted>`). Operational data such as clicks and destinations is returned in full to the authenticated caller.

## Actions

Selected with the `action` query parameter.

### Reads (GET)

| Action | Parameters | Returns |
|---|---|---|
| `version` | — | instance version and PHP version |
| `campaigns.list` | — | narrow campaign list |
| `campaign.get` | `id`, optional `section`, `full` | one campaign (narrow, section subtree, or full redacted settings) |
| `stats` | `campaign`, optional `from`, `to`, `columns`, `groupby` | aggregate metrics for the window |
| `clicks` | `campaign` (except `view=trafficback`), optional `view`, `from`, `to`, `limit`, `page`, `sort`, `dir`, `filter[]` (`field:op:value`), `filter-cond`, `param[]`, `search`, `full` | recent clicks, filtered/sorted/paginated |
| `landing.list` | — | landing folders |
| `networks.list` | — | global Networks library |
| `destinations.list` | — | global destinations with resolved effective URLs |

### Mutations (POST)

Every mutation is a dry run unless `commit=1` is passed. A dry run validates and previews without writing.

| Action | Parameters | Effect |
|---|---|---|
| `campaign.create` | `name`, optional `template` (default `blank`), `commit` | create from a versioned template |
| `campaign.clone` | `id`, optional `name`, `commit` | duplicate (clone starts with no domains) |
| `campaign.rename` | `id`, `name`, `commit` | rename |
| `campaign.delete` | `id`, `commit` | delete |
| `campaign.domains` | `id`, `set` (comma-separated), `commit` | replace the domain list (overlap-validated) |
| `campaign.patch` | `id`, `commit`; JSON body | merge a settings fragment through the panel validators |
| `campaign.set` | `id`, `commit`; JSON body `{ "dot.path": value }` | friendly per-field edit merged through the validators |
| `campaign.kill-defaults` | `id`, `commit` | remove the author's three dangerous defaults if present |
| `networks.add` / `.update` / `.delete` | `name`/`params`, or `id` (+ optional fields) | manage the global Networks library |
| `destinations.add` / `.update` / `.delete` | `name`/`base_url`/`network_id`, or `id` | manage the global Destinations library |
| `landing.upload` | `name`, `commit`; ZIP bytes as the POST body | extract a ZIP into a new landing folder (zip-slip guarded) |
| `landing.duplicate` | `from`, `to`, `commit` | copy a landing folder |
| `landing.delete` | `name`, `commit` | delete a landing folder (dry run lists referencing campaigns) |

Mutations run through the same `CampaignService` and validators as the panel save, so the API, the CLI, and the panel share one write path. Domain overlap is checked in dry run and commit alike and refused with `DOMAIN_CONFLICT`. `campaign.create` uses a template with the author's dangerous defaults removed and refuses any template that still references them.

## Example

```
# read
curl -s -H 'Authorization: Bearer <token>' \
  'https://stg.example.com/api/admin.php?action=campaigns.list'

# mutate (dry run, then commit)
curl -s -X POST -H 'Authorization: Bearer <token>' \
  'https://stg.example.com/api/admin.php?action=campaign.domains&id=5&set=ytds.example.com'
curl -s -X POST -H 'Authorization: Bearer <token>' \
  'https://stg.example.com/api/admin.php?action=campaign.domains&id=5&set=ytds.example.com&commit=1'
```

In practice the [ytds CLI](ytds-cli.md) is the intended client; use raw `curl` only for debugging.
