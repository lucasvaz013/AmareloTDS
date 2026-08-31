# Meta Conversions API

Meta Conversions API (CAPI) lets AmareloTDS send server-side conversion events directly to Meta's Graph API after a postback or a site-tracking call is received. No browser is involved in the event delivery: the TDS makes an authenticated POST to Meta using data captured at click time.

## Campaign Settings

The **Meta Conversions API** section appears in the campaign settings sidebar. All fields are stored in `campaigns.settings` under the `capi` key.

### Enabling CAPI

The **Send events to Meta** checkbox enables the integration. A campaign can configure up to 20 pixels. Saving with the checkbox on requires all three of the following or validation rejects the save:

- At least one pixel with a non-empty Pixel ID and access token
- Every configured pixel to have both its Pixel ID and access token
- At least one entry in the status → event map

Disabling the checkbox saves the configuration but stops all event delivery. Existing mapping rows are preserved.

### Pixels

Use **Add pixel** to add destinations and **Remove** to delete one. Every mapped conversion is sent to every configured pixel in parallel. Pixel IDs must be unique within the campaign, numeric only, and no longer than 32 characters. Obtain each ID from Meta Events Manager.

The event map is shared: for example, if `Lead → InitiateCheckout` is configured, every pixel receives the same `InitiateCheckout` event and deterministic `event_id`. Pixel ID is deliberately not included in `event_id`; Meta deduplication is scoped to each pixel.

### Access Token

Each pixel has its own System User access token with `ads_management` permission. The field is rendered as a password input in the authenticated campaign panel. The panel must retain the value so an unchanged save does not erase the credential; machine reads through the ytds CLI and Admin API always return every CAPI token as `<redacted>`. Tokens are stored in `campaigns.settings` (in the SQLite database and backups); they must not be committed or logged. The server rejects tokens longer than 1024 characters or containing control characters.

Use a System User token rather than a personal user token; personal tokens expire.

### Test Event Code

Optional and configured separately for each pixel. When non-empty, that pixel's payload includes `test_event_code`, routing the event to its **Test Events** tab in Events Manager without affecting production data. Accepted characters are letters, digits, hyphens, and underscores; maximum 64 characters. Remove the code before going live.

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

1. Checks that CAPI is enabled and the campaign has at least one usable pixel and one mapping (`isUsable()`).
2. Looks up the Meta event name for the incoming status using the campaign map.
3. If no mapping exists for that status, exits silently.
4. For events in `EVENTS_REQUIRING_VALUE` (currently `Purchase`), exits and logs one skip per pixel if the reported payout is zero or absent. A Purchase event with no payout value is never sent.
5. Builds the event payload using the click data captured at visit time: client IP address, user agent, and — when present — the `fbc` parameter derived from the captured `fbclid`.
6. Creates one POST per pixel to `https://graph.facebook.com/v25.0/{pixelId}/events`, with that pixel's access token in an `Authorization: Bearer` header (not in the URL), and executes the requests in parallel.
7. Logs each pixel independently with `ytds_log_postback`, including Pixel ID, HTTP status code, response body excerpt, and whether `fbc` was present. Access tokens are never written to the log. A failed pixel does not cancel successful deliveries to other pixels.

The parallel batch is synchronous and shares the postback request's execution. There is no retry queue: a network failure or a non-200 response from Meta is logged as failed for that pixel and not re-attempted. The conversion was already recorded and is never rolled back because a Meta destination failed.

### Stored-format compatibility

Existing campaigns with the legacy scalar `pixel_id`, `access_token`, and `test_event_code` load automatically as a one-pixel list; no SQL or manual migration is required. New saves store `pixels[]` and mirror the first pixel into those scalar fields. This lets an emergency rollback to older code continue sending to the first pixel. Do not edit CAPI in the old panel during a rollback, because old code does not know how to preserve additional pixels.

The Graph API version remains deliberately pinned in code (`v25.0`). It is not configurable by campaign or pixel because Graph versions can change payload compatibility.

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
