<?php
/*
Plugin Name: KB Backtick Comments
Plugin URI: http://adambrown.info/b/widgets/category/kb-backtick-comments/
Description: Include code in posts or comments by putting it in `backticks`
Author: Adam Brown
Author URI: http://adambrown.info/
Version: 0.2
*/



// OPTIONAL SETTINGS
// If this plugin gets used by lots of people, then I'll take the time to make these options settable via the WP admin. But since I doubt
// many people besides me will need this plugin, I'll take the easy route for now and have you set options here via constants.

// Note that all settings are wrapped in an "if (!defined())" condition. That allows you to define these settings in your theme's
// functions.php if you want, so that you won't need to change these settings each time you upgrade the plugin.

// This plugin spits out some CSS to make the code look a little better. If you would prefer that it did not insert any CSS into your site,
// set this to false. You'll want to insert appropriate CSS into your theme, though.
if (!defined('KB_BACKTICK_CSS'))
	define('KB_BACKTICK_CSS',true);

// To control how this plugin's code blocks look, modify the style declarations at the very end of this file. If all you want to change is the colors, though,
// you can do that here. (These settings apply only if KB_BACKTICK_CSS is true.)
if (!defined('KB_BACKTICK_BG'))
	define('KB_BACKTICK_BG', '#e3e3ff');	// what background color will code blocks have? (Affects BOTH code blocks AND inline code.)
if (!defined('KB_BACKTICK_BORDER'))
	define('KB_BACKTICK_BORDER', '#88a');	// what border color will code blocks have? (Does not affect inline code--code blocks only.)




/*
	WHAT DOES THIS DO?
	Allows your commenters to paste code in their comments by enclosing it in `backticks`, like in the wp.org support forums.
	Also works in posts, but only if you have the visual editor disabled (in your profile).

	TIPS
	* If you need to include backticks in the code you paste, escape them with a backslash: \`
	* If, for some odd reason, you need to include a \` in what you post, double escape it (two backslashes): \\`
	
	LICENSE
	THIS IS BETA SOFTWARE. I have only tested it on my platform under limited circumstances. In other words, I wrote this to do something specific on my site. I hope
	it works for you too, but I don't promise anything. You use this entirely at your own risk. You are welcome to modify this code, but please refer people to my site
	(or WP.org if it gets listed there) to download the plugin rather than redistributing it from your own site. I mean really, I gave this to you for free, so the least you 
	can do is let me have a shot at getting some advertising revenue by sending people to my site to get the code.

	DEV NOTES
	This plugin works in three stages.
	1) 	Before a comment/post gets saved, we look for `...` and replace it with `<code>htmlspecialchars(...)</code>` (preserving the backticks, as you see, to make the block
		of code recognizable to later stages of the plugin)
	2)	Before a comment/post gets displayed, we remove the backticks and, if the code is a separate paragraph, wrap it in <pre> tags. (This is a distinct stage
		from the first one because <pre> tags aren't allowed in comments.)
	3)	When a comment/post gets opened for editing, we look for `<code>...</code>`, remove the <code> tags, and apply htmlspecialchars_decode() to the contents.
	
	SOMETHING WEIRD -- CURIOUS ABOUT ALL THE __abENT__ STUFF IN THE CODE?
	WP will not let you post a comment that contains certain HTML entities, including &#39;. So we strip all entities in stage 1 and reformat them as __abENT__###; (where
	### is the entity code), then in stages 2 and 3 we turn that back into &###; for display. You shouldn't ever see a __abENT__ show up in the output, though.
*/




// STAGE 1 FILTERS: BEFORE A COMMENT/POST GETS SAVED
$ab_bslash = '\\'; // makes life easier when you're trying to tell \ from \\. (This is a single backslash.)

// looks for code blocks (in backticks), sends to callback
function ab_backtickFilter($content){
	if (false===strpos($content,'`')) // save CPU resources
		return $content;
	global $ab_bslash;
	
	// check for special problems:
	// on the offchance that somebody's code contains '__abENT__' in it, we should replace the underscores with entity #95 (makes posting this file possible, for example):
	$content = str_replace('__abENT__', '__abENT__#95;__abENT__#95;abENT__abENT__#95;__abENT__#95;', $content);
	// allow for escaped backticks. Remember that posting vars adds slashes, so we check for two slashes, not just one:
	$content = str_replace($ab_bslash.$ab_bslash.'`', '__abENT__#96;', $content);
	// but just in case, we'll also look for just one slash:
	$content = str_replace($ab_bslash.'`', '__abENT__#96;', $content);

	// do it
	$content = preg_replace_callback( '|`([^`]+)`|Us', 'ab_backtickCallback', $content );
	return $content;
}
function ab_backtickCallback($matches){ // callback
	$r = trim($matches[1]);
	$r = stripslashes($r); // do this before converting any backslashes to entities (see below)
	$r = htmlspecialchars($r);

	// inexplicably, if $r contains &#039; the comment won't post, so we can't use htmlspecialchars(...,ENT_QUOTES). (More broadly, this is why we have that ab_removeEnts() function.)
	// But we also can't just leave the apostrophes untouched, or WP texturizes them into "friendly" apostrophes. So we turn them into &apos; even though &apos;
	// isn't recognized by IE. We'll turn the &apos; back into &#039; with our stage 2 filters.
	$r = str_replace( "'", '&apos;', $r );

	// some other cleanup:
	global $ab_bslash;
	$r = str_replace( $ab_bslash, '&#92;', $r ); // convert backslashes to entities (lest WP have problems)
	$r = str_replace( '.', '&#46;', $r ); // lest WP turn ... into an elipses entity
	$r = str_replace( '/', '&#8260;', $r ); // break links in the code (lest WP underline them) by replacing slashes with fraction entities (which are similar to slashes)

	// wrap in tags:
	$r = '`<code>' . nl2br($r) . '</code>`';

	// remove all entities, lest WP refuse to post the comment (not necessary for posts, but very necessary for comments)
	$r = ab_removeEnts($r);
	return $r;
}
// Our hooks. Do it early, so kses and wp_texturize haven't hit yet--priority of 5
add_filter('content_save_pre', 'ab_backtickFilter', 5);
add_filter('pre_comment_content', 'ab_backtickFilter', 5);




// STAGE 2 FILTERS: BEFORE DISPLAYING A COMMENT/POST:

function ab_backtickStage2($content){
	if (false==strpos($content,'`<code>')) // save CPU resources
		return $content;

	// first we look for blocks of code and apply <pre> tags:
	$content = preg_replace_callback('|<p>`<code>(.*)</code>`</p>|Us', 'ab_backtickCallback2', $content );

	// then we look for inline code and remove the backticks:
	$content = preg_replace('|`<code>(.*)</code>`|Us', '<code class="backtick">$1</code>', $content );

	// fix the entities that we destroyed in stage 1
	$content = ab_restoreEnts($content);

	// fix our apostrophes (see notes in ab_backtickCallback())
	$content = str_replace( '&apos;', "&#039;", $content );

	// fix our slashes (see notes in ab_backtickFilter())
	$content = str_replace( '&#8260;', '/', $content );

	return $content;
}
function ab_backtickCallback2($matches){
	// change paragraph tags to <pre> tags
	$r = '<div class="backtick"><pre><code>'.$matches[1].'</code></pre></div>';

	// if this is a multi-line block of code, there might be </p><p> inside it. Let's get rid of those. Also, WP likes to turn newlines into <br />, so let's kill those too.
	$find = array('<p>', '</p>', '<br />');
	$replace = array("\n", '', '');
	$r = str_replace($find, $replace, $r);

	return $r;
}
// Our hooks. Do it late in the game (after WP is done texturizing) with a very high priority:
add_filter('comment_text', 'ab_backtickStage2', 1001);
add_filter('the_content', 'ab_backtickStage2', 1001);
// We also want to get the content and comments in feeds:
add_filter('the_content_rss', 'ab_backtickStage2', 1001);
add_filter('comment_text_rss', 'ab_backtickStage2', 1001);
// and excerpts:
add_filter('the_excerpt', 'ab_backtickStage2', 1001);
add_filter('the_excerpt_rss', 'ab_backtickStage2', 1001);
// and comment excerpts:
add_filter('comment_excerpt', 'ab_backtickStage2', 1001);

// STAGE 3 FILTERS: BEFORE OPENING A COMMENT/POST FOR EDITING:

// we undo everything in the stage 1 filter (and in the opposite order)
function ab_backtickStage3($content){
	if (false===strpos($content,'`')) // save CPU resources
		return $content;
	$content = ab_restoreEnts($content);
	$content = preg_replace_callback( '|`<code>(.*)</code>`|Us', 'ab_backtickCallback3', $content );

	// check for escaped backticks outside the code blocks:
	$content = str_replace('&#96;', '\`', $content );

	return $content;
}
function ab_backtickCallback3($matches){
	$r = $matches[1];

	// WP likes to convert newlines in code blocks into <br />. Let's kill them all (the <pre> tag only needs newlines)
	$r = str_replace( '<br />', '', $r );

	// undo stage 1:
	// fix:			slashes,		backslashes,		apostrophes,		(escaped) backticks,	periods
	$find = array( 	'&#8260;',	'&#92;',	'&apos;',	'&#96;',		'&#46;' );
	$replace = array('/',		'\\',		"'",		'\`',			'.');
	$r = str_replace( $find, $replace, $r );
	$r = htmlspecialchars_decode($r);

	$r = '`'.$r.'`'; // restore backticks

	return $r;
}
add_filter('format_to_edit', 'ab_backtickStage3', 5); // one hook for both comments and posts



// OTHER STUFF

// used to remove and restore the HTML entities, which WP doesn't like to see in comments:
function ab_removeEnts($s){ // reformat all entities so WP won't screw with them
	return preg_replace('|&([0-9a-zA-Z#]{1,5});|', '__abENT__$1;', $s);
}
function ab_restoreEnts($s){ // fix all the entities
	return preg_replace('|__abENT__([0-9a-zA-Z#]{1,5});|', '&$1;', $s);
}

// Replicate a PHP 5 function for all those PHP 4 types out there:
if (!function_exists("htmlspecialchars_decode")){
    function htmlspecialchars_decode($string, $quote_style = ENT_COMPAT){
        return strtr($string, array_flip(get_html_translation_table(HTML_SPECIALCHARS, $quote_style)));
    }
}



// OPTIONAL. 
// You'll want this in your style, or something like it, so that code blocks look good. I've made it easy to change the settings via an option at the top of
// this file, or you can just edit this function directly.
function ab_backtickStyles(){
	// inline code looks like this:	<p>blah blah blah <code class="backtick">code code code</code> blah blah blah</p>
	// blocks of code like like this:	<div class="backtick"><pre><code>code code code code</code></pre></div>
	// Anyway, this is just the CSS that works for my site. Change this however you want. If you are using a fixed (instead of fluid, like mine) theme and you have problems with
	// IE, look at the additional IE hacks in my blog.css stylesheet (based on http://perishablepress.com/press/2007/01/16/maximum-and-minimum-height-and-width-in-internet-explorer/)
	echo '
		<style type="text/css"><!--
			.backtick{background:'.KB_BACKTICK_BG.';color:#000;}
			div.backtick{max-height:20em;width:90%;margin-left:auto;margin-right:auto;overflow:auto;border:'.KB_BACKTICK_BORDER.' 1px solid;padding:1em;}
			div.backtick pre{padding:0;margin:0;}
		// -->
		</style>
	';
}
if ('KB_BACKTICK_CSS')
	add_action('wp_head', 'ab_backtickStyles');

?>