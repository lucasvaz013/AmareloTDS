# AmareloTDS

AmareloTDS is a self-hosted **Traffic Distribution System** written in PHP + SQLite. For each
incoming request it decides what to serve — **white** (a safe page for filtered/blocked traffic),
**black** (the real funnel), or **trafficback** (when no campaign matches). Campaigns are matched by
the request **domain**; there is no campaign id or alias in the URL.

It is a self-contained application: the whole distribution runtime lives under `code/`, backed by a
single SQLite database. There is no external service dependency for serving traffic.

> AmareloTDS is a derivative work based on [YellowTDS](https://github.com/dvygolov/YellowTDS) by
> Yehor Dvygolov. See [`NOTICE`](NOTICE) for attribution and [`LICENSE`](LICENSE) for the terms
> covering this project's own additions.

## Requirements

- A Linux VPS (or any host) with **PHP 8.4-FPM**, **nginx**, and the **sqlite3** PHP extension.
- A domain pointing directly at the host's public IP (no proxy/CDN in front — the installer and
  Let's Encrypt need to reach the domain directly).
- Ports **22, 80, 443** open.

The bundled `code/install.sh` provisions nginx, PHP-FPM, Let's Encrypt/certbot, a firewall, and swap.

## Quick start

```bash
# Production install on a fresh VPS (run as root)
curl -fsSL https://raw.githubusercontent.com/lucasvaz013/AmareloTDS/production/code/install.sh \
  | sudo AMARELOTDS_DOMAIN=yourdomain.com bash
```

The installer renames the admin panel to a random hex path and prints it once, generates an admin
password, and writes all per-instance configuration (admin path, passwords, API keys, update
channel) to `code/settings.local.php` — which is **git-ignored** and never leaves your machine.

Updates are applied from the panel's **update** button, which downloads the configured branch from
GitHub. See [`docs/en/installation.md`](docs/en/installation.md) for VPS, shared-hosting, and
control-panel setups.

## Operating

Drive an instance from the command line with the **`ytds` CLI** (local or remote over a token-auth
admin API) instead of clicking the panel or editing SQLite:

```bash
bin/ytds campaigns list
bin/ytds campaign get <id> --full
bin/ytds campaign patch <id> --set uniqueness.ttl_hours=48 --yes
bin/ytds clicks --campaign <id> --filter country:=:US --param subid --sort payout
```

Every mutation is a dry run by default; add `--yes` to commit. Secrets are masked in output. See:

- [`docs/en/ytds-cli.md`](docs/en/ytds-cli.md) — full command and exit-code reference.
- [`docs/en/operating-with-ytds.md`](docs/en/operating-with-ytds.md) — operator profile and standard procedures.
- [`docs/en/admin-api.md`](docs/en/admin-api.md) — the JSON API behind remote mode.

## Documentation

Full product, admin, and runtime documentation lives in [`docs/en/`](docs/en/index.md)
(also available in `docs/ru/`). Start at the [documentation index](docs/en/index.md).

## Configuration & secrets

Nothing instance-specific belongs in the repository. All secrets and per-machine settings —
admin path, passwords, Namecheap/Cloudflare keys, update channel — live only in
`code/settings.local.php` (git-ignored). A `pre-commit` hook runs `gitleaks`; never commit secrets.

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md) for the branch model, testing, and release flow.
