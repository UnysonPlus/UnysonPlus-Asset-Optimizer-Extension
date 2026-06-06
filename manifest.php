<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$manifest = array();

$manifest['name']        = __( 'Asset Optimizer', 'fw' );
$manifest['slug']        = 'unysonplus-asset-optimizer';
$manifest['description'] = __(
	'Combines enqueued frontend assets into single cached files to reduce HTTP requests. Currently merges CSS stylesheets - JavaScript combining is planned next. Every detected asset is listed so you can pick which ones to merge.',
	'fw'
);

$manifest['version']    = '1.1.1';
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
