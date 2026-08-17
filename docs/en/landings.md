# Landings

The Landings page is a global library of landing page folders. Upload a page once and reuse it across every campaign without uploading it again inside each step. Any folder registered here appears in a step's **Add Existing** picker.

## Storage

Each landing is a directory under `caching/landings/<name>`. There is no database row; the library is entirely filesystem-based. The directory is resolved at startup via `get_cache_path('landings')`.

## Folder Names

Names must match `[a-zA-Z0-9_\-\.]` — letters, digits, hyphens, underscores, and dots. Empty strings and the reserved names `.` and `..` are rejected. Every operation that modifies the filesystem re-validates the name and confirms the resolved path stays inside `caching/landings/`, so a crafted name cannot reach a sibling directory.

## Landing Table

The table lists all subdirectories of `caching/landings/`, sorted case-insensitively by name. Columns:

| Column | Description |
|--------|-------------|
| Name | Folder name |
| Files | Recursive file count |
| Size | Recursive total in bytes |
| Modified | Modification time of the folder itself |
| Actions | Edit, Duplicate, Delete |

A folder with no root index file (`index.php`, `index.html`, or `index.htm`) is flagged in the UI because it would produce a 404 at runtime.

## Operations

### Upload ZIP

Click **Upload ZIP** to upload a ZIP archive. The archive is extracted into a new folder under `caching/landings/`. The folder name is taken from the ZIP upload form. The name must pass the character validation above and must not already exist.

### Edit Files

The edit action opens the same in-browser file editor used by campaign step folders. Text files (HTML, CSS, JS, PHP) open in a syntax-aware editor. Binary files cannot be edited through the browser.

### Duplicate

Copies the full folder tree to a new name. The new name must pass character validation and must not already exist. The original folder is not modified.

### Delete

Recursively removes the folder and all its contents. Before confirming deletion, the UI queries every campaign for references to the folder and displays which campaigns, flows, steps, and white pages use it. Deleting a folder that is referenced by active campaigns removes it from the filesystem immediately; any in-flight click that was routed to that step will fail to load the landing.

Renaming a folder is not a built-in operation. To rename: duplicate to the new name, update every step that references the old name, then delete the old folder.

## Usage Scanning

Before deletion the system scans every campaign's settings for references to the folder. References appear in two forms:

- **Flow steps** — steps store landing references as objects (`name`, `loadtype`, `weight`, `mvt`). A match is reported as `<Flow name> — step <N>`.
- **White pages** — the safe page and domain-specific safe pages store landing references as plain folder name strings. A match is reported as `White page` or `White page for <domain>`.

The scan reads live campaign settings and covers all campaigns.

## Load Types

Each folder entry on a step carries a `loadtype` field. The Landings library itself does not store a load type — it is set per step, per folder entry. Two values are accepted:

### base

Assets in `caching/landings/<name>` are served directly by the web server (nginx). AmareloTDS injects a `<base href="...">` tag pointing at the absolute server path of the folder so relative asset references resolve correctly. No TDS routing is involved for static asset requests.

### direct

Assets are served through the TDS runtime at `/__dl/<clickid>/<step>/`. AmareloTDS injects a `<base href="/__dl/<clickid>/<step>/">` tag and rewrites root-relative URLs in the HTML so all asset and navigation requests pass through the TDS. This allows the runtime to apply macro substitutions, MVT replacements, and other HTML processing on each request. The `/__dl/` route resolves the click record from the database on each request.

The `base` load type is the default when none is specified.

## How Steps Reference a Landing

A step's folder entry stores:

```json
{
  "name": "my-landing",
  "loadtype": "direct",
  "weight": 100,
  "mvt": {},
  "links": []
}
```

The `name` must match an existing folder under `caching/landings/`. Weight, MVT settings, and Destinations links (`links`) are stored on the step entry, not in the library. The library holds only the files on disk.

When a campaign step is rendered, the TDS looks up `caching/landings/<name>` at request time. If the folder does not exist (because it was deleted or renamed after the step was configured), the request fails for that click.

## Macros Available in Landing HTML

Only `{clickid}`, `{userid}`, and `{px}` are substituted inside landing HTML. `{next}` and `{offer}` advance the funnel step and are also processed. Other macros that work in redirect URLs or S2S rules are not available in landing HTML.
