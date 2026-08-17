# Contributing to AmareloTDS

Thanks for helping improve AmareloTDS. This guide covers the branch model, testing, and how a
change reaches a running instance. For architecture and operation, read [`agents.md`](agents.md)
and [`docs/en/`](docs/en/index.md).

## Branch model

- **`staging`** — where all work starts. Develop and commit here.
- **`production`** — released code. It only ever receives a **merge** from `staging`, never a direct
  commit. `staging` being ahead of `production` is expected.

Each commit should be a single logical change with a green tree, so it doubles as a rollback unit.

## Local setup & tests

```bash
composer install                        # installs phpunit into vendor/ (git-ignored)
php -r 'require "code/db/db.php"; new Db();'   # create the local DB once (avoids a known test flake)
./vendor/bin/phpunit                    # engine suite
./vendor/bin/phpunit tests/application  # application suite (run SEPARATELY)
```

- **Run the two suites separately.** `phpunit.xml` only registers `tests/engine`, so a bare
  `./vendor/bin/phpunit` silently skips `tests/application`. Both must be green.
- Geo tests need MaxMind databases under `code/bases/` (git-ignored). See
  [`docs/en/testing-and-diagnostics.md`](docs/en/testing-and-diagnostics.md).
- UI, domain routing, and external APIs are only exercised on a real instance — verify those on a
  staging host, not just locally.

## Data model — no schema migrations

`code/db/db.sql` runs exactly once, when the database file does not yet exist. **Updating the code
never updates the schema.** New data goes into JSON (`campaigns.settings` / `common.settings`), read
with `?? default`. Adding a SQL column silently breaks existing installs — don't.

## Secrets & configuration

- Everything instance-specific lives only in `code/settings.local.php` (git-ignored): admin path,
  passwords, API keys, update channel. Never commit it, and never put a real IP, domain, SSH detail,
  or key in a tracked file.
- A `pre-commit` hook runs `gitleaks`. Install it: `gitleaks` on PATH (`brew install gitleaks`) and
  wire `.git/hooks/pre-commit` to run `gitleaks git --staged`. A green scan is necessary but not
  sufficient — review your diff.

## Releasing to an instance

Deploy is **not** `git pull`. A running instance updates when someone clicks **update** in its panel,
which downloads the configured branch's zip from GitHub. It only fires when
`code/admin/version.txt` is bumped to a strictly greater value (`DD.MM.YY[.BUILD]`).

1. Land your change on `staging` with both suites green.
2. To deploy staging: bump `version.txt`, push, click **update** on the staging instance.
3. To promote: `git checkout production && git merge staging && git push`, then bump/update prod.

Before promoting, run the **promotion gate** to prove `staging` is production-ready:

```bash
scripts/promotion-gate.sh
```

It runs both suites, PHP lint, gitleaks, a boot check, and verifies `code/admin/version.txt` is
bumped above `production` and that the merge is a clean fast-forward — printing **GO** / **NO-GO**.
The same checks run in CI (`.github/workflows/ci.yml`): every push to `staging`/`production` runs the
suites and secret scan, and a pull request into `production` also enforces the version bump.

## Code style

Run the project's PHP CodeSniffer config before submitting:

```bash
vendor/bin/phpcs --standard=phpcs.xml code/
```
