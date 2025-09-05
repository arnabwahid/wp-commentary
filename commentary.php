<?php
/**
 * Plugin Name:  Commentary
 * Plugin URI: http://github.com/arnabwahid
 * Description:  Commentary-style link blogging (no CPT).
 * Version:      4.13.0
 * Author: arnabwahid, peiche, kevindayton, yjsoon
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
 * A post is “Commentary” if:
 *  - Category 'commentary' OR Category 'linked' exists on it, OR
 *  - it has a non-empty External Link URL (meta: commentary_url).
 *
 * Listings (home/archive/search,/commentary/):
 *   - Title → internal permalink
 *   - Glyph “∞” (plaintext, right of title) → external URL (50% opacity; 100% on hover)
 *
 * Singles:
 *   - Title remains unlinked (theme default)
 *   - Glyph “∞” (right of title) → external URL
 */

/* ========================================================================== *
 * CONSTANTS
 * ========================================================================== */

const GLYPH_DEFAULT          = "∞\u{FE0E}";
const CAT_LINKED_SLUG        = 'linked';
const CAT_COMMENTARY_SLUG    = 'commentary';
const TAG_LINKED_SLUG        = 'linked';
const TAG_COMMENTARY_SLUG    = 'commentary';

const META_URL               = 'commentary_url';
const META_SKIP_REDIRECT     = 'commentary_skip_redirect';
const META_SKIP_REWRITE      = 'commentary_skip_rewrite'; // legacy toggle kept for compatibility
const META_LOCK_FORMAT       = 'commentary_lock_format';  // NEW per-post lock

const OPT_GROUP              = 'commentary';
const OPT_SINGLE_REDIRECT    = 'commentary_single_redirect';
const OPT_EXCLUDE_MAIN       = 'commentary_exclude_main_loop';
const OPT_POST_FORMAT        = 'commentary_post_format'; // 'link' | 'standard'

/* Internal flag used when opening Add New from our panel (to default Link format). */
const QV_FORCE_LINK_FORMAT   = 'commentary_default_format';

/* ========================================================================== *
 * 0) ACTIVATION / DEACTIVATION / INIT
 * ========================================================================== */

register_activation_hook(__FILE__, __NAMESPACE__ . '\\on_activate');
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\on_deactivate');

function on_activate(): void {
	ensure_terms_on_activation(); // Create categories + tags once on activation
	add_option(OPT_SINGLE_REDIRECT, 0);
	add_option(OPT_EXCLUDE_MAIN, 0);
	add_option(OPT_POST_FORMAT, 'link'); // default now: LINK format
	register_virtual_archive_rewrite();
	flush_rewrite_rules();
}

function on_deactivate(): void {
	flush_rewrite_rules();
}

// Rewrite + query var for /commentary/ and pagination
add_action('init', __NAMESPACE__ . '\\register_virtual_archive_rewrite');
add_filter('query_vars', __NAMESPACE__ . '\\register_virtual_archive_qv');

// Theme-driven archive via MAIN QUERY
add_action('pre_get_posts', __NAMESPACE__ . '\\commentary_virtual_pre_get_posts');
add_filter('get_the_archive_title', __NAMESPACE__ . '\\commentary_archive_title');
add_filter('template_include', __NAMESPACE__ . '\\commentary_virtual_template', 50);

// Exclude commentary posts from main blog loop (if enabled)
add_action('pre_get_posts', __NAMESPACE__ . '\\maybe_exclude_commentary_from_main', 20);

// Post meta registration (REST-aware)
add_action('init', __NAMESPACE__ . '\\register_post_meta_fields');

/* ========================================================================== *
 * 1) TERMS: Create Linked + Commentary categories AND tags (activation only)
 * ========================================================================== */

function ensure_terms_on_activation(): void {
	// Categories
	if (!get_term_by('slug', CAT_LINKED_SLUG, 'category')) {
		wp_insert_term('Linked', 'category', ['slug' => CAT_LINKED_SLUG]);
	}
	if (!get_term_by('slug', CAT_COMMENTARY_SLUG, 'category')) {
		wp_insert_term('Commentary', 'category', ['slug' => CAT_COMMENTARY_SLUG]);
	}

	// Tags
	if (!get_term_by('slug', TAG_LINKED_SLUG, 'post_tag')) {
		wp_insert_term('Linked', 'post_tag', ['slug' => TAG_LINKED_SLUG]);
	}
	if (!get_term_by('slug', TAG_COMMENTARY_SLUG, 'post_tag')) {
		wp_insert_term('Commentary', 'post_tag', ['slug' => TAG_COMMENTARY_SLUG]);
	}
}

/** Is a given post (or current global) a commentary post? */
function is_commentary_post(null|int|WP_Post $post = null): bool {
	$p = $post ? get_post($post) : get_post();
	if (!$p instanceof WP_Post || $p->post_type !== 'post') return false;
	if (has_term(CAT_COMMENTARY_SLUG, 'category', $p) || has_term(CAT_LINKED_SLUG, 'category', $p)) return true;
	$url = trim((string) get_post_meta($p->ID, META_URL, true));
	return $url !== '';
}

/* ========================================================================== *
 * 2) ADMIN POSTS LIST: “Commentary” view in Posts screen
 * ========================================================================== */

add_filter('views_edit-post', function(array $views): array {
	$base_url = add_query_arg(['post_type' => 'post'], admin_url('edit.php'));
	$url      = add_query_arg('linked', '1', $base_url);
	$count    = commentary_admin_count();

	$views['linked'] = sprintf(
		'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
		esc_url($url),
		(isset($_GET['linked']) && $_GET['linked'] === '1') ? ' class="current"' : '',
		esc_html__('Commentary', 'commentary'),
		(int) $count
	);

	return $views;
});

add_action('pre_get_posts', function(WP_Query $q) {
	if (!is_admin() || !$q->is_main_query()) return;
	if (!isset($_GET['linked']) || $_GET['linked'] !== '1') return;

	$q->set('post_type', 'post');
	$q->set('tax_query', [[
		'taxonomy' => 'category',
		'field'    => 'slug',
		'terms'    => [CAT_COMMENTARY_SLUG, CAT_LINKED_SLUG],
		'operator' => 'IN',
	]]);
});

function commentary_admin_count(): int {
	global $wpdb;

	$cat_comm = get_term_by('slug', CAT_COMMENTARY_SLUG, 'category');
	$cat_link = get_term_by('slug', CAT_LINKED_SLUG, 'category');

	$clauses = [];
	if ($cat_comm) { $clauses[] = $wpdb->prepare("(tt.taxonomy='category' AND tt.term_id=%d)", $cat_comm->term_id); }
	if ($cat_link) { $clauses[] = $wpdb->prepare("(tt.taxonomy='category' AND tt.term_id=%d)", $cat_link->term_id); }
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
 * 3) TRUE VIRTUAL ARCHIVE: /commentary/ with pagination (main loop)
 * ========================================================================== */

function register_virtual_archive_rewrite(): void {
	add_rewrite_rule('^commentary/?$', 'index.php?commentary_virtual=1', 'top');
	add_rewrite_rule('^commentary/page/([0-9]+)/?$', 'index.php?commentary_virtual=1&paged=$matches[1]', 'top');
}
function register_virtual_archive_qv(array $vars): array {
	$vars[] = 'commentary_virtual';
	return $vars;
}

function commentary_virtual_pre_get_posts(WP_Query $q): void {
	if (is_admin() || !$q->is_main_query()) return;
	if ((int) get_query_var('commentary_virtual') !== 1) return;

	$q->set('post_type', 'post');
	$q->set('ignore_sticky_posts', true);
	$q->set('tax_query', [[
		'taxonomy' => 'category',
		'field'    => 'slug',
		'terms'    => [CAT_COMMENTARY_SLUG, CAT_LINKED_SLUG],
		'operator' => 'IN',
	]]);
	$paged = get_query_var('paged');
	if (!$paged) $paged = isset($_GET['paged']) ? (int) $_GET['paged'] : 1;
	$q->set('paged', max(1, (int) $paged));

	$q->is_archive  = true;
	$q->is_home     = false;
	$q->is_singular = false;
	$q->is_page     = false;
	$q->is_404      = false;
}

function commentary_archive_title($title) {
	if ((int) get_query_var('commentary_virtual') === 1 && !is_admin()) {
		return __('Commentary', 'commentary');
	}
	return $title;
}

function commentary_virtual_template(string $template): string {
	if ((int) get_query_var('commentary_virtual') !== 1 || is_admin()) {
		return $template;
	}
	$preferred = locate_template(['archive.php', 'index.php']);
	return $preferred ?: $template;
}

/* ========================================================================== *
 * 3b) Exclude Commentary posts from the main blog loop (home) if enabled
 * ========================================================================== */

function maybe_exclude_commentary_from_main(WP_Query $q): void {
	if (is_admin() || !$q->is_main_query()) return;
	if (!$q->is_home()) return; // only the main blog loop
	if (!get_option(OPT_EXCLUDE_MAIN, 0)) return; // setting disabled

	$existing = (array) $q->get('tax_query');
	$existing[] = [
		'taxonomy' => 'category',
		'field'    => 'slug',
		'terms'    => [CAT_COMMENTARY_SLUG],
		'operator' => 'NOT IN',
	];
	$q->set('tax_query', $existing);
}

/* ========================================================================== *
 * 4) META: Register post meta (Gutenberg + REST)
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
	foreach ([META_SKIP_REDIRECT, META_SKIP_REWRITE, META_LOCK_FORMAT] as $bool_meta) {
		register_post_meta('post', $bool_meta, [
			'type'              => 'boolean',
			'single'            => true,
			'show_in_rest'      => true,
			'auth_callback'     => fn(): bool => current_user_can('edit_posts'),
			'sanitize_callback' => fn($v): bool => (bool) $v,
			'default'           => false, // lock is OFF by default
		]);
	}
}

/* ========================================================================== *
 * 5) EDITOR UI: Gutenberg sidebar (always visible) + Classic metabox fallback
 * ========================================================================== */

add_action('enqueue_block_editor_assets', __NAMESPACE__ . '\\enqueue_block_editor_assets');
function enqueue_block_editor_assets(): void {
	if (!function_exists('use_block_editor_for_post_type') || !use_block_editor_for_post_type('post')) return;

	$screen = function_exists('get_current_screen') ? get_current_screen() : null;
	if (!$screen || $screen->base !== 'post' || $screen->post_type !== 'post') return;

	$inline = '
	( function( wp ) {
		const { registerPlugin } = wp.plugins;
		const { PluginDocumentSettingPanel } = wp.editPost || {};
		if ( ! registerPlugin || ! PluginDocumentSettingPanel ) return;

		const { TextControl, ToggleControl, Tooltip } = wp.components;
		const { __ } = wp.i18n;
		const { useSelect, useDispatch } = wp.data;
		const { createElement: el } = wp.element;

		const Panel = () => {
			const postType = useSelect( s => s("core/editor").getCurrentPostType(), [] );
			if ( postType !== "post" ) return null;

			const meta = useSelect( s => s("core/editor").getEditedPostAttribute("meta") || {}, [] );
			const { editPost } = useDispatch("core/editor");
			const setMeta = (key, value) => editPost({ meta: { ...meta, [key]: value } });

			return el( PluginDocumentSettingPanel,
				{ name: "commentary-panel", title: __("External Link", "commentary"), initialOpen: true },

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

				el( Tooltip, { text: __("(Legacy toggle—kept for compatibility)", "commentary") },
					el( "div", {},
						el( ToggleControl, {
							label: __("Use post permalink in lists", "commentary"),
							checked: !!meta.' . META_SKIP_REWRITE . ',
							onChange: (v) => setMeta("' . META_SKIP_REWRITE . '", !!v)
						})
					)
				),

				el( Tooltip, { text: __("Prevent the plugin from changing this post’s format on save", "commentary") },
					el( "div", {},
						el( ToggleControl, {
							label: __("Lock this post’s format", "commentary"),
							checked: !!meta.' . META_LOCK_FORMAT . ',
							onChange: (v) => setMeta("' . META_LOCK_FORMAT . '", !!v)
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
		__('External Link', 'commentary'),
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
	$lock_format   = (bool) get_post_meta($post->ID, META_LOCK_FORMAT, true);
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
		<label title="<?php echo esc_attr__('(Legacy toggle—kept for compatibility)', 'commentary'); ?>">
			<input type="checkbox" name="<?php echo esc_attr(META_SKIP_REWRITE); ?>" value="1" <?php checked(true, $skip_rewrite); ?> />
			<?php echo esc_html__('Use post permalink in lists', 'commentary'); ?>
		</label>
	</p>
	<p>
		<label title="<?php echo esc_attr__('Prevent the plugin from changing this post’s format on save', 'commentary'); ?>">
			<input type="checkbox" name="<?php echo esc_attr(META_LOCK_FORMAT); ?>" value="1" <?php checked(true, $lock_format); ?> />
			<?php echo esc_html__('Lock this post’s format', 'commentary'); ?>
		</label>
	</p>
	<p class="description"><?php echo esc_html__('Tip: Adding a Link URL will auto-categorize & tag this post as Commentary + Linked and remove Uncategorized on save.', 'commentary'); ?></p>
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
	update_post_meta($post_id, META_LOCK_FORMAT, !empty($_POST[META_LOCK_FORMAT]) ? 1 : 0);
}

/* ========================================================================== *
 * 6) LISTINGS & SINGLE: glyph (external) and title behavior
 * ========================================================================== */

function get_commentary_external_url(WP_Post $post): string {
	$url = trim((string) get_post_meta($post->ID, META_URL, true));
	if ($url === '') return '';
	$parsed = wp_parse_url($url);
	if (!is_array($parsed) || !isset($parsed['scheme']) || !in_array(strtolower((string) $parsed['scheme']), ['http','https'], true)) {
		return '';
	}
	return esc_url($url);
}

/* Block themes: append glyph to post-title render (external link). */
add_filter('render_block', __NAMESPACE__ . '\\filter_post_title_block', 20, 2);
function filter_post_title_block(string $html, array $block): string {
	if (($block['blockName'] ?? '') !== 'core/post-title') return $html;
	if (is_admin() || is_feed()) return $html;

	$post = get_post();
	if (!$post instanceof WP_Post || $post->post_type !== 'post' || !is_commentary_post($post)) return $html;

	$external = get_commentary_external_url($post);
	if ($external === '') return $html;

	if (!str_contains($html, 'commentary-permalink-glyph')) {
		$glyph = apply_filters('commentary_glyph_text', GLYPH_DEFAULT, $post);
		$glyph = is_string($glyph) && $glyph !== '' ? $glyph : GLYPH_DEFAULT;

		$glyph_html = sprintf(
			'<span class="commentary-permalink-glyph"><a href="%s" rel="noopener nofollow ugc" title="%s" aria-label="external-link">%s</a></span>',
			esc_url($external),
			esc_attr__('External Link', 'commentary'),
			esc_html($glyph)
		);
		$modified = preg_replace('~(</h[1-6]>)\s*$~i', ' ' . $glyph_html . '$1', $html, 1);
		$html = $modified ?: $html;
	}
	return $html;
}

/* Classic themes: add span placeholder and upgrade to link with small JS. */
add_filter('the_title', __NAMESPACE__ . '\\append_classic_title_glyph_placeholder', 20, 2);
function append_classic_title_glyph_placeholder(string $title, int $post_id): string {
	if (is_admin() || is_feed()) return $title;

	$post = get_post($post_id);
	if (!$post instanceof WP_Post || $post->post_type !== 'post' || !is_commentary_post($post)) return $title;

	$external = get_commentary_external_url($post);
	if ($external === '') return $title;

	$glyph = apply_filters('commentary_glyph_text', GLYPH_DEFAULT, $post);
	$glyph = is_string($glyph) && $glyph !== '' ? $glyph : GLYPH_DEFAULT;

	if (str_contains($title, 'commentary-permalink-glyph')) return $title;

	$span = sprintf(
		' <span class="commentary-permalink-glyph" data-external="%s" title="%s" aria-label="external-link">%s</span>',
		esc_attr($external),
		esc_attr__('External Link', 'commentary'),
		esc_html($glyph)
	);

	return rtrim($title) . $span;
}

add_action('wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_classic_fix_script');
function enqueue_classic_fix_script(): void {
	if (is_admin() || is_feed()) return;

	$js = <<<JS
document.addEventListener('DOMContentLoaded',function(){
  var spans = document.querySelectorAll('h1 .commentary-permalink-glyph, h2 .commentary-permalink-glyph, h3 .commentary-permalink-glyph, h4 .commentary-permalink-glyph, h5 .commentary-permalink-glyph, h6 .commentary-permalink-glyph');
  spans.forEach(function(span){
    var external = span.getAttribute('data-external') || '';
    var heading = span.closest('h1,h2,h3,h4,h5,h6');
    if (!heading || !external) return;
    var a = document.createElement('a');
    a.href = external;
    a.className = 'commentary-permalink-glyph-link';
    a.setAttribute('rel','noopener nofollow ugc');
    a.setAttribute('title','External Link');
    a.setAttribute('aria-label','external-link');
    a.appendChild(document.createTextNode(span.textContent || '∞'));
    span.replaceWith(a);
  });
});
JS;
	wp_register_script('commentary-classic-glyph', false, [], null, true);
	wp_enqueue_script('commentary-classic-glyph');
	wp_add_inline_script('commentary-classic-glyph', $js, 'after');
}

/* ========================================================================== *
 * 7) AUTO-CATEGORIZE, AUTO-TAG & APPLY POST FORMAT on qualifying posts
 * ========================================================================== */

add_action('save_post_post', __NAMESPACE__ . '\\ensure_commentary_terms_and_format', 20, 3);
function ensure_commentary_terms_and_format(int $post_id, WP_Post $post, bool $update): void {
	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
	if (!current_user_can('edit_post', $post_id)) return;

	// Determine if this is a commentary post
	$url            = trim((string) get_post_meta($post_id, META_URL, true));
	$has_linked_cat = has_term(CAT_LINKED_SLUG, 'category', $post);
	$has_comm_cat   = has_term(CAT_COMMENTARY_SLUG, 'category', $post);

	if ($url === '' && !$has_linked_cat && !$has_comm_cat) {
		return; // not commentary; do nothing
	}

	/* Categories: remove "Uncategorized" and ensure Linked + Commentary are present (if they exist) */
	$current_cats = wp_get_post_terms($post_id, 'category', ['fields' => 'ids']);
	if (!is_array($current_cats)) $current_cats = [];

	$default_cat_id = (int) get_option('default_category');
	$current_cats   = array_map('intval', $current_cats);
	$current_cats   = array_filter($current_cats, fn($id) => $id !== $default_cat_id);

	$cat_linked     = get_term_by('slug', CAT_LINKED_SLUG, 'category');
	$cat_commentary = get_term_by('slug', CAT_COMMENTARY_SLUG, 'category');
	$add_cat_ids    = [];
	if ($cat_linked)     { $add_cat_ids[] = (int) $cat_linked->term_id; }
	if ($cat_commentary) { $add_cat_ids[] = (int) $cat_commentary->term_id; }

	$final_cats = array_values(array_unique(array_merge($current_cats, $add_cat_ids)));
	if ($final_cats) {
		wp_set_post_categories($post_id, $final_cats, false);
	}

	/* Tags: automatically assign "Commentary" and "Linked" (created on activation) */
	$current_tags = wp_get_post_terms($post_id, 'post_tag', ['fields' => 'ids']);
	if (!is_array($current_tags)) $current_tags = [];

	$tag_linked     = get_term_by('slug', TAG_LINKED_SLUG, 'post_tag');
	$tag_commentary = get_term_by('slug', TAG_COMMENTARY_SLUG, 'post_tag');
	$add_tag_ids    = [];
	if ($tag_linked)     { $add_tag_ids[] = (int) $tag_linked->term_id; }
	if ($tag_commentary) { $add_tag_ids[] = (int) $tag_commentary->term_id; }

	$final_tags = array_values(array_unique(array_merge($current_tags, $add_tag_ids)));
	if ($final_tags) {
		wp_set_post_terms($post_id, $final_tags, 'post_tag', false);
	}

	/* Apply Post Format per setting (if supported by theme), unless locked per-post. */
	if (post_type_supports('post', 'post-formats')) {
		$locked = (bool) get_post_meta($post_id, META_LOCK_FORMAT, true);
		if (!$locked) {
			$choice = get_option(OPT_POST_FORMAT, 'link'); // 'link' or 'standard'
			if ($choice === 'standard') {
				set_post_format($post_id, false); // Standard
			} else {
				set_post_format($post_id, 'link'); // Link format
			}
		}
	}
}

/* ========================================================================== *
 * 8) DEDICATED ADMIN PANEL + SETTINGS
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

	$catLinked  = get_term_by('slug', CAT_LINKED_SLUG, 'category');
	$catComm    = get_term_by('slug', CAT_COMMENTARY_SLUG, 'category');

	$url = admin_url('post-new.php?post_type=post');

	// Pre-select categories (if they exist)
	if ($catLinked) { $url = add_query_arg(['tax_input[category][]' => (int) $catLinked->term_id], $url); }
	if ($catComm)   { $url = add_query_arg(['tax_input[category][]' => (int) $catComm->term_id], $url); }

	// Signal the editor to use Link format by default for this new Commentary post
	$url = add_query_arg(QV_FORCE_LINK_FORMAT, '1', $url);

	wp_safe_redirect($url);
	exit;
}

/** Force the default post format to "link" when opening Add New from our panel. */
add_filter('default_post_format', function(string $format): string {
	if (is_admin() && isset($_GET[QV_FORCE_LINK_FORMAT]) && $_GET[QV_FORCE_LINK_FORMAT] === '1') {
		return 'link';
	}
	return $format;
});

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
		'tax_query'      => [[
			'taxonomy' => 'category',
			'field'    => 'slug',
			'terms'    => [CAT_COMMENTARY_SLUG, CAT_LINKED_SLUG],
			'operator' => 'IN',
		]],
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
				$post  = get_post();
				$ID    = (int) $post->ID;
				$edit  = get_edit_post_link($ID);
				$view  = get_permalink($ID);
				$trash = get_delete_post_link($ID, '', true);
				$url   = (string) get_post_meta($ID, META_URL, true);

				$qe_url = add_query_arg(
					[
						'post_type' => 'post',
						'linked'    => '1',
					],
					admin_url('edit.php')
				) . '#post-' . $ID;
			?>
				<tr id="post-<?php echo esc_attr($ID); ?>">
					<td>
						<strong><a href="<?php echo esc_url($edit); ?>"><?php echo esc_html(get_the_title($post)); ?></a></strong>
						<div class="row-actions">
							<span class="edit"><a href="<?php echo esc_url($edit); ?>"><?php esc_html_e('Edit', 'commentary'); ?></a> | </span>
							<span class="inline hide-if-no-js"><a href="<?php echo esc_url($qe_url); ?>" class="editinline"><?php esc_html_e('Quick Edit', 'commentary'); ?></a> | </span>
							<span class="trash"><a href="<?php echo esc_url($trash); ?>" class="submitdelete"><?php esc_html_e('Trash', 'commentary'); ?></a> | </span>
							<span class="view"><a href="<?php echo esc_url($view); ?>" target="_blank" rel="noopener"><?php esc_html_e('View', 'commentary'); ?></a></span>
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

/* Settings (single-view redirect + exclude main loop + POST FORMAT selection) */
add_action('admin_init', __NAMESPACE__ . '\\register_settings');
function register_settings(): void {
	register_setting(OPT_GROUP, OPT_SINGLE_REDIRECT, [
		'type'              => 'boolean',
		'sanitize_callback' => fn($v): bool => (bool) $v,
		'default'           => 0,
	]);
	register_setting(OPT_GROUP, OPT_EXCLUDE_MAIN, [
		'type'              => 'boolean',
		'sanitize_callback' => fn($v): bool => (bool) $v,
		'default'           => 0,
	]);
	register_setting(OPT_GROUP, OPT_POST_FORMAT, [
		'type'              => 'string',
		'sanitize_callback' => function($v): string {
			$v = is_string($v) ? strtolower($v) : '';
			return in_array($v, ['link','standard'], true) ? $v : 'link';
		},
		'default'           => 'link',
	]);

	add_settings_section(
		'commentary_main',
		__('Commentary Settings', 'commentary'),
		function() {
			echo '<p>' . esc_html__('Global settings for Commentary behavior.', 'commentary') . '</p>';
		},
		'commentary_settings'
	);

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

	add_settings_field(
		OPT_EXCLUDE_MAIN,
		__('Exclude Commentary from main blog loop', 'commentary'),
		function() {
			$val = (bool) get_option(OPT_EXCLUDE_MAIN, 0);
			echo '<label><input type="checkbox" name="' . esc_attr(OPT_EXCLUDE_MAIN) . '" value="1" ' . checked(true, $val, false) . ' />';
			echo ' ' . esc_html__('Hide posts in the “Commentary” category from the home/posts page loop.', 'commentary') . '</label>';
		},
		'commentary_settings',
		'commentary_main'
	);

	// Post format selector (Standard vs Link)
	add_settings_field(
		OPT_POST_FORMAT,
		__('Post Format for Commentary', 'commentary'),
		function() {
			$val = (string) get_option(OPT_POST_FORMAT, 'link');
			?>
			<select name="<?php echo esc_attr(OPT_POST_FORMAT); ?>">
				<option value="link" <?php selected($val, 'link'); ?>>
					<?php esc_html_e('Link (recommended for link posts)', 'commentary'); ?>
				</option>
				<option value="standard" <?php selected($val, 'standard'); ?>>
					<?php esc_html_e('Standard (no special format)', 'commentary'); ?>
				</option>
			</select>
			<p class="description">
				<?php esc_html_e('Applied automatically to qualifying Commentary posts on save (if your theme supports Post Formats). Use the per-post “Lock this post’s format” to prevent changes.', 'commentary'); ?>
			</p>
			<?php
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

/* Optional redirect logic (single view) */
add_action('template_redirect', __NAMESPACE__ . '\\maybe_redirect_single', 1);
function maybe_redirect_single(): void {
	if (is_admin() || is_feed() || !is_singular('post')) return;
	if (!get_option(OPT_SINGLE_REDIRECT, 0)) return;
	if (isset($_GET['stay']) && (string) $_GET['stay'] === '1') return;

	$post = get_post();
	if (!$post instanceof WP_Post || !is_commentary_post($post)) return;

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
 * 9) CSS: Glyph opacity (50%) and hover 100%
 * ========================================================================== */

add_action('wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_front_css');
function enqueue_front_css(): void {
	$css = <<<CSS
/* Commentary glyph styles */
.commentary-permalink-glyph a,
a.commentary-permalink-glyph-link {
  opacity: .5;
  text-decoration: none;
}
.commentary-permalink-glyph a:hover,
a.commentary-permalink-glyph-link:hover {
  opacity: 1;
  text-decoration: none;
}
CSS;
	wp_register_style('commentary-inline', false, [], null);
	wp_enqueue_style('commentary-inline');
	wp_add_inline_style('commentary-inline', $css);
}

/* ========================================================================== *
 * 10) GLYPH Helpers
 * ========================================================================== */

add_filter('commentary_glyph_text', function(string $glyph, WP_Post $post): string {
	$len  = mb_strlen($glyph, 'UTF-8');
	$last = $len ? mb_substr($glyph, -1, 1, 'UTF-8') : '';
	return $last === "\u{FE0E}" ? $glyph : ($glyph . "\u{FE0E}");
}, 10, 2);
