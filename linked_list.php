<?php
/**
 * Plugin Name:       WP Linked List (Refactored)
 * Description:       Secure, modern refactor of the DF-Style Linked List plugin. Lets your RSS behave like Daring Fireball: link posts in feeds point to the external URL and add glyphs around titles/content. Includes a safe “first line link” capture, optional custom category handling, and (legacy) Twitter Tools glyph support.
 * Version:           3.0.0
 * Requires PHP:      8.1
 * Requires at least: 6.3
 * Tested up to:      6.7
 * Author:            Original by Yinjie Soon; fork by Kevin Dayton
 * License:           MIT license
 * Text Domain:       wp-linked-list
 */

declare(strict_types=1);

namespace WP_Linked_List;

use DOMDocument;
use WP_Post;
use WP_Term;

if (!defined('ABSPATH')) {
	exit;
}

final class Plugin {
	public const VERSION = '3.0.0';

	// Option keys
	private const OPT_LINK_GOES_TO                 = 'dfll_link_goes_to';
	private const OPT_GLYPH_AFTER_POST             = 'dfll_glyph_after_post';
	private const OPT_GLYPH_AFTER_POST_TEXT        = 'dfll_glyph_after_post_text';
	private const OPT_GLYPH_BEFORE_LINK_TITLE      = 'dfll_glyph_before_link_title';
	private const OPT_GLYPH_BEFORE_LINK_TITLE_TEXT = 'dfll_glyph_before_link_title_text';
	private const OPT_GLYPH_AFTER_LINK_TITLE       = 'dfll_glyph_after_link_title';
	private const OPT_GLYPH_AFTER_LINK_TITLE_TEXT  = 'dfll_glyph_after_link_title_text';
	private const OPT_GLYPH_BEFORE_BLOG_TITLE      = 'dfll_glyph_before_blog_title';
	private const OPT_GLYPH_BEFORE_BLOG_TITLE_TEXT = 'dfll_glyph_before_blog_title_text';
	private const OPT_USE_FIRST_LINK               = 'dfll_use_first_link';
	private const OPT_TW_GLYPH_BEFORE_LINKED       = 'dfll_twitter_glyph_before_linked_list';
	private const OPT_TW_GLYPH_BEFORE_NON_LINKED   = 'dfll_twitter_glyph_before_non_linked_list';
	private const OPT_CUSTOM_CAT_NAME              = 'dfll_custom_category_name';
	private const OPT_CUSTOM_CAT_DESC              = 'dfll_custom_category_desc';
	private const OPT_USE_CUSTOM_CAT               = 'dfll_use_custom_category';
	private const OPT_CUSTOM_CAT_EXCLUDE           = 'dfll_custom_category_exclude';
	private const OPT_CUSTOM_CAT_HIDE_NAV          = 'dfll_custom_category_hide_nav';

	// Meta key
	private const META_LINKED_LIST_URL = 'linked_list_url';

	// Messages in category description
	private const DELETE_CAT_DEFAULT_DESC = '&#9733; Created by DF-Style Linked List Plugin.';
	private const DELETE_CAT_WARNING      = '<strong>NOTE</strong>: If you delete this, it will disable the link list custom category options.';

	/** Bootstrap */
	public static function init(): void {
		// Activation defaults
		register_activation_hook(__FILE__, [self::class, 'activate']);

		// Admin
		add_action('admin_menu', [self::class, 'register_settings_page']);
		add_action('admin_init', [self::class, 'register_settings']);

		// Public/Feeds
		add_filter('the_permalink_rss', [self::class, 'filter_rss_permalink'], 100);
		add_filter('the_content_feed', [self::class, 'filter_feed_content']);
		add_filter('the_excerpt_rss', [self::class, 'filter_feed_content']);
		add_filter('the_title_rss', [self::class, 'filter_feed_title'], 10, 1);

		// Post meta helpers
		add_filter('content_save_pre', [self::class, 'maybe_capture_first_line_link']);
		add_action('save_post', [self::class, 'persist_captured_link_meta'], 10, 2);

		// Category handling on save & delete
		add_action('wp_insert_post', [self::class, 'maybe_adjust_post_categories']);
		add_action('delete_term', [self::class, 'on_delete_term'], 10, 4);

		// Adjacent posts SQL constraints (safe)
		add_filter('get_previous_post_where', [self::class, 'adjacent_where']);
		add_filter('get_next_post_where', [self::class, 'adjacent_where']);
		add_filter('get_previous_post_join', [self::class, 'adjacent_join']);
		add_filter('get_next_post_join', [self::class, 'adjacent_join']);

		// Settings quick link
		add_filter('plugin_action_links', [self::class, 'settings_link'], 10, 2);

		// (Legacy) Twitter Tools integration if present
		add_filter('aktt_do_tweet', [self::class, 'twitter_tools_glyph'], 15, 2);
	}

	/* ---------------------------
	 * Activation defaults
	 * --------------------------*/
	public static function activate(): void {
		update_option(self::OPT_LINK_GOES_TO, true);
		update_option(self::OPT_GLYPH_AFTER_POST, true);
		update_option(self::OPT_GLYPH_AFTER_POST_TEXT, '&#9733;');
		update_option(self::OPT_GLYPH_BEFORE_LINK_TITLE, '');
		update_option(self::OPT_GLYPH_BEFORE_LINK_TITLE_TEXT, '');
		update_option(self::OPT_GLYPH_AFTER_LINK_TITLE, '');
		update_option(self::OPT_GLYPH_AFTER_LINK_TITLE_TEXT, '');
		update_option(self::OPT_GLYPH_BEFORE_BLOG_TITLE, true);
		update_option(self::OPT_GLYPH_BEFORE_BLOG_TITLE_TEXT, '&#9733;');
		update_option(self::OPT_USE_FIRST_LINK, false);
		update_option(self::OPT_TW_GLYPH_BEFORE_LINKED, '');
		update_option(self::OPT_TW_GLYPH_BEFORE_NON_LINKED, '');
	}

	/* ---------------------------
	 * Utilities / getters
	 * --------------------------*/
	private static function get_option_bool(string $key): bool {
		return (bool) get_option($key);
	}

	private static function get_option_string(string $key): string {
		return (string) get_option($key);
	}

	/** Return the glyph that links back to the post permalink */
	public static function get_permalink_glyph(?int $post_id = null): string {
		$post_id = $post_id ?? get_the_ID();
		if (!$post_id) {
			return '';
		}
		$title = esc_attr(get_the_title($post_id));
		$glyph = self::get_glyph(); // may contain entities; output as-is
		$perma = esc_url(get_permalink($post_id));
		return sprintf(
			'<a href="%s" rel="bookmark" title="%s" class="glyph">%s</a>',
			$perma,
			sprintf(esc_attr__('Permanent link to \'%s\'', 'wp-linked-list'), $title),
			$glyph
		);
	}

	/** Glyph text (after post) */
	public static function get_glyph(): string {
		// Stored as admin entered (often an entity). Output unescaped on purpose.
		return self::get_option_string(self::OPT_GLYPH_AFTER_POST_TEXT);
	}

	/** Determine if a post is a linked list item */
	public static function is_linked_list(?int $post_id = null): bool {
		$post_id = $post_id ?? get_the_ID();
		if (!$post_id) {
			return false;
		}
		$url = get_post_meta($post_id, self::META_LINKED_LIST_URL, true);
		return !empty($url);
	}

	/** Get the linked list URL */
	public static function get_link_url(?int $post_id = null): string {
		$post_id = $post_id ?? get_the_ID();
		if (!$post_id) {
			return '';
		}
		return (string) get_post_meta($post_id, self::META_LINKED_LIST_URL, true);
	}

	/* ---------------------------
	 * Feed filters
	 * --------------------------*/
	public static function filter_rss_permalink(string $value): string {
		// If enabled and is a link post, RSS permalink should point to external URL
		if (self::get_option_bool(self::OPT_LINK_GOES_TO)) {
			$post = get_post();
			if ($post instanceof WP_Post && self::is_linked_list($post->ID)) {
				$link = self::get_link_url($post->ID);
				if (!empty($link)) {
					return esc_url($link);
				}
			}
		}
		return $value;
	}

	public static function filter_feed_content(string $content): string {
		$post = get_post();
		if (!$post instanceof WP_Post) {
			return $content;
		}
		if (is_feed() && self::is_linked_list($post->ID) && self::get_option_bool(self::OPT_GLYPH_AFTER_POST)) {
			$content .= "\n<p>" . self::get_permalink_glyph($post->ID) . '</p>' . "\n";
		}
		return $content;
	}

	public static function filter_feed_title(string $title): string {
		$post = get_post();
		$is_link = $post instanceof WP_Post ? self::is_linked_list($post->ID) : false;

		if (!$is_link && self::get_option_bool(self::OPT_GLYPH_BEFORE_BLOG_TITLE)) {
			// Prefix for normal blog posts (not link posts)
			$prefix = self::get_option_string(self::OPT_GLYPH_BEFORE_BLOG_TITLE_TEXT);
			if ($prefix !== '') {
				$title = self::entity_to_numeric($prefix) . ' ' . $title;
			}
		} elseif ($is_link) {
			// Prefix for link post titles
			if (self::get_option_bool(self::OPT_GLYPH_BEFORE_LINK_TITLE)) {
				$pre = self::get_option_string(self::OPT_GLYPH_BEFORE_LINK_TITLE_TEXT);
				if ($pre !== '') {
					$title = self::entity_to_numeric($pre) . ' ' . $title;
				}
			}
			// Suffix for link post titles
			if (self::get_option_bool(self::OPT_GLYPH_AFTER_LINK_TITLE)) {
				$post = self::get_option_string(self::OPT_GLYPH_AFTER_LINK_TITLE_TEXT);
				if ($post !== '') {
					$title .= ' ' . self::entity_to_numeric($post);
				}
			}
		}

		return $title;
	}

	/** Convert named/entity strings to numeric entities (safe for RSS consumers) */
	private static function entity_to_numeric(string $str): string {
		// WordPress core has ent2ncr, but it is pluggable; fall back if missing
		if (function_exists('ent2ncr')) {
			return (string) ent2ncr($str);
		}
		return $str;
	}

	/* ---------------------------
	 * "First line link" capture
	 * --------------------------*/
	/** If enabled, capture first-line anchor’s href and drop that line from content before save */
	public static function maybe_capture_first_line_link(string $post_content): string {
		if (!self::get_option_bool(self::OPT_USE_FIRST_LINK)) {
			return $post_content;
		}

		$lines = preg_split('/\R/u', $post_content) ?: [$post_content];
		if (empty($lines)) {
			return $post_content;
		}

		// Accepts: optional <p>, <a ...>...</a> followed by a period, optional </p>, nothing else on that line
		$pattern = '/^\s*(?:<p>)?\s*<a\s+[^>]*href=(["\'])(?<href>[^"\']+)\1[^>]*>.*?<\/a>\.\s*(?:<\/p>)?\s*$/i';

		if (preg_match($pattern, $lines[0], $m) === 1) {
			$link = $m['href'] ?? '';
			if ($link !== '') {
				// Store temporarily in a runtime cache keyed to current user (no globals)
				self::runtime()->captured_link = $link;

				// Remove the first line from the content
				$post_content = implode("\n", array_slice($lines, 1));
			}
		}

		return $post_content;
	}

	/** Persist any captured link into post meta */
	public static function persist_captured_link_meta(int $post_id, WP_Post $post): void {
		$runtime = self::runtime();
		$link    = $runtime->captured_link ?? null;

		if (!empty($link)) {
			// Add only if meta not already set
			if (!get_post_meta($post_id, self::META_LINKED_LIST_URL, true)) {
				add_post_meta($post_id, self::META_LINKED_LIST_URL, esc_url_raw($link), true);
			}
			$runtime->captured_link = null; // clear
		}
	}

	/* ---------------------------
	 * Category handling
	 * --------------------------*/
	public static function maybe_adjust_post_categories(int $post_id): void {
		// Avoid autosaves/revisions
		if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
			return;
		}

		$post = get_post($post_id);
		if (!$post instanceof WP_Post || $post->post_type !== 'post') {
			return;
		}

		$use_custom = self::get_option_bool(self::OPT_USE_CUSTOM_CAT);
		$cat_name   = trim(self::get_option_string(self::OPT_CUSTOM_CAT_NAME));

		if (!$use_custom || $cat_name === '') {
			return;
		}

		$cat = get_term_by('name', $cat_name, 'category');
		if (!$cat instanceof WP_Term) {
			$inserted = wp_insert_term(
				$cat_name,
				'category',
				[
					'slug'        => sanitize_title($cat_name),
					'description' => self::DELETE_CAT_DEFAULT_DESC . '&nbsp;&nbsp;' . self::DELETE_CAT_WARNING,
				]
			);
			if (is_wp_error($inserted)) {
				return;
			}
			$cat_id = (int) ($inserted['term_id'] ?? 0);
		} else {
			$cat_id = (int) $cat->term_id;
		}

		if (self::is_linked_list($post_id)) {
			// Link posts go ONLY to the custom category (per original behavior)
			wp_set_post_categories($post_id, [$cat_id], false);
		} else {
			// Ensure non-link posts do NOT have the custom category
			$current = wp_get_post_categories($post_id);
			$current = array_map('intval', (array) $current);
			$filtered = array_values(array_filter($current, static fn (int $id): bool => $id !== $cat_id));
			wp_set_post_categories($post_id, $filtered, false);
		}
	}

	public static function on_delete_term(int $term, int $tt_id, string $taxonomy, $deleted_term): void {
		if ($taxonomy !== 'category' || !is_object($deleted_term)) {
			return;
		}
		$cat_name = self::get_option_string(self::OPT_CUSTOM_CAT_NAME);
		if ($deleted_term->name === $cat_name) {
			update_option(self::OPT_USE_CUSTOM_CAT, false);
		}
	}

	/* ---------------------------
	 * Adjacent posts constraints (SQL but safely prepared)
	 * --------------------------*/
	public static function adjacent_where(string $where): string {
		global $wpdb;

		$use_custom = self::get_option_bool(self::OPT_USE_CUSTOM_CAT);
		$exclude    = self::get_option_bool(self::OPT_CUSTOM_CAT_EXCLUDE);
		$hide_nav   = self::get_option_bool(self::OPT_CUSTOM_CAT_HIDE_NAV);
		$cat_name   = self::get_option_string(self::OPT_CUSTOM_CAT_NAME);

		$post = get_post();
		$is_link = $post instanceof WP_Post ? self::is_linked_list($post->ID) : false;

		if ($use_custom && $hide_nav && $is_link) {
			// Disable navigation entirely for link posts when hide_nav is on
			$where .= ' AND 1 = 0 ';
			return $where;
		}

		if ($use_custom && $exclude && $cat_name !== '') {
			// For link posts when both exclude+hide_nav are on, restrict to custom category
			if ($is_link && $hide_nav) {
				$where .= $wpdb->prepare(
					" AND {$wpdb->terms}.name = %s AND {$wpdb->term_taxonomy}.taxonomy = 'category' ",
					$cat_name
				);
			} else {
				// For non-link posts, exclude the custom category
				$where .= $wpdb->prepare(
					" AND {$wpdb->terms}.name != %s AND {$wpdb->term_taxonomy}.taxonomy = 'category' ",
					$cat_name
				);
			}
		}

		return $where;
	}

	public static function adjacent_join(string $join): string {
		$use_custom = self::get_option_bool(self::OPT_USE_CUSTOM_CAT);
		$exclude    = self::get_option_bool(self::OPT_CUSTOM_CAT_EXCLUDE);

		if ($use_custom && $exclude) {
			global $wpdb;
			$join .= " LEFT JOIN {$wpdb->term_relationships} ON {$wpdb->term_relationships}.object_id = p.ID";
			$join .= " LEFT JOIN {$wpdb->term_taxonomy} ON {$wpdb->term_taxonomy}.term_taxonomy_id = {$wpdb->term_relationships}.term_taxonomy_id";
			$join .= " LEFT JOIN {$wpdb->terms} ON {$wpdb->term_taxonomy}.term_id = {$wpdb->terms}.term_id";
		}

		return $join;
	}

	/* ---------------------------
	 * Twitter Tools integration (legacy)
	 * --------------------------*/
	public static function twitter_tools_glyph(object $tweet, int $post_id): object {
		// Respect options; only decorate text, do not change URLs
		$link = (string) get_post_meta($post_id, self::META_LINKED_LIST_URL, true);

		if ($link === '') {
			if (self::get_option_bool(self::OPT_TW_GLYPH_BEFORE_NON_LINKED)) {
				$tweet->tw_text = self::get_glyph() . ' ' . $tweet->tw_text;
			}
		} else {
			if (self::get_option_bool(self::OPT_TW_GLYPH_BEFORE_LINKED)) {
				$pre = self::get_option_string(self::OPT_GLYPH_BEFORE_LINK_TITLE_TEXT);
				if ($pre !== '') {
					$tweet->tw_text = $pre . ' ' . $tweet->tw_text;
				}
			}
		}

		return $tweet;
	}

	/* ---------------------------
	 * Settings UI
	 * --------------------------*/
	public static function register_settings_page(): void {
		add_options_page(
			__('DF-Style Linked List', 'wp-linked-list'),
			__('DF-Style Linked List', 'wp-linked-list'),
			'manage_options',
			'dfll',
			[self::class, 'render_settings_page']
		);
	}

	public static function register_settings(): void {
		// Sanitizers
		$cb = static fn($v): string => $v ? 'on' : '';
		$text_raw = static fn($v): string => is_string($v) ? wp_kses_post($v) : '';
		$text_attr = static fn($v): string => is_string($v) ? sanitize_text_field($v) : '';
		$url_text = static fn($v): string => is_string($v) ? esc_url_raw($v) : '';

		// Register each setting with appropriate sanitizer
		register_setting('dfll_options', self::OPT_LINK_GOES_TO, ['sanitize_callback' => $cb]);
		register_setting('dfll_options', self::OPT_GLYPH_AFTER_POST, ['sanitize_callback' => $cb]);
		register_setting('dfll_options', self::OPT_GLYPH_AFTER_POST_TEXT, ['sanitize_callback' => $text_raw]);

		register_setting('dfll_options', self::OPT_GLYPH_BEFORE_LINK_TITLE, ['sanitize_callback' => $cb]);
		register_setting('dfll_options', self::OPT_GLYPH_BEFORE_LINK_TITLE_TEXT, ['sanitize_callback' => $text_attr]);

		register_setting('dfll_options', self::OPT_GLYPH_AFTER_LINK_TITLE, ['sanitize_callback' => $cb]);
		register_setting('dfll_options', self::OPT_GLYPH_AFTER_LINK_TITLE_TEXT, ['sanitize_callback' => $text_attr]);

		register_setting('dfll_options', self::OPT_GLYPH_BEFORE_BLOG_TITLE, ['sanitize_callback' => $cb]);
		register_setting('dfll_options', self::OPT_GLYPH_BEFORE_BLOG_TITLE_TEXT, ['sanitize_callback' => $text_attr]);

		register_setting('dfll_options', self::OPT_USE_FIRST_LINK, ['sanitize_callback' => $cb]);
		register_setting('dfll_options', self::OPT_TW_GLYPH_BEFORE_LINKED, ['sanitize_callback' => $cb]);
		register_setting('dfll_options', self::OPT_TW_GLYPH_BEFORE_NON_LINKED, ['sanitize_callback' => $cb]);

		register_setting('dfll_options', self::OPT_CUSTOM_CAT_NAME, [
			'sanitize_callback' => [self::class, 'sanitize_custom_category_name'],
		]);
		register_setting('dfll_options', self::OPT_CUSTOM_CAT_DESC, [
			'sanitize_callback' => [self::class, 'sanitize_custom_category_desc'],
		]);
		register_setting('dfll_options', self::OPT_USE_CUSTOM_CAT, [
			'sanitize_callback' => [self::class, 'sanitize_custom_category_toggle'],
		]);
		register_setting('dfll_options', self::OPT_CUSTOM_CAT_EXCLUDE, ['sanitize_callback' => $cb]);
		register_setting('dfll_options', self::OPT_CUSTOM_CAT_HIDE_NAV, ['sanitize_callback' => $cb]);

		// Sections & fields
		add_settings_section('dfll_main', __('Linked List Properties', 'wp-linked-list'), function (): void {
			echo '<p>' . esc_html__('Defines RSS behavior of linked list posts.', 'wp-linked-list') . '</p>';
		}, 'dfll');

		self::add_checkbox(self::OPT_LINK_GOES_TO, __('RSS link goes to linked item', 'wp-linked-list'), 'dfll_main', 'dfll',
			__('If enabled, the <link> of a linked list item in the feed points to the external URL rather than your post permalink.', 'wp-linked-list')
		);

		self::add_checkbox(self::OPT_GLYPH_AFTER_POST, __('Insert permalink after post (in feeds)', 'wp-linked-list'), 'dfll_main', 'dfll',
			sprintf(
				/* translators: %s is an example glyph */
				esc_html__('Appends a back-to-post permalink glyph at the end of the feed content (e.g. %s).', 'wp-linked-list'),
				'&#9733;'
			)
		);

		self::add_text(self::OPT_GLYPH_AFTER_POST_TEXT, '', 'dfll_main', 'dfll', [
			'label' => __('Text for permalink glyph', 'wp-linked-list'),
			'placeholder' => '&#9733;',
		]);

		add_settings_section('dfll_main2', __('Blog Post Properties', 'wp-linked-list'), function (): void {
			echo '<p>' . esc_html__('Defines RSS title decoration for non-link (regular) posts.', 'wp-linked-list') . '</p>';
		}, 'dfll');

		self::add_checkbox(self::OPT_GLYPH_BEFORE_BLOG_TITLE, __('Highlight blog post titles (prefix)', 'wp-linked-list'), 'dfll_main2', 'dfll');
		self::add_text(self::OPT_GLYPH_BEFORE_BLOG_TITLE_TEXT, '', 'dfll_main2', 'dfll', [
			'label' => __('Prefix text for blog titles', 'wp-linked-list'),
			'placeholder' => '&#9733;',
		]);

		add_settings_section('dfll_main3', __('Linking From Posts', 'wp-linked-list'), function (): void {
			echo '<p>' . esc_html__('Optionally capture the first-line anchor as the linked URL (Press This style).', 'wp-linked-list') . '</p>';
		}, 'dfll');

		self::add_checkbox(self::OPT_USE_FIRST_LINK, __('Use first link in post as the linked URL', 'wp-linked-list'), 'dfll_main3', 'dfll',
			__('Expects the first line to be only an anchor and end with a period. That line is removed from the content upon save.', 'wp-linked-list')
		);

		add_settings_section('dfll_main4', __('Custom Category', 'wp-linked-list'), function (): void {
			echo '<p>' . esc_html__('Create/use a custom category for link posts, and control navigation behavior.', 'wp-linked-list') . '</p>';
		}, 'dfll');

		self::add_checkbox(self::OPT_USE_CUSTOM_CAT, __('Use custom category for link posts', 'wp-linked-list'), 'dfll_main4', 'dfll');
		self::add_text(self::OPT_CUSTOM_CAT_NAME, '', 'dfll_main4', 'dfll', [
			'label' => __('Category name', 'wp-linked-list'),
			'placeholder' => __('Link List Items', 'wp-linked-list'),
		]);
		self::add_text(self::OPT_CUSTOM_CAT_DESC, '', 'dfll_main4', 'dfll', [
			'label' => __('Category description', 'wp-linked-list'),
			'placeholder' => self::DELETE_CAT_DEFAULT_DESC,
		]);
		self::add_checkbox(self::OPT_CUSTOM_CAT_EXCLUDE, __('Exclude custom category from adjacent nav for non-link posts', 'wp-linked-list'), 'dfll_main4', 'dfll');
		self::add_checkbox(self::OPT_CUSTOM_CAT_HIDE_NAV, __('Hide adjacent navigation for link posts in custom category', 'wp-linked-list'), 'dfll_main4', 'dfll');
	}

	private static function add_checkbox(string $option, string $label, string $section, string $page, string $help = ''): void {
		add_settings_field(
			$option,
			esc_html($label),
			function () use ($option, $help): void {
				$checked = self::get_option_bool($option) ? ' checked' : '';
				printf('<label><input type="checkbox" name="%1$s" value="on"%2$s /> %3$s</label>',
					esc_attr($option),
					$checked,
					$help ? esc_html($help) : ''
				);
			},
			$page,
			$section
		);
	}

	/** @param array{label?:string,placeholder?:string} $args */
	private static function add_text(string $option, string $desc, string $section, string $page, array $args = []): void {
		add_settings_field(
			$option,
			isset($args['label']) ? esc_html($args['label']) : '',
			function () use ($option, $args, $desc): void {
				$val = self::get_option_string($option);
				printf(
					'<input type="text" class="regular-text" name="%1$s" value="%2$s" placeholder="%3$s" />',
					esc_attr($option),
					esc_attr($val),
					isset($args['placeholder']) ? esc_attr($args['placeholder']) : ''
				);
				if ($desc !== '') {
					echo '<p class="description">' . esc_html($desc) . '</p>';
				}
			},
			$page,
			$section
		);
	}

	public static function render_settings_page(): void {
		if (!current_user_can('manage_options')) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__('DF-Style Linked List', 'wp-linked-list'); ?></h1>
			<p><em><?php echo esc_html__('When entering symbols, prefer HTML entities (e.g., &#9733;) to avoid encoding issues.', 'wp-linked-list'); ?></em></p>
			<form action="options.php" method="post">
				<?php
				settings_fields('dfll_options');
				do_settings_sections('dfll');
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/* ---------------------------
	 * Settings sanitizers
	 * --------------------------*/
	public static function sanitize_custom_category_name(string $value): string {
		$value    = sanitize_text_field($value);
		$old_name = (string) get_option(self::OPT_CUSTOM_CAT_NAME);

		if ($value === '') {
			return '';
		}

		if ($value !== $old_name) {
			$slug = sanitize_title($value);
			$existing = get_term_by('name', $old_name, 'category');
			if ($existing instanceof WP_Term) {
				wp_update_term((int) $existing->term_id, 'category', [
					'name' => $value,
					'slug' => $slug,
				]);
			} else {
				wp_insert_term($value, 'category', [
					'slug'        => $slug,
					'description' => self::DELETE_CAT_DEFAULT_DESC . '&nbsp;&nbsp;' . self::DELETE_CAT_WARNING,
				]);
			}
		}

		return $value;
	}

	public static function sanitize_custom_category_desc(string $value): string {
		$value = wp_kses_post($value);
		$name  = (string) get_option(self::OPT_CUSTOM_CAT_NAME);
		$cat   = get_term_by('name', $name, 'category');

		if ($cat instanceof WP_Term) {
			wp_update_term((int) $cat->term_id, 'category', [
				'description' => $value . '&nbsp;&nbsp;' . self::DELETE_CAT_WARNING,
			]);
		}
		return $value;
	}

	public static function sanitize_custom_category_toggle($value): string {
		$value = $value ? 'on' : '';
		if ($value === '') {
			// If turning off, do not delete the term automatically; original plugin removed it,
			// but silently deleting categories is risky. We’ll leave it intact.
		}
		return $value;
	}

	/* ---------------------------
	 * Plugin row settings link
	 * --------------------------*/
	public static function settings_link(array $links, string $file): array {
		$plugin_file = plugin_basename(__FILE__);
		if (basename($file) === basename($plugin_file)) {
			$settings = '<a href="' . esc_url(admin_url('options-general.php?page=dfll')) . '">' . esc_html__('Settings', 'wp-linked-list') . '</a>';
			array_unshift($links, $settings);
		}
		return $links;
	}

	/* ---------------------------
	 * Small runtime scratchpad (no globals)
	 * --------------------------*/
	private static function runtime(): object {
		static $bag;
		if (!is_object($bag)) {
			$bag = (object) [];
		}
		return $bag;
	}
}

Plugin::init();

/* ----------------------------------------------------------
 * Public template helpers (for theme developers)
 * --------------------------------------------------------*/

/**
 * Echo the permalink glyph anchor (returns to the post).
 */
function the_permalink_glyph(?int $post_id = null): void {
	echo Plugin::get_permalink_glyph($post_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Get the permalink glyph anchor (returns to the post).
 */
function get_the_permalink_glyph(?int $post_id = null): string {
	return Plugin::get_permalink_glyph($post_id);
}

/**
 * Echo the external linked URL for the current (or given) post.
 */
function the_linked_list_link(?int $post_id = null): void {
	echo esc_url(Plugin::get_link_url($post_id));
}

/**
 * Get the external linked URL for the current (or given) post.
 */
function get_the_linked_list_link(?int $post_id = null): string {
	return Plugin::get_link_url($post_id);
}

/**
 * Get the configured glyph text (typically an HTML entity).
 * Returned unescaped by design for glyph correctness.
 */
function get_glyph(): string { // Back-compat function name
	return Plugin::get_glyph();
}

/**
 * Is the current (or given) post a linked list item?
 */
function is_linked_list(?int $post_id = null): bool { // Back-compat function name
	return Plugin::is_linked_list($post_id);
}
