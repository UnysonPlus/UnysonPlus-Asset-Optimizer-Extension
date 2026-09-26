<?php
/**
 * Standalone checks for FW_AO_Purger. No WordPress needed:
 *
 *   php tests/test-purger.php
 *
 * Every case here is either a behaviour the audit proved matters, or a parser
 * edge case that would silently corrupt a stylesheet if it regressed.
 */
define( 'FW', true );
require __DIR__ . '/../includes/class-fw-ao-purger.php';

$html = '<html><body class="page theme-x">'
	. '<div class="card card--wide" id="main">'
	. '<a class="btn" href="#">go</a>'
	. '<span class="md:flex"></span>'
	. '</div>'
	. '<script>document.querySelector(".card").classList.add("is-open")</script>'
	. '</body></html>';

$tokens   = FW_AO_Purger::collect_tokens( $html );
$safelist = array( '#\b(is|has)-#', '#:(hover|focus)#' );

$pass = 0;
$fail = 0;
function check( $name, $got, $want ) {
	global $pass, $fail;
	$ok = ( trim( $got ) === trim( $want ) );
	$ok ? $pass++ : $fail++;
	echo ( $ok ? "PASS  " : "FAIL  " ) . $name . PHP_EOL;
	if ( ! $ok ) {
		echo "        got:  " . trim( $got ) . PHP_EOL;
		echo "        want: " . trim( $want ) . PHP_EOL;
	}
}
function purge( $css ) {
	global $tokens, $safelist;
	return FW_AO_Purger::purge( $css, $tokens, $safelist );
}

// --- the basics ------------------------------------------------------------
check( 'keeps a class that exists',        purge( '.card{color:red}' ), '.card{color:red}' );
check( 'drops a class that does not',      purge( '.nope{color:red}' ), '' );
check( 'keeps an id that exists',          purge( '#main{color:red}' ), '#main{color:red}' );
check( 'drops an id that does not',        purge( '#gone{color:red}' ), '' );
check( 'keeps bare element selectors',     purge( 'body{margin:0}' ), 'body{margin:0}' );
check( 'keeps :root (custom properties)',  purge( ':root{--a:1px}' ), ':root{--a:1px}' );
check( 'keeps the universal selector',     purge( '*{box-sizing:border-box}' ), '*{box-sizing:border-box}' );

// --- selector groups -------------------------------------------------------
check( 'prunes only the dead half of a group',
	purge( '.card,.nope{color:red}' ), '.card{color:red}' );
check( 'drops a group where nothing survives',
	purge( '.nope,.gone{color:red}' ), '' );
check( 'a compound needs ALL its classes present',
	purge( '.card.nope{color:red}' ), '' );
check( 'descendant with both classes present is kept',
	purge( '.card .btn{color:red}' ), '.card .btn{color:red}' );

// --- the safety rules the audit produced -----------------------------------
check( 'keeps attribute selectors (JS toggles them)',
	purge( '.nope[aria-expanded="true"]{color:red}' ), '.nope[aria-expanded="true"]{color:red}' );
check( 'safelist keeps a state class that is not in the DOM',
	purge( '.is-active{color:red}' ), '.is-active{color:red}' );
check( 'safelist keeps hover rules',
	purge( '.nothinghere:hover{color:red}' ), '.nothinghere:hover{color:red}' );
check( 'class found only inside a <script> string is kept',
	purge( '.is-open{color:red}' ), '.is-open{color:red}' );
check( 'escaped class matches its HTML form',
	purge( '.md\\:flex{display:flex}' ), '.md\\:flex{display:flex}' );

// --- at-rules --------------------------------------------------------------
check( 'recurses into @media and keeps live rules',
	purge( '@media (min-width:0){.card{color:red}}' ), '@media (min-width:0){.card{color:red}}' );
check( 'drops an @media left empty',
	purge( '@media (min-width:0){.nope{color:red}}' ), '' );
check( 'keeps @font-face verbatim',
	purge( '@font-face{font-family:"X";src:url(x.woff2)}' ), '@font-face{font-family:"X";src:url(x.woff2)}' );
check( 'never prunes @keyframes (JS can start an animation)',
	purge( '@keyframes spin{from{transform:none}to{transform:rotate(1turn)}}' ),
	'@keyframes spin{from{transform:none}to{transform:rotate(1turn)}}' );
check( 'keeps @supports with a live rule',
	purge( '@supports (display:grid){.card{display:grid}}' ), '@supports (display:grid){.card{display:grid}}' );
check( 'keeps @import statements',
	purge( '@import url(a.css);' ), '@import url(a.css);' );

// --- modern selectors that broke the off-the-shelf tools -------------------
check( ':where() does not hang or throw',
	purge( ':where(.card){color:red}' ), ':where(.card){color:red}' );
check( ':has() is kept',
	purge( '.card:has(.btn){color:red}' ), '.card:has(.btn){color:red}' );
// :is()/:where() are looked INSIDE: the argument IS the selector in
// WordPress's global styles (`:root :where(.wp-block-x)`), so treating it as
// unjudgeable kept the entire sheet.
check( ':is() whose only alternative is absent cannot match, so it is dropped',
	purge( '.card:is(.nope){color:red}' ), '' );
check( ':is() is an OR - one live alternative keeps the rule',
	purge( '.card:is(.nope,.btn){color:red}' ), '.card:is(.nope,.btn){color:red}' );
check( 'comma inside :is() still does not split the selector group',
	purge( '.card:is(.a,.btn){color:red}' ), '.card:is(.a,.btn){color:red}' );
check( ':where() with a live class is kept',
	purge( ':root :where(.card){color:red}' ), ':root :where(.card){color:red}' );
check( 'WordPress global-styles shape with a dead class is dropped',
	purge( ':root :where(.wp-block-nothing){color:red}' ), '' );
// Regression: a real rule from the theme, `.entry-content a:not(.btn):not(:is(.posts *))`.
// Reading the :is() out of the :not() inverted the rule's meaning and dropped it
// on exactly the pages where it applies, recolouring every link on the page.
check( ':is() nested inside :not() is NOT a requirement',
	purge( '.card a:not(.btn):not(:is(.nope *)){color:blue}' ),
	'.card a:not(.btn):not(:is(.nope *)){color:blue}' );
check( 'nested :not(:is()) with several dead alternatives is still kept',
	purge( '.card:not(:is(.dead1,.dead2)){color:blue}' ), '.card:not(:is(.dead1,.dead2)){color:blue}' );
check( 'a real :is() requirement outside :not() still applies',
	purge( '.card:is(.dead1,.dead2){color:blue}' ), '' );
check( ':not() does NOT require its argument to exist',
	purge( '.card:not(.nope){color:red}' ), '.card:not(.nope){color:red}' );

// --- parser edge cases that would corrupt output ---------------------------
check( 'brace inside a string does not end the rule',
	purge( '.card{content:"}"}' ), '.card{content:"}"}' );
check( 'comma inside an attribute value does not split',
	purge( '.card[data-x="a,b"]{color:red}' ), '.card[data-x="a,b"]{color:red}' );
check( 'comments are stripped',
	purge( '/* hi */.card{color:red}' ), '.card{color:red}' );
check( 'bang comments (licences) are preserved',
	purge( '/*! (c) me */.card{color:red}' ), '/*! (c) me */.card{color:red}' );
check( 'nested @media inside @supports survives',
	purge( '@supports (a:b){@media screen{.card{color:red}}}' ),
	'@supports (a:b){@media screen{.card{color:red}}}' );
check( 'empty input is safe', purge( '' ), '' );

// --- Animation Engine: the set most likely to be destroyed -----------------
// Its classes are applied by JS on scroll/in-view, so a DOM scan of the page at
// rest sees none of them. The real safelist (not the trimmed one above) is what
// protects them, so these use it.
// The REAL defaults, imported from the product - never a copy. A duplicated
// safelist in this file silently lost its word boundaries once and still
// reported PASS, which is exactly the failure a test is supposed to catch.
$real_safelist = FW_AO_Purger::default_safelist();

// Every default pattern must actually COMPILE. An invalid one is swallowed by
// the matcher and silently protects nothing - that is how a `~` inside a
// character class, with `~` as the delimiter, disabled the state-class rule.
foreach ( $real_safelist as $i => $pat ) {
	check( "default safelist pattern #$i compiles", ( @preg_match( $pat, "x" ) === false ? "INVALID" : "ok" ), "ok" );
}
function purge_real( $css ) {
	global $tokens, $real_safelist;
	return FW_AO_Purger::purge( $css, $tokens, $real_safelist );
}
check( 'keeps .animate__fadeInUp though nothing on the page has it',
	purge_real( '.animate__fadeInUp{animation-name:fadeInUp}' ),
	'.animate__fadeInUp{animation-name:fadeInUp}' );
check( 'keeps .animate__animated base class',
	purge_real( '.animate__animated{animation-duration:1s}' ),
	'.animate__animated{animation-duration:1s}' );
check( 'keeps an in-view / reveal class',
	purge_real( '.fw-ae-reveal.in-view{opacity:1}' ), '.fw-ae-reveal.in-view{opacity:1}' );
check( 'keeps a slider class the scan cannot see',
	purge_real( '.swiper-slide-active{z-index:2}' ), '.swiper-slide-active{z-index:2}' );
check( 'keeps an accordion open-state rule',
	purge_real( '.accordion-item.is-open .accordion-content{display:block}' ),
	'.accordion-item.is-open .accordion-content{display:block}' );
check( 'keeps the @keyframes an effect animates with',
	purge_real( '@keyframes fadeInUp{from{opacity:0}to{opacity:1}}' ),
	'@keyframes fadeInUp{from{opacity:0}to{opacity:1}}' );
check( 'the state safelist does NOT cover WordPress static content classes',
	purge_real( '.has-vivid-red-background{background:red}' ), '' );
check( 'nor .is-layout-* (static, not JS state)',
	purge_real( ':root :where(.is-layout-flow) > *{margin:0}' ), '' );
check( 'but a genuine JS state class is still protected',
	purge_real( '.is-open .panel{display:block}' ), '.is-open .panel{display:block}' );
check( 'and a WP content class that IS on the page is kept by plain matching',
	FW_AO_Purger::purge( '.card.has-vivid-red-background{background:red}',
		FW_AO_Purger::collect_tokens( '<div class="card has-vivid-red-background"></div>' ),
		FW_AO_Purger::default_safelist() ),
	'.card.has-vivid-red-background{background:red}' );
check( 'still drops an ordinary dead rule with the real safelist',
	purge_real( '.totally-unused-thing{color:red}' ), '' );

// --- the property that matters most ---------------------------------------
$real = '.card{color:red}.nope{color:blue}@media screen{.btn{x:1}.dead{y:2}}';
$out  = purge( $real );
check( 'a realistic sheet keeps only the live rules',
	$out, '.card{color:red}@media screen{.btn{x:1}}' );

echo PHP_EOL . "$pass passed, $fail failed" . PHP_EOL;
exit( $fail ? 1 : 0 );
