# Postback Gateway

Some sales platforms accept postback URLs only on a registrable/root domain and reject subdomains. A Postback Gateway publishes a dedicated apex such as `https://example.com/api/postback.php` on the current AmareloTDS installation.

## Security boundary

The gateway is not a campaign domain or a second TDS site. Its nginx virtual host exposes only:

```text
/api/postback.php
```

The root path, admin path, Connect endpoints, landings, arbitrary PHP files, and every other URL return `404`. The endpoint still resolves the campaign from `clickid`, so the gateway must belong to the installation whose database created that click.

Enable campaign **Key protection** and include `pbkey` in partner URLs when the partner supports a static secret.

## Creating a gateway

Open **Domains → Postback Gateway**, enter a root domain already present in the configured Cloudflare account, read the destructive warning, and confirm.

The operation intentionally:

1. preserves MX, TXT and other non-address records;
2. leaves exactly one apex `A` record pointing to this installation;
3. turns the Cloudflare proxy off for that record;
4. removes conflicting apex `AAAA` and `CNAME` address records;
5. queues a marked, postback-only nginx virtual host and HTTPS certificate.

Replacing apex address records can take an existing website offline. Use a domain dedicated to postbacks.

## Reconciliation

State lives in `settings.local.php` under the versioned `postbackGateway` object; no SQL migration is used. The existing domain crons provide two privilege-separated loops:

- `refresh_domains.php` runs as `www-data`, reconciles Cloudflare DNS, and updates status;
- `provision_domains.php` runs as root, calls `install.sh --add-postback-gateway`, validates nginx, and requests/renews HTTPS.

The root cron accepts only nginx files carrying `# amarelotds-postback-gateway v1` and refuses to overwrite an unrelated site with the same hostname. Ready gateways are left alone by the 5-minute sweep so a concurrent panel edit cannot be overwritten; use **Check now** to re-run DNS and reset exhausted nginx attempts.

Removing a gateway from the panel stops reconciliation but deliberately leaves DNS and nginx untouched. This avoids taking a live partner integration offline by accident; cleanup is an explicit infrastructure operation.

## Partner URL

Use the macros required by the partner, for example:

```text
https://example.com/api/postback.php?clickid={click_id}&status={status}&payout={payout}&currency=USD&tid={transaction_id}&pbkey=secret
```

The status must be an internal campaign status or configured alias. A click created in staging is not present in production, and vice versa.

## Verification

A healthy gateway has these properties:

```text
GET /                         -> 404
GET /admin/                   -> 404
GET /api/postback.php         -> 400 missing_clickid
GET /api/postback.php?clickid=unknown -> 404 click_not_found
```

DNS must return exactly the instance IPv4 for `A` and no `AAAA` records.
