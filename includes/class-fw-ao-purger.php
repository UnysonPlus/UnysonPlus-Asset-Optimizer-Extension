<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Removes CSS rules that nothing on the page can match.
 *
 * A typical UnysonPlus page downloads ~90% CSS it never uses: the framework and
 * each shortcode ship every variant they can render (every gap step, alignment,
 * decoration and breakpoint) while one page uses one of them. A measured audit
 * over 33 pages found the combined bundle drops by roughly half with no
 * rendering change at all.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS DELIBERATELY CONSERVATIVE
 * ---------------------------------------------------------------------------
 * The failure mode of removing a rule that IS needed is silent and delayed: a
 * hover state or an opened menu renders wrong, days later, on a page nobody
 * re-tested. Nothing errors. So every ambiguous case here resolves toward
 * KEEPING the rule - the cost of keeping a dead rule is bytes, the cost of
 * dropping a live one is a broken site.
 *
 * Concretely, this keeps:
 *
 *   - Any selector carrying no class and no id (`body`, `:root`, `a:hover`,
 *     `*`). It cannot be judged from a token scan, so it stays.
 *   - Any selector using an attribute (`[aria-expanded="true"]`), since the
 *     attribute is usually toggled by JS after the scan.
 *   - Everything matched by the safelist, which exists because a static scan
 *     cannot see classes JavaScript adds later. Measured: purging without one
 *     destroyed 111 of 120 hover rules, every `[aria-expanded]` rule and 78 of
 *     79 `.is-*`/`.has-*` state rules on a single page - while a screenshot of
 *     that page still looked perfect.
 *   - Every at-rule it does not fully understand, plus all `@font-face` and
 *     `@keyframes` blocks. Keyframes are NOT pruned even when no surviving rule
 *     names them, because an animation can be started from JavaScript or an
 *     inline style that no CSS scan can see.
 *
 * Matching is token-based (does this class/id exist anywhere in the document?),
 * not a full selector engine. That over-keeps - `.a .b` survives when both
 * exist but never nest - and over-keeping is the whole point. A browser-accurate
 * engine measured ~51-55% savings; this trades a few points of that for not
 * needing a selector-to-XPath layer that could silently disagree with the
 * browser on modern syntax (`:has()`, `:where()`).
 *
 * @since 1.2.0
 */
if ( ! class_exists( 'FW_AO_Purger' ) ) :
class FW_AO_Purger {

	/**
	 * The default safelist: selectors kept even when nothing in the scanned DOM
	 * matches them.
	 *
	 * It lives here, next to the matcher, rather than in the extension, so the
	 * product and its tests cannot drift apart - they did, and the copy in the
	 * tests silently lost its word boundaries while still "passing".
	 *
	 * NOTE the delimiter. These patterns contain `~` (in the combinator class of
	 * the first one), so `~` cannot be the delimiter: `~[...~...]~` ends the
	 * pattern at the inner tilde and PCRE rejects it. That failure is SILENT
	 * here - selector_survives() suppresses preg errors and treats a broken
	 * pattern as "no match" - so an invalid entry does not warn, it just quietly
	 * stops protecting anything. Hence `#` throughout, and `#` escaped inside.
	 *
	 * @return string[]
	 */
	public static function default_safelist() {
		return array(
			// .is-open, .has-children and friends - the commonest JS state prefix.
			//
			// The lookaheads carve out WordPress's own `is-`/`has-` families,
			// which are STATIC content classes, not JS state: `.is-layout-flow`,
			// `.has-vivid-red-background`, `.has-large-font-size` and the rest.
			// Blanket-safelisting them kept 16.6 KB of global-styles CSS on a page
			// that contains none of those classes. They need no protection: a
			// class that is actually used appears in the markup and is kept by
			// ordinary token matching, which is exactly what should decide it.
			'#(^|[\s.\#\[>+~,])(is|has)-(?!layout-)(?![\w-]*(?:color|background|gradient|font-size|font-family|text-align|drop-cap)\b)#',
			// State words that appear as whole classes or as a suffix.
			'#\b(active|open|opened|closed|collapsed|expanded|current|selected|checked|visible|hidden|sticky|stuck|scrolled|loading|loaded|in-?view|animated|playing|paused|error|success|disabled|toggled|dragging)\b#',
			// Slider / lightbox libraries, which class their own DOM at runtime.
			'#\b(swiper|glide|splide|flickity|lightbox|glightbox|fancybox)#',
			// The Animation Engine and animate.css effect classes. These are applied
			// on scroll / in-view, so a scan of the page at rest never sees them.
			'#\b(animate__|upw-|fw-ae-|anim|motion|reveal|parallax|count(er|up)|typed|marquee)#',
			'#\b(menu-toggle|menu-open|nav-open|drawer|offcanvas|mobile-|burger)#',
			'#\b(accordion|tabs?[-_]|modal|tooltip|popover|dropdown|submenu|sub-menu)#',
			// Core classes that content or core JS applies without the scan seeing
			// them. Deliberately NOT a blanket `wp-`: that matched every
			// `.wp-block-*` rule and kept all 16.8 KB of WordPress block CSS on a
			// page built with the page builder that contains no blocks at all.
			// A `.wp-block-*` class that IS used appears in the markup and is kept
			// by ordinary matching, so it needs no special case.
			'#\b(admin-bar|screen-reader|skip-link|sr-only|wp-caption|wp-smiley|alignleft|alignright|aligncenter|alignwide|alignfull)#',
			// Interaction pseudo-classes and pseudo-elements are never judgeable
			// from a static DOM.
			'#:(hover|focus|focus-visible|focus-within|active|visited|target|checked|disabled|placeholder-shown)#',
			'#::(before|after|placeholder|selection|marker|backdrop|-webkit-|-moz-)#',
		);
	}

	/**
	 * Collect every tag, class and id present in a rendered HTML document.
	 *
	 * Uses a regex sweep rather than DOMDocument: the input is a whole page of
	 * real-world markup, DOMDocument is strict about it, and we only need the
	 * token sets - not a tree. Being over-inclusive here is safe (it can only
	 * cause a rule to be KEPT), so the sweep also picks up class names that
	 * appear inside inline scripts and data attributes, which is exactly where
	 * a JS-toggled class name usually lives.
	 *
	 * @param string $html
	 * @return array{tags:array,classes:array,ids:array}
	 */
	public static function collect_tokens( $html ) {
		$tags = $classes = $ids = array();

		if ( preg_match_all( '#<([a-zA-Z][a-zA-Z0-9-]*)#', $html, $m ) ) {
			foreach ( $m[1] as $t ) {
				$tags[ strtolower( $t ) ] = true;
			}
		}

		// class="..." / class='...'
		if ( preg_match_all( '#\sclass\s*=\s*(["\'])(.*?)\1#is', $html, $m ) ) {
			foreach ( $m[2] as $chunk ) {
				foreach ( preg_split( '#\s+#', $chunk ) as $c ) {
					if ( $c !== '' ) {
						$classes[ $c ] = true;
					}
				}
			}
		}

		if ( preg_match_all( '#\sid\s*=\s*(["\'])(.*?)\1#is', $html, $m ) ) {
			foreach ( $m[2] as $id ) {
				$id = trim( $id );
				if ( $id !== '' ) {
					$ids[ $id ] = true;
				}
			}
		}

		// Class names that only ever appear in JS (classList.add('is-open'),
		// a template string, a data-* attribute). Cheap to gather and it removes
		// a whole category of false drops.
		if ( preg_match_all( '#["\']([A-Za-z_][\w-]{2,})["\']#', $html, $m ) ) {
			foreach ( $m[1] as $c ) {
				$classes[ $c ] = true;
			}
		}

		return array( 'tags' => $tags, 'classes' => $classes, 'ids' => $ids );
	}

	/**
	 * Purge a stylesheet against a document's tokens.
	 *
	 * @param string $css       Stylesheet source.
	 * @param array  $tokens    From collect_tokens().
	 * @param array  $safelist  Regex patterns (full `#...#` form); a selector
	 *                          matching any of them is always kept.
	 * @return string
	 */
	public static function purge( $css, array $tokens, array $safelist = array() ) {
		if ( ! is_string( $css ) || $css === '' ) {
			return (string) $css;
		}
		return self::purge_block( $css, $tokens, $safelist );
	}

	/**
	 * Walk one level of CSS, emitting only what survives. Recurses into the
	 * at-rules that contain ordinary rules (@media, @supports, @layer,
	 * @container); every other at-rule is copied out whole.
	 */
	private static function purge_block( $css, array $tokens, array $safelist ) {
		$out = '';
		$len = strlen( $css );
		$i   = 0;

		while ( $i < $len ) {
			// Skip whitespace between rules.
			if ( ctype_space( $css[ $i ] ) ) {
				$i++;
				continue;
			}

			// Comments are dropped (the combined file is minified anyway), except
			// a bang-comment, which by convention carries a licence.
			if ( '/' === $css[ $i ] && $i + 1 < $len && '*' === $css[ $i + 1 ] ) {
				$end = strpos( $css, '*/', $i + 2 );
				if ( false === $end ) {
					break;
				}
				if ( $i + 2 < $len && '!' === $css[ $i + 2 ] ) {
					$out .= substr( $css, $i, $end + 2 - $i );
				}
				$i = $end + 2;
				continue;
			}

			// Find this construct's prelude: everything up to `{` or `;`.
			$brace = self::find_top_level( $css, $i, '{' );
			$semi  = self::find_top_level( $css, $i, ';' );

			// A statement at-rule (@charset, @import) - no block. Always kept.
			if ( false !== $semi && ( false === $brace || $semi < $brace ) ) {
				$out .= trim( substr( $css, $i, $semi + 1 - $i ) );
				$i    = $semi + 1;
				continue;
			}
			if ( false === $brace ) {
				break; // trailing garbage
			}

			$prelude = trim( substr( $css, $i, $brace - $i ) );
			$body_end = self::match_brace( $css, $brace );
			if ( false === $body_end ) {
				break;
			}
			$body = substr( $css, $brace + 1, $body_end - $brace - 1 );
			$i    = $body_end + 1;

			if ( '' === $prelude ) {
				continue;
			}

			if ( '@' === $prelude[0] ) {
				$name = strtolower( preg_replace( '#^@([a-zA-Z-]+).*$#s', '$1', $prelude ) );

				// Conditional groups contain ordinary rules - recurse, and drop
				// the wrapper only if nothing inside survived.
				if ( in_array( $name, array( 'media', 'supports', 'layer', 'container', 'scope' ), true ) ) {
					$inner = self::purge_block( $body, $tokens, $safelist );
					if ( '' !== trim( $inner ) ) {
						$out .= $prelude . '{' . $inner . '}';
					}
					continue;
				}

				// @font-face, @keyframes, @page, @property, @counter-style,
				// @font-feature-values... kept verbatim. Keyframes in particular
				// are never pruned: an animation can be started from JS or an
				// inline style, neither of which this scan can see.
				$out .= $prelude . '{' . $body . '}';
				continue;
			}

			$kept = array();
			foreach ( self::split_selectors( $prelude ) as $sel ) {
				if ( self::selector_survives( $sel, $tokens, $safelist ) ) {
					$kept[] = $sel;
				}
			}
			if ( $kept ) {
				$out .= implode( ',', $kept ) . '{' . $body . '}';
			}
		}

		return $out;
	}

	/**
	 * Whether one selector should be kept.
	 *
	 * Returns true unless EVERY class and id it names is absent from the
	 * document - i.e. a selector is only dropped when there is positive evidence
	 * that nothing can match it.
	 */
	private static function selector_survives( $sel, array $tokens, array $safelist ) {
		$sel = trim( $sel );
		if ( '' === $sel ) {
			return false;
		}

		foreach ( $safelist as $pattern ) {
			if ( @preg_match( $pattern, $sel ) ) {
				return true;
			}
		}

		// An attribute selector usually keys off state that JS toggles after the
		// page is scanned ([aria-expanded="true"], [data-state=open]). Keep.
		if ( false !== strpos( $sel, '[' ) ) {
			return true;
		}

		// :is() and :where() must be looked INSIDE, not stripped.
		//
		// WordPress 6.x emits its entire global-styles sheet as
		// `:root :where(.wp-block-x){…}`. Stripping the argument leaves a bare
		// `:root`, which carries no class and is therefore "not judgeable" and
		// always kept - so every one of those rules survived and the pass saved
		// 1% of a 23 KB sheet. The argument is the whole selector there.
		//
		// A comma inside :is()/:where() is an OR, so the rule can match if ANY
		// alternative can. If none can, nothing can match the rule at all.
		// :not() is stripped FIRST, with its nested parentheses, and what it
		// contained is never treated as a requirement.
		//
		// `.entry-content a:not(.btn):not(:is(.posts *))` means "a link that is
		// NOT inside .posts". Reading the `:is()` out of it and demanding `.posts`
		// exist inverts the meaning exactly: the rule was dropped on every page
		// WITHOUT `.posts`, which is precisely where it applies. That turned every
		// link on a gallery page the wrong colour, and no unit test caught it -
		// only running the purge against a real page did.
		$requires = self::strip_pseudo( $sel, array( 'not' ) );

		if ( preg_match_all( '#(?<!\\\\):(?:is|where|matches|any)\(([^()]*)\)#i', $requires, $groups ) ) {
			foreach ( $groups[1] as $inner ) {
				$any = false;
				foreach ( self::split_selectors( $inner ) as $alt ) {
					if ( self::tokens_present( $alt, $tokens ) ) {
						$any = true;
						break;
					}
				}
				if ( ! $any ) {
					return false;
				}
			}
		}

		// Remaining pseudo-element/class arguments (:has(), :not(), :nth-child())
		// are NOT treated this way: `:not(.x)` does not require `.x` to exist, and
		// `:has(.y)` is a relative match we do not model. Strip them and judge what
		// is left; if that leaves nothing identifiable, the selector is kept.
		// The lookbehind matters: an ESCAPED colon is part of a class name, not a
		// pseudo-class. Without it `.md\:flex` is read as class `.md` plus a
		// pseudo `:flex`, and the rule is dropped even though the class exists.
		$probe = preg_replace( '#(?<!\\\\):{1,2}[a-zA-Z-]+\([^()]*\)#', '', $sel );
		$probe = preg_replace( '#(?<!\\\\):{1,2}[a-zA-Z-]+#', '', $probe );

		return self::tokens_present( $probe, $tokens );
	}

	/**
	 * Whether every class and id named in a selector fragment exists in the
	 * document. A fragment naming neither is "present" - it cannot be judged
	 * from tokens, and the conservative answer is yes.
	 *
	 * @param string $sel
	 * @param array  $tokens
	 * @return bool
	 */
	private static function tokens_present( $sel, array $tokens ) {
		// `~` delimiter for the id pattern: `#` is both the usual delimiter and
		// the character we need to match, and `##...#` is an empty pattern.
		$has_class = preg_match_all( '~\.((?:[\\\\].|[\w-])+)~', $sel, $cm );
		$has_id    = preg_match_all( '~#((?:[\\\\].|[\w-])+)~', $sel, $im );

		if ( ! $has_class && ! $has_id ) {
			return true; // element/universal/pseudo-only - not judgeable, keep
		}

		if ( $has_class ) {
			foreach ( $cm[1] as $class ) {
				if ( ! isset( $tokens['classes'][ self::unescape( $class ) ] ) ) {
					return false;
				}
			}
		}
		if ( $has_id ) {
			foreach ( $im[1] as $id ) {
				if ( ! isset( $tokens['ids'][ self::unescape( $id ) ] ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Remove `:name(...)` functional pseudo-classes, arguments included, handling
	 * NESTED parentheses - `:not(:is(.a *))` has to come out whole. A plain
	 * `[^()]*` regex stops at the inner `(` and leaves a mangled tail behind.
	 *
	 * @param string   $sel
	 * @param string[] $names Pseudo-class names to remove, e.g. array( 'not' ).
	 * @return string
	 */
	private static function strip_pseudo( $sel, array $names ) {
		$alt = implode( '|', array_map( 'preg_quote', $names ) );
		$out = '';
		$len = strlen( $sel );
		$i   = 0;
		while ( $i < $len ) {
			if ( ':' === $sel[ $i ] && ( 0 === $i || '\\' !== $sel[ $i - 1 ] )
				&& preg_match( '#^:(' . $alt . ')\(#i', substr( $sel, $i ), $m ) ) {
				$j     = $i + strlen( $m[0] ) - 1; // at the '('
				$depth = 0;
				for ( ; $j < $len; $j++ ) {
					if ( '(' === $sel[ $j ] ) {
						$depth++;
					} elseif ( ')' === $sel[ $j ] ) {
						$depth--;
						if ( 0 === $depth ) {
							break;
						}
					}
				}
				$i = $j + 1; // skip the whole pseudo, arguments and all
				continue;
			}
			$out .= $sel[ $i ];
			$i++;
		}
		return $out;
	}

	/** `.md\:flex` in CSS is the class `md:flex` in HTML. */
	private static function unescape( $ident ) {
		return preg_replace( '#\\\\(.)#', '$1', $ident );
	}

	/**
	 * Split a selector group on its TOP-LEVEL commas only - a comma inside
	 * :is(), :not() or an attribute value does not start a new selector.
	 */
	private static function split_selectors( $group ) {
		$out   = array();
		$buf   = '';
		$depth = 0;
		$quote = '';
		$len   = strlen( $group );

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $group[ $i ];
			if ( '' !== $quote ) {
				$buf .= $ch;
				if ( $ch === $quote && ( $i === 0 || '\\' !== $group[ $i - 1 ] ) ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $ch || "'" === $ch ) {
				$quote = $ch;
				$buf  .= $ch;
				continue;
			}
			if ( '(' === $ch || '[' === $ch ) {
				$depth++;
			} elseif ( ')' === $ch || ']' === $ch ) {
				$depth--;
			}
			if ( ',' === $ch && $depth <= 0 ) {
				$out[] = trim( $buf );
				$buf   = '';
				continue;
			}
			$buf .= $ch;
		}
		if ( '' !== trim( $buf ) ) {
			$out[] = trim( $buf );
		}
		return $out;
	}

	/** Offset of the next $needle that is not inside a string, comment or (). */
	private static function find_top_level( $css, $from, $needle ) {
		$len   = strlen( $css );
		$depth = 0;
		$quote = '';
		for ( $i = $from; $i < $len; $i++ ) {
			$ch = $css[ $i ];
			if ( '' !== $quote ) {
				if ( $ch === $quote && '\\' !== $css[ $i - 1 ] ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $ch || "'" === $ch ) {
				$quote = $ch;
				continue;
			}
			if ( '/' === $ch && $i + 1 < $len && '*' === $css[ $i + 1 ] ) {
				$end = strpos( $css, '*/', $i + 2 );
				if ( false === $end ) {
					return false;
				}
				$i = $end + 1;
				continue;
			}
			if ( '(' === $ch ) {
				$depth++;
			} elseif ( ')' === $ch ) {
				$depth--;
			} elseif ( $ch === $needle && $depth <= 0 ) {
				return $i;
			}
		}
		return false;
	}

	/** Offset of the `}` closing the `{` at $open, honouring strings/comments. */
	private static function match_brace( $css, $open ) {
		$len   = strlen( $css );
		$depth = 0;
		$quote = '';
		for ( $i = $open; $i < $len; $i++ ) {
			$ch = $css[ $i ];
			if ( '' !== $quote ) {
				if ( $ch === $quote && '\\' !== $css[ $i - 1 ] ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $ch || "'" === $ch ) {
				$quote = $ch;
				continue;
			}
			if ( '/' === $ch && $i + 1 < $len && '*' === $css[ $i + 1 ] ) {
				$end = strpos( $css, '*/', $i + 2 );
				if ( false === $end ) {
					return false;
				}
				$i = $end + 1;
				continue;
			}
			if ( '{' === $ch ) {
				$depth++;
			} elseif ( '}' === $ch ) {
				$depth--;
				if ( 0 === $depth ) {
					return $i;
				}
			}
		}
		return false;
	}
}
endif;
