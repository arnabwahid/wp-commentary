<?php
/**
 * Plugin Name:  Commentary
 * Description:  Commentary-style link blogging without a CPT: ensures terms, auto-tags qualifying posts, adds a Commentary admin panel, a [commentary_archive] shortcode, a virtual archive at /commentary/, a Gutenberg sidebar (with classic metabox fallback), title glyphs on listings (∞ → internal permalink), and optional single-view redirects to the external URL.
 * Version:      4.4.0
 * Requires PHP: 8.0
 * Requires at least: 6.3
 * Tested up to: 6.7
 * License:      MIT
 * Text Domain:  commentary
 */

declare(strict_types=1);

namespace Commentary\Admin;

use WP_Post;
use WP_Query;

if (!defined('ABSPATH')) { exit; }

/**
 * WHAT COUNTS AS A "COMMENTARY POST"?
 * -----------------------------------
 * A post is considered “Commentary” if it has:
 *  - Tag 'commentary' OR Tag 'linklog'
 *  - (Also accepts legacy Category 'linked' for convenience/migration.)
 *
 * This plugin:
 *  - Ensures Category 'linked' + Tags 'commentary' and 'linklog' exist.
 *  - Auto-assigns Tags 'commentary' and 'linklog' to posts that either:
 *      a) have Category 'linked', OR
 *      b) have a non-empty Commentary URL (meta: commentary_url).
 *
 * LISTING BEHAVIOR
 * ----------------
 * - On listing contexts (home/archive/search, not single), the POST TITLE links to the EXTERNAL URL
 *   unless per-post “Use post permalink in lists” is checked.
 * - A plaintext glyph “∞” appears to the RIGHT of the title and links to the INTERNAL permalink.
 * - On single views, no glyph is shown; optional redirect to the external URL can be enabled in
 *   Commentary → Settings (respects per-post “Don’t auto-redirect” and `?stay=1`).
 */

/* ========================================================================== *
 * CONSTANTS
 * ========================================================================== */

const GLYPH_DEFAULT          = "∞\u{FE0E}"; // plaintext infinity + U+FE0E (text presentation)
const TAG_COMMENTARY_SLUG    = 'commentary';
const TAG_LINKLOG_SLUG       = 'linklog';
const CAT_LINKED_SLUG        = 'linked';

const META_URL               = 'commentary_url';
const META_SKIP_REDIRECT     = 'commentary_skip_redirect'; // per-post: don't redirect single
const META_SKIP_REWRITE      = 'commentary_skip_rewrite';  // per-post: use permalink in lists

const OPT_GROUP              = 'commentary';
const OPT_SINGLE_REDIRECT    = 'commentary_single_redirect'; // bool global

/* ========================================================================== *
 * 0) ACTIVATION / DEACTIVATION / INIT
 * ========================================================================== */

register_activation_hook(__FILE__, __NAMESPACE__ . '\\on_activate');
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\on_deactivate');

function on_activate(): void {
	ensure_terms();
	// default settings
	if (get_option(OPT_SINGLE_REDIRECT, null) === null) {
		add_option(OPT_SINGLE_REDIRECT, 0);
	}
	register_virtual_archive_rewrite();
	flush_rewrite_rules();
}

function on_deactivate(): void {
	flush_rewrite_rules();
}

add_action('after_switch_theme', __NAMESPACE__ . '\\ensure_terms');

// Init hooks
add_action('init', __NAMESPACE__ . '\\register_virtual_archive_rewrite');
add_filter('query_vars', __NAMESPACE__ . '\\register_virtual_archive_qv');
add_action('template_redirect', __NAMESPACE__ . '\\maybe_render_virtual');
add_action('init', __NAMESPACE__ . '\\register_post_meta_fields');

/* ========================================================================== *
 * 1) TERMS: Ensure Category/Tags
 * ========================================================================== */

function ensure_terms(): void {
	// Category: linked
	if (!get_term_by('slug', CAT_LINKED_SLUG, 'category')) {
		wp_insert_term('Linked', 'category', ['slug' => CAT_LINKED_SLUG]);
	}
	// Tags: commentary, linklog
	foreach ([TAG_COMMENTARY_SLUG => 'Commentary', TAG_LINKLOG_SLUG => 'Linklog'] as $slug => $name) {
		if (!get_term_by('slug', $slug, 'post_tag')) {
			wp_insert_term($name, 'post_tag', ['slug' => $slug]);
		}
	}
}

/** Is a given post (or current global) a commentary post? */
function is_commentary_post(null|int|WP_Post $post = null): bool {
	$p = $post ? get_post($post) : get_post();
	if (!$p instanceof WP_Post || $p->post_type !== 'post') return false;
	return has_term(TAG_COMMENTARY_SLUG, 'post_tag', $p)
		|| has_term(TAG_LINKLOG_SLUG, 'post_tag', $p)
		|| has_term(CAT_LINKED_SLUG, 'category', $p);
}

/* ========================================================================== *
 * 2) ADMIN POSTS LIST: “Linked” view (legacy convenience)
 * ========================================================================== */

add_filter('views_edit-post', function(array $views): array {
	$base_url = add_query_arg(['post_type' => 'post'], admin_url('edit.php'));
	$url      = add_query_arg('linked', '1', $base_url);
	$count    = linked_admin_count();

	$views['linked'] = sprintf(
		'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
		esc_url($url),
		(isset($_GET['linked']) && $_GET['linked'] === '1') ? ' class="current"' : '',
		esc_html__('Linked', 'commentary'),
		(int) $count
	);

	return $views;
});

add_action('pre_get_posts', function(WP_Query $q) {
	if (!is_admin() || !$q->is_main_query()) return;
	if (!isset($_GET['linked']) || $_GET['linked'] !== '1') return;

	$q->set('post_type', 'post');
	$q->set('tax_query', [
		'relation' => 'OR',
		[
			'taxonomy' => 'post_tag',
			'field'    => 'slug',
			'terms'    => [TAG_COMMENTARY_SLUG, TAG_LINKLOG_SLUG],
		],
		[
			'taxonomy' => 'category',
			'field'    => 'slug',
			'terms'    => [CAT_LINKED_SLUG],
		],
	]);
});

function linked_admin_count(): int {
	global $wpdb;

	$tag_commentary = get_term_by('slug', TAG_COMMENTARY_SLUG, 'post_tag');
	$tag_linklog    = get_term_by('slug', TAG_LINKLOG_SLUG, 'post_tag');
	$cat_linked     = get_term_by('slug', CAT_LINKED_SLUG, 'category');

	$clauses = [];

	if ($tag_commentary) {
		$clauses[] = $wpdb->prepare("(tt.taxonomy='post_tag' AND tt.term_id=%d)", $tag_commentary->term_id);
	}
	if ($tag_linklog) {
		$clauses[] = $wpdb->prepare("(tt.taxonomy='post_tag' AND tt.term_id=%d)", $tag_linklog->term_id);
	}
	if ($cat_linked) {
		$clauses[] = $wpdb->prepare("(tt.taxonomy='category' AND tt.term_id=%d)", $cat_linked->term_id);
	}

	if (!$clauses) return 0;

	$sql = "
		SELECT COUNT(DISTINCT p.ID)
		FROM {$wpdb->posts} p
		INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
		INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
		WHERE p.post_type='post'
		  AND p.post_status NOT IN ('trash','auto-draft')
		  AND (" . implode(' OR ', $clauses) . ")
	";
	return (int) $wpdb->get_var($sql);
}

/* ========================================================================== *
 * 3) SHORTCODE: [commentary_archive] — ONLY commentary posts
 * ========================================================================== */

add_shortcode('commentary_archive', function($atts = []): string {
	$atts = shortcode_atts([
		'posts_per_page' => get_option('posts_per_page'),
		'paged'          => max(1, (int) get_query_var('paged')),
	], $atts, 'commentary_archive');

	$q = new WP_Query([
		'post_type'           => 'post',
		'posts_per_page'      => (int) $atts['posts_per_page'],
		'paged'               => (int) $atts['paged'],
		'ignore_sticky_posts' => true,
		'tax_query'           => [
			'relation' => 'OR',
			[
				'taxonomy' => 'post_tag',
				'field'    => 'slug',
				'terms'    => [TAG_COMMENTARY_SLUG, TAG_LINKLOG_SLUG],
			],
			[
				'taxonomy' => 'category',
				'field'    => 'slug',
				'terms'    => [CAT_LINKED_SLUG], // accept legacy
			],
		],
	]);

	ob_start();

	if ($q->have_posts()) {
		echo '<div class="commentary-archive">';
		while ($q->have_posts()) {
			$q->the_post();
			echo '<article class="commentary-item">';
			echo '  <h2 class="entry-title"><a href="' . esc_url(get_permalink()) . '">' . esc_html(get_the_title()) . '</a></h2>';
			echo '  <div class="entry-meta">' . esc_html(get_the_date()) . '</div>';
			echo '  <div class="entry-excerpt">' . wp_kses_post(get_the_excerpt()) . '</div>';
			echo '</article>';
		}
		echo '</div>';

		$links = paginate_links([
			'total'   => (int) $q->max_num_pages,
			'current' => (int) $atts['paged'],
			'type'    => 'list',
		]);
		if ($links) echo wp_kses_post($links);
	} else {
		echo '<p>' . esc_html__('No commentary posts found.', 'commentary') . '</p>';
	}

	wp_reset_postdata();
	return (string) ob_get_clean();
});

/* ========================================================================== *
 * 4) VIRTUAL ARCHIVE: /commentary/ — ONLY commentary posts
 * ========================================================================== */

function register_virtual_archive_rewrite(): void {
	add_rewrite_rule('^commentary/?$', 'index.php?commentary_virtual=1', 'top');
}

function register_virtual_archive_qv(array $vars): array {
	$vars[] = 'commentary_virtual';
	return $vars;
}

function maybe_render_virtual(): void {
	if ((int) get_query_var('commentary_virtual') !== 1) return;

	status_header(200);
	add_filter('pre_get_document_title', fn() => __('Commentary', 'commentary'));
	add_filter('body_class', function(array $classes): array {
		$classes[] = 'commentary-virtual-archive';
		return $classes;
	});

	get_header();

	echo '<main id="primary" class="site-main">';
	echo '  <header class="page-header"><h1 class="page-title">' . esc_html__('Commentary', 'commentary') . '</h1></header>';
	echo do_shortcode('[commentary_archive]');
	echo '</main>';

	get_footer();
	exit;
}

/* ========================================================================== *
 * 5) META: Register post meta (Gutenberg + REST)
 * ========================================================================== */

function register_post_meta_fields(): void {
	register_post_meta('post', META_URL, [
		'type'              => 'string',
		'single'            => true,
		'show_in_rest'      => true,
		'auth_callback'     => fn(): bool => current_user_can('edit_posts'),
		'sanitize_callback' => fn($v): string => esc_url_raw((string) $v),
		'default'           => '',
		'description'       => __('External URL for this Commentary post', 'commentary'),
	]);
	foreach ([META_SKIP_REDIRECT, META_SKIP_REWRITE] as $bool_meta) {
		register_post_meta('post', $bool_meta, [
			'type'              => 'boolean',
			'single'            => true,
			'show_in_rest'      => true,
			'auth_callback'     => fn(): bool => current_user_can('edit_posts'),
			'sanitize_callback' => fn($v): bool => (bool) $v,
			'default'           => false,
		]);
	}
}

/* ========================================================================== *
 * 6) EDITOR UI: Gutenberg sidebar + Classic metabox fallback
 * ========================================================================== */

add_action('enqueue_block_editor_assets', __NAMESPACE__ . '\\enqueue_block_editor_assets');
function enqueue_block_editor_assets(): void {
	if (!function_exists('use_block_editor_for_post_type') || !use_block_editor_for_post_type('post')) return;

	$screen = function_exists('get_current_screen') ? get_current_screen() : null;
	if (!$screen || $screen->base !== 'post' || $screen->post_type !== 'post') return;

	$linked_cat = get_term_by('slug', CAT_LINKED_SLUG, 'category');
	$linked_cat_id = $linked_cat ? (int) $linked_cat->term_id : 0;

	$inline = '
	( function( wp ) {
		const { registerPlugin } = wp.plugins;
		const { PluginDocumentSettingPanel } = wp.editPost || {};
		if ( ! registerPlugin || ! PluginDocumentSettingPanel ) return;

		const { TextControl, ToggleControl, Tooltip } = wp.components;
		const { __ } = wp.i18n;
		const { useSelect, useDispatch } = wp.data;
		const { createElement: el } = wp.element;

		const LINKED_CAT_ID = ' . (int) $linked_cat_id . ';

		const useIsCommentary = () => {
			const cats = useSelect( s => s("core/editor").getEditedPostAttribute("categories") || [], [] );
			return Array.isArray(cats) && cats.includes(LINKED_CAT_ID);
		};

		const Panel = () => {
			const postType = useSelect( s => s("core/editor").getCurrentPostType(), [] );
			if ( postType !== "post" ) return null;

			const isComm = useIsCommentary();
			if ( !isComm ) return null;

			const meta = useSelect( s => s("core/editor").getEditedPostAttribute("meta") || {}, [] );
			const { editPost } = useDispatch("core/editor");
			const setMeta = (key, value) => editPost({ meta: { ...meta, [key]: value } });

			return el( PluginDocumentSettingPanel,
				{ name: "commentary-panel", title: __("Linked Post", "commentary"), initialOpen: true },

				el( TextControl, {
					label: __("Link URL", "commentary"),
					value: meta.' . META_URL . ' || "",
					onChange: (v) => setMeta("' . META_URL . '", v),
					type: "url",
					placeholder: "https://example.com/article"
				}),

				el( Tooltip, { text: __("Show post content instead of redirecting", "commentary") },
					el( "div", {},
						el( ToggleControl, {
							label: __("Don’t auto-redirect this post", "commentary"),
							checked: !!meta.' . META_SKIP_REDIRECT . ',
							onChange: (v) => setMeta("' . META_SKIP_REDIRECT . '", !!v)
						})
					)
				),

				el( Tooltip, { text: __("Link titles to post page (not external site)", "commentary") },
					el( "div", {},
						el( ToggleControl, {
							label: __("Use post permalink in lists", "commentary"),
							checked: !!meta.' . META_SKIP_REWRITE . ',
							onChange: (v) => setMeta("' . META_SKIP_REWRITE . '", !!v)
						})
					)
				)
			);
		};

		registerPlugin( "commentary-settings-panel", { render: Panel } );
	} )( window.wp );
	';
	wp_add_inline_script('wp-edit-post', $inline, 'after');
}

add_action('add_meta_boxes', __NAMESPACE__ . '\\maybe_add_classic_metabox');
function maybe_add_classic_metabox(): void {
	if (function_exists('use_block_editor_for_post_type') && use_block_editor_for_post_type('post')) return;

	add_meta_box(
		'commentary_url_box',
		__('Linked Post', 'commentary'),
		__NAMESPACE__ . '\\render_classic_metabox',
		'post',
		'side',
		'high'
	);
}

function render_classic_metabox(WP_Post $post): void {
	wp_nonce_field('commentary_meta_save', 'commentary_meta_nonce');
	$url           = (string) get_post_meta($post->ID, META_URL, true);
	$skip_redirect = (bool) get_post_meta($post->ID, META_SKIP_REDIRECT, true);
	$skip_rewrite  = (bool) get_post_meta($post->ID, META_SKIP_REWRITE, true);
	?>
	<p>
		<label for="commentary_url_field" class="screen-reader-text"><?php echo esc_html__('External URL', 'commentary'); ?></label>
		<input type="url" id="commentary_url_field" name="<?php echo esc_attr(META_URL); ?>" value="<?php echo esc_attr($url); ?>" class="widefat" placeholder="https://example.com/article" inputmode="url" />
	</p>
	<p>
		<label title="<?php echo esc_attr__('Show post content instead of redirecting', 'commentary'); ?>">
			<input type="checkbox" name="<?php echo esc_attr(META_SKIP_REDIRECT); ?>" value="1" <?php checked(true, $skip_redirect); ?> />
			<?php echo esc_html__('Don’t auto-redirect this post', 'commentary'); ?>
		</label>
	</p>
	<p>
		<label title="<?php echo esc_attr__('Link titles to post page (not external site)', 'commentary'); ?>">
			<input type="checkbox" name="<?php echo esc_attr(META_SKIP_REWRITE); ?>" value="1" <?php checked(true, $skip_rewrite); ?> />
			<?php echo esc_html__('Use post permalink in lists', 'commentary'); ?>
		</label>
	</p>
	<?php
}

add_action('save_post_post', __NAMESPACE__ . '\\save_classic_metabox', 10, 2);
function save_classic_metabox(int $post_id, WP_Post $post): void {
	if (!isset($_POST['commentary_meta_nonce']) || !wp_verify_nonce((string) $_POST['commentary_meta_nonce'], 'commentary_meta_save')) return;
	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
	if (!current_user_can('edit_post', $post_id)) return;

	update_post_meta($post_id, META_URL, esc_url_raw((string) ($_POST[META_URL] ?? '')));
	update_post_meta($post_id, META_SKIP_REDIRECT, !empty($_POST[META_SKIP_REDIRECT]) ? 1 : 0);
	update_post_meta($post_id, META_SKIP_REWRITE, !empty($_POST[META_SKIP_REWRITE]) ? 1 : 0);
}

/* ========================================================================== *
 * 7) LISTINGS: Title glyph + title rewrite (external link) for block & classic
 * ========================================================================== */

/** Get a safe external URL if present and allowed by per-post toggle. */
function get_commentary_external_url(WP_Post $post): string {
	$url = trim((string) get_post_meta($post->ID, META_URL, true));
	if ($url === '') return '';
	if ((bool) get_post_meta($post->ID, META_SKIP_REWRITE, true)) return ''; // per-post: keep internal titles
	$parsed = wp_parse_url($url);
	if (!is_array($parsed) || !isset($parsed['scheme']) || !in_array(strtolower((string) $parsed['scheme']), ['http','https'], true)) {
		return '';
	}
	return esc_url($url);
}

/**
 * BLOCK THEMES:
 * - Rewrite <a href> inside core/post-title to external URL on listings.
 * - Append glyph anchor (∞) linking to internal permalink to the RIGHT of the title.
 * - All other links remain internal (we only touch the post-title block).
 */
add_filter('render_block', __NAMESPACE__ . '\\filter_post_title_block', 20, 2);
function filter_post_title_block(string $html, array $block): string {
	if (($block['blockName'] ?? '') !== 'core/post-title') return $html;
	if (is_admin() || is_feed() || is_single()) return $html;

	$post = get_post();
	if (!$post instanceof WP_Post || $post->post_type !== 'post' || !is_commentary_post($post)) return $html;

	$external = get_commentary_external_url($post);
	$perma    = get_permalink($post);

	// 1) If we have an external URL, replace the first href="...".
	if ($external !== '') {
		$html = preg_replace(
			'~(<a\b[^>]*\bhref=)["\']([^"\']+)["\']~i',
			'$1"' . esc_url($external) . '"',
			$html,
			1
		) ?: $html;
	}

	// 2) Inject glyph (∞) as a separate anchor to the RIGHT of the title (internal permalink).
	if (!str_contains($html, 'commentary-permalink-glyph')) {
		$glyph = apply_filters('commentary_glyph_text', GLYPH_DEFAULT, $post);
		$glyph = is_string($glyph) && $glyph !== '' ? $glyph : GLYPH_DEFAULT;

		$glyph_html = sprintf(
			'<span class="commentary-permalink-glyph"><a href="%s" rel="bookmark">%s</a></span>',
			esc_url($perma),
			esc_html($glyph)
		);
		$modified = preg_replace('~(</h[1-6]>)\s*$~i', ' ' . $glyph_html . '$1', $html, 1);
		$html = $modified ?: $html;
	}

	return $html;
}

/**
 * CLASSIC THEMES:
 * - We DO NOT rewrite permalinks globally (so all non-title links remain internal).
 * - Instead, we:
 *   a) add a span placeholder after the title text with data-permalink (internal) and data-external (if any),
 *   b) run a tiny JS that:
 *      - finds the nearest title anchor and, if data-external present, sets that anchor's href to the external URL,
 *      - moves the glyph out of the anchor and appends a separate permalink anchor next to the title.
 */
add_filter('the_title', __NAMESPACE__ . '\\append_classic_title_glyph_placeholder', 20, 2);
function append_classic_title_glyph_placeholder(string $title, int $post_id): string {
	if (is_admin() || is_feed() || is_single()) return $title;

	$post = get_post($post_id);
	if (!$post instanceof WP_Post || $post->post_type !== 'post' || !is_commentary_post($post)) return $title;

	$glyph   = apply_filters('commentary_glyph_text', GLYPH_DEFAULT, $post);
	$glyph   = is_string($glyph) && $glyph !== '' ? $glyph : GLYPH_DEFAULT;
	$perma   = get_permalink($post);
	$external = get_commentary_external_url($post); // may be empty

	if (str_contains($title, 'commentary-permalink-glyph')) return $title;

	$span = sprintf(
		' <span class="commentary-permalink-glyph" data-permalink="%s"%s>%s</span>',
		esc_attr($perma),
		$external !== '' ? ' data-external="' . esc_attr($external) . '"' : '',
		esc_html($glyph)
	);

	return rtrim($title) . $span;
}

/** Inline script to tweak classic theme titles only; does not touch other links. */
add_action('wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_classic_fix_script');
function enqueue_classic_fix_script(): void {
	if (is_admin() || is_feed() || is_singular()) return;
	if (!is_home() && !is_archive() && !is_search() && !is_category() && !is_tag()) return;

	$js = <<<JS
document.addEventListener('DOMContentLoaded',function(){
  var spans = document.querySelectorAll('h1 .commentary-permalink-glyph, h2 .commentary-permalink-glyph, h3 .commentary-permalink-glyph, h4 .commentary-permalink-glyph, h5 .commentary-permalink-glyph, h6 .commentary-permalink-glyph');
  spans.forEach(function(span){
    var perma = span.getAttribute('data-permalink') || '';
    var external = span.getAttribute('data-external') || '';
    var parentLink = span.closest('a');
    var heading = span.closest('h1,h2,h3,h4,h5,h6');

    if (!heading) return;

    // If we are inside the title <a>, move span out and set title href to external (if provided).
    if (parentLink && heading.contains(parentLink)) {
      // Remove the span from inside the title link.
      parentLink.removeChild(span);

      // Rewrite title link to external (only if we have an external URL).
      if (external) { parentLink.setAttribute('href', external); }

      // Create a new glyph link to the internal permalink and insert after the title link.
      if (perma) {
        var a = document.createElement('a');
        a.href = perma;
        a.className = 'commentary-permalink-glyph-link';
        a.setAttribute('rel','bookmark');
        a.appendChild(document.createTextNode('' + (span.textContent || '∞')));
        parentLink.insertAdjacentText('afterend',' ');
        parentLink.insertAdjacentElement('afterend', a);
      }
      return;
    }

    // Fallback: if not inside a title <a>, just convert span into an <a> permalink right after the heading text.
    if (perma) {
      var a2 = document.createElement('a');
      a2.href = perma;
      a2.className = 'commentary-permalink-glyph-link';
      a2.setAttribute('rel','bookmark');
      a2.appendChild(document.createTextNode('' + (span.textContent || '∞')));
      span.replaceWith(a2);
    }
  });
});
JS;
	wp_register_script('commentary-classic-glyph', false, [], null, true);
	wp_enqueue_script('commentary-classic-glyph');
	wp_add_inline_script('commentary-classic-glyph', $js, 'after');
}

/* ========================================================================== *
 * 8) AUTO-TAGGING: Assign 'commentary' and 'linklog' to qualifying posts
 * ========================================================================== */

add_action('save_post_post', __NAMESPACE__ . '\\ensure_commentary_tags_on_save', 20, 3);
function ensure_commentary_tags_on_save(int $post_id, WP_Post $post, bool $update): void {
	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
	if (!current_user_can('edit_post', $post_id)) return;

	$has_linked_cat = has_term(CAT_LINKED_SLUG, 'category', $post);
	$url            = (string) get_post_meta($post_id, META_URL, true);

	if (!$has_linked_cat && $url === '') return; // not qualifying

	ensure_terms();

	$current_terms = wp_get_post_terms($post_id, 'post_tag', ['fields' => 'slugs']);
	if (!is_array($current_terms)) $current_terms = [];

	$desired = array_unique(array_merge($current_terms, [TAG_COMMENTARY_SLUG, TAG_LINKLOG_SLUG]));
	wp_set_post_terms($post_id, $desired, 'post_tag', false);
}

/* ========================================================================== *
 * 9) DEDICATED ADMIN PANEL
 * ========================================================================== */

add_action('admin_menu', __NAMESPACE__ . '\\register_commentary_panel');
function register_commentary_panel(): void {
	add_menu_page(
		__('Commentary', 'commentary'),
		__('Commentary', 'commentary'),
		'edit_posts',
		'commentary-panel',
		__NAMESPACE__ . '\\render_commentary_panel',
		'dashicons-admin-site-alt3',
		25
	);

	add_submenu_page(
		'commentary-panel',
		__('All Commentary Posts', 'commentary'),
		__('All Commentary Posts', 'commentary'),
		'edit_posts',
		'commentary-panel',
		__NAMESPACE__ . '\\render_commentary_panel'
	);

	add_submenu_page(
		'commentary-panel',
		__('Add New', 'commentary'),
		__('Add New', 'commentary'),
		'edit_posts',
		'commentary-add-new',
		__NAMESPACE__ . '\\add_new_placeholder'
	);

	// Settings page
	add_submenu_page(
		'commentary-panel',
		__('Settings', 'commentary'),
		__('Settings', 'commentary'),
		'manage_options',
		'commentary-settings',
		__NAMESPACE__ . '\\render_settings_page'
	);
}

add_action('admin_init', __NAMESPACE__ . '\\handle_add_new_redirect_early', 1);
function handle_add_new_redirect_early(): void {
	if (!is_admin() || !current_user_can('edit_posts')) return;
	if (($_GET['page'] ?? '') !== 'commentary-add-new') return;

	ensure_terms();
	$cat  = get_term_by('slug', CAT_LINKED_SLUG, 'category');
	$tag1 = get_term_by('slug', TAG_COMMENTARY_SLUG, 'post_tag');
	$tag2 = get_term_by('slug', TAG_LINKLOG_SLUG, 'post_tag');

	$url = admin_url('post-new.php?post_type=post');
	if ($cat)  { $url = add_query_arg(['tax_input[category][]' => (int) $cat->term_id], $url); }
	if ($tag1) { $url = add_query_arg(['tax_input[post_tag][]' => (int) $tag1->term_id], $url); }
	if ($tag2) { $url = add_query_arg(['tax_input[post_tag][]' => (int) $tag2->term_id], $url); }

	wp_safe_redirect($url);
	exit;
}

function add_new_placeholder(): void {
	echo '<div class="wrap"><h1>' . esc_html__('Redirecting…', 'commentary') . '</h1></div>';
}

function render_commentary_panel(): void {
	if (!current_user_can('edit_posts')) return;

	$paged  = max(1, (int) ($_GET['paged'] ?? 1));
	$search = isset($_GET['s']) ? sanitize_text_field((string) $_GET['s']) : '';

	$args = [
		'post_type'      => 'post',
		'posts_per_page' => 20,
		'paged'          => $paged,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'tax_query'      => [
			'relation' => 'OR',
			[
				'taxonomy' => 'post_tag',
				'field'    => 'slug',
				'terms'    => [TAG_COMMENTARY_SLUG, TAG_LINKLOG_SLUG],
			],
			[
				'taxonomy' => 'category',
				'field'    => 'slug',
				'terms'    => [CAT_LINKED_SLUG],
			],
		],
	];
	if ($search !== '') $args['s'] = $search;

	$q = new WP_Query($args);
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline"><?php echo esc_html__('Commentary', 'commentary'); ?></h1>
		<a class="page-title-action" href="<?php echo esc_url(admin_url('admin.php?page=commentary-add-new')); ?>">
			<?php echo esc_html__('Add New', 'commentary'); ?>
		</a>

		<form method="get" style="margin-top:12px;">
			<input type="hidden" name="page" value="commentary-panel" />
			<p class="search-box">
				<label class="screen-reader-text" for="commentary-search-input"><?php esc_html_e('Search Commentary posts:', 'commentary'); ?></label>
				<input type="search" id="commentary-search-input" name="s" value="<?php echo esc_attr($search); ?>" />
				<input type="submit" id="search-submit" class="button" value="<?php esc_attr_e('Search', 'commentary'); ?>" />
			</p>
		</form>

		<table class="wp-list-table widefat fixed striped table-view-list posts">
			<thead>
				<tr>
					<th><?php esc_html_e('Title', 'commentary'); ?></th>
					<th><?php esc_html_e('Date', 'commentary'); ?></th>
					<th><?php esc_html_e('Author', 'commentary'); ?></th>
					<th><?php esc_html_e('Link URL', 'commentary'); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ($q->have_posts()) : while ($q->have_posts()) : $q->the_post();
				$post = get_post();
				$perma = get_edit_post_link($post->ID);
				$url   = (string) get_post_meta($post->ID, META_URL, true);
			?>
				<tr>
					<td>
						<strong><a href="<?php echo esc_url($perma); ?>"><?php echo esc_html(get_the_title($post)); ?></a></strong>
						<div class="row-actions">
							<span class="edit"><a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>"><?php esc_html_e('Edit', 'commentary'); ?></a> | </span>
							<span class="view"><a href="<?php echo esc_url(get_permalink($post)); ?>" target="_blank"><?php esc_html_e('View', 'commentary'); ?></a></span>
						</div>
					</td>
					<td><?php echo esc_html(get_the_time(get_option('date_format'))); ?></td>
					<td><?php echo esc_html(get_the_author()); ?></td>
					<td><?php echo $url ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($url) . '</a>' : '&mdash;'; ?></td>
				</tr>
			<?php endwhile; else: ?>
				<tr><td colspan="4"><?php esc_html_e('No commentary posts found.', 'commentary'); ?></td></tr>
			<?php endif; wp_reset_postdata(); ?>
			</tbody>
		</table>

		<?php
		$total = (int) $q->max_num_pages;
		if ($total > 1) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo paginate_links([
				'base'      => add_query_arg('paged', '%#%'),
				'format'    => '',
				'current'   => $paged,
				'total'     => $total,
				'prev_text' => '«',
				'next_text' => '»',
			]);
			echo '</div></div>';
		}
		?>
	</div>
	<?php
}

/* ========================================================================== *
 * 10) SETTINGS: Optional single-view redirect
 * ========================================================================== */

add_action('admin_init', __NAMESPACE__ . '\\register_settings');
function register_settings(): void {
	register_setting(OPT_GROUP, OPT_SINGLE_REDIRECT, [
		'type'              => 'boolean',
		'sanitize_callback' => fn($v): bool => (bool) $v,
		'default'           => 0,
	]);

	add_settings_section('commentary_main', __('Commentary Settings', 'commentary'), function() {
		echo '<p>' . esc_html__('Global settings for Commentary behavior.', 'commentary') . '</p>';
	}, 'commentary_settings');

	add_settings_field(
		OPT_SINGLE_REDIRECT,
		__('Redirect single commentary posts', 'commentary'),
		function() {
			$val = (bool) get_option(OPT_SINGLE_REDIRECT, 0);
			echo '<label><input type="checkbox" name="' . esc_attr(OPT_SINGLE_REDIRECT) . '" value="1" ' . checked(true, $val, false) . ' />';
			echo ' ' . esc_html__('On single post view, redirect to the external Link URL (append ?stay=1 to bypass).', 'commentary') . '</label>';
		},
		'commentary_settings',
		'commentary_main'
	);
}

function render_settings_page(): void {
	if (!current_user_can('manage_options')) return; ?>
	<div class="wrap">
		<h1><?php esc_html_e('Commentary Settings', 'commentary'); ?></h1>
		<form method="post" action="options.php">
			<?php
			settings_fields(OPT_GROUP);
			do_settings_sections('commentary_settings');
			submit_button();
			?>
		</form>
	</div>
<?php }

/* Redirect logic: runs only on front-end single post view if enabled. */
add_action('template_redirect', __NAMESPACE__ . '\\maybe_redirect_single', 1);
function maybe_redirect_single(): void {
	if (is_admin() || is_feed() || !is_singular('post')) return;
	if (!get_option(OPT_SINGLE_REDIRECT, 0)) return;                 // global off
	if (isset($_GET['stay']) && (string) $_GET['stay'] === '1') return; // bypass

	$post = get_post();
	if (!$post instanceof WP_Post || !is_commentary_post($post)) return;

	// Per-post opt-out:
	if ((bool) get_post_meta($post->ID, META_SKIP_REDIRECT, true)) return;

	$url = trim((string) get_post_meta($post->ID, META_URL, true));
	if ($url === '') return;

	$parsed = wp_parse_url($url);
	if (!is_array($parsed) || !isset($parsed['scheme']) || !in_array(strtolower((string) $parsed['scheme']), ['http','https'], true)) {
		return;
	}

	wp_safe_redirect(esc_url_raw($url), 302);
	exit;
}

/* ========================================================================== *
 * 11) GLYPH HELPERS
 * ========================================================================== */

add_filter('commentary_glyph_text', function(string $glyph, WP_Post $post): string {
	// Ensure last codepoint is U+FE0E (text presentation), to avoid emoji styling.
	$len  = mb_strlen($glyph, 'UTF-8');
	$last = $len ? mb_substr($glyph, -1, 1, 'UTF-8') : '';
	return $last === "\u{FE0E}" ? $glyph : ($glyph . "\u{FE0E}");
}, 10, 2);
