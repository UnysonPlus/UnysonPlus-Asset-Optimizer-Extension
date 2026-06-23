<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Changelog ----------------------------------------------------------------
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

$manifest['version']    = '1.1.23';
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
