<?php
/**
 * Plugin Name:  Commentary
 * Description:  Utilities for a “Commentary” link-blogging workflow without a CPT: ensures Category+Tag “Linked”, adds an admin “Linked” view in the Posts list, provides a [commentary_archive] shortcode, and exposes a virtual archive at /commentary/ (no theme edits).
 * Version:      4.0.0
 * Requires PHP: 8.0
 * Requires at least: 6.3
 * Tested up to: 6.7
 * License:      MIT
 * Text Domain:  commentary
 */

declare(strict_types=1);

namespace Commentary\Admin;

use WP_Query;

if (!defined('ABSPATH')) { exit; }

/**
 * OVERVIEW
 * --------
 * Baseline + Virtual Archive:
 * - Ensure Category “Linked” (slug: linked) and Tag “Linked” (slug: linked).
 * - Add an admin Posts list "Linked" view (like All | Published | Linked).
 * - Provide a [commentary_archive] shortcode to render “Linked” posts.
 * - Register a virtual archive at /commentary/ that renders the shortcode
 *   within your theme’s header/footer — no Page object or theme edits.
 *
 * “Linked” post definition (simple and deliberate, no back-compat):
 *   - A normal Post that has Category “linked” OR Tag “linked”
 *   - (Optional) You can also use post meta key `commentary_url` in your own flows.
 */

/* ========================================================================== *
 * 0) ACTIVATION / DEACTIVATION / INIT
 * ========================================================================== */

register_activation_hook(__FILE__, __NAMESPACE__ . '\\on_activate');
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\on_deactivate');

/**
 * On activation:
 * - Ensure terms exist.
 * - Register rewrite rules for the virtual archive.
 * - Flush to write rules to the DB/webserver config.
 */
function on_activate(): void {
	ensure_terms();
	register_virtual_archive_rewrite();
	flush_rewrite_rules();
}

/** On deactivation: flush rewrites to remove our rules. */
function on_deactivate(): void {
	flush_rewrite_rules();
}

/** Also ensure terms on theme switch (keeps sites tidy if themes are swapped). */
add_action('after_switch_theme', __NAMESPACE__ . '\\ensure_terms');

/** Public init hooks (rewrite rules must be added on `init`). */
add_action('init', __NAMESPACE__ . '\\register_virtual_archive_rewrite');    // rules
add_filter('query_vars', __NAMESPACE__ . '\\register_virtual_archive_qv');   // query var
add_action('template_redirect', __NAMESPACE__ . '\\maybe_render_virtual');   // render

/* ========================================================================== *
 * 1) TERMS: Ensure Category + Tag “Linked”
 * ========================================================================== */

/**
 * Ensure Category “Linked” (slug: linked) and Tag “Linked” (slug: linked) exist.
 * Idempotent: safe to call multiple times.
 */
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

/* ========================================================================== *
 * 2) ADMIN POSTS LIST: “Linked” view
 * ========================================================================== */

/**
 * Add a “Linked” view tab to Posts list (like All | Published | Linked).
 * Clicking it filters admin list to posts that are in category/tag “linked”.
 */
add_filter('views_edit-post', function(array $views): array {
	$base_url = add_query_arg(['post_type' => 'post'], admin_url('edit.php'));
	$url      = add_query_arg('linked', '1', $base_url);

	$count = linked_admin_count();

	$views['linked'] = sprintf(
		'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
		esc_url($url),
		(isset($_GET['linked']) && $_GET['linked'] === '1') ? ' class="current"' : '',
		esc_html__('Linked', 'commentary'),
		(int) $count
	);

	return $views;
});

/**
 * When “Linked” view active, shape main query:
 * - include posts in category “linked” OR tag “linked”
 */
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
			'operator' => 'IN',
		],
		[
			'taxonomy' => 'post_tag',
			'field'    => 'slug',
			'terms'    => ['linked'],
			'operator' => 'IN',
		],
	]);
});

/**
 * Lightweight count for the “Linked” view tab (category/tag only; no meta).
 */
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
		  AND (
			   1 = 0
			{$cat_clause}
			{$tag_clause}
		  )
	";

	return (int) $wpdb->get_var($sql);
}

/* ========================================================================== *
 * 3) SHORTCODE: [commentary_archive]
 * ========================================================================== */

/**
 * Shortcode to render a “Commentary” archive anywhere.
 * Usage example: [commentary_archive posts_per_page="10"]
 */
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

/**
 * Map /commentary/ to an internal flag we can detect at runtime.
 */
function register_virtual_archive_rewrite(): void {
	add_rewrite_rule('^commentary/?$', 'index.php?commentary_virtual=1', 'top');
}

/** Register the custom query var so WordPress won’t strip it. */
function register_virtual_archive_qv(array $vars): array {
	$vars[] = 'commentary_virtual';
	return $vars;
}

/**
 * If the request matches our virtual archive, render it with theme header/footer.
 * We simply output on template_redirect and then `exit` to stop normal templating.
 */
function maybe_render_virtual(): void {
	if ((int) get_query_var('commentary_virtual') !== 1) {
		return;
	}

	status_header(200);

	// Give it a proper <title>.
	add_filter('pre_get_document_title', fn() => __('Commentary', 'commentary'));

	// Allow themes to target styles easily.
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
