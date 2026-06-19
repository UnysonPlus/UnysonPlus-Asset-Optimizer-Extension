<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Changelog ----------------------------------------------------------------
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

$manifest['version']    = '1.1.10';
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
