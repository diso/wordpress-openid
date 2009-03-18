<?php
/**
 * All the code required for handling logins via wp-login.php.  These functions should not be considered public, 
 * and may change without notice.
 */


// add OpenID input field to wp-login.php
add_action( 'login_head', 'openid_wp_login_head');
add_action( 'login_form', 'openid_wp_login_form');
add_action( 'register_form', 'openid_wp_register_form', 9);
add_action( 'register_post', 'openid_register_post', 10, 3);
add_action( 'wp_authenticate', 'openid_wp_authenticate' );
add_action( 'openid_finish_auth', 'openid_finish_login', 10, 2);
add_filter( 'registration_errors', 'openid_clean_registration_errors', -99);
add_filter( 'registration_errors', 'openid_registration_errors');
add_action( 'init', 'openid_login_errors' );

// WordPress 2.5 has wp_authenticate in the wrong place
if (version_compare($wp_version, '2.5', '>=') && version_compare($wp_version, '2.6', '<')) {
	add_action( 'init', 'wp25_login_openid' );
}


/**
 * If we're doing openid authentication ($_POST['openid_identifier'] is set), start the consumer & redirect
 * Otherwise, return and let WordPress handle the login and/or draw the form.
 *
 * @param string $credentials username and password provided in login form
 */
function openid_wp_authenticate(&$credentials) {
	if (!empty($_POST['openid_identifier'])) {
		$finish_url = $_REQUEST['redirect_to'];
		openid_start_login($_POST['openid_identifier'], 'login', $finish_url);

		// if we got this far, something is wrong
		global $error;
		$error = openid_message();
	}
}


/**
 * Setup OpenID errors to be displayed to the user.
 */
function openid_login_errors() {
	$self = basename( $GLOBALS['pagenow'] );
		
	if ($self != 'wp-login.php') return;

	if ($_REQUEST['openid_error']) {
		global $error;
		$error = $_REQUEST['openid_error'];
	}

	if ($_REQUEST['registration_closed']) {
		global $error;
		$error = __('Your have entered a valid OpenID, but this site is not currently accepting new accounts.', 'openid');
	}
}


/**
 * Add style and script to login page.
 */
function openid_wp_login_head() {
	openid_style();
	wp_enqueue_script('jquery.xpath', openid_plugin_url() . '/f/jquery.xpath.min.js', 
		array('jquery'), OPENID_PLUGIN_REVISION);
	wp_print_scripts(array('jquery.xpath'));
}


/**
 * Add OpenID input field to wp-login.php
 *
 * @action: login_form
 **/
function openid_wp_login_form() {
	global $wp_version;

	echo '<hr id="openid_split" style="clear: both; margin-bottom: 1.0em; border: 0; border-top: 1px solid #999; height: 1px;" />';

	echo '
	<p style="margin-bottom: 8px;">
		<label style="display: block; margin-bottom: 5px;">' . __('Or login using an OpenID', 'openid') . '</label>
		<input type="text" name="openid_identifier" id="openid_identifier" class="input openid_identifier" value="" size="20" tabindex="25" /></label>
	</p>

	<p style="font-size: 0.9em; margin: 8px 0 24px 0;" id="what_is_openid">
		<a href="http://openid.net/what/" target="_blank">'.__('Learn about OpenID', 'openid').'</a>
	</p>';
}


/**
 * Add information about registration to wp-login.php?action=register 
 *
 * @action: register_form
 **/
function openid_wp_register_form() {
	global $wp_version;

	echo '
	<div style="width:100%;">'; //Added to fix IE problem

	if (get_option('openid_required_for_registration')) {
		$label = __('Register using an OpenID:', 'openid');
		echo '
		<script type="text/javascript">
			jQuery(function() {
				jQuery("#registerform > p:first").hide();
				jQuery("#registerform > p:first + p").hide();
				jQuery("#reg_passmail").hide();
				jQuery("p.submit").css("margin", "1em 0");
				var link = jQuery("#nav a:first");
				jQuery("#nav").text("").append(link);
			});
		</script>';
	} else {
		$label = __('Or register using an OpenID:', 'openid');

		echo '<hr id="openid_split" style="clear: both; margin-bottom: 1.5em; border: 0; border-top: 1px solid #999; height: 1px;" />';

		echo '
		<script type="text/javascript">
			jQuery(function() {
				jQuery("#reg_passmail").insertBefore("#openid_split");
				jQuery("p.submit").css("margin", "1em 0").clone().insertBefore("#openid_split");
			});
		</script>';
	}

	echo '
		<p>
			<label style="display: block; margin-bottom: 5px;">' . $label . '</label>
			<input type="text" name="openid_identifier" id="openid_identifier" class="input openid_identifier" value="" size="20" tabindex="25" /></label>
		</p>

		<p style="float: left; font-size: 0.8em; margin: 0.8em 0;" id="what_is_openid">
			<a href="http://openid.net/what/" target="_blank">'.__('What is OpenID?', 'openid').'</a>
		</p>

	</div>';

}


/**
 * Action method for completing the 'login' action.  This action is used when a user is logging in from
 * wp-login.php.
 *
 * @param string $identity_url verified OpenID URL
 */
function openid_finish_login($identity_url, $action) {
	if ($action != 'login') return;

	$redirect_to = $_SESSION['openid_finish_url'];
		
	if (empty($identity_url)) {
		$url = get_option('siteurl') . '/wp-login.php?openid_error=' . urlencode(openid_message());
		wp_safe_redirect($url);
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
			$url = get_option('siteurl') . '/wp-login.php?registration_closed=1';
			wp_safe_redirect($url);
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

	wp_safe_redirect( $redirect_to );
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


/**
 * Clean out registration errors that don't apply.
 */
function openid_clean_registration_errors($errors) {
	if (get_option('openid_required_for_registration') || !empty($_POST['openid_identifier'])) {
		$new = new WP_Error();
		foreach ($errors->get_error_codes() as $code) {
			if (in_array($code, array('empty_username', 'empty_email'))) continue;

			$message = $errors->get_error_message($code);
			$data = $errors->get_error_data($code);
			$new->add($code, $message, $data);
		}

		$errors = $new;
	}

	if (get_option('openid_required_for_registration') && empty($_POST['openid_identifier'])) {
		$errors->add('openid_only', __('<strong>ERROR</strong>: ', 'openid') . __('New users must register using OpenID.', 'openid'));
	}

	return $errors;
}

/**
 * Handle WordPress registration errors.
 */
function openid_registration_errors($errors) {
	if (!empty($_POST['openid_identifier'])) {
		$errors->add('invalid_openid', __('<strong>ERROR</strong>: ', 'openid') . openid_message());
	}

	return $errors;
}


/**
 * Handle WordPress registrations.
 */
function openid_register_post($username, $password, $errors) {
	if (!empty($_POST['openid_identifier'])) {
		wp_signon(array('user_login'=>'openid', 'user_password'=>'openid'));
	}
}
?>
