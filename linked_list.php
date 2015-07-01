<?php
/*
Plugin Name: WP Linked List
Plugin URI: https://github.com/kevindayton/wp-linked-list/releases
Description: This is a fork of Yinjie Soon's DFLL-Plugin.  Make your blog's RSS feed behave like <a href="http://daringfireball.net">Daring Fireball</a>. To use, set the custom field "linked_list_url" to the desired location on a link post. See "DF-Style Linked List" under WordPress Settings for more options. <strong>NEW</strong>: Now supports "Post This" and Twitter Tools integration &mdash; please see settings page for more details or to enable these features.
Author: Yinjie Soon
Version: 2.9.1
Author URI: http://yjsoon.com/dfll-plugin
*/

define('DFLL_DELETE_CATEGORY_DEFAULT_DESC', '&#9733; Created by DF-Style Linked List Plugin.');
define('DFLL_DELETE_CATEGORY_WARNING', '<strong>NOTE</strong>: If you delete this, it will disable the link list custom category options.');

/*-----------------------------------------------------------------------------
  For theme developers - these should be all you need to refer to
-----------------------------------------------------------------------------*/

// To display the glyph with a permalink around it
function get_the_permalink_glyph() {
  return '<a href="' . get_permalink() . '" rel="bookmark" title="Permanent link to \''.get_the_title().'\'" class="glyph">'. get_glyph() .'</a>';
}
function the_permalink_glyph() {
  echo get_the_permalink_glyph();
}

// To display the linked list URL
function get_the_linked_list_link() {
  $url = get_post_custom_values("linked_list_url");
  return $url[0];
}
function the_linked_list_link() {
  echo get_the_linked_list_link();
}

// Just returns the glyph (this is set in the option under "Text for permalink")
function get_glyph() {
  return get_option('dfll_glyph_after_post_text');
}

// Called to see if the current post in the loop is a linked list
function is_linked_list() {
  // global $wp_query;
  // $postid = $wp_query->post->ID;
  // $url = get_post_meta($postid, 'linked_list_url', true);
  $url = get_post_custom_values('linked_list_url');
  if (!empty($url)) {
    $GLOBALS['dfllCustomFieldValue'] = $url;
    return true;
  } 
  return false;
  // return (!empty($url));
}


// Just returns the blog glyph (this is set in the option under "Highlight blog post titles")
function get_blog_glyph() {
  $options = get_option('dfll_options');  
  return $options['glyph_before_blog_title_text'];
}
// Same as above but echoes it
function the_blog_glyph() {
	echo get_blog_glyph();
}

// Just returns the link glyph (this is set in the option under "Highlight link post titles")
function get_link_glyph() {
  $options = get_option('dfll_options');  
  return $options['glyph_before_link_title_text'];
}
// Same as above but echoes it
function the_link_glyph() {
	echo get_link_glyph();
}

/*-----------------------------------------------------------------------------
  RSS modification handling functions
-----------------------------------------------------------------------------*/

// Echoes the linked list link if it should
function ensure_rss_linked_list($value) {
  if (get_option('dfll_link_goes_to') && is_linked_list()) {
    return get_the_linked_list_link();
  } else {
    return $value;
  }
}

// Now set function up to execute when the admin_footer action is called
// Priority 100 for compatibility with Google Analytics plugin
add_filter('the_permalink_rss', 'ensure_rss_linked_list', 100);


// Inject permalink glyph into RSS feed contents
function insert_permalink_glyph_rss($content) {
  $options = get_option('dfll_options');
  if (is_linked_list() && is_feed()) {
    if (get_option('dfll_glyph_after_post')) {
      $content = $content . "<p>" . get_the_permalink_glyph() . "</p>\n";
    }
  }
  return $content;
}
add_filter('the_content', 'insert_permalink_glyph_rss');
add_filter('the_excerpt_rss', 'insert_permalink_glyph_rss');

// Inject permalink glyph into RSS title
function insert_title_glyph_rss($title,$cdata=true) {
  $options = get_option('dfll_options');
  if (!is_linked_list() && get_option('dfll_glyph_before_blog_title')) { // if normal blog title
    $title = get_option('dfll_glyph_before_blog_title_text') . " " . $title;
  }
  elseif (is_linked_list()) { // if linked list title
    if (get_option('dfll_glyph_before_link_title')) $title  = ent2ncr(get_option('dfll_glyph_before_link_title_text')) . " " . $title;
    if (get_option('dfll_glyph_after_link_title'))   $title = $title . " " . ent2ncr(get_option('dfll_glyph_after_link_title_text'));
  }

  return $title;
}
add_filter('the_title_rss', 'insert_title_glyph_rss');


/*-----------------------------------------------------------------------------
  Options menu functions
-----------------------------------------------------------------------------*/

// Add the menu 
function dfll_menu() {
  global $dfll_adminpage;
  $dfll_adminpage = add_options_page('Linked List Options', 'DF-Style Linked List', 'manage_options', 'dfll', 'dfll_options_page');
  add_action("admin_head-$dfll_adminpage", "dfll_help");
  
}
add_action('admin_menu', 'dfll_menu');

// Initialise the settings
function dfll_init() {
	register_setting('dfll_options','dfll_link_goes_to');
	register_setting('dfll_options','dfll_glyph_after_post');
	register_setting('dfll_options','dfll_glyph_after_post_text');
	register_setting('dfll_options','dfll_glyph_before_link_title');
	register_setting('dfll_options','dfll_glyph_before_link_title_text');
	register_setting('dfll_options','dfll_glyph_after_link_title');
	register_setting('dfll_options','dfll_glyph_after_link_title_text');
	register_setting('dfll_options','dfll_glyph_before_blog_title');
	register_setting('dfll_options','dfll_glyph_before_blog_title_text');
	register_setting('dfll_options','dfll_use_first_link');
	register_setting('dfll_options','dfll_twitter_glyph_before_linked_list');
	register_setting('dfll_options','dfll_twitter_glyph_before_non_linked_list');
  register_setting('dfll_options','dfll_custom_category_name','dfll_custom_category_name_sanitize');
	register_setting('dfll_options','dfll_custom_category_exclude');
	register_setting('dfll_options','dfll_custom_category_desc','dfll_custom_category_desc_sanitize');
	register_setting('dfll_options','dfll_use_custom_category','dfll_custom_category_sanitize');
	register_setting('dfll_options','dfll_custom_category_hide_nav');
	
	if (!get_option('dfll_options')) {
		define('DFLL_VERSION','2.9.1');
	} else {
		define('DFLL_VERSION','2.7.4');
		// register_setting('dfll_options','dfll_options','dfll_sanitize_checkbox');
		upgrade_dfll();
	}

    add_settings_section("dfll_main", "Linked List Properties", "dfll_text", "dfll");
    add_settings_field("link_goes_to", "RSS link goes to linked item", "link_goes_to_callback", "dfll", "dfll_main");
    add_settings_field("glyph_after_post", "Insert permalink after post", "glyph_after_post_callback", "dfll", "dfll_main");
    add_settings_field("glyph_after_post_text", "", "glyph_after_post_text_callback", "dfll", "dfll_main");
    add_settings_field("glyph_before_link_title", "Highlight link post titles", "glyph_before_link_title_callback", "dfll", "dfll_main");
    add_settings_field("glyph_before_link_title_text", "", "glyph_before_link_title_text_callback", "dfll", "dfll_main");
    add_settings_field("glyph_after_link_title", "", "glyph_after_link_title_callback", "dfll", "dfll_main");
    add_settings_field("glyph_after_link_title_text", "", "glyph_after_link_title_text_callback", "dfll", "dfll_main");

    add_settings_section("dfll_main2", "Blog Post Properties", "dfll_text2", "dfll");
    add_settings_field("glyph_before_blog_title", "Highlight blog post titles", "glyph_before_blog_title_callback", "dfll", "dfll_main2");
    add_settings_field("glyph_before_blog_title_text", "", "glyph_before_blog_title_text_callback", "dfll", "dfll_main2");

    add_settings_section("dfll_main3", "Linking From Posts", "dfll_text3", "dfll");
    add_settings_field("use_first_link", "Use first link in post", "use_first_link_callback", "dfll", "dfll_main3");

    // add_settings_section("dfll_main4", "Twitter Tools integration", "dfll_text4", "dfll");
    // add_settings_field("twitter_glyph_before_non_linked_list", "Insert glyph before non-linked list items in tweets", "twitter_glyph_before_non_callback", "dfll", "dfll_main4");
    // add_settings_field("twitter_glyph_before_linked_list", "Insert glyph before linked list items in tweets", "twitter_glyph_before_callback", "dfll", "dfll_main4");
  
    add_settings_section("dfll_main4", "Custom Category", "dfll_text5", "dfll");
    add_settings_field("use_custom_category", "Use custom category for post", "use_custom_category_callback", "dfll", "dfll_main4");
    add_settings_field("custom_category_name", "", "custom_category_name_callback", "dfll", "dfll_main4");
    add_settings_field("custom_category_desc", "", "custom_category_desc_callback", "dfll", "dfll_main4");
    add_settings_field("use_custom_category_exclude", "Exclude custom category", "use_custom_category_exclude_callback", "dfll", "dfll_main4");
    add_settings_field("custom_category_hide_nav", "Hide navigation for custom category", "custom_category_hide_nav_callback", "dfll", "dfll_main4");       
}
add_action('admin_init', 'dfll_init');

function dfll_custom_category_name_sanitize($value) {
  if($value != $oldValue) {
  	$slug = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $value);
  	$oldValue = get_option('dfll_custom_category_name');
    $cat = get_term_by( 'name', get_option('dfll_custom_category_name'), 'category' );
    if( $cat != false ) {
      wp_update_term($cat->term_id,'category',array('name' => $value, 'slug' => $slug));
    } else {
      wp_insert_term(
        $value,
        'category',
        array('slug' => $slug,'description' => DFLL_DELETE_CATEGORY_DEFAULT_DESC.'&nbsp;&nbsp;'.DFLL_DELETE_CATEGORY_WARNING)
      );    
    }
  }
  return $value;
}

function dfll_custom_category_desc_sanitize($value) {
  $oldValue = get_option('dfll_custom_category_desc');
  $cat = get_term_by( 'name', get_option('dfll_custom_category_name'), 'category' );
  if( false != $cat ) {
    wp_update_term($cat->term_id,'category',array('description' => $value.'&nbsp;&nbsp;'.DFLL_DELETE_CATEGORY_WARNING));
  }
  return $value;
}

function dfll_custom_category_sanitize($value) {
  if(null == $value) {
    $cat = get_term_by( 'name', get_option('dfll_custom_category_name'), 'category' );
    if( false != $cat ) {
      $d = wp_delete_category( $cat->term_id );
    }  
  }
  return $value;
}

add_action( 'delete_term', 'dfll_custom_category_delete',10,4 );
function dfll_custom_category_delete( $term, $tt_id, $taxonomy, $deleted_term ) {
		if( $deleted_term->name == get_option('dfll_custom_category_name') ) {
			update_option('dfll_use_custom_category',false);	
		}
}


add_action( 'wp_insert_post', 'dfll_update_post_terms' );
function dfll_update_post_terms( $post_id ) {
    if( is_linked_list() ) {
      $use_custom_category = get_option('dfll_use_custom_category');
      if( 'on' == $use_custom_category ) {
        if ( $parent = wp_is_post_revision( $post_id ) )
            $post_id = $parent;
        $post = get_post( $post_id );
        if ( $post->post_type != 'post' )
            return;
    
        // add a category
        $categories = wp_get_post_categories( $post_id );
        $newcat    = get_term_by( 'name', get_option('dfll_custom_category_name'), 'category' );
        // if the category doesn't exist, create it
        if( false == $newcat ) {
          $insert_newcat = wp_insert_term(
        		get_option('dfll_custom_category_name'),
        		'category',
        		array('description' => DFLL_DELETE_CATEGORY_DEFAULT_DESC.'&nbsp;&nbsp;'.DFLL_DELETE_CATEGORY_WARNING)
        	);
        	$id = $insert_newcat['term_id'];
        } else {
          $id = $newcat->term_id;
        }
        
        // Linked list items should only use the designated caetgory
        $cat_count = count($categories);
        for($x=0; $x < $cat_count; $x++) {
            unset($categories[$x]);
        }
        array_push( $categories, $id);
        wp_set_post_categories( $post_id, $categories );
      }
    } else {
      $categories = wp_get_post_categories( $post_id );
      $newcat    = get_term_by( 'name', get_option('dfll_custom_category_name'), 'category' );
      // Non-link list items should not use the custom category
      $cat_count = count($categories);
      for($x=0; $x < $cat_count; $x++) {
        if($categories[$x] == $newcat->term_id) {
          unset($categories[$x]);
        }
      }
      wp_set_post_categories( $post_id, $categories );
    }
}




/* Callback functions to display each of the options */

function link_goes_to_callback() {
  $checked = "";
  if(get_option('dfll_link_goes_to')) { $checked = ' checked="checked" '; }
  echo "<input " . $checked . " name='dfll_link_goes_to' type='checkbox' />";
  echo " Linked list entries point to the linked item in question, i.e. when you click on the link title in your RSS reader, your browser opens that link instead of your blog permalink.";
}

function glyph_after_post_callback() {
  $checked = "";
  if(get_option('dfll_glyph_after_post')) { $checked = ' checked="checked" '; }
  echo "<input " . $checked . " name='dfll_glyph_after_post' type='checkbox' />";
  echo " At the bottom of each linked list blog post, show a permalink bringing you back to your blog post. On DF, this is &#9733; (which you should enter as &amp;#9733;). <em>Note for theme customizers</em>: this is what's returned in get_glyph() (just the text) and get_the_permalink_glyph() (text wrapped inside an anchor).";
}

function glyph_after_post_text_callback() { 
  echo "<label for='input1'>Text for permalink: </label>";
  echo "<input name='dfll_glyph_after_post_text' size='12' type='text' value='" . get_option('dfll_glyph_after_post_text') . "' id='input1' /> <span class='eg'>e.g. &amp;#9733; (&#9733;) or Permalink. ";
  if (!get_option('dfll_glyph_after_post')) echo "Remember to check the checkbox above.";
  echo "</span>";
}

function glyph_before_link_title_callback() {
  $checked = "";
  if(get_option('dfll_glyph_before_link_title')) { $checked = ' checked="checked" '; }
  echo "<input " . $checked . " name='dfll_glyph_before_link_title' type='checkbox' />";
  echo " Show text <em>before</em> linked-list article titles, e.g. <em>Link: </em>. This is useful if you want to distinguish these link posts from your regular blog posts, and may help readers figure out how to get to the link.";
}

function glyph_before_link_title_text_callback() { 
  $style = '';
  echo "<label for='input2'>Text to display: </label>";
  echo "<input {$style} name='dfll_glyph_before_link_title_text' size='12' type='text' value='" . get_option('dfll_glyph_before_link_title_text') . "' id='input2' /> <span class='eg'>e.g. Link:. ";
  if (!get_option('dfll_glyph_before_link_title')) echo "Remember to check the checkbox above.";
  echo "</span>";
}

function glyph_after_link_title_callback() {
  $checked = "";
  if(get_option('dfll_glyph_after_link_title')) { $checked = ' checked="checked" '; }
  echo "<input " . $checked . " name='dfll_glyph_after_link_title' type='checkbox' />";
  echo " Show text <em>after</em> linked-list article titles, e.g. &raquo; (which you should enter as &amp;raquo;). This is useful if you want to distinguish these link posts from your regular blog posts, and may help readers figure out how to get to the link.";
}

function glyph_after_link_title_text_callback() { 
  $style = '';
  echo "<label for='input3'>Text to display: </label>";
  echo "<input {$style} name='dfll_glyph_after_link_title_text' size='12' type='text' value='" . get_option('dfll_glyph_after_link_title_text') . "' id='input3' /> <span class='eg'>e.g. &amp;raquo; (&raquo;). ";
  if (!get_option('dfll_glyph_after_link_title')) echo "Remember to check the checkbox above.";
  echo "</span>";  
}

function glyph_before_blog_title_callback() {
  $checked = "";
  if(get_option('dfll_glyph_before_blog_title')) { $checked = ' checked="checked" '; }
  echo "<input " . $checked . " name='dfll_glyph_before_blog_title' type='checkbox' />";
  echo " Show text before blog article titles in the RSS feed. This helps distinguish them from link posts, which is useful if you link more than you post. DF has a &#9733; (which you should enter as &amp;#9733;) in front of such articles.";
}

function glyph_before_blog_title_text_callback() {
  $style = '';
  echo "<label for='input4'>Text to display: </label>";
  echo "<input {$style} name='dfll_glyph_before_blog_title_text' size='12' type='text' value='" . get_option('dfll_glyph_before_blog_title_text') . "' id='input4' /> <span class='eg'>e.g. &amp;#9733; (&#9733;). ";
  if (!get_option('dfll_glyph_before_blog_title')) echo "Remember to check the checkbox above.";
  echo "</span>";  
}

function use_first_link_callback() {
  $checked = '';
  if(get_option('dfll_use_first_link')) { $checked = ' checked="checked" ';}
  echo "<input " . $checked . " name='dfll_use_first_link' type='checkbox' />";
  echo " <strong>Warning</strong>: Please read the instructions below carefully and disable this feature if it affects your post content unexpectedly!";
  echo "<div style='padding-left: 1em; border-left: 4px solid #bbb; margin-top: 0.5em'>";
  echo "<h3>Very Important Instructions &mdash; this feature will change your posts</h3>";
  echo "<p>This feature allows you to set the linked_list_url custom field from within the post content. This is especially handy for using with the 'Press This' bookmarklet.</p>"; 
  echo "<p>When you activate this feature, the DFLL plugin will look at the first line of your post content for a link anchor, and it'll set that link as the linked_list_url for your post. For example, the following post content:</p>";
  echo "<pre style='border:1px solid #999; margin-left: 1em; padding: 1em; width: 70%; background: #eee; margin-bottom: 1em;'>&lt;a href='http://google.com'&gt;Google!!!&lt;/a&gt;.\nThis is a link post to Google.</pre>";
  echo "<p>... will have its first line removed, the URL http://google.com passed into the custom field <em>linked_list_url</em>, and will have its first line removed to just end up with the text 'This is a link post to Google'. The text in the anchor ('Google!!!') will be ignored.</p>";
  echo "<p>It's very important to note three requirements: (i) the anchor tag must be in the first line of the post, (ii) the tag must be the only element on that line, and (iii) the line <strong>must end in a period</strong>. This is the syntax that the 'Press This' bookmarklet uses, so you can just hit 'Press This' and enter to go to the next line and stop typing.</p>";
  echo "<p>Worth noting again: Any text in the anchor will be ignored, and the entire first line will be discarded. This also means that if, for whatever, reason, you like posting link anchors that end in periods as the first line of your blog, you shouldn't activate this checkbox, or you'll end up with linked list posts by accident!</p>"; 
  echo "</div>";
}

function use_custom_category_callback() {
  $checked = "";
  if(get_option('dfll_use_custom_category')) { $checked = ' checked="checked" '; }
  echo "<input " . $checked . " name='dfll_use_custom_category' type='checkbox' />";
  echo " Selecting this option will create and use a custom category for link list posts.";
}

function use_custom_category_exclude_callback() {
  $checked = "";
  if(get_option('dfll_custom_category_exclude')) { $checked = ' checked="checked" '; }
  echo "<input " . $checked . " name='dfll_custom_category_exclude' type='checkbox' />";
  echo " Selecting this option will exclude the post in the custom category from previous and next links.";
}

function custom_category_hide_nav_callback() {
  $checked = "";
  if(get_option('dfll_custom_category_hide_nav')) { $checked = ' checked="checked" '; }
  echo "<input " . $checked . " name='dfll_custom_category_hide_nav' type='checkbox' />";
  echo " Selecting this option will hide next and previous links for posts in this custom category when viewing the full post.";
}

function custom_category_name_callback() {
  $style = '';
  echo "<label for='input4'>Category name: </label>";
  echo "<input {$style} name='dfll_custom_category_name' size='12' type='text' value='" . get_option('dfll_custom_category_name') . "' id='input4' /> <span class='eg'>e.g. Link List Items.  ";
  if (!get_option('dfll_custom_category_name')) echo "(Remember to check the checkbox above.)";
  echo "</span>";  
}

function custom_category_desc_callback() {
  $style = '';
  echo "<label for='input4'>Category Description: </label>";
  echo "<input {$style} name='dfll_custom_category_desc' size='50' type='text' value='" . get_option('dfll_custom_category_desc') . "' id='input4' /> <span class='eg'>e.g. '&#9733; Created by DF-Style Linked List Plugin";
  echo "</span>";  
}

function twitter_glyph_before_non_callback() {
  $checked = '';	
  if(get_option('dfll_twitter_glyph_before_non_linked_list')) { $checked = ' checked="checked" ';}
  echo "<input " . $checked . " name='dfll_twitter_glyph_before_non_linked_list' type='checkbox' />";
  echo " Inserts your glyph (from 'Insert permalink after post') before any tweets that are not linked list posts. <p>For example, Daring Fireball's <a href='http://twitter.com/daringfireball'>Twitter feed</a> has a star glyph before tweets which link to full blog posts. </p><p>Note that you don't have to enable the checkbox for 'Insert permalink after post' &mdash; it'll just pull the glyph from the 'Text for permalink' text box above.</p>";

}

function twitter_glyph_before_callback() {
  $checked = '';	
  if(get_option('dfll_twitter_glyph_before_linked_list')) { $checked = ' checked="checked" ';}
  echo "<input " . $checked . " name='dfll_twitter_glyph_before_linked_list' type='checkbox' />";
  echo " Insert the pre-link-list text (defined at 'Highlight link post titles') before any tweets that are linked lists. <p>E.g. if your pre-link-list text is 'Link:', your tweet would become 'Link: This is my post http://yjsoon.com'. </p><p>Note that you don't have to enable the checkbox for 'Show text before linked-list articles' &mdash; it'll just pull the glyph from the 'Link title text' text box above.</p>";

}

/* Callback functions for main sections */

function dfll_text() {
  echo "<p>This section defines the behaviour of RSS entries of linked list posts. Default behaviour follows Daring Fireball.</p>";
}

function dfll_text2() {
  echo "<p>This section defines the behaviour of RSS entries of blog posts (i.e., not links).</p>";  
}

function dfll_text3() {
  echo "<p>This section allows you to enable the posting of linked_list_url links from <em>within</em> your posts, so you don't have to set the custom field yourself.</p>";
}

function dfll_text4() {
  echo "<p>This section customises whether your glyph shows up in auto-tweets. You must have the <a href='http://wordpress.org/extend/plugins/twitter-tools/'>Twitter Tools</a> plugin installed.</p><p><b>Important</b>: Please note that there are quite a few steps to follow if you want to use this feature. Refer to the 'Twitter Tools' section on <a href='http://yjsoon.com/dfll-plugin'>this post</a> to proceed.";
}

function dfll_text5() {
  echo "<p>This section customizes whether your you use a custom category for link list posts.";
}

/* Add default options */

register_activation_hook(__FILE__, 'dfll_defaults_callback');
// Define default option settings


function dfll_defaults_callback() {
	update_option('dfll_link_goes_to',true);
	update_option('dfll_glyph_after_post',true); 
	update_option('dfll_glyph_after_post_text','&#9733;'); 
	update_option('dfll_glyph_before_link_title',''); 
	update_option('dfll_glyph_before_link_title_text',''); 
	update_option('dfll_glyph_after_link_title',''); 
	update_option('dfll_glyph_after_link_title_text',''); 
	update_option('dfll_glyph_before_blog_title',true); 
	update_option('dfll_glyph_before_blog_title_text','&#9733;');
	update_option('dfll_use_first_link',false); 
	update_option('dfll_twitter_glyph_before_linked_list','');
	update_option('dfll_twitter_glyph_before_non_linked_list','');
}

function upgrade_dfll() {
	$options = get_option('dfll_options');
	
	update_option('dfll_link_goes_to',$options['link_goes_to']);
	update_option('dfll_glyph_after_post',$options['glyph_after_post']); 
	update_option('dfll_glyph_after_post_text',$options['glyph_after_post_text']); 
	update_option('dfll_glyph_before_link_title',$options['glyph_before_link_title']); 
	update_option('dfll_glyph_before_link_title_text',$options['glyph_before_link_title_text']); 
	update_option('dfll_glyph_after_link_title',$options['glyph_after_link_title']); 
	update_option('dfll_glyph_after_link_title_text',$options['glyph_after_link_title_text']); 
	update_option('dfll_glyph_before_blog_title',$options['glyph_before_blog_title']); 
	update_option('dfll_glyph_before_blog_title_text',$options['glyph_before_blog_title_text']);
	update_option('dfll_use_first_link',$options['use_first_link']); 
	update_option('dfll_twitter_glyph_before_linked_list',$options['twitter_glyph_before_linked_list']);
	update_option('dfll_twitter_glyph_before_non_linked_list',$options['twitter_glyph_before_non_linked_list']);
	update_option('dfll_options',false);
	
	
}

/*
function dfll_sanitize_checkbox($options) {
  $dfll_off = array("link_goes_to"=>false, 
                            "glyph_after_post" => false, 
                            "glyph_after_post_text" => "", 
                            "glyph_before_link_title" => false, 
                            "glyph_before_link_title_text" => "", 
                            "glyph_after_link_title" => false, 
                            "glyph_after_link_title_text" => "", 
                            "glyph_before_blog_title" => false, 
                            "glyph_before_blog_title_text" => ""
                            );
  return array_merge($dfll_off,$options);
}
 */

/* Add help */
function dfll_help() {
  global $dfll_adminpage;
  $help = '<h3>Some Notes</strong></h3>';
  $help .= '<ul style="margin-left: 1.5em; list-style-type:disc;">';
  $help .= "<li>Changing the settings on this page <em>only affects the behaviour of your RSS feeds</em>, i.e. it won't change the way your blog is displayed on the web. To change your blog's display properties, edit your theme to use the following functions: is_linked_list(), get_the_linked_list_link(), get_glyph() and get_the_permalink_glyph().</li>";
  $help .= "<li>To enable linked list post behaviour, make sure you create a custom field called <strong>linked_list_url</strong> containing the link you want your post to go to. Other posts without this custom field will be treated as blog, or \"regular\", posts. If you don't know what custom fields are or how to set them, read the first few sections of <a href=\"http://www.rlmseo.com/blog/wordpress-custom-fields/\">this article</a>.</li>";
  $help .= '<li>Some glyphs (symbols) you can use: &#9733; (&amp;#9733;), &#8594; (&amp;#8594;), &#8658; (&amp;#8658;), &nabla; (&amp;nabla;), &loz; (&amp;loz;), &#10004; (&amp;#10004;), &#10010; (&amp;#10010;), &#10020; (&amp;#10020;), &#10022; (&amp;#10022;), &#9819; (&amp;#9819;), &#9820; (&amp;#9820;), &raquo; (&amp;raquo;), &laquo; (&amp;laquo;), and <a href="http://www.danshort.com/HTMLentities/index.php">more here</a>. You should copy and paste the HTML character entity codes (not the symbols) into the fields below.</li>';
  $help .= '<li>For theme designers, these are the functions that you can use: get_the_permalink_glyph(), the_permalink_glyph(), get_the_linked_list_link(), the_linked_list_link(), get_glyph() and is_linked_list().</li>';
  $help .= "</ul>";
  $help .="<p>For more information or to contact the author, please refer to the <a href=\"http://github.com/yjsoon/df-style-linked-list_wordpress-plugin\">plugin homepage</a>.</p>";
  if (function_exists('get_current_screen')) {
	$screen = get_current_screen();
    if ($screen->id != $dfll_adminpage ) {
	  return;
    }
    $screen->add_help_tab(array('id'=>'settings_page_dfll','title'=>'Linked List Help','content'=>$help));  
  }
  
}

/* Actual options page rendering */

function dfll_options_page() {

  ?>
  
  <style type="text/css" media="screen">
   .eg { color: #888; }
   .append { color: #d66; }
  </style>
  
  <div class="wrap">
    <div id="icon-options-general" class="icon32"><br></div>
    <h2>Daring Fireball-Style Linked List Plugin Settings</h2>

<!--
    <div style="border:1px solid #aaa;margin:2em 0 1em;background-color:#eee;padding:0 1em 1em;" id="df-expl">
      <h3>Notes - Read First!</h3>
    </div>
-->
    <p><em>Please take a look at the help drop down menu (up there &#8599; ) for more information on getting started. When entering symbols, it's advisable to use numerical HTML character entities &mdash; the ones with a &amp; in front and which end with semicolons &mdash; instead of the symbols themselves. This may prevent URL errors.</em></p>

    <form name="df-form" method="post" action="options.php">
      <?php settings_fields('dfll_options'); ?>
      <?php do_settings_sections('dfll'); ?>
      <p class="submit"><input type="submit" name="Submit" class="button-primary" value="<?php esc_attr_e('Save Changes') ?>" /></p>      
    </form>
  
  </div>

  <?php

}

/*-----------------------------------------------------------------------------
  Allows you to post by putting in a link anchor in the first line of your
  post content, as long as it's the only element on that line, and it ends in 
  a period.

  Adapted from CF Setter by Justin Blanton: http://hypertext.net/projects/cfsetter 
  Thanks, Justin! You rock.
-----------------------------------------------------------------------------*/

/* dfll_customField_getValue
* Reads in the post content, finds the custom field value you want to use and sets it as a global variable.
* @param STRING
* @return STRING
*/
function dfll_customField_getValue($post_content) {

   $options = get_option('dfll_options');
 
    if (get_option('dfll_use_first_link')) { // TODO: change to get_option

      $split_post_content = explode("\n", $post_content);
      // First, check if this is the only link on the line -- the line starts with <a href, ends with </a>.
      // (includes dot, and optionally a carriage return) 
      $reg = '/^(\\<p\\>)?\\<a href[^<]*\\<\\/a\\>\\.(\r)?$/';
      if (preg_match($reg, $split_post_content[0])) { // found it! Let's goooooooo

        // Open up a HTML parser (no regular expressions here!) and extract the href
        $d = new DOMDocument();
        $d->loadHTML("<html>".$split_post_content[0]."</html>");
        foreach ($d->getElementsByTagName('a') as $tag) {
          $link = $tag->getAttribute('href');
          break; // Should only be one... but whatever, just get out. I wish node(0) worked.
        }
        $link = substr($link, 2, -1); // Strip the "s. But why start at 2?!?!
        // Set the custom field value to that link.
        $GLOBALS['dfllCustomFieldValue'] = $link;

        // Now to clear and reconstruct the entire post_content, sans first line. Yes, we're getting
        // rid of the entire first line. This is why we have to be sure there's nothing else there!
        $post_content = ""; 
        for ($i=1; $i<count($split_post_content); $i++) {
          $post_content .= $split_post_content[$i] . "\n";
        }
        
      }

    }

    return $post_content;
}

/* dfll_customField_setValue
* Sets the custom field value.
* @param STRING
*/
function dfll_customField_setValue($post_id) {
    global $dfllCustomFieldValue;
    
    // Insert the custom field value, if it isn't already inserted
    if ($dfllCustomFieldValue) {
        add_post_meta($post_id, 'linked_list_url', $dfllCustomFieldValue, true);
    }
}

// Grab the custom field value and save to a global
add_filter('content_save_pre', 'dfll_customField_getValue'); 
// Insert the custom field value into the post's metadata
add_action('save_post', 'dfll_customField_setValue');

/*-----------------------------------------------------------------------------
  Hooks into the Twitter Tools plugin to allow you to tweet your glyph along
  with linked list or non-linked list posts. Thanks to Ben Brooks for the 
  suggestion. Twitter Tools is at http://wordpress.org/extend/plugins/twitter-tools/
-----------------------------------------------------------------------------*/

function dfll_tweet($tweet, $post_id) {
  global $dfllCustomFieldValue; // in case it was added using the first-line method
  $url = get_post_meta($post_id, 'linked_list_url', true);

  if (empty($url) && empty($dfllCustomFieldValue)) { // not a linked list item
    if (get_option('dfll_twitter_glyph_before_non_linked_list')) { // check for option 
      $tweet->tw_text = get_glyph() . " " . $tweet->tw_text;
    }
  } else { // is a linked list item
    if (get_option('dfll_twitter_glyph_before_linked_list')) { // check for option 
      $tweet->tw_text =  get_option('dfll_glyph_before_link_title_text') . " " . $tweet->tw_text;
    }
  }

  return $tweet;
}
add_filter('aktt_do_tweet', 'dfll_tweet', 15, 2);


/* Plugin settings access
------------------------------------------------------------------------------ */

function dfll_plugin_action_links($links, $file) {
	$plugin_file = basename(__FILE__);
	if (basename($file) == $plugin_file) {
		$settings_link = '<a href="options-general.php?page=dfll">'.__('Settings', 'dfll').'</a>';
		array_unshift($links, $settings_link);
	}
	return $links;
}
add_filter('plugin_action_links', 'dfll_plugin_action_links', 10, 2);


/* Linkmarklet possible fix (doesn't work)
------------------------------------------------------------------------------ */

// add_action('save_post', 'adjust_customfield_before_saving');
// 
// function adjust_customfield_before_saving( $post_id ) 
// {
//     $settings       = get_option( LINKMARKLET_PREFIX . 'settings' );
//     $custom_field   = isset( $settings['custom_field'] ) ? $settings['custom_field'] : '';
//     if( !empty( $custom_field ) )
//         update_post_meta( $post_id, $custom_field, mysql_real_escape_string($_POST['url']) );
// }

/* Manipulating the previous and next buttons
------------------------------------------------------------------------------ */
add_filter( 'get_previous_post_where', 'dfll_mod_adjacent_bis_where' );
add_filter( 'get_next_post_where', 'dfll_mod_adjacent_bis_where' );

/** 
 * get_{$adjacent}_post_where hook
 */
function dfll_mod_adjacent_bis_where( $where ) {
  global $wpdb;
  $use_custom_category = get_option('dfll_use_custom_category');
  $exlude_custom_category = get_option('dfll_custom_category_exclude');
  $hide_nav = get_option('dfll_custom_category_hide_nav');
  if( $use_custom_category == 'on' && is_linked_list() && $hide_nav == 'on') {
  	$where .= " AND 1 = 0 "; // This might be a bit of a hack, but it works.
	} else if( $use_custom_category == 'on') {
	    if(is_linked_list()) {
  	    if( $exlude_custom_category == 'on' && $hide_nav == 'on') {
          $where .= " AND $wpdb->terms.name = '".get_option('dfll_custom_category_name')."'
          AND $wpdb->term_taxonomy.taxonomy = 'category' ";
        } 
      } else if( $use_custom_category == 'on' && $exlude_custom_category == 'on') {
        $where .= " AND $wpdb->terms.name != '".get_option('dfll_custom_category_name')."'
        AND $wpdb->term_taxonomy.taxonomy = 'category' ";
      }
  }

	return $where;
}

add_filter( 'get_previous_post_join', 'dfll_mod_adjacent_bis_join' );
add_filter( 'get_next_post_join', 'dfll_mod_adjacent_bis_join' );

/** 
 * get_{$adjacent}_post_join hook
 */
function dfll_mod_adjacent_bis_join( $join ) {
  $use_custom_category = get_option('dfll_use_custom_category');
  $exlude_custom_category = get_option('dfll_custom_category_exclude');
  if( $use_custom_category == 'on' && $exlude_custom_category == 'on') {
    global $wpdb;
    $join .= " LEFT JOIN $wpdb->term_relationships ON $wpdb->term_relationships.object_id = p.ID LEFT JOIN $wpdb->term_taxonomy on $wpdb->term_taxonomy.term_taxonomy_id = $wpdb->term_relationships.term_taxonomy_id LEFT JOIN $wpdb->terms ON $wpdb->term_taxonomy.term_id = $wpdb->terms.term_id ";
  }
  return $join;
}

?>
