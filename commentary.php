<?php
/**
 * Plugin Name:  Commentary
 * Description:  Commentary-style link blogging without a CPT: ensures Category+Tag “Linked”, adds an admin “Linked” view in the Posts list, a [commentary_archive] shortcode, a virtual archive at /commentary/, a Gutenberg sidebar (with classic metabox fallback), and title glyphs on listings only.
 * Version:      4.1.0
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
 * OVERVIEW
 * --------
 * - “Linked” post = any Post with Category OR Tag “linked” (slug: linked).
 * - Virtual archive /commentary/ renders with your theme header/footer.
 * - Gutenberg sidebar panel for per-post Link URL + two toggles (with classic metabox fallback).
 * - Plaintext glyph “∞” (text presentation) appended to the RIGHT of Linked titles on listings only,
 *   linking to the internal post permalink. Never shown on single views.
 *
 * Meta keys (registered for Posts):
 * - commentary_url            : string (external link; optional)
 * - commentary_skip_redirect  : boolean (future phase use; stored now)
 * - commentary_skip_rewrite   : boolean (future phase use; stored now)
 *
 * NOTE: Title rewrite/redirect behavior is NOT enabled in this build (glyphs only),
 *       but we store the toggles for future phases.
 */

/* ========================================================================== *
 * CONSTANTS / INTERNALS
 * ========================================================================== */

const GLYPH_DEFAULT = "∞\u{FE0E}"; // plaintext infinity + U+FE0E (text presentation)

/* ========================================================================== *
 * 0) ACTIVATION / DEACTIVATION / INIT
 * ========================================================================== */

register_activation_hook(__FILE__, __NAMESPACE__ . '\\on_activate');
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\on_deactivate');

function on_activate(): void {
	ensure_terms();
	register_virtual_archive_rewrite();
	flush_rewrite_rules();
}

function on_deactivate(): void {
	flush_rewrite_rules();
}

add_action('after_switch_theme', __NAMESPACE__ . '\\ensure_terms');

// Public init hooks (rewrite + meta)
add_action('init', __NAMESPACE__ . '\\register_virtual_archive_rewrite');
add_filter('query_vars', __NAMESPACE__ . '\\register_virtual_archive_qv');
add_action('template_redirect', __NAMESPACE__ . '\\maybe_render_virtual');
add_action('init', __NAMESPACE__ . '\\register_post_meta_fields');

/* ========================================================================== *
 * 1) TERMS: Ensure Category + Tag “Linked”
 * ========================================================================== */

function ensure_terms(): void {
	$cat = get_term_by('slug', 'linked', 'category');
	if (!$cat) {
		wp_insert_term('Linked', 'category', ['slug' => 'linked']);
	}
	$tag = get_term_by('slug', 'linked', 'post_tag');
	if (!$tag) {
		wp_insert_term('Linked', 'post_tag', ['slug' => 'linked']);
	}
}

/** Helper: is a given post (or current global) “Linked”? */
function is_linked_post(null|int|WP_Post $post = null): bool {
	$p = $post ? get_post($post) : get_post();
	if (!$p instanceof WP_Post || $p->post_type !== 'post') return false;
	return has_term('linked', 'category', $p) || has_term('linked', 'post_tag', $p);
}

/* ========================================================================== *
 * 2) ADMIN POSTS LIST: “Linked” view
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
			'taxonomy' => 'category',
			'field'    => 'slug',
			'terms'    => ['linked'],
		],
		[
			'taxonomy' => 'post_tag',
			'field'    => 'slug',
			'terms'    => ['linked'],
		],
	]);
});

function linked_admin_count(): int {
	global $wpdb;

	$cat_term = get_term_by('slug', 'linked', 'category');
	$tag_term = get_term_by('slug', 'linked', 'post_tag');

	$cat_clause = $cat_term ? $wpdb->prepare(
		"OR (tt.taxonomy = 'category' AND tt.term_id = %d)",
		$cat_term->term_id
	) : '';

	$tag_clause = $tag_term ? $wpdb->prepare(
		"OR (tt.taxonomy = 'post_tag' AND tt.term_id = %d)",
		$tag_term->term_id
	) : '';

	$sql = "
		SELECT COUNT(DISTINCT p.ID)
		FROM {$wpdb->posts} p
		LEFT JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
		LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
		WHERE p.post_type = 'post'
		  AND p.post_status NOT IN ('trash', 'auto-draft')
		  AND (1=0 {$cat_clause} {$tag_clause})
	";

	return (int) $wpdb->get_var($sql);
}

/* ========================================================================== *
 * 3) SHORTCODE: [commentary_archive]
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
				'taxonomy' => 'category',
				'field'    => 'slug',
				'terms'    => ['linked'],
			],
			[
				'taxonomy' => 'post_tag',
				'field'    => 'slug',
				'terms'    => ['linked'],
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
		if ($links) {
			echo wp_kses_post($links);
		}
	} else {
		echo '<p>' . esc_html__('No commentary posts found.', 'commentary') . '</p>';
	}

	wp_reset_postdata();
	return (string) ob_get_clean();
});

/* ========================================================================== *
 * 4) VIRTUAL ARCHIVE: /commentary/ WITHOUT CREATING A PAGE
 * ========================================================================== */

function register_virtual_archive_rewrite(): void {
	add_rewrite_rule('^commentary/?$', 'index.php?commentary_virtual=1', 'top');
}

function register_virtual_archive_qv(array $vars): array {
	$vars[] = 'commentary_virtual';
	return $vars;
}

function maybe_render_virtual(): void {
	if ((int) get_query_var('commentary_virtual') !== 1) {
		return;
	}

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
 * 5) META: Register post meta (Gutenberg + REST) for Posts
 * ========================================================================== */

function register_post_meta_fields(): void {
	// External URL for this Linked/Commentary post
	register_post_meta('post', 'commentary_url', [
		'type'              => 'string',
		'single'            => true,
		'show_in_rest'      => true,
		'auth_callback'     => fn(): bool => current_user_can('edit_posts'),
		'sanitize_callback' => fn($v): string => esc_url_raw((string) $v),
		'default'           => '',
		'description'       => __('External URL for this Commentary post', 'commentary'),
	]);

	// Future use; stored now so UI is consistent with our roadmap.
	foreach (['commentary_skip_redirect', 'commentary_skip_rewrite'] as $bool_meta) {
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
 * 6) EDITOR UI: Gutenberg sidebar (with classic metabox fallback)
 * ========================================================================== */

/**
 * Gutenberg sidebar panel for Posts (shows ONLY when category/tag “linked” is selected).
 * Fallback: if block editor is disabled for posts, we provide a classic metabox instead.
 */
add_action('enqueue_block_editor_assets', __NAMESPACE__ . '\\enqueue_block_editor_assets');
function enqueue_block_editor_assets(): void {
	if (!function_exists('use_block_editor_for_post_type') || !use_block_editor_for_post_type('post')) {
		return; // classic editor in use for Posts; metabox will be added separately
	}

	$screen = function_exists('get_current_screen') ? get_current_screen() : null;
	if (!$screen || $screen->base !== 'post' || $screen->post_type !== 'post') return;

	// Find Linked category ID for quick checks in the panel (visible only if selected).
	$linked_cat = get_term_by('slug', 'linked', 'category');
	$linked_cat_id = $linked_cat ? (int) $linked_cat->term_id : 0;

	$inline = '
	( function( wp ) {
		const { registerPlugin } = wp.plugins;
		const { PluginDocumentSettingPanel } = wp.editPost || {};
		if ( ! registerPlugin || ! PluginDocumentSettingPanel ) return;

		const { TextControl, ToggleControl, Tooltip } = wp.components;
		const { __ } = wp.i18n;
		const { useSelect, useDispatch } = wp.data;
		const { createElement: el, Fragment } = wp.element;

		const LINKED_CAT_ID = ' . (int) $linked_cat_id . ';

		const useIsLinked = () => {
			const cats = useSelect( s => s("core/editor").getEditedPostAttribute("categories") || [], [] );
			const tags = useSelect( s => s("core/editor").getEditedPostAttribute("tags") || [], [] );
			const linkedTagId = 0; // unknown here; we only gate on category presence reliably
			const hasLinkedCat = Array.isArray(cats) && cats.includes(LINKED_CAT_ID);
			return hasLinkedCat; // minimal gating; tag may also qualify but category is enough
		};

		const Panel = () => {
			const postType = useSelect( s => s("core/editor").getCurrentPostType(), [] );
			if ( postType !== "post" ) return null;

			const isLinked = useIsLinked();
			if ( !isLinked ) return null;

			const meta = useSelect( s => s("core/editor").getEditedPostAttribute("meta") || {}, [] );
			const { editPost } = useDispatch("core/editor");
			const setMeta = (key, value) => editPost({ meta: { ...meta, [key]: value } });

			return el( PluginDocumentSettingPanel,
				{ name: "commentary-panel", title: __("Linked Post", "commentary"), initialOpen: true },

				el( TextControl, {
					label: __("Link URL", "commentary"),
					value: meta.commentary_url || "",
					onChange: (v) => setMeta("commentary_url", v),
					type: "url",
					placeholder: "https://example.com/article"
				}),

				el( Tooltip, { text: __("Show post content instead of redirecting", "commentary") },
					el( "div", {},
						el( ToggleControl, {
							label: __("Don’t auto-redirect this post", "commentary"),
							checked: !!meta.commentary_skip_redirect,
							onChange: (v) => setMeta("commentary_skip_redirect", !!v)
						})
					)
				),

				el( Tooltip, { text: __("Link titles to post page (not external site)", "commentary") },
					el( "div", {},
						el( ToggleControl, {
							label: __("Use post permalink in lists", "commentary"),
							checked: !!meta.commentary_skip_rewrite,
							onChange: (v) => setMeta("commentary_skip_rewrite", !!v)
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

/** Classic editor metabox fallback when Gutenberg is disabled for Posts. */
add_action('add_meta_boxes', __NAMESPACE__ . '\\maybe_add_classic_metabox');
function maybe_add_classic_metabox(): void {
	if (function_exists('use_block_editor_for_post_type') && use_block_editor_for_post_type('post')) {
		return; // Using block editor → sidebar panel covers it
	}

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
	$url           = (string) get_post_meta($post->ID, 'commentary_url', true);
	$skip_redirect = (bool) get_post_meta($post->ID, 'commentary_skip_redirect', true);
	$skip_rewrite  = (bool) get_post_meta($post->ID, 'commentary_skip_rewrite', true);
	?>
	<p>
		<label for="commentary_url_field" class="screen-reader-text"><?php echo esc_html__('External URL', 'commentary'); ?></label>
		<input type="url" id="commentary_url_field" name="commentary_url" value="<?php echo esc_attr($url); ?>" class="widefat" placeholder="https://example.com/article" inputmode="url" />
	</p>
	<p>
		<label title="<?php echo esc_attr__('Show post content instead of redirecting', 'commentary'); ?>">
			<input type="checkbox" name="commentary_skip_redirect" value="1" <?php checked(true, $skip_redirect); ?> />
			<?php echo esc_html__('Don’t auto-redirect this post', 'commentary'); ?>
		</label>
	</p>
	<p>
		<label title="<?php echo esc_attr__('Link titles to post page (not external site)', 'commentary'); ?>">
			<input type="checkbox" name="commentary_skip_rewrite" value="1" <?php checked(true, $skip_rewrite); ?> />
			<?php echo esc_html__('Use post permalink in lists', 'commentary'); ?>
		</label>
	</p>
	<?php
}

/** Save classic metabox values safely. */
add_action('save_post_post', __NAMESPACE__ . '\\save_classic_metabox', 10, 2);
function save_classic_metabox(int $post_id, WP_Post $post): void {
	if (!isset($_POST['commentary_meta_nonce']) || !wp_verify_nonce((string) $_POST['commentary_meta_nonce'], 'commentary_meta_save')) return;
	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
	if (!current_user_can('edit_post', $post_id)) return;

	update_post_meta($post_id, 'commentary_url', esc_url_raw((string) ($_POST['commentary_url'] ?? '')));
	update_post_meta($post_id, 'commentary_skip_redirect', !empty($_POST['commentary_skip_redirect']) ? 1 : 0);
	update_post_meta($post_id, 'commentary_skip_rewrite', !empty($_POST['commentary_skip_rewrite']) ? 1 : 0);
}

/* ========================================================================== *
 * 7) TITLE GLYPHS ON LISTINGS (block + classic themes)
 * ========================================================================== */

/**
 * BLOCK THEMES:
 * Inject a plaintext glyph to the RIGHT of core/post-title closing tag on
 * non-single views for “Linked” posts only. Glyph links to internal permalink.
 */
add_filter('render_block', __NAMESPACE__ . '\\inject_glyph_in_post_title_block', 20, 2);
function inject_glyph_in_post_title_block(string $html, array $block): string {
	if (($block['blockName'] ?? '') !== 'core/post-title') return $html;
	if (is_admin() || is_feed() || is_single()) return $html;

	$post = get_post();
	if (!$post instanceof WP_Post || $post->post_type !== 'post') return $html;
	if (!is_linked_post($post)) return $html;

	$glyph = apply_filters('commentary_glyph_text', GLYPH_DEFAULT, $post);
	$glyph = is_string($glyph) && $glyph !== '' ? $glyph : GLYPH_DEFAULT;

	$perma = get_permalink($post);
	$glyph_html = sprintf(
		'<span class="commentary-permalink-glyph"><a href="%s" rel="bookmark">%s</a></span>',
		esc_url($perma),
		esc_html($glyph)
	);

	// If already injected, avoid duplication.
	if (str_contains($html, 'commentary-permalink-glyph')) return $html;

	$modified = preg_replace('~(</h[1-6]>)\s*$~i', ' ' . $glyph_html . '$1', $html, 1);
	return $modified ?: $html;
}

/**
 * CLASSIC THEMES:
 * Append " ∞" to the title string on listing views only. We keep the glyph
 * rendered as plain text; the main title link remains clickable as usual.
 */
add_filter('the_title', __NAMESPACE__ . '\\inject_glyph_in_title_classic', 20, 2);
function inject_glyph_in_title_classic(string $title, int $post_id): string {
	if (is_admin() || is_feed() || is_single()) return $title;

	$post = get_post($post_id);
	if (!$post instanceof WP_Post || $post->post_type !== 'post') return $title;
	if (!is_linked_post($post)) return $title;

	$glyph = apply_filters('commentary_glyph_text', GLYPH_DEFAULT, $post);
	$glyph = is_string($glyph) && $glyph !== '' ? $glyph : GLYPH_DEFAULT;

	// Prevent accidental duplication
	if (str_contains($title, $glyph)) return $title;

	return rtrim($title) . ' ' . $glyph;
}
