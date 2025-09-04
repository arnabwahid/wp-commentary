<?php
/**
 * Plugin Name:       LinkLog – Daring Fireball–style link posts
 * Description:       A zero-theme-changes link blogging plugin. Registers a "linklog" post type with a Link URL field, supports Gutenberg sidebar panel, per-post overrides, optional on-site ↩︎ permalink glyph, UTM auto-append, and DF-style feed behavior.
 * Version:           1.1.0
 * Requires at least: 6.3
 * Tested up to:      6.7
 * Requires PHP:      8.1
 * Author:            (Your Name)
 * License:           GPL-2.0-or-later
 * Text Domain:       linklog
 */

declare(strict_types=1);

namespace LinkLog;

use WP_Post;

if (!defined('ABSPATH')) {
	exit;
}

final class Plugin {
	public const VERSION = '1.1.0';

	// Meta keys (registered with REST for Gutenberg)
	private const META_URL            = 'linklog_url';             // string
	private const META_SKIP_REDIRECT  = 'linklog_skip_redirect';   // bool
	private const META_SKIP_REWRITE   = 'linklog_skip_rewrite';    // bool

	// Options
	private const OPT_REDIRECT_SINGLES    = 'linklog_redirect_singles';      // bool
	private const OPT_REWRITE_PERMALINKS  = 'linklog_rewrite_permalinks';    // bool
	private const OPT_FEED_GLYPH_ENABLE   = 'linklog_feed_glyph_enable';     // bool
	private const OPT_FEED_GLYPH_TEXT     = 'linklog_feed_glyph_text';       // string
	private const OPT_SITE_GLYPH_ENABLE   = 'linklog_site_glyph_enable';     // bool (↩︎ in on-site content)
	private const OPT_SITE_GLYPH_TEXT     = 'linklog_site_glyph_text';       // string

	// UTM options
	private const OPT_UTM_ENABLE          = 'linklog_utm_enable';            // bool
	private const OPT_UTM_PRESERVE        = 'linklog_utm_preserve_existing'; // bool
	private const OPT_UTM_SOURCE          = 'linklog_utm_source';
	private const OPT_UTM_MEDIUM          = 'linklog_utm_medium';
	private const OPT_UTM_CAMPAIGN        = 'linklog_utm_campaign';
	private const OPT_UTM_TERM            = 'linklog_utm_term';
	private const OPT_UTM_CONTENT         = 'linklog_utm_content';

	public static function init(): void {
		// Activation
		register_activation_hook(__FILE__, [self::class, 'activate']);

		// Core
		add_action('init', [self::class, 'register_post_type']);
		add_action('init', [self::class, 'register_meta']);

		// Settings UI
		add_action('admin_menu', [self::class, 'register_settings_page']);
		add_action('admin_init', [self::class, 'register_settings']);
		add_filter('plugin_action_links', [self::class, 'settings_link'], 10, 2);

		// Editor UI: Gutenberg sidebar (when available) or classic metabox fallback
		add_action('enqueue_block_editor_assets', [self::class, 'enqueue_block_editor_assets']);
		add_action('add_meta_boxes', [self::class, 'maybe_add_classic_metabox']);
		add_action('save_post_linklog', [self::class, 'save_classic_metabox'], 10, 2);

		// Front-end behavior (theme-agnostic)
		add_filter('post_type_link', [self::class, 'filter_post_type_link'], 10, 2);
		add_action('template_redirect', [self::class, 'maybe_redirect_single']);
		add_filter('the_content', [self::class, 'inject_site_glyph'], 20);

		// Feeds
		add_filter('the_permalink_rss', [self::class, 'filter_rss_permalink'], 100);
		add_filter('the_content_feed', [self::class, 'filter_feed_content']);
		add_filter('the_excerpt_rss', [self::class, 'filter_feed_content']);
	}

	/* Activation defaults */
	public static function activate(): void {
		add_option(self::OPT_REDIRECT_SINGLES, 'on');
		add_option(self::OPT_REWRITE_PERMALINKS, 'on');

		add_option(self::OPT_FEED_GLYPH_ENABLE, 'on');
		add_option(self::OPT_FEED_GLYPH_TEXT, '&#9733;'); // ★

		add_option(self::OPT_SITE_GLYPH_ENABLE, 'on');
		add_option(self::OPT_SITE_GLYPH_TEXT, '&#8617;'); // ↩︎

		add_option(self::OPT_UTM_ENABLE, '');
		add_option(self::OPT_UTM_PRESERVE, 'on');
		add_option(self::OPT_UTM_SOURCE, 'rss');
		add_option(self::OPT_UTM_MEDIUM, 'linklog');
		add_option(self::OPT_UTM_CAMPAIGN, '');
		add_option(self::OPT_UTM_TERM, '');
		add_option(self::OPT_UTM_CONTENT, '');

		self::register_post_type();
		flush_rewrite_rules();
	}

	/* CPT + Meta */
	public static function register_post_type(): void {
		register_post_type('linklog', [
			'labels' => [
				'name'               => __('Linklog', 'linklog'),
				'singular_name'      => __('Linklog', 'linklog'),
				'add_new'            => __('Add Linklog', 'linklog'),
				'add_new_item'       => __('Add New Linklog', 'linklog'),
				'edit_item'          => __('Edit Linklog', 'linklog'),
				'new_item'           => __('New Linklog', 'linklog'),
				'view_item'          => __('View Linklog', 'linklog'),
				'search_items'       => __('Search Linklog', 'linklog'),
				'not_found'          => __('No linklog posts found', 'linklog'),
				'not_found_in_trash' => __('No linklog posts found in Trash', 'linklog'),
				'all_items'          => __('All Linklog Posts', 'linklog'),
			],
			'description'   => __('Daring Fireball–style external link posts', 'linklog'),
			'public'        => true,
			'show_ui'       => true,
			'show_in_menu'  => true,
			'show_in_rest'  => true,
			'has_archive'   => true,
			'hierarchical'  => false,
			'menu_icon'     => 'dashicons-external',
			'supports'      => ['title', 'editor', 'excerpt', 'author', 'thumbnail', 'comments', 'custom-fields'],
			'rewrite'       => ['slug' => 'linklog', 'with_front' => false],
			'map_meta_cap'  => true,
		]);
	}

	public static function register_meta(): void {
		register_post_meta('linklog', self::META_URL, [
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'auth_callback'     => fn(): bool => current_user_can('edit_posts'),
			'sanitize_callback' => fn($v): string => esc_url_raw((string) $v),
			'default'           => '',
			'description'       => __('External URL for this linklog post', 'linklog'),
		]);

		foreach ([self::META_SKIP_REDIRECT, self::META_SKIP_REWRITE] as $metaBool) {
			register_post_meta('linklog', $metaBool, [
				'type'              => 'boolean',
				'single'            => true,
				'show_in_rest'      => true,
				'auth_callback'     => fn(): bool => current_user_can('edit_posts'),
				'sanitize_callback' => fn($v): bool => (bool) $v,
				'default'           => false,
			]);
		}
	}

	/* Settings UI */
	public static function register_settings_page(): void {
		add_options_page(
			__('LinkLog Settings', 'linklog'),
			__('LinkLog', 'linklog'),
			'manage_options',
			'linklog',
			[self::class, 'render_settings_page']
		);
	}

	public static function register_settings(): void {
		$cb_bool = static fn($v): string => $v ? 'on' : '';
		$cb_text = static fn($v): string => is_string($v) ? wp_kses_post($v) : '';

		// Behavior
		register_setting('linklog_settings', self::OPT_REDIRECT_SINGLES, ['sanitize_callback' => $cb_bool]);
		register_setting('linklog_settings', self::OPT_REWRITE_PERMALINKS, ['sanitize_callback' => $cb_bool]);

		// Feeds + site glyphs
		register_setting('linklog_settings', self::OPT_FEED_GLYPH_ENABLE, ['sanitize_callback' => $cb_bool]);
		register_setting('linklog_settings', self::OPT_FEED_GLYPH_TEXT, ['sanitize_callback' => $cb_text]);

		register_setting('linklog_settings', self::OPT_SITE_GLYPH_ENABLE, ['sanitize_callback' => $cb_bool]);
		register_setting('linklog_settings', self::OPT_SITE_GLYPH_TEXT, ['sanitize_callback' => $cb_text]);

		// UTM
		register_setting('linklog_settings', self::OPT_UTM_ENABLE, ['sanitize_callback' => $cb_bool]);
		register_setting('linklog_settings', self::OPT_UTM_PRESERVE, ['sanitize_callback' => $cb_bool]);
		foreach ([self::OPT_UTM_SOURCE, self::OPT_UTM_MEDIUM, self::OPT_UTM_CAMPAIGN, self::OPT_UTM_TERM, self::OPT_UTM_CONTENT] as $opt) {
			register_setting('linklog_settings', $opt, ['sanitize_callback' => $cb_text]);
		}

		// Sections
		add_settings_section('linklog_main', __('Behavior', 'linklog'), fn() =>
			print '<p>' . esc_html__('Control rewrite/redirect behavior for linklog posts.', 'linklog') . '</p>', 'linklog');

		self::add_checkbox(self::OPT_REWRITE_PERMALINKS, __('Make linklog permalinks across the site point to external URL', 'linklog'));
		self::add_checkbox(self::OPT_REDIRECT_SINGLES, __('Redirect single linklog posts to external URL', 'linklog'));

		add_settings_section('linklog_siteglyph', __('On-site Permalink Glyph', 'linklog'), fn() =>
			print '<p>' . esc_html__('Append a ↩︎ permalink glyph to linklog content on the site (theme-agnostic).', 'linklog') . '</p>', 'linklog');

		self::add_checkbox(self::OPT_SITE_GLYPH_ENABLE, __('Enable on-site permalink glyph', 'linklog'));
		self::add_text(self::OPT_SITE_GLYPH_TEXT, __('Glyph text', 'linklog'), '&#8617;');

		add_settings_section('linklog_feed', __('Feeds', 'linklog'), fn() =>
			print '<p>' . esc_html__('Daring Fireball–style feed behavior.', 'linklog') . '</p>', 'linklog');

		self::add_checkbox(self::OPT_FEED_GLYPH_ENABLE, __('Append "back to post" glyph at end of feed content', 'linklog'));
		self::add_text(self::OPT_FEED_GLYPH_TEXT, __('Feed glyph text', 'linklog'), '&#9733;');

		add_settings_section('linklog_utm', __('Analytics (UTM)', 'linklog'), fn() =>
			print '<p>' . esc_html__('Automatically append UTM parameters to external links.', 'linklog') . '</p>', 'linklog');

		self::add_checkbox(self::OPT_UTM_ENABLE, __('Enable UTM auto-append', 'linklog'));
		self::add_checkbox(self::OPT_UTM_PRESERVE, __('Preserve existing UTM parameters (do not overwrite if present)', 'linklog'));
		self::add_text(self::OPT_UTM_SOURCE, __('utm_source', 'linklog'));
		self::add_text(self::OPT_UTM_MEDIUM, __('utm_medium', 'linklog'));
		self::add_text(self::OPT_UTM_CAMPAIGN, __('utm_campaign', 'linklog'));
		self::add_text(self::OPT_UTM_TERM, __('utm_term', 'linklog'));
		self::add_text(self::OPT_UTM_CONTENT, __('utm_content', 'linklog'));
	}

	private static function add_checkbox(string $opt, string $label): void {
		add_settings_field(
			$opt,
			esc_html($label),
			function () use ($opt): void {
				$checked = get_option($opt) ? ' checked' : '';
				printf('<label><input type="checkbox" name="%1$s" value="on"%2$s /></label>', esc_attr($opt), $checked);
			},
			'linklog',
			preg_match('/utm_/', $opt) ? 'linklog_utm' : (str_contains($opt, 'glyph') ? (str_contains($opt, 'site') ? 'linklog_siteglyph' : 'linklog_feed') : 'linklog_main')
		);
	}

	private static function add_text(string $opt, string $label, string $placeholder = ''): void {
		add_settings_field(
			$opt,
			esc_html($label),
			function () use ($opt, $placeholder): void {
				$val = (string) get_option($opt, '');
				printf('<input type="text" class="regular-text" name="%1$s" value="%2$s" placeholder="%3$s" />',
					esc_attr($opt), esc_attr($val), esc_attr($placeholder));
			},
			'linklog',
			preg_match('/utm_/', $opt) ? 'linklog_utm' : (str_contains($opt, 'glyph') ? (str_contains($opt, 'site') ? 'linklog_siteglyph' : 'linklog_feed') : 'linklog_main')
		);
	}

	public static function render_settings_page(): void {
		if (!current_user_can('manage_options')) {
			return;
		} ?>
		<div class="wrap">
			<h1><?php echo esc_html__('LinkLog Settings', 'linklog'); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields('linklog_settings');
				do_settings_sections('linklog');
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public static function settings_link(array $links, string $file): array {
		$plugin_file = plugin_basename(__FILE__);
		if (basename($file) === basename($plugin_file)) {
			array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=linklog')) . '">' . esc_html__('Settings', 'linklog') . '</a>');
		}
		return $links;
	}

	/* Editor UI: Gutenberg sidebar (inline JS) + classic fallback */
	public static function enqueue_block_editor_assets(): void {
		// Load only when block editor is used for linklog
		if (!function_exists('use_block_editor_for_post_type') || !use_block_editor_for_post_type('linklog')) {
			return;
		}
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen || $screen->base !== 'post' || $screen->post_type !== 'linklog') {
			return;
		}
		$js = <<<'JS'
		( function( wp ) {
			const { registerPlugin } = wp.plugins;
			const { PluginDocumentSettingPanel } = wp.editPost || {};
			if ( ! registerPlugin || ! PluginDocumentSettingPanel ) { return; }
			const { TextControl, ToggleControl } = wp.components;
			const { __ } = wp.i18n;
			const { useSelect, useDispatch } = wp.data;
			const { createElement: el, Fragment } = wp.element;

			const Panel = () => {
				const postType = useSelect( s => s('core/editor').getCurrentPostType(), [] );
				if ( postType !== 'linklog' ) return null;
				const meta = useSelect( s => s('core/editor').getEditedPostAttribute('meta') || {}, [] );
				const { editPost } = useDispatch('core/editor');

				const setMeta = (key, value) => editPost({ meta: { ...meta, [key]: value } });

				return el( PluginDocumentSettingPanel,
					{ name: 'linklog-panel', title: __('LinkLog', 'linklog'), initialOpen: true },
					el( TextControl, {
						label: __('Link URL', 'linklog'),
						value: meta.linklog_url || '',
						onChange: (v) => setMeta('linklog_url', v),
						type: 'url',
						placeholder: 'https://example.com/article'
					}),
					el( ToggleControl, {
						label: __('Skip Redirect', 'linklog'),
						checked: !!meta.linklog_skip_redirect,
						onChange: (v) => setMeta('linklog_skip_redirect', !!v)
					}),
					el( ToggleControl, {
						label: __('Skip Rewrite', 'linklog'),
						checked: !!meta.linklog_skip_rewrite,
						onChange: (v) => setMeta('linklog_skip_rewrite', !!v)
					})
				);
			};

			registerPlugin( 'linklog-settings-panel', { render: Panel } );
		} )( window.wp );
		JS;
		// Attach after core editor scripts are loaded
		wp_add_inline_script('wp-edit-post', $js, 'after');
	}

	public static function maybe_add_classic_metabox(): void {
		// Only if block editor is disabled for this CPT
		if (function_exists('use_block_editor_for_post_type') && use_block_editor_for_post_type('linklog')) {
			return;
		}
		add_meta_box(
			'linklog_url_box',
			__('Link URL & Overrides', 'linklog'),
			[self::class, 'render_classic_metabox'],
			'linklog',
			'side',
			'high'
		);
	}

	public static function render_classic_metabox(WP_Post $post): void {
		wp_nonce_field('linklog_meta_save', 'linklog_meta_nonce');
		$url          = get_post_meta($post->ID, self::META_URL, true);
		$skip_redirect = (bool) get_post_meta($post->ID, self::META_SKIP_REDIRECT, true);
		$skip_rewrite  = (bool) get_post_meta($post->ID, self::META_SKIP_REWRITE, true);
		?>
		<p>
			<label for="linklog_url_field" class="screen-reader-text"><?php echo esc_html__('External URL', 'linklog'); ?></label>
			<input type="url" id="linklog_url_field" name="<?php echo esc_attr(self::META_URL); ?>" value="<?php echo esc_attr((string) $url); ?>" class="widefat" placeholder="https://example.com/article" inputmode="url" />
		</p>
		<label><input type="checkbox" name="<?php echo esc_attr(self::META_SKIP_REDIRECT); ?>" value="1" <?php checked(true, $skip_redirect); ?> /> <?php echo esc_html__('Skip Redirect', 'linklog'); ?></label><br/>
		<label><input type="checkbox" name="<?php echo esc_attr(self::META_SKIP_REWRITE); ?>" value="1" <?php checked(true, $skip_rewrite); ?> /> <?php echo esc_html__('Skip Rewrite', 'linklog'); ?></label>
		<?php
	}

	public static function save_classic_metabox(int $post_id, WP_Post $post): void {
		if (!isset($_POST['linklog_meta_nonce']) || !wp_verify_nonce((string) $_POST['linklog_meta_nonce'], 'linklog_meta_save')) {
			return;
		}
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
		if (!current_user_can('edit_post', $post_id)) return;

		update_post_meta($post_id, self::META_URL, esc_url_raw((string) ($_POST[self::META_URL] ?? '')));
		update_post_meta($post_id, self::META_SKIP_REDIRECT, !empty($_POST[self::META_SKIP_REDIRECT]) ? 1 : 0);
		update_post_meta($post_id, self::META_SKIP_REWRITE, !empty($_POST[self::META_SKIP_REWRITE]) ? 1 : 0);
	}

	/* Theme-agnostic behaviors */

	// 1) Rewire link targets across the site (home/archives/widgets/etc.)
	public static function filter_post_type_link(string $permalink, WP_Post $post): string {
		if (is_admin() || $post->post_type !== 'linklog') {
			return $permalink;
		}
		if (!get_option(self::OPT_REWRITE_PERMALINKS)) {
			return $permalink;
		}
		$skip = (bool) get_post_meta($post->ID, self::META_SKIP_REWRITE, true);
		if ($skip) return $permalink;

		$url = (string) get_post_meta($post->ID, self::META_URL, true);
		if (!$url) return $permalink;

		return esc_url(self::decorate_url($url, 'rewrite', $post->ID));
	}

	// 2) Redirect single linklog posts to the external URL
	public static function maybe_redirect_single(): void {
		if (!is_singular('linklog')) return;
		if (!get_option(self::OPT_REDIRECT_SINGLES)) return;

		if (!empty($_GET['preview']) || !empty($_GET['stay'])) return;

		$post = get_queried_object();
		if (!$post instanceof WP_Post) return;

		$skip = (bool) get_post_meta($post->ID, self::META_SKIP_REDIRECT, true);
		if ($skip) return;

		$url = (string) get_post_meta($post->ID, self::META_URL, true);
		if ($url) {
			wp_safe_redirect(self::decorate_url($url, 'redirect', $post->ID), 302);
			exit;
		}
	}

	// 3) On-site “↩︎ permalink” glyph injection (safe for any theme)
	public static function inject_site_glyph(string $content): string {
		if (is_admin() || is_feed()) return $content;

		$post = get_post();
		if (!$post instanceof WP_Post || $post->post_type !== 'linklog') return $content;
		if (!get_option(self::OPT_SITE_GLYPH_ENABLE)) return $content;

		$glyph = (string) get_option(self::OPT_SITE_GLYPH_TEXT, '&#8617;');
		$perma = get_permalink($post);
		$anchor = sprintf(
			'<span class="linklog-permalink-glyph"> <a href="%s" rel="bookmark">%s</a></span>',
			esc_url($perma),
			$glyph // entity text by design
		);
		// Append once
		if (!str_contains($content, 'linklog-permalink-glyph')) {
			$content .= "\n<p>{$anchor}</p>\n";
		}
		return $content;
	}

	/* Feeds */

	public static function filter_rss_permalink(string $value): string {
		$post = get_post();
		if ($post instanceof WP_Post && $post->post_type === 'linklog') {
			$url = (string) get_post_meta($post->ID, self::META_URL, true);
			if ($url) {
				return esc_url(self::decorate_url($url, 'feed', $post->ID));
			}
		}
		return $value;
	}

	public static function filter_feed_content(string $content): string {
		$post = get_post();
		if (!is_feed() || !$post instanceof WP_Post || $post->post_type !== 'linklog') {
			return $content;
		}
		if (!get_option(self::OPT_FEED_GLYPH_ENABLE)) {
			return $content;
		}
		$glyph = (string) get_option(self::OPT_FEED_GLYPH_TEXT, '&#9733;');
		$perma = get_permalink($post);
		$anchor = sprintf('<a href="%s" rel="bookmark" class="linklog-feed-glyph">%s</a>', esc_url($perma), $glyph);
		return $content . "\n<p>{$anchor}</p>\n";
	}

	/* UTM decoration (without modifying stored meta) */

	private static function decorate_url(string $url, string $context, int $post_id): string {
		// Only http(s)
		$parts = wp_parse_url($url);
		if (empty($parts['scheme']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
			return $url;
		}
		if (!get_option(self::OPT_UTM_ENABLE)) {
			return $url;
		}
		$params = [
			'utm_source'   => (string) get_option(self::OPT_UTM_SOURCE, ''),
			'utm_medium'   => (string) get_option(self::OPT_UTM_MEDIUM, ''),
			'utm_campaign' => (string) get_option(self::OPT_UTM_CAMPAIGN, ''),
			'utm_term'     => (string) get_option(self::OPT_UTM_TERM, ''),
			'utm_content'  => (string) get_option(self::OPT_UTM_CONTENT, ''),
			// You could add context-aware defaults here if desired, e.g. utm_medium = $context
		];

		$preserve = (bool) get_option(self::OPT_UTM_PRESERVE, true);

		// Build query map, preserving existing when set and $preserve = true
		$existing = [];
		if (!empty($parts['query'])) {
			wp_parse_str($parts['query'], $existing);
		}

		foreach ($params as $k => $v) {
			if ($v === '') continue;
			if ($preserve && array_key_exists($k, $existing)) continue;
			$existing[$k] = $v;
		}

		// Rebuild
		$rebuilt = $parts['scheme'] . '://' . ($parts['host'] ?? '');
		if (!empty($parts['port'])) $rebuilt .= ':' . (int) $parts['port'];
		$rebuilt .= $parts['path'] ?? '';
		if (!empty($existing)) {
			$rebuilt .= '?' . http_build_query($existing, '', '&', PHP_QUERY_RFC3986);
		}
		if (!empty($parts['fragment'])) $rebuilt .= '#' . $parts['fragment'];
		return $rebuilt;
	}
}

Plugin::init();
