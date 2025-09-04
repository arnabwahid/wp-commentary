<?php
/**
 * Plugin Name:       Linked List
 * Description:       Link blogging with a “Linked List” post type (“Linked Post” items) with a Link URL field, Gutenberg sidebar (with classic fallback), per-post overrides, optional on-site ↩︎ permalink glyph, UTM auto-append, and DF-style feed behavior.
 * Version:           1.3.0
 * Requires at least: 6.3
 * Tested up to:      6.7
 * Requires PHP:      8.1
 * Author:            Refactor by Arnab Wahid (Previously: peiche, Kevin Dayton, YJ Soon)
 * License:           MIT
 * Text Domain:       linked-list
 */

declare(strict_types=1);

namespace LinkLog;

use WP_Post;

if (!defined('ABSPATH')) exit;

final class Plugin {
	public const VERSION = '1.3.0';

	// Meta keys (registered with REST for Gutenberg)
	private const META_URL           = 'linklog_url';            // string
	private const META_SKIP_REDIRECT = 'linklog_skip_redirect';  // bool
	private const META_SKIP_REWRITE  = 'linklog_skip_rewrite';   // bool

	// Options
	private const OPT_REDIRECT_SINGLES   = 'linklog_redirect_singles';      // bool
	private const OPT_REWRITE_PERMALINKS = 'linklog_rewrite_permalinks';    // bool
	private const OPT_FEED_GLYPH_ENABLE  = 'linklog_feed_glyph_enable';     // bool
	private const OPT_FEED_GLYPH_TEXT    = 'linklog_feed_glyph_text';       // string
	private const OPT_SITE_GLYPH_ENABLE  = 'linklog_site_glyph_enable';     // bool
	private const OPT_SITE_GLYPH_TEXT    = 'linklog_site_glyph_text';       // string

	// UTM options
	private const OPT_UTM_ENABLE         = 'linklog_utm_enable';
	private const OPT_UTM_PRESERVE       = 'linklog_utm_preserve_existing';
	private const OPT_UTM_SOURCE         = 'linklog_utm_source';
	private const OPT_UTM_MEDIUM         = 'linklog_utm_medium';
	private const OPT_UTM_CAMPAIGN       = 'linklog_utm_campaign';
	private const OPT_UTM_TERM           = 'linklog_utm_term';
	private const OPT_UTM_CONTENT        = 'linklog_utm_content';

	public static function init(): void {
		register_activation_hook(__FILE__, [self::class, 'activate']);

		add_action('init', [self::class, 'register_post_type']);
		add_action('init', [self::class, 'register_meta']);

		// Settings
		add_action('admin_menu', [self::class, 'register_settings_page']);
		add_action('admin_init', [self::class, 'register_settings']);
		add_filter('plugin_action_links', [self::class, 'settings_link'], 10, 2);

		// Editor UI: Gutenberg sidebar (when used) or classic fallback
		add_action('enqueue_block_editor_assets', [self::class, 'enqueue_block_editor_assets']);
		add_action('add_meta_boxes', [self::class, 'maybe_add_classic_metabox']);
		add_action('save_post_linked_list', [self::class, 'save_classic_metabox'], 10, 2);

		// Front-end behavior
		add_filter('post_type_link', [self::class, 'filter_post_type_link'], 10, 2);
		add_action('template_redirect', [self::class, 'maybe_redirect_single']);
		add_filter('the_content', [self::class, 'inject_site_glyph'], 20);

		// Feeds
		add_filter('the_permalink_rss', [self::class, 'filter_rss_permalink'], 100);
		add_filter('the_content_feed', [self::class, 'filter_feed_content']);
		add_filter('the_excerpt_rss', [self::class, 'filter_feed_content']);
	}

	/* Activation defaults + rewrite flush */
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
		add_option(self::OPT_UTM_MEDIUM, 'linked-post');
		add_option(self::OPT_UTM_CAMPAIGN, '');
		add_option(self::OPT_UTM_TERM, '');
		add_option(self::OPT_UTM_CONTENT, '');

		self::register_post_type();
		flush_rewrite_rules();
	}

	/* CPT + Meta */

	/**
	 * CPT key is `linked_list` (no spaces); UI name is “Linked List”, items are “Linked Post”.
	 */
	public static function register_post_type(): void {
		register_post_type('linked_list', [
			'labels' => [
				'name'                     => __('Linked List', 'linked-list'),
				'singular_name'            => __('Linked Post', 'linked-list'),
				'add_new'                  => __('Add Linked Post', 'linked-list'),
				'add_new_item'             => __('Add New Linked Post', 'linked-list'),
				'edit_item'                => __('Edit Linked Post', 'linked-list'),
				'new_item'                 => __('New Linked Post', 'linked-list'),
				'view_item'                => __('View Linked Post', 'linked-list'),
				'view_items'               => __('View Linked Posts', 'linked-list'),
				'search_items'             => __('Search Linked Posts', 'linked-list'),
				'not_found'                => __('No linked posts found', 'linked-list'),
				'not_found_in_trash'       => __('No linked posts found in Trash', 'linked-list'),
				'all_items'                => __('All Linked Posts', 'linked-list'),
				'archives'                 => __('Linked List Archives', 'linked-list'),
				'item_published'           => __('Linked Post published.', 'linked-list'),
				'item_updated'             => __('Linked Post updated.', 'linked-list'),
			],
			'description'        => __('Daring Fireball–style external link posts.', 'linked-list'),
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_rest'       => true,
			'show_in_nav_menus'  => true,
			'has_archive'        => true,
			'hierarchical'       => false,
			'menu_icon'          => 'dashicons-external',
			'supports'           => ['title', 'editor', 'excerpt', 'author', 'thumbnail', 'comments', 'custom-fields'],
			'rewrite'            => ['slug' => 'linked-list', 'with_front' => false],
			'map_meta_cap'       => true,
		]);
	}

	public static function register_meta(): void {
		register_post_meta('linked_list', self::META_URL, [
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'auth_callback'     => fn(): bool => current_user_can('edit_posts'),
			'sanitize_callback' => fn($v): string => esc_url_raw((string) $v),
			'default'           => '',
			'description'       => __('External URL for this Linked Post', 'linked-list'),
		]);

		foreach ([self::META_SKIP_REDIRECT, self::META_SKIP_REWRITE] as $metaBool) {
			register_post_meta('linked_list', $metaBool, [
				'type'              => 'boolean',
				'single'            => true,
				'show_in_rest'      => true,
				'auth_callback'     => fn(): bool => current_user_can('edit_posts'),
				'sanitize_callback' => fn($v): bool => (bool) $v,
				'default'           => false,
			]);
		}
	}

	/* Settings */

	public static function register_settings_page(): void {
		add_options_page(
			__('Linked List Settings', 'linked-list'),
			__('Linked List', 'linked-list'),
			'manage_options',
			'linked-list',
			[self::class, 'render_settings_page']
		);
	}

	public static function register_settings(): void {
		$cb_bool = static fn($v): string => $v ? 'on' : '';
		$cb_text = static fn($v): string => is_string($v) ? wp_kses_post($v) : '';

		register_setting('linked_list_settings', self::OPT_REDIRECT_SINGLES, ['sanitize_callback' => $cb_bool]);
		register_setting('linked_list_settings', self::OPT_REWRITE_PERMALINKS, ['sanitize_callback' => $cb_bool]);

		register_setting('linked_list_settings', self::OPT_FEED_GLYPH_ENABLE, ['sanitize_callback' => $cb_bool]);
		register_setting('linked_list_settings', self::OPT_FEED_GLYPH_TEXT, ['sanitize_callback' => $cb_text]);

		register_setting('linked_list_settings', self::OPT_SITE_GLYPH_ENABLE, ['sanitize_callback' => $cb_bool]);
		register_setting('linked_list_settings', self::OPT_SITE_GLYPH_TEXT, ['sanitize_callback' => $cb_text]);

		register_setting('linked_list_settings', self::OPT_UTM_ENABLE, ['sanitize_callback' => $cb_bool]);
		register_setting('linked_list_settings', self::OPT_UTM_PRESERVE, ['sanitize_callback' => $cb_bool]);

		foreach ([self::OPT_UTM_SOURCE, self::OPT_UTM_MEDIUM, self::OPT_UTM_CAMPAIGN, self::OPT_UTM_TERM, self::OPT_UTM_CONTENT] as $opt) {
			register_setting('linked_list_settings', $opt, ['sanitize_callback' => $cb_text]);
		}

		add_settings_section('linked_list_main', __('Behavior', 'linked-list'), fn() =>
			print '<p>' . esc_html__('Control rewrite/redirect behavior for Linked Posts.', 'linked-list') . '</p>', 'linked-list');

		self::add_checkbox(self::OPT_REWRITE_PERMALINKS, __('Make Linked Post permalinks across the site point to external URL', 'linked-list'), 'linked_list_main');
		self::add_checkbox(self::OPT_REDIRECT_SINGLES, __('Redirect single Linked Post to external URL', 'linked-list'), 'linked_list_main');

		add_settings_section('linked_list_siteglyph', __('On-site Permalink Glyph', 'linked-list'), fn() =>
			print '<p>' . esc_html__('Append a ↩︎ permalink glyph to Linked Post content on the site (theme-agnostic).', 'linked-list') . '</p>', 'linked-list');

		self::add_checkbox(self::OPT_SITE_GLYPH_ENABLE, __('Enable on-site permalink glyph', 'linked-list'), 'linked_list_siteglyph');
		self::add_text(self::OPT_SITE_GLYPH_TEXT, __('Glyph text', 'linked-list'), '&#8617;', 'linked_list_siteglyph');

		add_settings_section('linked_list_feed', __('Feeds', 'linked-list'), fn() =>
			print '<p>' . esc_html__('Daring Fireball–style feed behavior.', 'linked-list') . '</p>', 'linked-list');

		self::add_checkbox(self::OPT_FEED_GLYPH_ENABLE, __('Append "back to post" glyph at end of feed content', 'linked-list'), 'linked_list_feed');
		self::add_text(self::OPT_FEED_GLYPH_TEXT, __('Feed glyph text', 'linked-list'), '&#9733;', 'linked_list_feed');

		add_settings_section('linked_list_utm', __('Analytics (UTM)', 'linked-list'), fn() =>
			print '<p>' . esc_html__('Automatically append UTM parameters to external links.', 'linked-list') . '</p>', 'linked-list');

		self::add_checkbox(self::OPT_UTM_ENABLE, __('Enable UTM auto-append', 'linked-list'), 'linked_list_utm');
		self::add_checkbox(self::OPT_UTM_PRESERVE, __('Preserve existing UTM parameters (do not overwrite if present)', 'linked-list'), 'linked_list_utm');
		self::add_text(self::OPT_UTM_SOURCE, __('utm_source', 'linked-list'), '', 'linked_list_utm');
		self::add_text(self::OPT_UTM_MEDIUM, __('utm_medium', 'linked-list'), '', 'linked_list_utm');
		self::add_text(self::OPT_UTM_CAMPAIGN, __('utm_campaign', 'linked-list'), '', 'linked_list_utm');
		self::add_text(self::OPT_UTM_TERM, __('utm_term', 'linked-list'), '', 'linked_list_utm');
		self::add_text(self::OPT_UTM_CONTENT, __('utm_content', 'linked-list'), '', 'linked_list_utm');
	}

	private static function add_checkbox(string $opt, string $label, string $section): void {
		add_settings_field(
			$opt,
			esc_html($label),
			function () use ($opt): void {
				$checked = get_option($opt) ? ' checked' : '';
				printf('<label><input type="checkbox" name="%1$s" value="on"%2$s /></label>', esc_attr($opt), $checked);
			},
			'linked-list',
			$section
		);
	}

	private static function add_text(string $opt, string $label, string $placeholder, string $section): void {
		add_settings_field(
			$opt,
			esc_html($label),
			function () use ($opt, $placeholder): void {
				$val = (string) get_option($opt, '');
				printf('<input type="text" class="regular-text" name="%1$s" value="%2$s" placeholder="%3$s" />',
					esc_attr($opt), esc_attr($val), esc_attr($placeholder));
			},
			'linked-list',
			$section
		);
	}

	public static function render_settings_page(): void {
		if (!current_user_can('manage_options')) return; ?>
		<div class="wrap">
			<h1><?php echo esc_html__('Linked List Settings', 'linked-list'); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields('linked_list_settings');
				do_settings_sections('linked-list');
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public static function settings_link(array $links, string $file): array {
		$plugin_file = plugin_basename(__FILE__);
		if (basename($file) === basename($plugin_file)) {
			array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=linked-list')) . '">' . esc_html__('Settings', 'linked-list') . '</a>');
		}
		return $links;
	}

	/* Editor UI */

	// Gutenberg sidebar panel (only when the block editor is used for this CPT)
	public static function enqueue_block_editor_assets(): void {
		if (!function_exists('use_block_editor_for_post_type') || !use_block_editor_for_post_type('linked_list')) {
			return;
		}
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen || $screen->base !== 'post' || $screen->post_type !== 'linked_list') {
			return;
		}
		$js = <<<'JS'
		( function( wp ) {
			const { registerPlugin } = wp.plugins;
			const { PluginDocumentSettingPanel } = wp.editPost || {};
			if ( ! registerPlugin || ! PluginDocumentSettingPanel ) { return; }
			const { TextControl, ToggleControl, Tooltip } = wp.components;
			const { __ } = wp.i18n;
			const { useSelect, useDispatch } = wp.data;
			const { createElement: el } = wp.element;

			const Panel = () => {
				const postType = useSelect( s => s('core/editor').getCurrentPostType(), [] );
				if ( postType !== 'linked_list' ) return null;
				const meta = useSelect( s => s('core/editor').getEditedPostAttribute('meta') || {}, [] );
				const { editPost } = useDispatch('core/editor');
				const setMeta = (key, value) => editPost({ meta: { ...meta, [key]: value } });

				return el( PluginDocumentSettingPanel,
					{ name: 'linked-list-panel', title: __('Linked Post', 'linked-list'), initialOpen: true },

					el( TextControl, {
						label: __('Link URL', 'linked-list'),
						value: meta.linklog_url || '',
						onChange: (v) => setMeta('linklog_url', v),
						type: 'url',
						placeholder: 'https://example.com/article'
					}),

					// Toggle: Don’t auto-redirect this post (tooltip on hover)
					el( Tooltip, { text: __('Show post content instead of redirecting', 'linked-list') },
						el( 'div', { },
							el( ToggleControl, {
								label: __('Don’t auto-redirect this post', 'linked-list'),
								checked: !!meta.linklog_skip_redirect,
								onChange: (v) => setMeta('linklog_skip_redirect', !!v)
							})
						)
					),

					// Toggle: Use post permalink in lists (tooltip on hover)
					el( Tooltip, { text: __('Link titles to post page (not external site)', 'linked-list') },
						el( 'div', { },
							el( ToggleControl, {
								label: __('Use post permalink in lists', 'linked-list'),
								checked: !!meta.linklog_skip_rewrite,
								onChange: (v) => setMeta('linklog_skip_rewrite', !!v)
							})
						)
					)
				);
			};

			registerPlugin( 'linked-list-settings-panel', { render: Panel } );
		} )( window.wp );
		JS;
		wp_add_inline_script('wp-edit-post', $js, 'after');
	}

	// Classic editor fallback metabox (with hover tooltips via title attr)
	public static function maybe_add_classic_metabox(): void {
		if (function_exists('use_block_editor_for_post_type') && use_block_editor_for_post_type('linked_list')) {
			return;
		}
		add_meta_box(
			'linked_list_url_box',
			__('Link URL & Overrides', 'linked-list'),
			[self::class, 'render_classic_metabox'],
			'linked_list',
			'side',
			'high'
		);
	}

	public static function render_classic_metabox(WP_Post $post): void {
		wp_nonce_field('linklog_meta_save', 'linklog_meta_nonce');
		$url           = get_post_meta($post->ID, self::META_URL, true);
		$skip_redirect = (bool) get_post_meta($post->ID, self::META_SKIP_REDIRECT, true);
		$skip_rewrite  = (bool) get_post_meta($post->ID, self::META_SKIP_REWRITE, true);
		?>
		<p>
			<label for="linked_list_url_field" class="screen-reader-text"><?php echo esc_html__('External URL', 'linked-list'); ?></label>
			<input type="url" id="linked_list_url_field" name="<?php echo esc_attr(self::META_URL); ?>" value="<?php echo esc_attr((string) $url); ?>" class="widefat" placeholder="https://example.com/article" inputmode="url" />
		</p>
		<p>
			<label title="<?php echo esc_attr__('Show post content instead of redirecting', 'linked-list'); ?>">
				<input type="checkbox" name="<?php echo esc_attr(self::META_SKIP_REDIRECT); ?>" value="1" <?php checked(true, $skip_redirect); ?> />
				<?php echo esc_html__('Don’t auto-redirect this post', 'linked-list'); ?>
			</label>
		</p>
		<p>
			<label title="<?php echo esc_attr__('Link titles to post page (not external site)', 'linked-list'); ?>">
				<input type="checkbox" name="<?php echo esc_attr(self::META_SKIP_REWRITE); ?>" value="1" <?php checked(true, $skip_rewrite); ?> />
				<?php echo esc_html__('Use post permalink in lists', 'linked-list'); ?>
			</label>
		</p>
		<?php
	}

	public static function save_classic_metabox(int $post_id, WP_Post $post): void {
		if (!isset($_POST['linklog_meta_nonce']) || !wp_verify_nonce((string) $_POST['linklog_meta_nonce'], 'linklog_meta_save')) return;
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
		if (!current_user_can('edit_post', $post_id)) return;

		update_post_meta($post_id, self::META_URL, esc_url_raw((string) ($_POST[self::META_URL] ?? '')));
		update_post_meta($post_id, self::META_SKIP_REDIRECT, !empty($_POST[self::META_SKIP_REDIRECT]) ? 1 : 0);
		update_post_meta($post_id, self::META_SKIP_REWRITE, !empty($_POST[self::META_SKIP_REWRITE]) ? 1 : 0);
	}

	/* Theme-agnostic behaviors */

	// 1) Rewire permalink targets across the site (home/archives/widgets/etc.)
	public static function filter_post_type_link(string $permalink, WP_Post $post): string {
		if (is_admin() || $post->post_type !== 'linked_list') return $permalink;

		if (!get_option(self::OPT_REWRITE_PERMALINKS)) return $permalink;
		if ((bool) get_post_meta($post->ID, self::META_SKIP_REWRITE, true)) return $permalink;

		$url = (string) get_post_meta($post->ID, self::META_URL, true);
		if ($url === '') return $permalink;

		return esc_url(self::decorate_url($url, 'rewrite', $post->ID));
	}

	// 2) Redirect single Linked Post to the external URL (only if URL is valid)
	public static function maybe_redirect_single(): void {
		if (!is_singular('linked_list')) return;
		if (!get_option(self::OPT_REDIRECT_SINGLES)) return;

		// Allow preview and manual override
		if (!empty($_GET['preview']) || !empty($_GET['stay'])) return;

		$post = get_queried_object();
		if (!$post instanceof WP_Post) return;

		if ((bool) get_post_meta($post->ID, self::META_SKIP_REDIRECT, true)) return;

		$url = (string) get_post_meta($post->ID, self::META_URL, true);
		if ($url === '') return; // No URL => don't redirect

		// Safety: only http/https; never redirect to admin URLs
		$parts = wp_parse_url($url);
		if (empty($parts['scheme']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) return;

		// Avoid self-redirect loops
		$perma = get_permalink($post);
		if ($perma && 0 === strcasecmp(trailingslashit($perma), trailingslashit($url))) return;

		wp_safe_redirect(self::decorate_url($url, 'redirect', $post->ID), 302);
		exit;
	}

	// 3) On-site “↩︎ permalink” glyph injection (works with any theme) — links to INTERNAL permalink
	public static function inject_site_glyph(string $content): string {
		if (is_admin() || is_feed()) return $content;

		$post = get_post();
		if (!$post instanceof WP_Post || $post->post_type !== 'linked_list') return $content;
		if (!get_option(self::OPT_SITE_GLYPH_ENABLE)) return $content;

		$glyph = (string) get_option(self::OPT_SITE_GLYPH_TEXT, '&#8617;');
		$perma = get_permalink($post); // INTERNAL permalink

		$anchor = sprintf(
			'<span class="linklog-permalink-glyph"> <a href="%s" rel="bookmark">%s</a></span>',
			esc_url($perma),
			$glyph // entity text by design
		);

		if (!str_contains($content, 'linklog-permalink-glyph')) {
			$content .= "\n<p>{$anchor}</p>\n";
		}
		return $content;
	}

	/* Feeds */

	public static function filter_rss_permalink(string $value): string {
		$post = get_post();
		if ($post instanceof WP_Post && $post->post_type === 'linked_list') {
			$url = (string) get_post_meta($post->ID, self::META_URL, true);
			if ($url) return esc_url(self::decorate_url($url, 'feed', $post->ID));
		}
		return $value;
	}

	public static function filter_feed_content(string $content): string {
		$post = get_post();
		if (!is_feed() || !$post instanceof WP_Post || $post->post_type !== 'linked_list') return $content;
		if (!get_option(self::OPT_FEED_GLYPH_ENABLE)) return $content;

		$glyph = (string) get_option(self::OPT_FEED_GLYPH_TEXT, '&#9733;');
		$perma = get_permalink($post); // INTERNAL permalink for feed glyph too
		$anchor = sprintf('<a href="%s" rel="bookmark" class="linklog-feed-glyph">%s</a>', esc_url($perma), $glyph);
		return $content . "\n<p>{$anchor}</p>\n";
	}

	/* UTM decoration (append on-the-fly without changing stored meta) */

	private static function decorate_url(string $url, string $context, int $post_id): string {
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
		];

		$preserve = (bool) get_option(self::OPT_UTM_PRESERVE, true);

		$existing = [];
		if (!empty($parts['query'])) {
			wp_parse_str($parts['query'], $existing);
		}
		foreach ($params as $k => $v) {
			if ($v === '') continue;
			if ($preserve && array_key_exists($k, $existing)) continue;
			$existing[$k] = $v;
		}

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
