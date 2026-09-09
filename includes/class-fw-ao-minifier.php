<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Conservative, string-aware CSS + JS minifiers for the Asset Optimizer.
 *
 * Extracted verbatim from FW_Extension_Asset_Optimizer so the two hand-rolled
 * tokenizers - the highest-risk code in the extension to edit - live in one
 * small, dependency-free, unit-testable unit. Every method is pure (input
 * string -> output string, no WordPress, no state), which makes them trivial
 * to cover with a test corpus of tricky inputs (regex-vs-division, comment-in-
 * string, unclosed comment, template literals, `content: "a: b"`, etc.).
 *
 * Behaviour is byte-identical to the previous inline implementation.
 */
if ( ! class_exists( 'FW_AO_Minifier' ) ) :
class FW_AO_Minifier {

	/**
	 * Conservative, opt-in JS minifier.
	 *
	 * A single-pass character scanner that is aware of strings ('...', "..."),
	 * template literals (`...`, passed through verbatim including ${...}), regex
	 * literals, and line/block comments. It only does the SAFE transforms:
	 *   - strip comments;
	 *   - collapse runs of horizontal whitespace to a single space;
	 *   - collapse blank lines and drop indentation.
	 * Crucially it PRESERVES single newlines, so automatic-semicolon-insertion
	 * can't change the program's meaning (e.g. a bare `return\n5`). It never
	 * rewrites tokens, so it can't fuse identifiers or mangle operators.
	 *
	 * Regex-vs-division is resolved with the standard heuristic (the previous
	 * significant char / keyword). When in doubt it treats `/` as division and
	 * simply emits it verbatim - which is lossless, just less compressed.
	 *
	 * @param string $js
	 * @return string
	 */
	public static function js( $js ) {
		$len      = strlen( $js );
		$out      = '';
		$i        = 0;
		$prev_sig = '';   // last significant char emitted
		$word     = '';   // current identifier/keyword run (for regex heuristic)

		$append = static function ( $s ) use ( &$out ) {
			$out .= $s;
		};

		while ( $i < $len ) {
			$c  = $js[ $i ];
			$c2 = $i + 1 < $len ? $js[ $i + 1 ] : '';

			// Line comment - drop to end of line (newline handled next loop).
			if ( $c === '/' && $c2 === '/' ) {
				$i += 2;
				while ( $i < $len && $js[ $i ] !== "\n" && $js[ $i ] !== "\r" ) {
					$i++;
				}
				continue;
			}

			// Block comment - drop entirely.
			if ( $c === '/' && $c2 === '*' ) {
				$i += 2;
				while ( $i < $len && ! ( $js[ $i ] === '*' && ( $i + 1 < $len ) && $js[ $i + 1 ] === '/' ) ) {
					$i++;
				}
				$i += 2;
				continue;
			}

			// String literal.
			if ( $c === '"' || $c === "'" ) {
				$q = $c;
				$append( $c );
				$i++;
				while ( $i < $len ) {
					$ch = $js[ $i ];
					if ( $ch === '\\' && $i + 1 < $len ) {
						$append( $ch . $js[ $i + 1 ] );
						$i += 2;
						continue;
					}
					$append( $ch );
					$i++;
					if ( $ch === $q ) {
						break;
					}
				}
				$prev_sig = $q;
				$word     = '';
				continue;
			}

			// Template literal - pass through verbatim (incl. ${...}).
			if ( $c === '`' ) {
				$append( $c );
				$i++;
				while ( $i < $len ) {
					$ch = $js[ $i ];
					if ( $ch === '\\' && $i + 1 < $len ) {
						$append( $ch . $js[ $i + 1 ] );
						$i += 2;
						continue;
					}
					$append( $ch );
					$i++;
					if ( $ch === '`' ) {
						break;
					}
				}
				$prev_sig = '`';
				$word     = '';
				continue;
			}

			// Regex literal (only when a regex is grammatically allowed here).
			if ( $c === '/' && self::js_regex_allowed( $prev_sig, $word ) ) {
				$append( $c );
				$i++;
				$in_class = false;
				while ( $i < $len ) {
					$ch = $js[ $i ];
					if ( $ch === '\\' && $i + 1 < $len ) {
						$append( $ch . $js[ $i + 1 ] );
						$i += 2;
						continue;
					}
					$append( $ch );
					$i++;
					if ( $ch === '[' ) {
						$in_class = true;
					} elseif ( $ch === ']' ) {
						$in_class = false;
					} elseif ( $ch === '/' && ! $in_class ) {
						break;
					} elseif ( $ch === "\n" || $ch === "\r" ) {
						break; // malformed - bail safely
					}
				}
				while ( $i < $len && ctype_alpha( $js[ $i ] ) ) { // flags
					$append( $js[ $i ] );
					$i++;
				}
				$prev_sig = '/';
				$word     = '';
				continue;
			}

			// Horizontal whitespace - collapse to one space (never before a newline).
			if ( $c === ' ' || $c === "\t" || $c === "\f" || $c === "\v" ) {
				while ( $i < $len && ( $js[ $i ] === ' ' || $js[ $i ] === "\t" || $js[ $i ] === "\f" || $js[ $i ] === "\v" ) ) {
					$i++;
				}
				$last = $out === '' ? '' : substr( $out, -1 );
				if ( $last !== '' && $last !== "\n" && $last !== ' ' ) {
					$append( ' ' );
				}
				continue;
			}

			// Newlines - collapse runs (and adjacent ws) to a single \n.
			if ( $c === "\n" || $c === "\r" ) {
				while ( $i < $len && ( $js[ $i ] === "\n" || $js[ $i ] === "\r" || $js[ $i ] === ' ' || $js[ $i ] === "\t" ) ) {
					$i++;
				}
				$out = rtrim( $out, " \t" );
				if ( $out !== '' && substr( $out, -1 ) !== "\n" ) {
					$append( "\n" );
				}
				continue;
			}

			// Significant char.
			$append( $c );
			$prev_sig = $c;
			if ( ctype_alnum( $c ) || $c === '_' || $c === '$' ) {
				$word .= $c;
			} else {
				$word = '';
			}
			$i++;
		}

		return trim( $out );
	}

	/**
	 * Whether a `/` at this point begins a regex literal (vs. division), using
	 * the standard previous-significant-token heuristic.
	 *
	 * @param string $prev_sig Last significant char emitted.
	 * @param string $word     Trailing identifier/keyword run, if any.
	 * @return bool
	 */
	private static function js_regex_allowed( $prev_sig, $word ) {
		if ( $prev_sig === '' ) {
			return true;
		}
		if ( strpos( '(,=:[!&|?{};+-*%<>~^', $prev_sig ) !== false ) {
			return true;
		}
		if ( ctype_alnum( $prev_sig ) || $prev_sig === '_' || $prev_sig === '$' ) {
			static $keywords = array(
				'return', 'typeof', 'instanceof', 'in', 'of', 'new', 'delete',
				'void', 'throw', 'else', 'do', 'yield', 'await', 'case',
			);
			return in_array( $word, $keywords, true );
		}
		return false;
	}

	/**
	 * Strips CSS comments with a single-pass, string-aware scanner.
	 *
	 * Robust against malformed input (the whole point of using this instead of a
	 * `/*...*\/` regex):
	 *   - an UNCLOSED `/*` drops the remainder of the string (when run per source
	 *     file this contains the damage to that one file, never the next);
	 *   - a STRAY `*\/` with no opener is simply dropped (it would be invalid CSS
	 *     and could otherwise abort the parser for everything after it);
	 *   - string literals ('...' / "...") are copied verbatim, so a `/*` or `*\/`
	 *     inside a value (e.g. content: "*\/") is never mistaken for a delimiter.
	 *
	 * @param string $css
	 * @return string
	 */
	public static function strip_css_comments( $css ) {
		$len = strlen( $css );
		$out = '';
		$i   = 0;

		while ( $i < $len ) {
			$c  = $css[ $i ];
			$c2 = $i + 1 < $len ? $css[ $i + 1 ] : '';

			// Comment open: skip to the matching close, or to EOF if unclosed.
			if ( $c === '/' && $c2 === '*' ) {
				$end = strpos( $css, '*/', $i + 2 );
				if ( $end === false ) {
					break; // unclosed - drop the rest (contained to this file)
				}
				$i = $end + 2;
				continue;
			}

			// Stray close delimiter (malformed source) - drop it.
			if ( $c === '*' && $c2 === '/' ) {
				$i += 2;
				continue;
			}

			// String literal - copy verbatim so delimiters inside can't fool us.
			if ( $c === '"' || $c === "'" ) {
				$q    = $c;
				$out .= $c;
				$i++;
				while ( $i < $len ) {
					$ch = $css[ $i ];
					if ( $ch === '\\' && $i + 1 < $len ) {
						$out .= $ch . $css[ $i + 1 ];
						$i   += 2;
						continue;
					}
					$out .= $ch;
					$i++;
					if ( $ch === $q ) {
						break;
					}
				}
				continue;
			}

			$out .= $c;
			$i++;
		}

		return $out;
	}

	/**
	 * Neutralises a per-source stylesheet whose string / comment / bracket state is
	 * left OPEN at EOF, so a single malformed source can't corrupt the whole
	 * combined file.
	 *
	 * Rationale: strip_css_comments() contains an unclosed COMMENT to its own file
	 * (it drops the rest), but it copies string literals VERBATIM and never tracks
	 * brackets - so a source that ends with an unterminated string OR an unbalanced
	 * `(` / `[` / `{` leaks into whatever follows. The real-world trigger is a
	 * corrupt data-URI value such as
	 *   background-image:url("data:image/svg+xml;transform:matrix(1,0,0,1,0,0);…
	 * with no closing quote or paren. A browser's CSS tokenizer requires `()[]{}`
	 * to nest and balance: an open `(` keeps consuming tokens (including `{` and
	 * `}`) until its matching `)`, so a single dangling `(` swallows every FOLLOWING
	 * rule - including the theme's `:root{--site-bg-color:…}` - and the page renders
	 * with the default (white) background. Tracking only braces is not enough; the
	 * unclosed PAREN is what desynced the parser.
	 *
	 * This scanner mirrors the CSS token grammar closely enough to contain damage:
	 * it tracks strings (with `\` escapes), block comments, and a STACK of open
	 * `{` / `(` / `[` delimiters, and it honours `\` escapes OUTSIDE strings too
	 * (so an escaped `\[` in a selector like `.pt-lg-\[80px\]` is NOT counted as a
	 * bracket). At EOF it appends the minimum needed to close what the file left
	 * open: the dangling quote first, then each open delimiter's mate in LIFO order.
	 *
	 * For WELL-FORMED input (every string / comment / bracket balanced) it appends
	 * nothing and returns the input byte-for-byte unchanged - so the combine stays
	 * identical for every valid stylesheet; only a genuinely broken source is
	 * contained (its trailing malformed run is folded into a single value, and the
	 * following stylesheets parse cleanly).
	 *
	 * @param string $css
	 * @return string
	 */
	public static function close_unbalanced( $css ) {
		$len   = strlen( $css );
		$i     = 0;
		$stack = array();  // open delimiters: '{', '(', '['
		$instr = false;    // false, or the open quote char (" or ')

		$close = array(
			'{' => '}',
			'(' => ')',
			'[' => ']',
		);

		while ( $i < $len ) {
			$c  = $css[ $i ];
			$c2 = $i + 1 < $len ? $css[ $i + 1 ] : '';

			if ( false !== $instr ) {
				if ( '\\' === $c && $i + 1 < $len ) {
					$i += 2;
					continue;
				}
				if ( $c === $instr ) {
					$instr = false;
				}
				$i++;
				continue;
			}

			// A backslash outside a string escapes the next char (CSS ident/selector
			// escapes such as `\[`), so it can't open or close a bracket.
			if ( '\\' === $c && $i + 1 < $len ) {
				$i += 2;
				continue;
			}

			// Skip comments (semantics mirror strip_css_comments; an unclosed one
			// runs to EOF and the loop ends inside it, contributing nothing).
			if ( '/' === $c && '*' === $c2 ) {
				$end = strpos( $css, '*/', $i + 2 );
				if ( false === $end ) {
					break;
				}
				$i = $end + 2;
				continue;
			}

			if ( '"' === $c || "'" === $c ) {
				$instr = $c;
				$i++;
				continue;
			}

			if ( '{' === $c || '(' === $c || '[' === $c ) {
				$stack[] = $c;
			} elseif ( '}' === $c || ')' === $c || ']' === $c ) {
				// Pop only if it matches the top; an unmatched closer (stray, no
				// opener) is left as-is - harmless and preserves byte-identity.
				$top = end( $stack );
				if ( false !== $top && $close[ $top ] === $c ) {
					array_pop( $stack );
				}
			}
			$i++;
		}

		$suffix = '';
		if ( false !== $instr ) {
			$suffix .= $instr; // terminate the dangling string
		}
		// Close still-open delimiters in LIFO order (innermost first).
		for ( $s = count( $stack ) - 1; $s >= 0; $s-- ) {
			$suffix .= $close[ $stack[ $s ] ];
		}

		return '' === $suffix ? $css : $css . $suffix;
	}

	/**
	 * Lightweight CSS minifier for the combined output.
	 *
	 * Strips comments and collapses non-significant whitespace. Deliberately
	 * conservative: it leaves spacing around value operators (`+ - * /` inside
	 * calc(), combinators) and colons untouched so declarations and selectors
	 * keep their meaning - the bulk of the savings comes from dropping the
	 * newlines/indentation and comments between the merged stylesheets.
	 *
	 * @param string $css
	 * @return string
	 */
	public static function css( $css ) {
		// Remove CSS comments with the string-aware scanner (same one used per
		// source file). At this point only the balanced "/* ==== handle ==== */"
		// markers remain, but using the scanner instead of a non-greedy regex
		// keeps comment handling uniformly safe - a regex would mis-pair across
		// the whole concatenated string if any stray delimiter slipped through.
		$css = self::strip_css_comments( $css );

		// String-aware whitespace tightening. String literals are copied
		// VERBATIM (so `content: "a: b"` keeps its exact text). Outside strings,
		// each whitespace run is dropped entirely when it sits next to a
		// character where CSS whitespace is insignificant, else collapsed to a
		// single space. Deliberate asymmetries:
		//   - space AFTER  `:` is removed (`margin: 0` -> `margin:0`) but space
		//     BEFORE `:` is kept (`li :hover` = descendant + pseudo);
		//   - space AFTER  `(` is removed but space BEFORE `(` is kept
		//     (`@media screen and (min-width:…)` requires it);
		//   - `+` and `~` are never touched (calc() / value math).
		$len = strlen( $css );
		$out = '';
		$i   = 0;
		while ( $i < $len ) {
			$c = $css[ $i ];

			if ( '"' === $c || "'" === $c ) { // string literal - copy verbatim
				$q    = $c;
				$out .= $c;
				$i++;
				while ( $i < $len ) {
					$ch   = $css[ $i ];
					$out .= $ch;
					$i++;
					if ( '\\' === $ch && $i < $len ) {
						$out .= $css[ $i ];
						$i++;
						continue;
					}
					if ( $ch === $q ) {
						break;
					}
				}
				continue;
			}

			if ( false !== strpos( " \t\n\r\f", $c ) ) {
				while ( $i < $len && false !== strpos( " \t\n\r\f", $css[ $i ] ) ) {
					$i++;
				}
				$prev = '' === $out ? '' : substr( $out, -1 );
				$next = $i < $len ? $css[ $i ] : '';
				if ( '' === $prev || '' === $next
					|| false !== strpos( '{};,>:(', $prev )
					|| false !== strpos( '{};,>)', $next ) ) {
					continue; // insignificant - drop the whitespace entirely
				}
				$out .= ' ';
				continue;
			}

			$out .= $c;
			$i++;
		}

		// Remove the now-redundant final semicolon before a closing brace.
		$out = str_replace( ';}', '}', $out );

		return trim( $out );
	}
}
endif;
