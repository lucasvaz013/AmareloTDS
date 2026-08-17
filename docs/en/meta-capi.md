# Meta Conversions API

Meta Conversions API (CAPI) lets AmareloTDS send server-side conversion events directly to Meta's Graph API after a postback or a site-tracking call is received. No browser is involved in the event delivery: the TDS makes an authenticated POST to Meta using data captured at click time.

## Campaign Settings

The **Meta Conversions API** section appears in the campaign settings sidebar. All fields are stored in `campaigns.settings` under the `capi` key.

### Enabling CAPI

The **Send events to Meta** checkbox enables the integration. Saving with the checkbox on requires all three of the following or validation rejects the save:

- A non-empty Pixel ID
- A non-empty access token
- At least one entry in the status → event map

Disabling the checkbox saves the configuration but stops all event delivery. Existing mapping rows are preserved.

### Pixel ID

Numeric only, up to 32 characters. Obtain this from Meta Events Manager. The field does not accept letters or punctuation; the server rejects a non-numeric value on save.

### Access Token

A System User access token with `ads_management` permission. The field is rendered as a password input and is never returned in clear text after being saved. The token is stored in `campaigns.settings` (in the SQLite database and in backups); it must not be committed to any versioned file or logged unredacted. The server rejects tokens longer than 1024 characters or containing control characters.

Use a System User token rather than a personal user token; personal tokens expire.

### Test Event Code

Optional. When non-empty, every outgoing payload includes `test_event_code`, routing events to the **Test Events** tab in Events Manager without affecting production data. Accepted characters are letters, digits, hyphens, and underscores; maximum 64 characters. Remove the code before going live.

### Status → Meta Event Map

Each row maps one campaign conversion status to a Meta standard event name. New campaigns start with two default rows:

| Campaign status | Meta event |
|---|---|
| Purchase | Purchase |
| Lead | InitiateCheckout |

The drop-down for each row lists Meta's standard event names. Selecting **Do not send** for a status removes it from delivery without deleting the row. A status can appear at most once in the map. The map can hold up to 10 rows.

Event names must match Meta's standard event list exactly (e.g. `Purchase`, `InitiateCheckout`). The server validates this on save.

## Conversion Flow

When the sales platform calls `api/postback.php` with a `clickid`, `status`, `payout`, and `currency`, `ConversionService` records the conversion and then calls `process_capi_conversion`. The function:

1. Checks that CAPI is enabled and the campaign has a pixel ID, access token, and at least one mapping (`isUsable()`).
2. Looks up the Meta event name for the incoming status using the campaign map.
3. If no mapping exists for that status, exits silently.
4. For events in `EVENTS_REQUIRING_VALUE` (currently `Purchase`), exits and logs a failure if the reported payout is zero or absent. A Purchase event with no payout value is never sent.
5. Builds the event payload using the click data captured at visit time: client IP address, user agent, and — when present — the `fbc` parameter derived from the captured `fbclid`.
6. POSTs the payload as JSON to `https://graph.facebook.com/v25.0/{pixelId}/events` with the access token in an `Authorization: Bearer` header (not in the URL).
7. Logs the outcome with `ytds_log_postback`, including the HTTP status code, response body excerpt, and whether `fbc` was present. The access token is never written to the log.

This call is synchronous and shares the postback request's execution. There is no retry queue: a network failure or a non-200 response from Meta is logged as failed and not re-attempted.

### Event Identity and Deduplication

Each event carries an `event_id` computed as:

```
sha1(clickid | eventName | tid)
```

`tid` is the transaction ID from the postback, or an empty string when absent. This makes the identifier deterministic: if the same postback arrives again (duplicate delivery from the sales platform), the `event_id` is identical and Meta deduplicates it. Meta's deduplication window is 48 hours from the first event received with a given `event_id`; re-sends arriving later are treated as new events.

### User Data Fields

The payload includes the following user data collected at click time:

- `client_ip_address` — the visitor's IP, sent as-is (not hashed).
- `client_user_agent` — the visitor's User-Agent, sent as-is.
- `fbc` — built only when a `fbclid` parameter was captured with the click. Format: `fb.1.<click_time_ms>.<fbclid>`. The click timestamp is stored in seconds and is multiplied by 1000 before embedding; using the raw seconds value would produce a syntactically valid but permanently non-attributing `fbc`. When no `fbclid` was captured, `fbc` is omitted and matching relies on IP and User-Agent alone.
- `external_id` — SHA-256 of `clicks.userid` when available, otherwise SHA-256 of the `clickid`. Sent as a stable anonymous identifier.

`fbp` is not included because the TDS does not run the Meta Pixel.

### Value and Currency

`value` and `currency` are the figures reported by the sales platform in this postback — the original payout and currency code before any internal USD conversion. This ensures the amount visible in Meta Events Manager matches the sales platform's records. The value is formatted as a decimal string (e.g. `"197.90"`).

## Browser Pixel and PageView

The TDS does not inject the Meta Pixel. The `fbq('init', …)` + `fbq('track', 'PageView')` snippet and its `<noscript>` image belong in the landing's `index.html` and are deployed as part of the landing ZIP. The Pixel fires in the visitor's browser after the TDS serves the landing; it is entirely independent of CAPI.

The 302 redirect to `/__dl/<clickid>/<step>/` used in direct load mode does not carry the original query string. Any `fbclid` needed by the Pixel's `fbc` handling must be embedded via a landing macro or `{link:N}` in the step redirect URL before the redirect.

## Site Tracking and InitiateCheckout

Many sales networks do not emit a "checkout started" event. When `conversions.site.enabled` is active the TDS injects `conversiontracking.js`, which exposes `window.ytdsConversion`. Placing the following call in the landing's CTA click handler sends a site-tracked conversion:

```javascript
if (window.ytdsConversion) ytdsConversion('Lead');
```

This causes three things:

1. A `POST` to `api/conversion.php` records a `Lead` conversion with `source=site_script`.
2. CAPI fires using the campaign map — with the default mapping, `Lead` sends `InitiateCheckout` to Meta.
3. The browser Pixel call (e.g. `fbq('track', 'InitiateCheckout')`) runs separately in the same handler if the landing includes it.

`Lead` from a site-tracking call does not require a payout value. A subsequent postback from the sales platform recording a `Purchase` status adds a second conversion row; the `Lead` row is not removed or replaced.
