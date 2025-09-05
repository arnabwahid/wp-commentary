<?php
/**
 * Plugin Name:  Commentary
 * Description:  Utilities for “Commentary” link-blogging without a CPT: ensures Category+Tag “Linked”, adds an admin “Linked” view in Posts list, provides a shortcode archive, and a virtual archive at /commentary/ (no theme edits).
 * Version:      3.0.0
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
 * Commentary baseline + Virtual Archive:
 * - Ensure Category “Linked” (slug: linked) and Tag “Linked” (slug: linked).
 * - Add an admin Posts list "Linked" view (like All | Published | Linked).
 * - Provide a [linked_list_archive] shortcode to render linked posts.
 * - Register a *virtual* archive route at /commentary/ that renders the shortcode
 *   wrapped with your theme header/footer — no Page object or theme edits required.
 *
 * “Linked” post = any Post that:
 *   - has Category “linked” OR Tag “linked” OR has meta key `linked_url` or `linklog_url`.
 */

/* ========================================================================== *
 * 0) ACTIVATION / DEACTIVATION / INIT
 * ========================================================================== */

register_activation_hook(__FILE__, __NAMESPACE__ . '\\on_activate');
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\on_deactivate');

/** On activation: ensure terms + rewrite rules for virtual archive, then flush. */
function on_activate(): void {
	ensure_terms();
	register_virtual_archive_rewrite(); // add rules into memory
	flush_rewrite_rules();              // write rules to .htaccess/nginx + options
}

/** On deactivation: flush to remove our rewrite rules. */
function on_deactivate(): void {
	flush_rewrite_rules();
}

/** Also ensure terms whenever themes switch (keeps sites tidy). */
add_action('after_switch_theme', __NAMESPACE__ . '\\ensure_terms');

/** Public init hooks (rewrite rules must be on `init`). */
add_action('init', __NAMESPACE__ . '\\register_virtual_archive_rewrite');    // rules
add_filter('query_vars', __NAMESPACE__ . '\\register_virtual_archive_qv');   // query var
add_action('template_redirect', __NAMESPACE__ . '\\maybe_render_virtual');   // render

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

/* ========================================================================== *
 * 2) ADMIN POSTS LIST: “Linked” view
 * ========================================================================== */

add_filter('views_edit-post', function(array $views): array {
	$base_url = add_query_arg(['post_type' => 'post'], admin_url('edit.php'));
	$url = add_query_arg('linked', '1', $base_url);

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

add_action('pre_get_posts', function(WP_Query $q) {
	if (!is_admin() || !$q->is_main_query()) return;
	if (!isset($_GET['linked']) || $_GET['linked'] !== '1') return;

	$q->set('post_type', 'post');

	$tax_query = [
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
	];

	$meta_query = [
		'relation' => 'OR',
		[
			'key'     => 'linked_url',
			'compare' => 'EXISTS',
		],
		[
			'key'     => 'linklog_url',
			'compare' => 'EXISTS',
		],
	];

	$q->set('tax_query', $tax_query);
	$q->set('meta_query', $meta_query);
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

	$meta_key_1 = 'linked_url';
	$meta_key_2 = 'linklog_url';

	$sql = "
		SELECT COUNT(DISTINCT p.ID)
		FROM {$wpdb->posts} p
		LEFT JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
		LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
		LEFT JOIN {$wpdb->postmeta} pm1 ON (pm1.post_id = p.ID AND pm1.meta_key = %s)
		LEFT JOIN {$wpdb->postmeta} pm2 ON (pm2.post_id = p.ID AND pm2.meta_key = %s)
		WHERE p.post_type = 'post'
		  AND p.post_status NOT IN ('trash', 'auto-draft')
		  AND (
			   pm1.post_id IS NOT NULL
			OR pm2.post_id IS NOT NULL
			{$cat_clause}
			{$tag_clause}
		  )
	";

	return (int) $wpdb->get_var($wpdb->prepare($sql, $meta_key_1, $meta_key_2));
}

/* ========================================================================== *
 * 3) SHORTCODE: [linked_list_archive]
 * ========================================================================== */

add_shortcode('linked_list_archive', function($atts = []): string {
	$atts = shortcode_atts([
		'posts_per_page' => get_option('posts_per_page'),
		'paged'          => max(1, (int) get_query_var('paged')),
	], $atts, 'linked_list_archive');

	$q = new WP_Query([
		'post_type'      => 'post',
		'posts_per_page' => (int) $atts['posts_per_page'],
		'paged'          => (int) $atts['paged'],
		'ignore_sticky_posts' => true,
		'tax_query'      => [
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
		'meta_query'     => [
			'relation' => 'OR',
			[
				'key'     => 'linked_url',
				'compare' => 'EXISTS',
			],
			[
				'key'     => 'linklog_url',
				'compare' => 'EXISTS',
			],
		],
	]);

	ob_start();

	if ($q->have_posts()) {
		echo '<div class="commentary-archive">';
		while ($q->have_posts()) { $q->the_post();
			echo '<article class="commentary-item">';
			echo '<h2 class="entry-title"><a href="' . esc_url(get_permalink()) . '">' . esc_html(get_the_title()) . '</a></h2>';
			echo '<div class="entry-meta">' . esc_html(get_the_date()) . '</div>';
			echo '<div class="entry-excerpt">' . wp_kses_post(get_the_excerpt()) . '</div>';
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
	echo do_shortcode('[linked_list_archive]');
	echo '</main>';

	get_footer();
	exit;
}
