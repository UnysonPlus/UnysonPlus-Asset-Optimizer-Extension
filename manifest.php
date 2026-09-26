<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Changelog ----------------------------------------------------------------
 *
 * 1.1.42 - "Serve WebP images" switch on the General tab (off by default).
 *          Every uploaded JPG / PNG, each of its generated sizes and each
 *          responsive crop from fw_image_crop_renditions() gets a WebP copy
 *          beside it, and front-end image URLs (attachment images, srcsets,
 *          images in post content, fw_image_tag crops) are swapped to the WebP
 *          when it exists. Originals are never touched. Existing images are
 *          converted gradually, a few per page view; a WebP that comes out
 *          larger than its source is dropped and marked so it isn't retried.
 *          Quality: fw:ext:asset-optimizer:webp_quality (default 80).
 *
 * 1.1.41 - "CSS delivery" option on the General tab. Linked file (default,
 *          unchanged) or Inline: the combined CSS is printed in a style tag in
 *          the page head instead of a separate stylesheet, so first paint no
 *          longer waits on a second request (the Lighthouse "render-blocking
 *          requests" insight). A bundle over 50 KB gzipped always stays a
 *          linked file; raise or lower the cap with the
 *          fw:ext:asset-optimizer:css_inline_max_bytes filter. The compressed
 *          size is measured once per bundle and cached.
 *
 * 1.1.33 - "CSS combining scope" now defaults to Per-page (was Site-wide).
 *          Each page combines only the stylesheets it actually enqueues into its
 *          own file, so pages stop shipping CSS they don't use - the big win on
 *          Lighthouse / GTmetrix "reduce unused CSS". Site-wide (one shared
 *          bundle) is still available and an explicit saved "site" still wins, so
 *          anyone who chose it keeps it. Per-page is now the FIRST dropdown
 *          choice. (JavaScript was already per-page.)
 *
 * 1.1.29 - "Minify combined script" now defaults ON. The conservative JS
 *          minifier (string/template/regex-aware, preserves line breaks) runs on
 *          the combined bundle out of the box so it isn't flagged as unminified;
 *          an explicit saved "off" still wins, so anyone who turned it off keeps
 *          that. (Defer stays opt-in - it's a footer bundle, so deferring has
 *          little upside and can reorder scripts that expect it synchronously.)
 *
 * 1.1.28 - Per-page CSS scope (new "CSS combining scope" option on the General
 *          tab). Site-wide (default, unchanged): one shared stylesheet built from
 *          every stylesheet ever discovered, cached and reused across pages.
 *          Per-page: each page combines ONLY the stylesheets it enqueued into its
 *          own file, in exact document order - smaller per page and cascade-exact,
 *          but not shared across pages. Implemented by sourcing handles from the
 *          live $wp_styles queue instead of the persisted map; all ordering /
 *          minify / suppression is shared. (JavaScript is already per-page.)
 *
 * 1.1.27 - Public combine API for cooperating extensions. Two methods -
 *          is_combine_enabled( 'css'|'js' ) and combine_files( $ordered, 'css'|'js' )
 *          - let another extension fold its OWN per-page assets into one cached
 *          request while honoring the master switches / logged-out-only / URL
 *          exclusions. The Animation Engine uses this to combine its on-demand
 *          per-style partials without breaking its "ship only used styles"
 *          contract (its `css_exclude_handles` filter keeps the site-wide
 *          combiner from also absorbing those handles).
 *
 * 1.1.26 - New "General" settings tab with master controls: "Combine CSS" and
 *          "Combine JavaScript" on/off switches (quick kill switches that don't
 *          touch the per-file selections), "Only for logged-out visitors" (serve
 *          combined files to visitors while logged-in users get the un-combined
 *          assets), and an "Exclude URLs" list (one path per line, * wildcard) so
 *          the combiner is skipped on chosen pages (e.g. /cart/, /checkout*).
 *          All gate both the CSS and JS combine passes and default to "combine".
 *
 * 1.1.25 - FIX: run the combine pass AFTER the theme's stylesheet orderer. The
 *          combine hooks moved from wp_enqueue_scripts:9999 to :99999. The
 *          UnysonPlus parent theme re-orders stylesheets via dependencies at
 *          :9999 (parent-style -> presets -> hf-custom -> child); because the
 *          plugin loads before the theme, our :9999 pass ran FIRST and captured
 *          the pre-ordered cascade, baking the wrong order into the combined
 *          file and breaking the header. Running last lets our dependency-
 *          resolved capture reflect the theme's intended order.
 *
 * 1.1.24 - FIX: combining broke front-end layouts. The cascade bucketing hoisted
 *          the WHOLE parent theme above the framework/shortcode CSS, which
 *          dragged early base assets like header-footer-builder.css (normally
 *          printed FIRST) to high authority and inverted the cascade. The parent
 *          theme is no longer hoisted - it keeps its natural per-file frontend
 *          order. Only the child theme (last) and the presets / generated
 *          customization CSS (just below it) are floated to the end, which simply
 *          reproduces the working uncombined cascade. Child-theme hoisting is
 *          also now gated on is_child_theme() so a lone theme's early bases are
 *          never sent to the end.
 *
 * 1.1.20 - Cascade-aware CSS order. The combined stylesheet (and the settings
 *          list) now order the merged CSS as: everything else in true frontend
 *          print order, then the PARENT theme, then the UnysonPlus design
 *          presets (handle `unysonplus-presets`), then the CHILD theme LAST - so
 *          a theme keeps authority to override the framework/shortcode CSS it is
 *          meant to style, the presets sit just under the child theme, and the
 *          child theme overrides everything. A deliberate improvement over the
 *          raw frontend order, where shortcode CSS enqueued late in the footer
 *          would otherwise outrank the theme. The preset-handle list is
 *          filterable via `fw:ext:asset-optimizer:preset_css_handles`.
 *
 * 1.1.13 - Optional defer + minify for the combined JS (two opt-in switches on
 *          the JavaScript tab, both off by default). Defer adds the `defer`
 *          attribute to the self-contained, dependency-ordered bundle. Minify
 *          runs a conservative single-pass minifier that is aware of strings,
 *          template literals and regex literals and preserves line breaks (so
 *          automatic-semicolon-insertion can't change behavior) - it only
 *          strips comments and redundant whitespace, never rewriting tokens.
 *          The minify state is folded into the cache fingerprint.
 *
 * 1.1.12 - Cache controls + auto-purge + developer filters. The settings page
 *          gained a Cache section showing the combined-file count/size with
 *          "Clear cache" and "Re-scan frontend" buttons (admin-post + nonce).
 *          The cached bundles are now auto-purged whenever the active asset set
 *          can change (theme switch, plugin activate/deactivate, any upgrade).
 *          Two new escape-hatch filters let code force-exclude handles from
 *          combining: 'fw:ext:asset-optimizer:css_exclude_handles' (passed the
 *          known handle=>src map) and 'fw:ext:asset-optimizer:js_exclude_handles'.
 *
 * 1.1.4 - JavaScript combiner + tabbed settings. The settings page is now
 *         split into CSS and JavaScript tabs (native Unyson `tab` containers).
 *         The new JS tab folds eligible footer scripts into one cached file.
 *         It is deliberately conservative: only LOCAL, FOOTER scripts with no
 *         async/defer strategy and no inline/localized data (which is often
 *         per-request/per-user and unsafe to cache) are merged; WordPress core
 *         and external/CDN scripts are always excluded, as is any script a
 *         non-absorbed script depends on (to preserve execution order). By
 *         default only FIRST-PARTY scripts (the UnysonPlus plugin and the
 *         active parent/child theme) are pre-checked - third-party scripts are
 *         listed but opt-in. Once the JS tab is saved its checkbox state is
 *         authoritative; before that, the first-party default applies.
 */

$manifest = array();

$manifest['name']        = __( 'Asset Optimizer', 'fw' );
$manifest['slug']        = 'unysonplus-asset-optimizer';
$manifest['description'] = __(
	'Combines enqueued frontend assets into single minified cached files to reduce HTTP requests and payload size. Merges both CSS stylesheets and JavaScript, each on its own settings tab. Every detected asset is listed so you can pick which ones to merge.',
	'fw'
);

$manifest['version']    = '1.1.49';
$manifest['github_update'] = 'UnysonPlus/UnysonPlus-Asset-Optimizer-Extension';
$manifest['display']    = true;
$manifest['standalone'] = true;

// Author Info
$manifest['author']     = 'UnysonPlus';
$manifest['author_uri'] = 'https://www.lastimosa.com.ph/unysonplus';

// Meta
$manifest['license']      = 'GPL-2.0-or-later';
$manifest['text_domain']  = 'fw';
$manifest['requires_php'] = '7.4';
$manifest['requires_wp']  = '5.8';
