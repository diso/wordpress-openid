<?php
/**
 * All the code required for handling OpenID comments.  These functions should not be considered public, 
 * and may change without notice.
 */


// -- WordPress Hooks
add_action( 'preprocess_comment', 'openid_process_comment', -99 );
add_action( 'comment_post', 'check_comment_author_openid', 5 );
add_filter( 'option_require_name_email', 'openid_option_require_name_email' );
add_filter( 'comments_array', 'openid_comments_array', 10, 2);
add_action( 'sanitize_comment_cookies', 'openid_sanitize_comment_cookies', 15);
add_filter( 'comment_post_redirect', 'openid_comment_post_redirect', 0, 2);
if( get_option('oid_enable_approval') ) {
	add_filter('pre_comment_approved', 'openid_comment_approval');
}
add_filter( 'get_comment_author_link', 'openid_comment_author_link');
if( get_option('oid_enable_commentform') ) {
	add_action( 'wp_head', 'openid_js_setup', 9);
	add_action( 'wp_footer', 'openid_comment_profilelink', 10);
	add_action( 'wp_footer', 'openid_comment_form', 10);
}


/**
 * Intercept comment submission and check if it includes a valid OpenID.  If it does, save the entire POST
 * array and begin the OpenID authentication process.
 *
 * regarding comment_type: http://trac.wordpress.org/ticket/2659
 *
 * @param array $comment comment data
 * @return array comment data
 */
function openid_process_comment( $comment ) {
	global $openid;

	if ($_REQUEST['openid_skip']) return $comment;
		
	$openid_url = (array_key_exists('openid_url', $_POST) ? $_POST['openid_url'] : $_POST['url']);

	if( !empty($openid_url) ) {  // Comment form's OpenID url is filled in.
		$_SESSION['openid_comment_post'] = $_POST;
		$_SESSION['openid_comment_post']['comment_author_openid'] = $openid_url;
		$_SESSION['openid_comment_post']['openid_skip'] = 1;

		openid_start_login( $openid_url, 'comment');

		// Failure to redirect at all, the URL is malformed or unreachable.

		// Display an error message only if an explicit OpenID field was used.  Otherwise,
		// just ignore the error... it just means the user entered a normal URL.
		if (array_key_exists('openid_url', $_POST)) {
			openid_repost_comment_anonymously($_SESSION['openid_comment_post']);
		}
	}

	return $comment;
}


/**
 * This filter callback simply approves all OpenID comments, but later it could do more complicated logic
 * like whitelists.
 *
 * @param string $approved comment approval status
 * @return string new comment approval status
 */
function openid_comment_approval($approved) {
	return ($_SESSION['oid_posted_comment'] ? 1 : $approved);
}


/**
 * If last comment was authenticated by an OpenID, record that in the database.
 *
 * @param string $location redirect location
 * @param object $comment comment that was just left
 * @return string redirect location
 */
function openid_comment_post_redirect($location, $comment) {
	if ($_SESSION['oid_posted_comment']) {
		set_comment_openid($comment->comment_ID);
		unset($_SESSION['oid_posted_comment']);
	}
		
	return $location;
}


/**
 * If the comment contains a valid OpenID, skip the check for requiring a name and email address.  Even if
 * this data is provided in the form, we may get it through other methods, so we don't want to bail out
 * prematurely.  After OpenID authentication has completed (and $_SESSION['oid_skip'] is set), we don't
 * interfere so that this data can be required if desired.
 *
 * @param boolean $value existing value of flag, whether to require name and email
 * @return boolean new value of flag, whether to require name and email
 * @see get_user_data
 */
function openid_option_require_name_email( $value ) {
	global $openid;
		
	if ($_REQUEST['oid_skip']) {
		return $value;
	}

	if (array_key_exists('openid_url', $_POST)) {
		if( !empty( $_POST['openid_url'] ) ) {
			return false;
		}
	} else {
		if (!empty($_POST['url'])) {
			if (openid_late_bind()) {
				// check if url is valid OpenID by forming an auth request
				$auth_request = openid_begin_consumer($_POST['url']);

				if (null !== $auth_request) {
					return false;
				}
			}
		}
	}

	return $value;
}


/**
 * Get any additional comments awaiting moderation by this user.  WordPress
 * core has been udpated to grab most, but we still do one last check for
 * OpenID comments that have a URL match with the current user.
 *
 * @param array $comments array of comments to display
 * @param int $post_id id of the post to display comments for
 * @return array new array of comments to display
 */
function openid_comments_array(&$comments, $post_id) {
	global $wpdb, $openid;
	$user = wp_get_current_user();

	$commenter = wp_get_current_commenter();
	extract($commenter);

	$author_db = $wpdb->escape($comment_author);
	$email_db  = $wpdb->escape($comment_author_email);
	$url_db  = $wpdb->escape($comment_author_url);

	if ($url_db) {
		$comments_table = WordPressOpenID::comments_table_name();
		$additional = $wpdb->get_results(
				"SELECT * FROM $comments_table"
		. " WHERE comment_post_ID = '$post_id'"
		. " AND openid = '1'"             // get OpenID comments
		. " AND comment_author_url = '$url_db'"      // where only the URL matches
		. ($user ? " AND user_id != '$user->ID'" : '')
		. ($author_db ? " AND comment_author != '$author_db'" : '')
		. ($email_db ? " AND comment_author_email != '$email_db'" : '')
		. " AND comment_approved = '0'"
		. " ORDER BY comment_date");

		if ($additional) {
			$comments = array_merge($comments, $additional);
			usort($comments, create_function('$a,$b',
					'return strcmp($a->comment_date_gmt, $b->comment_date_gmt);'));
		}
	}

	return $comments;
}


/**
 * Make sure that a user's OpenID is stored and retrieved properly.  This is important because the OpenID
 * may be an i-name, but WordPress is expecting the comment URL cookie to be a valid URL.
 *
 * @wordpress-action sanitize_comment_cookies
 */
function openid_sanitize_comment_cookies() {
	if ( isset($_COOKIE['comment_author_openid_'.COOKIEHASH]) ) {

		// this might be an i-name, so we don't want to run clean_url()
		remove_filter('pre_comment_author_url', 'clean_url');

		$comment_author_url = apply_filters('pre_comment_author_url',
		$_COOKIE['comment_author_openid_'.COOKIEHASH]);
		$comment_author_url = stripslashes($comment_author_url);
		$_COOKIE['comment_author_url_'.COOKIEHASH] = $comment_author_url;
	}
}


/**
 * Add OpenID class to author link.
 *
 * @filter: get_comment_author_link
 **/
function openid_comment_author_link( $html ) {
	if( is_comment_openid() ) {
		if (preg_match('/<a[^>]* class=[^>]+>/', $html)) {
			return preg_replace( '/(<a[^>]* class=[\'"]?)/', '\\1openid_link ' , $html );
		} else {
			return preg_replace( '/(<a[^>]*)/', '\\1 class="openid_link"' , $html );
		}
	}
	return $html;
}


/**
 * For comments that were handled by WordPress normally (not our code), check if the author
 * registered with OpenID and set comment openid flag if so.
 *
 * @action post_comment
 */
function check_comment_author_openid($comment_ID) {
	global $openid;

	$comment = get_comment($comment_ID);
	if ( $comment->user_id && !$comment->openid && is_user_openid($comment->user_id) ) {
		set_comment_openid($comment_ID);
	}
}


/**
 * Print jQuery call for slylizing profile link.
 *
 * @action: comment_form
 **/
function openid_comment_profilelink() {
	if (is_user_openid()) {
		echo '<script type="text/javascript">stylize_profilelink()</script>';
	}
}


/**
 * Print jQuery call to modify comment form.
 *
 * @action: comment_form
 **/
function openid_comment_form() {
	if (!is_user_logged_in()) {
		echo '<script type="text/javascript">add_openid_to_comment_form()</script>';
	}
}


function openid_repost_comment_anonymously($post) {
	$html = '
	<p id="error">We were unable to authenticate your claimed OpenID, however you 
	can continue to post your comment without OpenID:</p>

	<form action="' . get_option('siteurl') . '/wp-comments-post.php" method="post">
		<p>Name: <input name="author" value="'.$post['author'].'" /></p>
		<p>Email: <input name="email" value="'.$post['email'].'" /></p>
		<p>URL: <input name="url" value="'.$post['url'].'" /></p>
		<textarea name="comment" cols="80%" rows="10">'.stripslashes($post['comment']).'</textarea>
		<input type="submit" name="submit" value="Submit Comment" />
		<input type="hidden" name="oid_skip" value="1" />';
	foreach ($post as $name => $value) {
		if (!in_array($name, array('author', 'email', 'url', 'comment', 'submit'))) {
			$html .= '
		<input type="hidden" name="'.$name.'" value="'.$value.'" />';
		}
	}
	
	$html .= '</form>';
	wp_die($html, 'OpenID Authentication Error');
}
?>
