<?php
/**
 * Standalone regression test for FW_AO_Minifier::close_unbalanced().
 *
 * Run: php framework/extensions/asset-optimizer/tests/test-close-unbalanced.php
 * Exits 0 on success, 1 on any failed assertion.
 *
 * Guards against the "dark site renders white" corruption: a source stylesheet
 * that ends inside an UNTERMINATED string (a corrupt `url("data:image/svg+xml;…`
 * value with no closing quote, as emitted by a broken page-builder CSS blob) used
 * to make the final combined-CSS string scanner swallow every FOLLOWING block -
 * including the theme's `:root{--site-bg-color:…}` - so the browser dropped it and
 * the body rendered white. close_unbalanced() contains such a file by terminating
 * its dangling string and closing its still-open blocks BEFORE concatenation, and
 * leaves every well-formed stylesheet byte-for-byte unchanged.
 */

define( 'FW', true );
require dirname( __DIR__ ) . '/includes/class-fw-ao-minifier.php';

$fail = 0;
$DQ   = chr( 34 );

function check( $label, $cond ) {
	global $fail;
	echo ( $cond ? '  PASS  ' : '  FAIL  ' ) . $label . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

/* Browser-like scan: strings, comments, and {}()[] nesting (with `\` escapes
 * outside strings). With $stop set, returns state AT that substring; else totals. */
function scan( $s, $stop = null ) {
	$DQ = chr( 34 ); $SQ = chr( 39 ); $BS = chr( 92 );
	$off = ( $stop === null ) ? strlen( $s ) : strpos( $s, $stop );
	if ( $off === false ) {
		return array( 'found' => false );
	}
	$len = strlen( $s ); $i = 0; $brace = 0; $paren = 0; $brack = 0; $instr = false; $incom = false;
	while ( $i < $off ) {
		$c = $s[ $i ]; $c2 = $i + 1 < $len ? $s[ $i + 1 ] : '';
		if ( $incom ) { if ( $c === '*' && $c2 === '/' ) { $incom = false; $i += 2; continue; } $i++; continue; }
		if ( $instr !== false ) { if ( $c === $BS ) { $i += 2; continue; } if ( $c === $instr ) { $instr = false; } $i++; continue; }
		if ( $c === $BS ) { $i += 2; continue; }
		if ( $c === '/' && $c2 === '*' ) { $incom = true; $i += 2; continue; }
		if ( $c === $DQ || $c === $SQ ) { $instr = $c; $i++; continue; }
		if ( $c === '{' ) { $brace++; } elseif ( $c === '}' ) { $brace--; }
		elseif ( $c === '(' ) { $paren++; } elseif ( $c === ')' ) { $paren--; }
		elseif ( $c === '[' ) { $brack++; } elseif ( $c === ']' ) { $brack--; }
		$i++;
	}
	return array( 'found' => true, 'in_string' => ( $instr !== false ), 'in_comment' => $incom,
		'block_depth' => $brace, 'paren' => $paren, 'brack' => $brack );
}
function state_at( $s, $needle ) { return scan( $s, $needle ); }
function final_depth( $s ) { $r = scan( $s ); return array( $r['block_depth'], $r['in_string'], $r['paren'], $r['brack'] ); }

/* Mirror of the extension's per-file combine (strip -> [fix] -> concat -> css). */
function combine( $files, $use_fix ) {
	$DQ   = chr( 34 );
	$body = '';
	foreach ( $files as $handle => $css ) {
		$css = FW_AO_Minifier::strip_css_comments( $css );
		if ( $use_fix ) {
			$css = FW_AO_Minifier::close_unbalanced( $css );
		}
		$body .= "\n/* ==== " . $handle . " ==== */\n" . $css . "\n";
	}
	return FW_AO_Minifier::css( '@charset ' . $DQ . 'UTF-8' . $DQ . ";\n" . $body );
}

/* ---- 1. Byte-identity: every WELL-FORMED input returns unchanged. ---- */
$well_formed = array(
	':root{--site-bg-color:#030609}',
	'.a{content:"}"}.b{content:"/*"}',            // braces/comment tokens inside strings
	'.x{background:url("data:image/svg+xml,<svg/>")}',
	"a{color:red}\n/* note */\nb{color:blue}",
	'.q{content:"\\""}',                            // escaped quote inside string
	'.p{transform:matrix(1,0,0,1,0,0);width:calc((100% - 20px)/2)}', // nested parens
	'.pt-lg-\\[80px\\]{padding-top:80px}',          // escaped brackets in selector
	'.grid{grid-template-columns:[full-start] 1fr [full-end]}', // real balanced brackets
);
foreach ( $well_formed as $k => $css ) {
	check( "well-formed input #$k unchanged", FW_AO_Minifier::close_unbalanced( $css ) === $css );
}

/* ---- 2. Containment: a malformed source can't corrupt the combine. ---- */
// The real trigger: an unterminated url("data:… string that ALSO leaves an
// open `(` (url + matrix). Braces alone won't contain it - the dangling paren
// makes a browser swallow the following :root block.
$malformed = ':where(.a){background-image:url(' . $DQ
	. 'data:image/svg+xml;transform:matrix(1, 0, 0, 1, 0, 0);transition:opacity 1.4s;} .a .t{font-size:24px;}.b{margin:0;}';
$files = array(
	'page-x'               => $malformed,
	'presets'              => '.mid{color:red}.mid2{color:blue}',
	'unysonplus-hf-custom' => ':root{--site-bg-color:#030609;--h1-line-height:1}body{background:var(--site-bg-color)}',
);

$without = combine( $files, false );
$with    = combine( $files, true );

$sw = state_at( $without, '--site-bg-color:#030609' );
$si = state_at( $with, '--site-bg-color:#030609' );
list( $dw, $strw, $pw, $bw ) = final_depth( $without );
list( $di, $stri, $pi, $bi ) = final_depth( $with );

// WITHOUT the fix the design var lands inside a string AND/OR an open paren -
// either way the browser never applies it.
check( 'reproduces the bug WITHOUT the fix (design var unreachable: in string or open paren)',
	$sw['in_string'] === true || $sw['paren'] > 0 );
check( 'WITH fix: design var is real CSS, not string content', $si['in_string'] === false );
check( 'WITH fix: design var sits at block depth 1 (inside its own :root)', $si['block_depth'] === 1 );
check( 'WITH fix: design var is NOT inside an open paren', $si['paren'] === 0 );
check( 'WITH fix: design var is NOT inside an open bracket', $si['brack'] === 0 );
check( 'WITH fix: combined output has balanced braces (depth 0)', $di === 0 );
check( 'WITH fix: combined output has balanced parens (depth 0)', $pi === 0 );
check( 'WITH fix: combined output has balanced brackets (depth 0)', $bi === 0 );
check( 'WITH fix: combined output does not end inside a string', $stri === false );

echo "\n" . ( $fail === 0 ? "ALL PASS\n" : "$fail FAILED\n" );
exit( $fail === 0 ? 0 : 1 );
