# Domains Automation

AmareloTDS can register domains at Namecheap, delegate them to Cloudflare DNS, and provision an nginx server block with a Let's Encrypt certificate — all from the Domains page in the admin panel. The result is a hostname that resolves to this server and serves traffic over HTTPS, ready to be assigned to a campaign.

## Credentials

All API keys live in `settings.local.php` only. They are never written to any tracked file.

### Namecheap

The following keys are set under **Integrations → Namecheap** in the panel:

- `namecheapApiUser` — the Namecheap account username that owns the API key.
- `namecheapApiKey` — the API key from **Profile → Tools → Namecheap API Access**.
- `namecheapUsername` — the account to act on behalf of; defaults to `namecheapApiUser` when left blank.
- `namecheapSandbox` — when enabled, all calls go to the Namecheap sandbox endpoint instead of production. Use for testing only.

Namecheap authorises callers by IPv4 address. The server's egress IPv4 must appear in the API whitelist under **Profile → Tools → Namecheap API Access**. Calls from a dual-stack host are forced over IPv4 automatically.

### Cloudflare

- `cloudflareApiToken` — a Cloudflare API token with **Zone:Edit** and **Account:Read** permissions, or at minimum the zone-scoped equivalent. Set it under **Integrations → Cloudflare**.

The panel verifies both credentials on save and shows the result on the Integrations page.

## Registrant Profile

ICANN requires full registrant contact data on every domain registration. To avoid re-entering it each time, the profile is stored once under **Domains → Registrant profile** and reused for all purchases.

Required fields: first name, last name, address, city, state/province, postal code, country, phone, and email address.

AmareloTDS first tries to read the default address from the Namecheap account's address book. The saved profile here is used only as a fallback when the Namecheap address book is empty or incomplete.

## Adding a Domain

The Domains page offers three paths.

### Register via Namecheap

The operator types a bare domain name (for example `example.com`, no scheme or path) and selects the number of years. AmareloTDS:

1. Checks availability at Namecheap — no charge.
2. Purchases the domain — money changes hands at this point. The domain appears in the managed list immediately, even if later steps fail, so the purchase is never forgotten.
3. Creates a zone for the domain in the Cloudflare account.
4. Points the domain's nameservers at Cloudflare by calling Namecheap DNS API.
5. Creates an A record `ytds.<domain>` in the Cloudflare zone pointing to this server's public IPv4, with Cloudflare proxy **off** (DNS-only).
6. Waits for the Cloudflare zone to activate and for DNS to propagate, then triggers nginx + Let's Encrypt provisioning.

Steps 3–6 may not complete within the request. The refresh cron continues them in the background.

### Import from Cloudflare

For a domain already present in the Cloudflare account. AmareloTDS looks up the zone, creates or updates the `ytds.<domain>` A record, and proceeds with provisioning. No Namecheap call is made.

### Manual

For a domain pointed to this server by any means outside AmareloTDS. The panel checks that `ytds.<domain>` resolves to this server's public IP and waits for provisioning. No API calls are made.

## DNS Requirement: Proxy Off

The Cloudflare proxy (the orange cloud) **must be off** on the `ytds.<domain>` record. AmareloTDS compares the resolved IPv4 of `ytds.<domain>` with the server's public IP to confirm the domain points here. A proxied record answers with Cloudflare addresses instead, which causes the DNS check to fail with an explicit message. Let's Encrypt also requires direct HTTP access to the domain during certificate issuance.

## Domain Status

Each entry in the managed list carries one of three states:

- **checking** — work is still in progress; the panel shows a spinner and the refresh cron re-checks the domain automatically.
- **ready** — `ytds.<domain>` resolves to this server, the nginx server block is in place, and an HTTPS certificate is installed.
- **error** — something a person must fix: a wrong token permission, a record pointing elsewhere, or provisioning exhausting its retry limit.

**Check now** triggers an immediate re-check for a single domain and can reset an `error` state once the underlying problem is resolved.

## managedDomains in Settings

Every managed domain is stored as an entry in `common.settings.managedDomains`. Each entry tracks:

- `name` — the registrable domain, lowercase.
- `hostname` — the provisioned subdomain, always `ytds.<domain>`.
- `source` — how the domain entered the list: `registered`, `cloudflare`, or `manual`.
- `zone_id` — the Cloudflare zone identifier, retained across status checks.
- `added` — Unix timestamp of when the domain was first added.
- `status` — current state (`checking`, `ready`, or `error`).
- `detail` — the last status message from the most recent check.
- `checked` — Unix timestamp of the last check.
- `campaign_id` — the campaign this domain is assigned to route traffic for.

## Background Crons

Two cron jobs installed under `/etc/cron.d/` handle the work that cannot complete in a single request.

### refresh_domains (www-data)

Installed as `/etc/cron.d/amarelotds-domains`. Runs as `www-data`.

On each run it reads `managedDomains`, filters to entries whose status is not `ready`, and calls `domains_advance` for each. This function is idempotent: it skips any step already done and only performs what is still missing (creating the zone, setting nameservers, creating the DNS record, confirming zone activation). When a domain's status changes, the cron logs the transition and saves the updated list back to settings — re-reading settings fresh before saving so that changes made in the panel during the sweep are not overwritten.

### provision_domains (root)

Installed as `/etc/cron.d/amarelotds-provision`. Runs as **root**.

Writing to `/etc/nginx/sites-enabled/` and running certbot both require root. This cron does not accept any input from the web panel; it reads the domain list and reconciles what needs provisioning, so the panel cannot steer it into arbitrary commands.

For each managed domain it checks whether `ytds.<domain>` currently resolves to this server's public IP. If it does not, provisioning is skipped for that domain (burning a certbot attempt against the Let's Encrypt rate limit when DNS is not ready would waste one of three allowed retries). When DNS is correct, the cron runs `install.sh --add-domain` with the hostname, which writes the nginx server block, tests the config, reloads nginx, and calls certbot. The result — success or failure — is recorded in `tmp/domain-nginx.json` so the panel can report it.

Let's Encrypt caps certificate issuance per domain per week. After three failed attempts, the cron stops retrying that hostname automatically. The operator must fix the underlying cause and use **Check now** to reset the counter.

## Cron Collision Between Instances

Both the staging and production instances share the same host. The installer writes cron files with fixed names (`amarelotds-domains`, `amarelotds-provision`) and embeds the application directory path inside each file. When a second instance is installed, its crons overwrite the first's. After promoting Domains Automation to production, install the two cron files for the production instance manually on the server.

## Relationship to Campaign Domains

AmareloTDS routes every incoming request by matching its HTTP `Host` header against the domain lists of all campaigns. The **Domains** section of a campaign (see [Campaign Settings](campaign-settings.md)) is where the operator assigns a domain name to a campaign. Domains Automation makes the host actually resolve and serve: it ensures `ytds.<domain>` points to this server and that nginx handles requests for it with a valid HTTPS certificate. Without provisioning, adding the hostname to a campaign has no effect because the host either does not resolve or lands on the default nginx site.
