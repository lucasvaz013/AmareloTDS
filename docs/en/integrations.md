# Integrations

The Integrations page is the single place to store and verify the API credentials AmareloTDS uses to talk to outside services. Nothing is purchased, registered, or changed on external accounts from this page; it only holds credentials and confirms they work.

## Secret storage

All credentials are written to `settings.local.php` on the server. That file is gitignored and machine-specific, so secrets are never committed to the repository and each installation keeps its own copy.

When the page loads, masked credentials are not sent to the browser. Instead, the browser receives only a `configured` flag (true or false) for each masked field. The **API key** fields for both providers are masked this way; the Namecheap **API user** and **Username** fields are not masked and are returned as plain text.

### Leave blank to keep the current value

Because masked fields never arrive in the browser, submitting an empty value for them would erase the saved credential. To prevent this, the save logic treats an empty submission for a masked field as "keep the current value." The field placeholder changes to **Saved — leave empty to keep it** while a value is stored, signalling this behavior.

## Server egress address

The page detects and displays the public IPv4 address from which the server makes outbound calls. Namecheap validates the connecting IP against its API whitelist, so the address shown here is the one that must be added on the Namecheap account. Cloudflare tokens may also carry IP restrictions, so the address is useful for both providers.

## Cloudflare

| Field | Description |
|---|---|
| API token | A scoped token created in the Cloudflare dashboard. |

The token must have **Zone > DNS > Edit** and **Zone > Zone > Read** permissions. Use a scoped API token, not the legacy Global API Key.

**Health check**: AmareloTDS posts the token to `https://api.cloudflare.com/client/v4/user/tokens/verify`. A `200 OK` with `success: true` is not sufficient — the check also requires `status: active` in the response, because a disabled or expired token returns HTTP 200 but fails this condition.

Possible check results:
- **Token is active** — credentials are valid and active.
- **Token is valid but has expired** — the token was found but is expired.
- **Token exists but is disabled** — the token was found but has been switched off.
- **Token is invalid or was revoked**, or other Cloudflare error codes — reported with a plain-language description.
- Transport errors (DNS failure, timeout) — reported separately.

## Namecheap

| Field | Description |
|---|---|
| API user | The Namecheap account username used for API authentication. |
| Username | The account to act on behalf of; defaults to the API user when left empty. |
| API key | The secret key from **Profile > Tools > Namecheap API Access**. Masked. |
| Use the sandbox environment | Routes all calls to the Namecheap sandbox instead of production. |

### Sandbox toggle

When **Use the sandbox environment** is checked, the health check and all subsequent domain operations use `https://api.sandbox.namecheap.com/xml.response`. Sandbox and production keep separate IP whitelists and separate credentials; the toggle applies to both immediately on save.

### IP whitelist requirement

Namecheap authorizes API callers by IPv4 address. Add the server address shown at the top of the page to **Profile > Tools > Namecheap API Access** on the Namecheap account. Sandbox and production maintain independent whitelists, so the address must be added in both environments if both are used.

**Health check**: AmareloTDS sends the `namecheap.users.getBalances` command as a POST request (to keep credentials out of URLs and access logs). The response XML `Status` attribute determines the result. Namecheap returns HTTP 200 even for authentication failures, so the check reads this attribute rather than the HTTP status code.

Possible check results:
- **Credentials accepted** — API user and key are valid.
- **Credentials accepted (balance unavailable right now)** — authentication succeeded; balance data was temporarily unavailable but this is not a credential failure.
- **This server IP is not on the Namecheap whitelist** — add the displayed IP to the account whitelist.
- **API key is invalid, or API access is switched off** — verify the key and that API access is enabled on the account.
- **Account is locked** — the Namecheap account requires attention.
- Other error codes — reported with a plain-language description.

## Checking and saving

The page has two action buttons:

- **Check now** — runs the health checks against the currently saved credentials without changing anything.
- **Save and check** — saves the submitted values first, then immediately runs the health checks against the newly saved credentials.

Both actions read credentials from the saved settings, not from the form submission, so the check endpoint cannot be used to probe arbitrary tokens.

The save endpoint writes only the five integration fields (`namecheapApiUser`, `namecheapApiKey`, `namecheapUsername`, `namecheapSandbox`, `cloudflareApiToken`). Admin password, admin path, and all other settings are untouched regardless of what is submitted.

If settings were changed in another tab between page load and save, the server returns a conflict error; reload the page and try again.
