<?php
// public functions


/**
 * Check if the user has any OpenIDs.
 *
 * @param mixed $user the username or ID  If not provided, the current user will be used.
 * @return bool true if the user has any OpenIDs
 */
function is_user_openid($user = null) {
	global $current_user;

	if ($user === null && $current_user !== null) {
		$user = $current_user->ID;
	}

	return $user === null ? false : get_usermeta($user, 'has_openid');
}


/**
 * If the current comment was submitted with OpenID, return true
 * useful for  <?php echo ( is_comment_openid() ? 'Submitted with OpenID' : '' ); ?>
 */
// TODO: should we take a parameter here?
function is_comment_openid() {
	global $comment;
	return ( $comment->openid == 1 );
}


/**
 * Get the OpenID identities for the specified user.
 *
 * @param mixed $user the username or ID.  If not provided, the current user will be used.
 * @return array array of user's OpenID identities
 */
function get_user_openids($user = null) {
}


/**
 * Get the user associated with the specified OpenID.
 *
 * @param string $openid identifier to match
 * @return int|false ID of associated user, or false if no associated user
 */
function get_user_by_openid($url) {
}





// -- Private Functions


/**
 * Mark the specified comment as an OpenID comment.
 *
 * @param int $id id of comment to set as OpenID
 */
function set_comment_openid($id) {
	global $wpdb;

	$comments_table = WordPressOpenID::comments_table_name();
	$wpdb->query("UPDATE $comments_table SET openid='1' WHERE comment_ID='$id' LIMIT 1");
}


/**
 * Delete user.
 */
function delete_user_openids($userid) {
	openid_init();
	$store = openid_getStore();
	$store->drop_all_identities_for_user($userid);
}




?>
