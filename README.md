# UnysonPlus — Asset Optimizer

Combines your site's enqueued front-end **CSS and JavaScript** into fewer, minified,
cached files — fewer HTTP requests and a smaller payload, without you editing a single
enqueue. It's a **combiner**, built to be safe by default: it never combines anything it
can't prove is safe, and it leaves per-request/dynamic assets alone.

Ships as a standalone extension for the **Unyson+ (UnysonPlus)** framework. Inactive until
you enable it in *Extensions*.

---

## What it does

- **Combines CSS** — merges local stylesheets into one minified file, in correct **cascade
  order** (it preserves the order the browser would have used, so nothing restyles).
- **Combines JavaScript** — merges eligible **footer** scripts into one file, in dependency
  order. Deliberately conservative: only local footer scripts with **no `async`/`defer`** and
  **no inline/localized data** (nonces, per-user config) are merged. WordPress core and
  external/CDN scripts are always left alone.
- **Per-file control** — every detected stylesheet/script is listed on its tab so you can
  opt individual files in or out.
- **Cascade-aware** — the merged CSS floats the UnysonPlus design presets and the **child
  theme last**, so your theme keeps authority over the framework/shortcode CSS.
- **On-demand friendly** — cooperates with the UnysonPlus Animation Engine so its per-style,
  on-demand partials get folded into one request **without** shipping styles a page doesn't use.

## Settings

Two entry points render the same options: the extension's **Settings** link, and a dedicated
**Unyson+ → Asset Optimizer** page.

### General tab
- **Combine CSS** / **Combine JavaScript** — master on/off switches (don't touch your per-file
  selections).
- **CSS combining scope**
  - **Site-wide** *(default)* — one shared stylesheet built from every stylesheet discovered
    across the site, cached and reused on every page. Fewest downloads when visitors browse
    multiple pages.
  - **Per-page** — each page combines **only** the stylesheets it actually uses into its own
    file, in exact document order. Smaller per page and cascade-exact, but not shared across
    pages. *(JavaScript is always combined per-page.)*
- **Only for logged-out visitors** — serve combined files to visitors while logged-in users
  get the un-combined assets (handy while editing).
- **Exclude URLs** — one path per line (`*` wildcard); the combiner is skipped on matching
  pages, e.g. `/cart/`, `/checkout*`.

### CSS tab
The list of detected stylesheets, in front-end order (paths dimmed for readability). Checked =
merged; unchecked = left as its own request.

### JavaScript tab
The list of detected scripts, plus two opt-in switches:
- **Defer combined script** — add `defer` to the self-contained, dependency-ordered bundle.
- **Minify combined script** — a conservative single-pass minifier (string/template/regex-aware,
  preserves line breaks) — most scripts are already minified, so leave off unless you want it.

### Cache
The dedicated page shows the combined-file count/size with **Clear cache** and **Re-scan
frontend** buttons. Combined files are also auto-purged whenever the active asset set can change
(theme switch, plugin activate/deactivate, any upgrade).

## Notes & safety

- **CSS URLs are rewritten** so relative `url(...)` references still resolve from the combined
  file's location. Sub-directory / moved-`wp-content` installs are handled correctly.
- **WordPress-admin / option-type CSS** and **dead handles** (files from removed plugins/themes)
  are filtered out automatically, so the combined output stays front-end-only and lean.
- **Media-scoped stylesheets** (`print`, media queries) and **external/CDN** assets are left as
  their own requests.

## Developer hooks

```php
// Force-exclude specific handles from combining (CSS gets the known handle => src map).
add_filter( 'fw:ext:asset-optimizer:css_exclude_handles', function ( $handles, $known ) { /* … */ return $handles; }, 10, 2 );
add_filter( 'fw:ext:asset-optimizer:js_exclude_handles',  function ( $handles ) { /* … */ return $handles; } );

// Add/replace options on the settings page.
add_filter( 'fw:ext:asset-optimizer:settings-options:before', function ( $opts ) { return $opts; } );
add_filter( 'fw:ext:asset-optimizer:settings-options:after',  function ( $opts ) { return $opts; } );

// Promote extra handles to the "design preset" cascade tier (just below the child theme).
add_filter( 'fw:ext:asset-optimizer:preset_css_handles', function ( $handles ) { return $handles; } );
```

### Public combine API (for cooperating extensions)

```php
$ao = fw()->extensions->get( 'asset-optimizer' );
if ( $ao && $ao->is_combine_enabled( 'css' ) ) {
    // Fold your OWN ordered per-page files into one cached request.
    $url = $ao->combine_files( array(
        array( 'abs' => '/abs/path/a.css', 'src' => 'https://site/a.css', 'handle' => 'a' ),
        array( 'abs' => '/abs/path/b.css', 'src' => 'https://site/b.css', 'handle' => 'b' ),
    ), 'css' );
}
```

Both methods honor the master switches, logged-out-only, and URL exclusions. The UnysonPlus
Animation Engine uses this to combine its on-demand per-style partials.

## Requirements

- PHP **7.4+**
- WordPress **5.8+**
- The Unyson+ framework

## License

GPL-2.0-or-later
