# WP Linked List
Contributors: Arnab Wahid
Tags: linklog, linked list, external links, blogging, feeds
Requires at least: 6.3
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.3.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Create Daring Fireball–style link posts in WordPress. Register a “Linked List” post type, add external link support, optional redirection, feed adjustments, UTM tracking, and more — without changing your theme.

## Description

Linked List brings [Daring Fireball](https://daringfireball.net/)–style link blogging to WordPress, with no theme hacking required.

* Registers a new custom post type called **Linked List**. Each item is a **Linked Post**.
* Each Linked Post includes a **Link URL** meta field where you paste the external link.
* Optional per-post overrides:
  * **Don’t auto-redirect this post** – show your post content instead of sending visitors straight to the external link.
  * **Use post permalink in lists** – titles on the homepage and archives link to your post instead of the external site.
* Adds an optional **↩︎ permalink glyph** to content and feeds, pointing back to the post permalink.
* Supports **UTM analytics parameters** appended to external links automatically.
* Works with **any theme** — links are rewritten and redirections happen via filters and hooks.

This is ideal if you write commentary on articles or share interesting links but want them styled and syndicated like John Gruber’s “Linked List”.

## Installation

1. Upload the `linked-list` plugin folder to your `/wp-content/plugins/` directory, or install via the Plugins screen.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. A new menu item **Linked List** will appear in the dashboard sidebar.

## Usage

1. Go to **Linked List → Add New**.
2. Enter your post title and any commentary in the editor.
3. In the sidebar (block editor) or metabox (classic editor), paste your **Link URL**.
4. Publish the post.

By default:
* On your homepage and archives, the Linked Post title links directly to the external site.
* Visiting the single Linked Post permalink (e.g. `/linked-list/example/`) redirects to the external link.
* In your RSS feed, the item’s `<link>` points to the external site, with a ★ glyph appended linking back to your permalink.

### Per-post overrides

Inside the Linked Post editor:

* **Don’t auto-redirect this post**  
  Tooltip: *“Show post content instead of redirecting”*  
  When checked, the post permalink will display your content instead of redirecting.

* **Use post permalink in lists**  
  Tooltip: *“Link titles to post page (not external site)”*  
  When checked, the post title in home/archives will link to your WordPress permalink.

### Settings

Navigate to **Settings → Linked List**:

* Toggle global redirect and rewrite behavior.
* Customize the on-site ↩︎ glyph and feed ★ glyph.
* Enable/disable UTM parameter auto-append, and set default values (`utm_source`, `utm_medium`, etc).

### Examples

* Quick link share:  
  - Title: *“Great article about WordPress performance”*  
  - Link URL: `https://example.com/article`  
  - Result: visitors clicking the title on your homepage go to `example.com`, and single view redirects there.

* Commentary post:  
  - Same as above, but check **Don’t auto-redirect this post**.  
  - Result: homepage still links externally, but `/linked-list/my-post/` shows your commentary and the ↩︎ glyph.

* Internal blog-style post:  
  - Check both **Don’t auto-redirect** and **Use post permalink in lists**.  
  - Result: behaves exactly like a normal WordPress blog post.

== Frequently Asked Questions ==

= Can I disable the redirect globally? =  
Yes. Go to **Settings → Linked List** and uncheck *Redirect single Linked Post to external URL*.

= Can I change the glyph symbol? =  
Yes. In the settings, replace the glyph text (e.g. `&#8617;` for ↩︎, `&#9733;` for ★).

= Do I need to edit my theme? =  
No. This plugin rewires links and adds glyphs using WordPress hooks. It works with any theme.

= How do I view a Linked Post internally if redirect is on? =  
Append `?stay=1` to the post URL, e.g. `/linked-list/my-post/?stay=1`.
<!---
## Screenshots

1. Block editor sidebar with Link URL and toggles.  
2. Linked List settings page.  
3. Front-end view showing a Linked Post with ↩︎ glyph appended.
--->
## Changelog

### 1.3.0
* Rename plugin and text domain to **Linked List**.
* Improved toggle labels:  
  - “Don’t auto-redirect this post” with tooltip *“Show post content instead of redirecting”*.  
  - “Use post permalink in lists” with tooltip *“Link titles to post page (not external site)”*.  
* Ensure glyphs always point to the **internal post permalink**.

### 1.2.0 
* Added per-post overrides for redirect/rewrite.
* Added Gutenberg sidebar panel for Link URL + overrides.
* Added optional on-site ↩︎ glyph injection.
* Added UTM parameter auto-append.

### 1.0.0
* Initial release.

## License

This plugin is licensed under the MIT License.  
See: https://opensource.org/licenses/MIT
