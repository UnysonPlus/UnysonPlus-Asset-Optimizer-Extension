<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Changelog ----------------------------------------------------------------
 *
 * 1.1.76 - CSS combining scope now defaults to Site-wide instead of Per-page.
 *          Per-page builds a separate stylesheet for every page, so a visitor who
 *          looks at four pages downloads four stylesheets that are largely the same
 *          file. Site-wide builds one bundle, and only the first page pays for it -
 *          every page after that is a cache hit. Per-page wins only the single-page
 *          synthetic audits, because a one-page test can never observe the saving a
 *          shared bundle exists to make, and that is the wrong thing to optimise for
 *          on a site people browse. Per-page remains available and is still the
 *          better choice when most visitors land on one page and leave. Only sites
 *          that have never saved the setting are affected; a saved choice is kept.
 *
 * 1.1.74 - Unused `@keyframes` blocks are now dropped by the unused-CSS purge.
 *          They were kept unconditionally, on the sound reasoning that an animation
 *          can be started from JavaScript or an inline style that no CSS scan sees -
 *          but kept unconditionally they survive a purge that has just removed every
 *          rule naming them. A name is now kept if a surviving declaration names it,
 *          if it appears anywhere in the document as a class, id or tag (so an icon
 *          font's `fa-beat` stays the moment a `.fa-beat` element exists, which is
 *          exactly how a script animates one), or if the safelist matches it. What
 *          goes is a name present in neither the stylesheet nor the document.
 *          Measured on a real page: 16 of 40 keyframes blocks dropped, 1.9 KB.
 *          New filter `fw:ext:asset-optimizer:prune_keyframes` turns it off.
 *
 * 1.1.73 - "Defer" now defers the scripts the combiner could not absorb, not only the
 *          combined bundle. The setting gave the opposite impression to the truth: the
 *          scripts most worth deferring are precisely the ones the combiner refuses,
 *          since it refuses anything carrying inline or localized data, so a page could
 *          end up with one deferred bundle among a dozen render-blocking leftovers.
 *          Measured on a real page: 8 of 11 scripts deferred before, 11 of 11 after.
 *          The marking is done with `wp_script_add_data( $handle, 'strategy', 'defer' )`
 *          rather than by writing the attribute into the tag, and that is the whole
 *          safety argument - WordPress then decides per handle whether deferring is
 *          valid, downgrading a script back to blocking unless everything depending on
 *          it is also delayed. Verified against a three-deep dependency chain: with an
 *          `after` inline script present WordPress held two of the three blocking and
 *          deferred the third, every script still ran in order, no errors. Scope is
 *          front-end footer scripts with a local path; core, admin and remote are left
 *          alone. New filter `fw:ext:asset-optimizer:js_no_defer_handles`.
 *
 * 1.1.72 - Image warming now starts sooner (1200 ms rather than 2500 ms) and warms
 *          several images at once (4 lanes) rather than strictly one at a time. The
 *          strictly-serial design predates marking each request `fetchPriority = 'low'`;
 *          low priority is the stronger guarantee, since the browser schedules these
 *          behind anything the reader is waiting for however many are in flight, so
 *          serialising on top of it only made warming finish later than the reader
 *          scrolls - which is the single way this feature can fail. On a real
 *          eleven-image page, median on-demand image requests caused by scrolling,
 *          three runs each: scrolling at 2 s, 11 before and 3 after; scrolling at 3 s,
 *          10 before and 0 after. Variant selection was verified unchanged and exact -
 *          11 of 11 warmed URLs matched the file the browser went on to use. Two new
 *          filters: fw:ext:asset-optimizer:warm_images_concurrency (4) and the existing
 *          warm_images_delay, now defaulting to 1200. Note the floor this leaves: a
 *          reader who scrolls within the first second is still ahead of any warming,
 *          because starting earlier than that would compete with the first screen.
 *
 * 1.1.71 - Image warming no longer waits only for the page's `load` event, which on a
 *          slow page meant it effectively never ran. Measured on a real site: `load`
 *          did not fire until 12.6 seconds, because a hero carousel kept pulling
 *          2048px slides - so warming began after most readers had already scrolled,
 *          and they saw exactly the empty-slot-then-pop the setting exists to remove.
 *          Warming now starts at `load` OR after a timeout, whichever comes first
 *          (fw:ext:asset-optimizer:warm_images_delay, default 2500 ms). Because that
 *          can now begin while the page is still loading, each warm request is marked
 *          `fetchPriority = 'low'`, so the browser schedules it behind anything the
 *          reader is actually waiting for - a stronger guarantee than the previous
 *          idle-callback gap between images, which is therefore gone, making warming
 *          finish sooner as well. Verified against a page whose `load` event was held
 *          open indefinitely: all images warmed by 3.9 s, a scroll at 6 s triggered
 *          zero network requests, and every image painted in 27 ms.
 *
 * 1.1.70 - Load below-the-fold images early (General tab, off by default). Lazy loading
 *          is the right default, but on a long page it has a visible cost: an image is
 *          not requested until the reader scrolls near it, so the picture drops into an
 *          empty slot a beat after the text, which reads as a slow site. The surprise is
 *          that this is almost never about file size. Measured on a real page, throttled
 *          and scrolled at reading pace: the first content image was not REQUESTED until
 *          13.9 seconds, and then took 175 ms to arrive; four content images, 9-36 KB
 *          each, all under 200 ms. Nobody was ever waiting on bytes - nothing had asked
 *          for them yet. This fetches what is left once the page has loaded, during an
 *          idle moment, so a later scroll finds them in cache. On a controlled page with
 *          four images placed out of lazy-loading range: without it, the scroll triggered
 *          4 requests and took 1,158 ms before all four were painted; with it, 0 requests
 *          and 20 ms. Deliberately cautious, because it spends bandwidth nobody asked for:
 *          it waits for load then an idle callback, skips Save-Data and 2G outright,
 *          fetches strictly ONE image at a time so it never competes with anything the
 *          reader is waiting for, and stops at 24 images
 *          (fw:ext:asset-optimizer:warm_images_max). It deliberately does NOT flip the
 *          images to loading="eager": WordPress emits sizes="auto", which is only valid
 *          alongside loading="lazy", so dropping the lazy would invalidate sizes and send
 *          the browser back to 100vw - fetching the LARGEST srcset candidate for what may
 *          be a 240px slot, strictly worse than doing nothing. Instead it leaves every
 *          attribute untouched and warms a separate request for the same candidate the
 *          browser would have picked. Note this is for readers, not for scores: Lighthouse
 *          never scrolls, so these images do not load during an audit either way.
 *
 * 1.1.69 - Instant navigation: four new General-tab settings that put the
 *          browser's Speculation Rules API to work, so a click lands on a page the
 *          browser already has. This is the one thing that makes a React site feel
 *          immediate on an internal link while a server-rendered site waits for a
 *          whole navigation, and it is available to any WordPress site without
 *          becoming one. WordPress core (6.8+) already builds the rules and decides
 *          when they apply - it switches the feature off for logged-in users and for
 *          sites without pretty permalinks, excludes wp-admin and every URL carrying
 *          a query string, and honours rel="nofollow" plus the .no-prefetch /
 *          .no-prerender opt-out classes - so none of that is reimplemented here.
 *          What is added is the part core leaves to plugins: "Instant navigation"
 *          chooses prefetch, prerender or off; "How early to start" sets the
 *          eagerness; and two exclusion controls keep speculation away from pages
 *          where it does harm. The second of those matters more than it looks: core
 *          excludes query-string URLs, which covers an add-to-cart or logout link,
 *          but a cart or checkout page on a normal permalink is not covered and
 *          WooCommerce does not exclude it either, so fetching one starts a shopping
 *          session for a visitor who never went there and prerendering one runs its
 *          scripts. Those pages are resolved from WooCommerce and Easy Digital
 *          Downloads directly rather than guessed from slugs, so a renamed or
 *          translated checkout is still covered. Both new selects default to "auto",
 *          which leaves core's behaviour exactly as it was, so an existing site sees
 *          no change until it opts in. New filter:
 *          fw:ext:asset-optimizer:speculation_sensitive_paths, for removing one of
 *          the built-in commerce exclusions (core's own filter can only add).
 *
 * 1.1.67 - WebP quality is now actually applied, and settable on the General tab
 *          ("WebP quality", default 80). It had never been applied to anything:
 *          make() called $editor->set_quality() before save(), but save()
 *          re-derives the quality whenever the output format differs from the
 *          source's - which it always does here, JPEG/PNG in and WebP out - so the
 *          value was discarded and WordPress's own WebP default of 86 used
 *          instead. Measured on a real 768x512 photo from a live site: 75,944
 *          bytes at every requested quality from 80 down to 60, the editor
 *          reporting 86 each time. That is why the WebP copies were only a few per
 *          cent smaller than the JPEGs they replaced instead of the quarter or
 *          more they should be - and on that site the copies still won the
 *          "is it smaller?" test, so nothing looked wrong. The quality is now set
 *          through the wp_editor_set_quality filter, scoped to our own save: the
 *          same photo is 59,398 bytes at a genuine 80 (-22%), 46,362 at 70 and
 *          41,260 at 60. Existing copies are redone rather than left stale -
 *          a twin older than the last quality change counts as out of date, so a
 *          site works through them a few per request under the existing throttle,
 *          which is what makes changing the setting mean anything. The
 *          fw:ext:asset-optimizer:webp_quality filter still wins over the setting.
 *
 * 1.1.66 - "Inline small leftover stylesheets" switch on the General tab (off by
 *          default). Combining cannot reach every stylesheet, and some it is
 *          deliberately forbidden to touch: the Animation Engine registers
 *          `css_exclude_handles` to keep its on-demand per-style partials out of
 *          the site-wide bundle - correctly, since that bundle is built from every
 *          handle ever discovered, so folding them in would ship every effect's
 *          CSS on every page, the exact bloat its on-demand loader prevents. It
 *          then folds the partials a page used into its own per-page file. Right
 *          in principle, and still a round trip per leftover. Measured on a real
 *          page: four such stylesheets of 1.0, 1.0, 0.8 and 1.1 KiB blocking first
 *          paint for an estimated 380ms. At that size the round trips are the
 *          cost, not the bytes - the opposite of the full-bundle case the
 *          css_delivery: inline size cap exists to refuse - so this moves them
 *          into the page where their link stood. Inlining per page does not undo
 *          what the exclusion protects: a page inlines only what that page linked.
 *          Runs last among the buffer passes on the finished HTML, so it does not
 *          care which hook enqueued a sheet or how late, which is what made these
 *          unreachable by the combiner to begin with. Refuses a file over the
 *          per-file cap (filter fw_ao_inline_small_css_max_bytes, 4096) or past
 *          the per-page budget (fw_ao_inline_small_css_budget, 20480), a
 *          media-scoped sheet (print or a query - not blocking paint, and the
 *          condition would be lost inline), a <link> inside <noscript> (which
 *          would otherwise take the critical-CSS fallback copy), and anything it
 *          cannot resolve to a readable local file. Relative url() paths are
 *          rewritten against the sheet's own URL. Verified: the two small sheets
 *          inlined, a print-scoped and an over-cap one left alone, url() made
 *          absolute, the critical-CSS deferred link and its noscript copy both
 *          untouched, and 0 differing computed styles across 276 elements.
 *
 * 1.1.63 - "Record which script each line came from" switch on the General tab
 *          (off by default). A browser that blames a slow function in a combined
 *          bundle reports a line number in a file nobody wrote, so a forced-reflow
 *          or long-task report against it is unactionable - there is no way to
 *          tell whose script it is. The combiner already wrote a
 *          `/* ==== handle ==== *` marker before each source, but minification
 *          ran over the whole concatenation and stripped the comments, so the
 *          markers vanished in exactly the case that needed them. With this on,
 *          each source is minified SEPARATELY (the minifier only removes comments
 *          and whitespace - it does not rename or reorder - so this is equivalent
 *          in effect; verified byte-identical ignoring line-break placement), the
 *          marker is added afterwards so it survives, and a
 *          `combined-<hash>.js.offsets.json` sidecar records handle, source path
 *          and inclusive 1-based line range per script. Verified against a real
 *          bundle: 8 sources, ranges contiguous, final last_line equal to the
 *          bundle's own line count, every first_line landing on its marker. Not a
 *          .map - the combiner concatenates whole files, so a line range is all
 *          there is to say, and most sources are already-minified vendor files
 *          with maps of their own. The map flag joins the bundle hash, since it
 *          changes where the line breaks fall.
 *
 * 1.1.62 - "Inline the first screen's CSS" switch on the General tab (off by
 *          default) - critical-CSS inlining. A linked stylesheet blocks the first
 *          paint; CSS delivery: Inline removes the block only by moving the whole
 *          sheet into every HTML response, and is refused outright past the size
 *          cap. Measured on a real site: the purged bundle was 338 KiB raw /
 *          91 KiB gzipped against a 50 KiB cap - nearly double, and inlining it
 *          would have added 91 KiB to every navigation to save one blocking
 *          request on the first. Per-page purging makes that worse, not better,
 *          because each template's sheet differs so nothing is reused between
 *          pages. This inlines only the subset the first screen needs and loads
 *          the remainder non-blocking (media="print" plus an onload swap, with a
 *          <noscript> copy; preload would need a polyfill to apply and can
 *          double-fetch). The subset comes from the same purger run against the
 *          tokens of the first N elements of <body> only (filter
 *          fw_ao_critical_css_elements, default 200) with an EMPTY safelist -
 *          hover/open/in-view rules are by definition not the first paint and are
 *          in the deferred sheet. Measured: 36 KiB inlined of an 87 KiB sheet,
 *          and 0 differing computed styles across 289 elements and 16 properties
 *          against the control, with the deferred link confirmed flipping to
 *          media="all" on load. Bails when the subset is under 1% (the scan
 *          learned nothing) or over 60% (no blocking request worth saving) of the
 *          sheet, which is why it correctly does nothing on a short page whose
 *          whole document fits inside the element window. Approximation, named as
 *          such: document order stands in for viewport position, so a layout
 *          whose hero sits late in the source can flash for a frame.
 *
 * 1.1.61 - "Drop admin-only stylesheets" switch on the General tab (off by
 *          default). is_backend_css_handle() already recognised dashicons, the
 *          admin bar, wp-pointer and the option-type panel styles, but it was
 *          only ever consulted while BUILDING the combined file - excluding a
 *          handle from the bundle leaves its own <link> in place, so the
 *          browser still fetched it as a separate render-blocking request.
 *          Measured on a real site: dashicons.min.css, 35.6KB, 100% unused,
 *          downloaded by every logged-out visitor for an icon font that only
 *          wp-admin draws. This dequeues them instead. Resolves the dependency
 *          closure first (all_deps), because dashicons normally arrives as a
 *          dependency of another handle rather than queued directly, then
 *          clears $styles->to_do so the print pass recomputes. Stands down
 *          while the admin toolbar is showing (it uses those glyphs), for the
 *          Live Editor, and on excluded URLs. Opt-in because a theme is free to
 *          use a dashicon on the public site.
 *
 * 1.1.60 - Filter fw:ext:asset-optimizer:protected_classes, so an element can
 *          register the class names it builds in JavaScript and have their CSS
 *          survive the purge. collect_tokens() scans the rendered HTML, so a
 *          class that only ever appears in an external .js file is never seen,
 *          every rule naming it looks unused, and it is removed - silently, the
 *          only symptom being CSS absent from the served file. Reported from a
 *          live site: the table of contents builds its items, links and numbers
 *          in JS, so .sc-toc__num and .sc-toc__link were purged, the links
 *          rendered as plain inline text and failed the 24px tap-target audit.
 *          Entries are plain class names, escaped and anchored on a word
 *          boundary, so `sc-toc` covers the whole family; the existing
 *          purge_safelist filter still takes full patterns. Registering from an
 *          element's static.php scopes the protection to pages that contain it.
 *          Also: safelist patterns are now compiled once and the unusable ones
 *          dropped and reported in wp-admin. selector_survives() matches with
 *          @preg_match(), which treats a malformed pattern as "no match", so a
 *          typo used to protect nothing at all with no indication why.
 *
 * 1.1.59 - "Move late CSS into the head" switch on the General tab (off by
 *          default). The page builder collects each flexbox instance's rules
 *          while the content renders and prints them as one consolidated
 *          <style> on wp_footer. Those rules include max-width and
 *          margin:auto, i.e. they decide where an element sits, so the browser
 *          paints the element in the wrong place and moves it when they land.
 *          Measured on a converted page: the hero painted left-aligned at x=24
 *          and jumped to its centred x=384 when the footer block arrived - a
 *          360px horizontal move worth 0.1468 CLS, the whole of that page's
 *          layout-shift score. Hoisting the blocks into the end of <head> took
 *          it to 0.0000 with zero computed-style differences across 249
 *          elements: the page renders identically, it just stops moving.
 *          Cascade-safe because the blocks keep their relative order and land
 *          after every stylesheet already in the head. Skips the Live Editor
 *          and admin; filter fw_ao_hoist_styles_skip exempts an id.
 *          NOTE: Lighthouse attributes this shift to "Web font". It is not the
 *          font - blocking every font outright still measured 0.1451.
 *
 * 1.1.58 - "Generate missing image sizes" switch on the General tab (off by
 *          default). A srcset can only offer the sizes that exist on disk, and
 *          an attachment imported with a trimmed size set offers a 300w file
 *          and the full-size original and nothing between, so every visitor
 *          takes the original however small the slot. Measured: a 1024x434 slot
 *          pulled the 1440w file at 91.9 KiB where the 1024w file is 67.7 KiB,
 *          26% less for identical rendered pixels. Missing sizes are now filled
 *          in during page rendering, one attachment per request (filter
 *          fw_ao_subsizes_per_request), measured at ~154ms for one 1440x611
 *          original. Hooked on wp_calculate_image_srcset_meta, which runs
 *          BEFORE the candidate list is built, so the srcset is corrected on
 *          the same request that triggers the work rather than the next one.
 *          An attachment whose sizes cannot be produced is marked and skipped:
 *          wp_update_image_subsizes() returns an ordinary array rather than a
 *          WP_Error for a corrupt original, so success is judged by whether the
 *          missing-size count actually fell - without that, one unfixable image
 *          would consume the per-request budget on every view and block every
 *          other image on the page.
 *
 * 1.1.55 - "Browser cache for static files" switch on the General tab (off by
 *          default). The extension already gave its own generated files a
 *          one-year lifetime; measured on a live site, everything else was
 *          still on the host default - uploads and theme fonts at 7 days, and
 *          plugin static JS with no Cache-Control header at all. Switching this
 *          on writes a marked block into wp-content/.htaccess covering uploads,
 *          plugins and themes. Deliberately NOT marked immutable, unlike the
 *          extension's own cache directory: those filenames are content hashes,
 *          these are not, and immutable would stop a hard refresh picking up a
 *          file replaced in place. Written via insert_with_markers() so other
 *          rules in the file survive; switching it off strips the block and
 *          markers entirely, deleting the file if nothing else was in it.
 *          Filters: fw_ao_static_cache_extensions, fw_ao_static_cache_max_age.
 *          Apache / LiteSpeed only; the settings screen shows the nginx form.
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

$manifest['version']    = '1.1.76';
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
