# Linked List

Enables link-blogging in WordPress with a **Linked List** post type. Add external links, optional redirection, feed adjustments, UTM tracking, and more — without editing the theme.

## Features

- __Share an article with commentary__: title links externally, but you can keep readers on your site by turning off auto-redirect.
- **Custom post type**: “Linked List” with individual “Linked Posts”.
- **Link URL field**: Add an external URL for each post.
- **Per-post overrides**:
  - **Don’t auto-redirect this post** – show post content instead of redirecting.  
    *Tooltip: “Show post content instead of redirecting”*
  - **Use post permalink in lists** – link titles to your post instead of the external site.  
    *Tooltip: “Link titles to post page (not external site)”*
- **Permalink glyphs**: Append ↩︎ (on site) and ★ (in feeds), always pointing back to your post permalink.
- **Analytics**: Append UTM parameters automatically to external links.
- **Theme-agnostic**: Works with any WordPress theme — no template edits required.

## Installation

1. Upload the `linked-list` plugin folder to your `/wp-content/plugins/` directory, or install via the Plugins screen.
2. Activate the plugin from the **Plugins** menu in WordPress.
3. A new menu item **Linked List** will appear in the dashboard.

## Usage

1. Go to **Linked List → Add New**.
2. Add a title and any commentary in the editor.
3. In the sidebar (block editor) or metabox (classic editor), paste your **Link URL**.
4. Publish the post.

### Default behavior

- On the homepage and archives, Linked Post titles link to the external URL.
- Visiting a single Linked Post permalink (e.g. `/linked-list/example/`) redirects to the external link.
- In RSS feeds, items link externally and include a glyph back to your post permalink.

### Per-post overrides

- **Don’t auto-redirect this post**  
  Visitors will see your post content at the permalink instead of being redirected.

- **Use post permalink in lists**  
  Homepage/archive titles will link to your post permalink rather than the external link.

### Settings

Found under **Settings → Linked List**:

- Toggle global redirect and rewrite behavior.
- Configure on-site ↩︎ glyph and feed ★ glyph.
- Enable UTM parameter appending and define defaults (`utm_source`, `utm_medium`, etc.).

### Tips

- To bypass a redirect and view your post content, append `?stay=1` to the post URL.  
- Glyph symbols can be customized with any HTML entity (e.g. `&#8617;` for ↩︎).

## License

MIT — see [LICENSE](https://opensource.org/licenses/MIT).
