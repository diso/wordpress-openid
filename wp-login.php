<?php
/**
 * All the code required for handling logins via wp-login.php.  These functions should not be considered public, 
 * and may change without notice.
 */


// add OpenID input field to wp-login.php
add_action( 'login_head', 'openid_style');
add_action( 'login_form', 'openid_wp_login_form');
add_action( 'register_form', 'openid_wp_register_form');
add_filter( 'login_errors', 'openid_login_form_hide_username_password_errors');
add_action( 'wp_authenticate', 'openid_wp_authenticate' );

// WordPress 2.5 has wp_authenticate in the wrong place
if (strpos($wp_version, '2.5') == 0) {
	add_action( 'init', 'wp25_login_openid' );
}


/**
 * If we're doing openid authentication ($_POST['openid_identifier'] is set), start the consumer & redirect
 * Otherwise, return and let WordPress handle the login and/or draw the form.
 *
 * @param string $credentials username and password provided in login form
 */
function openid_wp_authenticate(&$credentials) {
	$openid = openid_init();

	if(!empty($_POST['openid_identifier'])) {
		$redirect_to = '';
		if( !empty( $_REQUEST['redirect_to'] ) ) $redirect_to = $_REQUEST['redirect_to'];
		openid_start_login( $_POST['openid_identifier'], 'login', array('redirect_to' => $redirect_to) );
	}
	if( !empty( $openid->message ) ) {
		global $error;
		$error = $openid->message;
	}
}


/**
 * Provide more useful OpenID error message to the user.
 *
 * @filter: login_errors
 **/
function openid_login_form_hide_username_password_errors($r) {
	$openid = openid_init();

	if( $_POST['openid_identifier'] or $_REQUEST['action'] == 'login' or $_REQUEST['action'] == 'comment' ) {
		return $openid->message;
	}

	return $r;
}


/**
 * Add OpenID input field to wp-login.php
 *
 * @action: login_form
 **/
function openid_wp_login_form() {
	global $wp_version;

	$link_class = 'openid_link';
	if ($wp_version < '2.5') { $link_class .= ' legacy'; }

	?>
	<hr />
	<p style="margin-top: 1em;">
		<label><?php printf(__('Or login using your %s url:', 'openid'), '<a class="'.$link_class.'" href="http://openid.net/">'.__('OpenID', 'openid').'</a>') ?><br/>
		<input type="text" name="openid_identifier" id="openid_identifier" class="input openid_identifier" value="" size="20" tabindex="25" /></label>
	</p>
	<?php
}


/**
 * Add information about registration to wp-login.php?action=register 
 *
 * @action: register_form
 **/
function openid_wp_register_form() {
	echo '<p>';
	printf(__('For faster registration, just %s login with %s.', 'openid'), '<a href="'.get_option('siteurl').'/wp-login.php">', '<span class="openid_link">'.__('OpenID', 'openid').'</span></a>');
	echo '</p>';
}


/**
 * Action method for completing the 'login' action.  This action is used when a user is logging in from
 * wp-login.php.
 *
 * @param string $identity_url verified OpenID URL
 */
function _finish_openid_login($identity_url) {
	$redirect_to = urldecode($_REQUEST['redirect_to']);
		
	if (empty($identity_url)) {
		openid_set_error('Unable to authenticate OpenID.');
		wp_safe_redirect(get_option('siteurl') . '/wp-login.php');
		exit;
	}
		
	openid_set_current_user($identity_url);

	if (!is_user_logged_in()) {
		if ( get_option('users_can_register') ) {
			$user_data =& openid_get_user_data($identity_url);
			$user = openid_create_new_user($identity_url, $user_data);
			openid_set_current_user($user->ID);
		} else {
			// TODO - Start a registration loop in WPMU.
			openid_set_error('OpenID authentication valid, but unable '
			. 'to find a WordPress account associated with this OpenID.<br /><br />'
			. 'Enable "Anyone can register" to allow creation of new accounts via OpenID.');
			wp_safe_redirect(get_option('siteurl') . '/wp-login.php');
			exit;
		}

	}
		
	if (empty($redirect_to)) {
		$redirect_to = 'wp-admin/';
	}
	if ($redirect_to == 'wp-admin/') {
		if (!current_user_can('edit_posts')) {
			$redirect_to .= 'profile.php';
		}
	}
	if (!preg_match('#^(http|\/)#', $redirect_to)) {
		$wpp = parse_url(get_option('siteurl'));
		$redirect_to = $wpp['path'] . '/' . $redirect_to;
	}

	if (function_exists('wp_safe_redirect')) {
		wp_safe_redirect( $redirect_to );
	} else {
		wp_redirect( $redirect_to );
	}
		
	exit;
}


/**
 * Intercept login requests on wp-login.php if they include an 'openid_identifier' 
 * value and start OpenID authentication.  This hook is only necessary in 
 * WordPress 2.5.x because it has the 'wp_authenticate' action call in the 
 * wrong place.
 */
function wp25_login_openid() {
	$self = basename( $GLOBALS['pagenow'] );
		
	if ($self == 'wp-login.php' && !empty($_POST['openid_identifier'])) {
		wp_signon(array('user_login'=>'openid', 'user_password'=>'openid'));
	}
}

?>
