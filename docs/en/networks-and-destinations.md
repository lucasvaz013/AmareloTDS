# Networks and Destinations

Networks and Destinations are two global libraries shared across the entire installation. They let operators register reusable affiliate link components once and apply them to any campaign without re-entering the same URLs and parameters every time. Both libraries are stored in `common.settings` with no SQL tables.

## Networks

A **network** is a name paired with a reusable set of query parameters — the parameters that a traffic or affiliate network expects on every click. Networks are listed and managed on the **Networks** page.

Each network entry has:

- **Name** — a display label, up to 64 characters.
- **Parameters** — the query string the network expects, such as `subid={clickid}&subid2={c.campaignname}`. A leading `?` or `&` is stripped on save; write only the key=value pairs.

Networks are persisted to `common.settings.networks`. The installation accepts up to 100 networks.

## Destinations

A **destination** is a named affiliate base URL, optionally linked to a network. Destinations are listed and managed on the **Destinations** page.

Each destination entry has:

- **Name** — a display label, up to 64 characters.
- **Affiliate base URL** — the base URL of the offer or checkout page. If no URL scheme is provided, `https://` is added automatically.
- **Network** (optional) — a network from the Networks library. When selected, the network's parameters are appended to the base URL to form the effective URL.

The **effective URL** shown in the preview row is the base URL joined to the network parameters. If the base URL already contains a `?`, the network parameters are appended with `&`; otherwise `?` is used.

If the selected network has since been deleted or its id no longer matches any registered network, the destination falls back to the base URL alone and the preview label reads **"network missing — base only"**. The destination can still be saved in this state.

Destinations are persisted to `common.settings.destinations`. The installation accepts up to 200 destinations.

## Per-step Destinations Panel

In the campaign flow editor, every landing folder inside a step has a **Destinations** panel. Each row maps a `{link:N}` placeholder to a URL. Place `{link:N}` anywhere in the landing HTML — for example `href="{link:1}"` — and the system replaces the token at serve time with the configured URL.

### Adding and managing rows

- Click **+ Destination** to add a new row. The row shows the assigned placeholder (for example `{link:1}`) and a URL field.
- `N` is explicit and stable. Deleting the `{link:1}` row does not renumber `{link:2}`.
- Each folder accepts up to 20 destination rows.
- Save is blocked if any `N` is less than 1, if two rows share the same `N`, or if the URL is not a valid `http(s)` address.

### Using the library dropdown

Every URL field includes a **library** dropdown populated from the Destinations library. Selecting an entry copies its **effective URL** into the field at that moment. The step stores only the resulting string — a plain URL. If the global destination is later edited or deleted, the saved campaign is not affected.

### JSON shape

Inside `campaigns.settings`, the destinations for a folder are stored under `links`:

```json
"folders": [{
  "name": "landing-folder-name",
  "loadtype": "direct",
  "weight": 25,
  "links": [
    { "n": 1, "url": "https://checkout.example.com/order?affid=aff_x&sub1={clickid}" }
  ]
}]
```

An empty `links` array is valid; the landing then renders checkout URLs directly from the HTML without using `{link:N}` placeholders.

## How {link:N} Renders

When a landing is served, `{link:N}` tokens in the HTML are resolved after MVT substitution and before general HTML macro replacement.

For each `{link:N}` token found in the HTML:

1. The system looks up `N` in the folder's `links` array for the assigned landing variant.
2. The stored URL is passed through the URL macro processor, which substitutes macros such as `{clickid}` and `{c.utm_source}` in query parameter values.
3. If no row matches `N`, the token is replaced with `#` and the event is logged. The literal `{link:N}` string is never exposed to the visitor.

### Macro substitution in destination URLs

The URL macro processor only substitutes a macro when it is the **entire value** of a query parameter. `?sub1={clickid}` and `?utm={c.utm_source}` resolve correctly. `?ref=user-{clickid}` — where the macro is embedded inside a larger value — does not substitute and remains literal. This is the same rule that applies to redirect step URLs.

In the landing HTML outside of destination URLs, only `{clickid}`, `{userid}`, and `{px}` are substituted anywhere in the text.
