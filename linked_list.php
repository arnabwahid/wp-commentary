<?php
/**
 * Plugin Name:       Linked List
 * Description:       Theme-agnostic link blogging for WordPress. Adds a “Linked List” post type (“Linked Posts”) with Link URL, per-post overrides, on-site ↩︎ permalink glyph, DF-style feed behavior, optional UTM auto-append, a shortcode archive page, and an option to exclude linked items from the main blog loop.
 * Version:           1.4.0
 * Requires at least: 6.3
 * Tested up to:      6.7
 * Requires PHP:      8.1
 * License:           MIT
 * Text Domain:       linked-list
 */

declare(strict_types=1);

namespace LinkedList;

use WP_Post;
use WP_Query;

if (!defined('ABSPATH')) exit;

final class Plugin {
	/** Version */
	public const VERSION = '1.4.0';

	/* -------------------------------------------------------------------------
	 * Meta keys (registered with REST for Gutenberg)
	 * ---------------------------------------------------------------------- */
	private const META_URL           = 'linklog_url';           // string: external URL
	private const META_SKIP_REDIRECT = 'linklog_skip_redirect'; // bool: per-post override
	private const META_SKIP_REWRITE  = 'linklog_skip_rewrite';  // bool: per-post override

	/* -------------------------------------------------------------------------
	 * Options (Settings → Linked List)
	 * ---------------------------------------------------------------------- */
	private const OPT_REDIRECT_SINGLES   = 'linklog_redirect_singles';     // bool
	private const OPT_REWRITE_PERMALINKS = 'linklog_rewrite_permalinks';   // bool
	private const OPT_FEED_GLYPH_ENABLE  = 'linklog_feed_glyph_enable';    // bool
	private const OPT_FEED_GLYPH_TEXT    = 'linklog_feed_glyph_text';      // string (HTML entity/string)
	private const OPT_SITE_GLYPH_ENABLE  = 'linklog_site_glyph_enable';    // bool
	private const OPT_SITE_GLYPH_TEXT    = 'linklog_site_glyph_text';      // string (HTML entity/string)
	private const OPT_UTM_ENABLE         = 'linklog_utm_enable';           // bool
	private const OPT_UTM_PRESERVE       = 'linklog_utm_preserve_existing';// bool
	private const OPT_UTM_SOURCE         = 'linklog_utm_source';
	private const OPT_UTM_MEDIUM         = 'linklog_utm_medium';
	private const OPT_UTM_CAMPAIGN       = 'linklog_utm_campaign';
	private const OPT_UTM_TERM           = 'linklog_utm_term';
	private const OPT_UTM_CONTENT        = 'linklog_utm_content';

	// NEW: exclude linked items from the main blog loop (home)
	private const OPT_EXCLUDE_FROM_MAIN  = 'linked_list_exclude_from_main'; // bool

	/* -------------------------------------------------------------------------
	 * Bootstrap
	 * ---------------------------------------------------------------------- */
	public static function init(): void {
		register_activation_hook(__FILE__, [self::class, 'activate']);

		add_action('init', [self::class, 'register_post_type']);
		add_action('init', [self::class, 'register_meta']);

		// Settings (admin)
		add_action('admin_menu', [self::class, 'register_settings_page']);
		add_action('admin_init', [self::class, 'register_settings']);
		add_filter('plugin_action_links', [self::class, 'settings_link'], 10, 2);

		// Editor UI
		add_action('enqueue_block_editor_assets', [self::class, 'enqueue_block_editor_assets']); // Gutenberg panel
		add_action('add_meta_boxes', [self::class, 'maybe_add_classic_metabox']);                // Classic fallback
		add_action('save_post_linked_list', [self::class, 'save_classic_metabox'], 10, 2);

		// Front-end behavior (theme-agnostic)
		add_filter('post_type_link', [self::class, 'filter_post_type_link'], 10, 2); // rewrite titles to external
		add_action('template_redirect', [self::class, 'maybe_redirect_single']);      // single redirect
		add_filter('the_content', [self::class, 'inject_site_glyph'], 20);            // ↩︎ glyph

		// Feeds
		add_filter('the_permalink_rss', [self::class, 'filter_rss_permalink'], 100);
		add_filter('the_content_feed', [self::class, 'filter_feed_content']);
		add_filter('the_excerpt_rss', [self::class, 'filter_feed_content']);

		// Exclude linked items from main blog loop (toggle)
		add_action('pre_get_posts', [self::class, 'maybe_exclude_from_main']);

		// Ensure organizational taxonomy terms exist (category & tag "Linked")
		add_action('after_switch_theme', [self::class, 'ensure_terms']);

		// Shortcode archive + auto-create page /linked-list/
		add_shortcode('linked_list_archive', [self::class, 'shortcode_archive']);
	}

	/* -------------------------------------------------------------------------
	 * Activation: defaults, ensure CPT, ensure terms, ensure archive page, flush rewrites
	 * ---------------------------------------------------------------------- */
	public static function activate(): void {
		// Defaults
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

		add_option(self::OPT_EXCLUDE_FROM_MAIN, ''); // off by default

		// Register CPT for rewrite rules, ensure terms and archive page
		self::register_post_type();
		self::ensure_terms();
		self::ensure_archive_page();

		flush_rewrite_rules();
	}

	/* -------------------------------------------------------------------------
	 * Custom Post Type: linked_list  (UI name: Linked List; item: Linked Post)
	 * ---------------------------------------------------------------------- */
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
			'description'        => __('External link posts (“linked list”)', 'linked-list'),
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

	/* -------------------------------------------------------------------------
	 * Post Meta
	 * ---------------------------------------------------------------------- */
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

		foreach ([self::META_SKIP_REDIRECT, self::META_SKIP_REWRITE] as $boolMeta) {
			register_post_meta('linked_list', $boolMeta, [
				'type'              => 'boolean',
				'single'            => true,
				'show_in_rest'      => true,
				'auth_callback'     => fn(): bool => current_user_can('edit_posts'),
				'sanitize_callback' => fn($v): bool => (bool) $v,
				'default'           => false,
			]);
		}
	}

	/* -------------------------------------------------------------------------
	 * Settings page
	 * ---------------------------------------------------------------------- */
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

		register_setting('linked_list_settings', self::OPT_REWRITE_PERMALINKS, ['sanitize_callback' => $cb_bool]);
		register_setting('linked_list_settings', self::OPT_REDIRECT_SINGLES,   ['sanitize_callback' => $cb_bool]);
		register_setting('linked_list_settings', self::OPT_EXCLUDE_FROM_MAIN,  ['sanitize_callback' => $cb_bool]);

		register_setting('linked_list_settings', self::OPT_SITE_GLYPH_ENABLE,  ['sanitize_callback' => $cb_bool]);
		register_setting('linked_list_settings', self::OPT_SITE_GLYPH_TEXT,    ['sanitize_callback' => $cb_text]);

		register_setting('linked_list_settings', self::OPT_FEED_GLYPH_ENABLE,  ['sanitize_callback' => $cb_bool]);
		register_setting('linked_list_settings', self::OPT_FEED_GLYPH_TEXT,    ['sanitize_callback' => $cb_text]);

		register_setting('linked_list_settings', self::OPT_UTM_ENABLE,         ['sanitize_callback' => $cb_bool]);
		register_setting('linked_list_settings', self::OPT_UTM_PRESERVE,       ['sanitize_callback' => $cb_bool]);
		foreach ([self::OPT_UTM_SOURCE, self::OPT_UTM_MEDIUM, self::OPT_UTM_CAMPAIGN, self::OPT_UTM_TERM, self::OPT_UTM_CONTENT] as $opt) {
			register_setting('linked_list_settings', $opt, ['sanitize_callback' => $cb_text]);
		}

		add_settings_section('linked_list_main', __('Behavior', 'linked-list'), fn() =>
			print '<p>' . esc_html__('Control sitewide rewrite/redirect and visibility behavior for Linked Posts.', 'linked-list') . '</p>', 'linked-list');

		self::add_checkbox(self::OPT_REWRITE_PERMALINKS, __('Make Linked Post titles across the site go to the external URL', 'linked-list'), 'linked_list_main');
		self::add_checkbox(self::OPT_REDIRECT_SINGLES,   __('Redirect single Linked Posts to the external URL', 'linked-list'), 'linked_list_main');
		self::add_checkbox(self::OPT_EXCLUDE_FROM_MAIN,  __('Exclude linked posts from the main blog loop (homepage)', 'linked-list'), 'linked_list_main',
			__('Hides linked items from the main home query. They remain visible on the Linked List archive page and anywhere you explicitly list them.', 'linked-list'));

		add_settings_section('linked_list_siteglyph', __('On-site Permalink Glyph', 'linked-list'), fn() =>
			print '<p>' . esc_html__('Append a ↩︎ permalink glyph to Linked Post content on the site (links back to the post permalink).', 'linked-list') . '</p>', 'linked-list');

		self::add_checkbox(self::OPT_SITE_GLYPH_ENABLE, __('Enable on-site permalink glyph', 'linked-list'), 'linked_list_siteglyph');
		self::add_text(self::OPT_SITE_GLYPH_TEXT, __('Glyph text', 'linked-list'), '&#8617;', 'linked_list_siteglyph');

		add_settings_section('linked_list_feed', __('Feeds', 'linked-list'), fn() =>
			print '<p>' . esc_html__('DF-style feed behavior (link items go out to the external URL, with a glyph back to the post).', 'linked-list') . '</p>', 'linked-list');

		self::add_checkbox(self::OPT_FEED_GLYPH_ENABLE, __('Append “back to post” glyph at end of feed content', 'linked-list'), 'linked_list_feed');
		self::add_text(self::OPT_FEED_GLYPH_TEXT, __('Feed glyph text', 'linked-list'), '&#9733;', 'linked_list_feed');

		add_settings_section('linked_list_utm', __('Analytics (UTM)', 'linked-list'), fn() =>
			print '<p>' . esc_html__('Automatically append UTM parameters to external links.', 'linked-list') . '</p>', 'linked-list');

		self::add_checkbox(self::OPT_UTM_ENABLE, __('Enable UTM auto-append', 'linked-list'), 'linked_list_utm');
		self::add_checkbox(self::OPT_UTM_PRESERVE, __('Preserve existing UTM parameters (do not overwrite if present)', 'linked-list'), 'linked_list_utm');
		self::add_text(self::OPT_UTM_SOURCE,   __('utm_source', 'linked-list'),   'rss', 'linked_list_utm');
		self::add_text(self::OPT_UTM_MEDIUM,   __('utm_medium', 'linked-list'),   'linked-post', 'linked_list_utm');
		self::add_text(self::OPT_UTM_CAMPAIGN, __('utm_campaign', 'linked-list'), '', 'linked_list_utm');
		self::add_text(self::OPT_UTM_TERM,     __('utm_term', 'linked-list'),     '', 'linked_list_utm');
		self::add_text(self::OPT_UTM_CONTENT,  __('utm_content', 'linked-list'),  '', 'linked_list_utm');
	}

	private static function add_checkbox(string $opt, string $label, string $section, string $desc = ''): void {
		add_settings_field(
			$opt,
			esc_html($label),
			function () use ($opt, $desc): void {
				$checked = get_option($opt) ? ' checked' : '';
				printf('<label><input type="checkbox" name="%1$s" value="on"%2$s /></label>', esc_attr($opt), $checked);
				if ($desc !== '') {
					printf('<p class="description">%s</p>', esc_html($desc));
				}
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

	/* -------------------------------------------------------------------------
	 * Editor UI
	 * ---------------------------------------------------------------------- */

	// Gutenberg document sidebar panel (Linked URL + overrides)
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

					el( Tooltip, { text: __('Show post content instead of redirecting', 'linked-list') },
						el( 'div', {},
							el( ToggleControl, {
								label: __('Don’t auto-redirect this post', 'linked-list'),
								checked: !!meta.linklog_skip_redirect,
								onChange: (v) => setMeta('linklog_skip_redirect', !!v)
							})
						)
					),

					el( Tooltip, { text: __('Link titles to post page (not external site)', 'linked-list') },
						el( 'div', {},
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

	// Classic editor fallback metabox
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

	/* -------------------------------------------------------------------------
	 * Theme-agnostic behaviors
	 * ---------------------------------------------------------------------- */

	// Rewrite titles/permalinks to external URL across the site (lists/widgets) when enabled
	public static function filter_post_type_link(string $permalink, WP_Post $post): string {
		if (is_admin() || $post->post_type !== 'linked_list') return $permalink;

		if (!get_option(self::OPT_REWRITE_PERMALINKS)) return $permalink;
		if ((bool) get_post_meta($post->ID, self::META_SKIP_REWRITE, true)) return $permalink;

		$url = (string) get_post_meta($post->ID, self::META_URL, true);
		if ($url === '') return $permalink;

		return esc_url(self::decorate_url($url, 'rewrite', $post->ID));
	}

	// Redirect single Linked Post to external URL (if valid) unless overridden
	public static function maybe_redirect_single(): void {
		if (!is_singular('linked_list')) return;
		if (!get_option(self::OPT_REDIRECT_SINGLES)) return;
		if (!empty($_GET['preview']) || !empty($_GET['stay'])) return;

		$post = get_queried_object();
		if (!$post instanceof WP_Post) return;
		if ((bool) get_post_meta($post->ID, self::META_SKIP_REDIRECT, true)) return;

		$url = (string) get_post_meta($post->ID, self::META_URL, true);
		if ($url === '') return;

		$parts = wp_parse_url($url);
		if (empty($parts['scheme']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) return;

		$perma = get_permalink($post);
		if ($perma && 0 === strcasecmp(trailingslashit($perma), trailingslashit($url))) return;

		wp_safe_redirect(self::decorate_url($url, 'redirect', $post->ID), 302);
		exit;
	}

	// On-site ↩︎ permalink glyph injection — always links to the INTERNAL post permalink
	public static function inject_site_glyph(string $content): string {
		if (is_admin() || is_feed()) return $content;

		$post = get_post();
		if (!$post instanceof WP_Post || $post->post_type !== 'linked_list') return $content;
		if (!get_option(self::OPT_SITE_GLYPH_ENABLE)) return $content;

		$glyph = (string) get_option(self::OPT_SITE_GLYPH_TEXT, '&#8617;');
		$perma = get_permalink($post);

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

	/* -------------------------------------------------------------------------
	 * Feeds
	 * ---------------------------------------------------------------------- */

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
		$perma = get_permalink($post); // INTERNAL permalink for feed glyph
		$anchor = sprintf('<a href="%s" rel="bookmark" class="linklog-feed-glyph">%s</a>', esc_url($perma), $glyph);
		return $content . "\n<p>{$anchor}</p>\n";
	}

	/* -------------------------------------------------------------------------
	 * UTM decoration (append without mutating saved meta)
	 * ---------------------------------------------------------------------- */
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

	/* -------------------------------------------------------------------------
	 * Exclude linked items from main blog loop (homepage)
	 * ---------------------------------------------------------------------- */
	public static function maybe_exclude_from_main(WP_Query $q): void {
		if (is_admin() || !$q->is_main_query()) return;
		if (!get_option(self::OPT_EXCLUDE_FROM_MAIN)) return;
		if (!$q->is_home()) return;

		// Ensure main loop fetches standard posts (many themes do this already).
		$q->set('post_type', 'post');

		// Exclude posts categorized/tagged as "Linked".
		$tax_query = (array) $q->get('tax_query');
		$tax_query[] = [
			'relation' => 'AND',
			[
				'taxonomy' => 'category',
				'field'    => 'slug',
				'terms'    => ['linked'],
				'operator' => 'NOT IN',
			],
			[
				'taxonomy' => 'post_tag',
				'field'    => 'slug',
				'terms'    => ['linked'],
				'operator' => 'NOT IN',
			],
		];
		$q->set('tax_query', $tax_query);

		// Exclude posts having link meta keys (for users who use meta on built-in posts).
		$meta_query = (array) $q->get('meta_query');
		$meta_query[] = [
			'relation' => 'AND',
			[
				'key'     => 'linklog_url',
				'compare' => 'NOT EXISTS',
			],
			[
				'key'     => 'linked_url', // legacy/alt key support
				'compare' => 'NOT EXISTS',
			],
		];
		$q->set('meta_query', $meta_query);
	}

	/* -------------------------------------------------------------------------
	 * Organizational helpers: ensure Category + Tag "Linked"
	 * ---------------------------------------------------------------------- */
	public static function ensure_terms(): void {
		$cat = get_term_by('slug', 'linked', 'category');
		if (!$cat) {
			wp_insert_term('Linked', 'category', ['slug' => 'linked']);
		}
		$tag = get_term_by('slug', 'linked', 'post_tag');
		if (!$tag) {
			wp_insert_term('Linked', 'post_tag', ['slug' => 'linked']);
		}
	}

	/* -------------------------------------------------------------------------
	 * Shortcode archive and auto-created Page /linked-list/
	 * ---------------------------------------------------------------------- */

	/**
	 * [linked_list_archive] — lists linked items from:
	 *   - CPT `linked_list`
	 *   - OR built-in posts marked as Linked (category/tag/meta)
	 */
	public static function shortcode_archive(array $atts = []): string {
		$atts = shortcode_atts([
			'posts_per_page' => (string) get_option('posts_per_page'),
			'paged'          => (string) max(1, (int) get_query_var('paged')),
		], $atts, 'linked_list_archive');

		$args = [
			'post_type'      => ['linked_list', 'post'],
			'posts_per_page' => (int) $atts['posts_per_page'],
			'paged'          => (int) $atts['paged'],
			// Pull CPT directly, OR posts that look “linked”
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
					'key'     => 'linklog_url',
					'compare' => 'EXISTS',
				],
				[
					'key'     => 'linked_url',
					'compare' => 'EXISTS',
				],
			],
		];

		// Make sure CPT items always included even if no taxonomy/meta on them
		// by duplicating the OR logic via 'suppress_filters' + filter on 'posts_where'
		add_filter('posts_where', $whereFilter = static function(string $where, WP_Query $q) {
			global $wpdb;
			// If this query is ours, broaden: include CPT regardless of tax/meta constraints.
			$post_types = (array) $q->get('post_type');
			if ($post_types && in_array('linked_list', $post_types, true)) {
				$where .= $wpdb->prepare(" OR {$wpdb->posts}.post_type = %s ", 'linked_list');
			}
			return $where;
		}, 10, 2);

		$q = new WP_Query($args);

		// Remove filter immediately after use
		remove_filter('posts_where', $whereFilter, 10);

		ob_start();
		if ($q->have_posts()) {
			echo '<div class="linked-list-archive">';
			while ($q->have_posts()) { $q->the_post();
				echo '<article class="linked-list-item">';
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
			if ($links) echo wp_kses_post($links);
		} else {
			echo '<p>' . esc_html__('No linked posts found.', 'linked-list') . '</p>';
		}
		wp_reset_postdata();
		return ob_get_clean();
	}

	/**
	 * Ensure a published Page exists at /linked-list/ using the shortcode.
	 */
	public static function ensure_archive_page(): void {
		$slug = 'linked-list';
		$existing = get_page_by_path($slug, OBJECT, 'page');
		if ($existing instanceof WP_Post) {
			return;
		}
		$page_id = wp_insert_post([
			'post_title'   => __('Linked List', 'linked-list'),
			'post_name'    => $slug,
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_content' => '[linked_list_archive]',
		]);
		if (!is_wp_error($page_id)) {
			update_option('linked_list_archive_page_id', (int) $page_id);
		}
	}
}

Plugin::init();
