<?php
/**
 * Common functions.
 */

// -- WP Hooks
register_activation_hook('openid/openid.php', 'openid_activate_plugin');
register_deactivation_hook('openid/openid.php', 'openid_deactivate_plugin');

// Add hooks to handle actions in WordPress
add_action( 'init', 'openid_textdomain' ); // load textdomain
	
// include internal stylesheet
add_action( 'wp_head', 'openid_style');

add_filter( 'init', 'openid_init_errors');

// parse request
add_action('parse_request', 'openid_parse_request');

add_action( 'delete_user', 'delete_user_openids' );
add_action( 'cleanup_openid', 'openid_cleanup' );


// hooks for getting user data
add_filter('openid_attribute_query_extensions', 'openid_add_sreg_extension');

add_filter( 'openid_user_data', 'openid_get_user_data_sreg', 10, 2);

add_filter( 'xrds_simple', 'openid_consumer_xrds_simple');


// Add custom OpenID options
add_option( 'oid_enable_commentform', true );
add_option( 'oid_plugin_enabled', true );
add_option( 'oid_plugin_revision', 0 );
add_option( 'oid_db_revision', 0 );
add_option( 'oid_enable_approval', false );
add_option( 'oid_enable_email_mapping', false );
add_option( 'openid_associations', array(), null, 'no' );
add_option( 'openid_nonces', array(), null, 'no' );


/**
 * Set the textdomain for this plugin so we can support localizations.
 */
function openid_textdomain() {
	$lang_folder = PLUGINDIR . '/openid/lang';
	load_plugin_textdomain('openid', $lang_folder);
}


/**
 * Soft verification of plugin activation
 *
 * @return boolean if the plugin is okay
 */
function openid_uptodate() {

	if( get_option('oid_db_revision') != WPOPENID_DB_REVISION ) {
		openid_enabled(false);
		error_log('Plugin database is out of date: ' . get_option('oid_db_revision') . ' != ' . WPOPENID_DB_REVISION);
		update_option('oid_plugin_enabled', false);
		return false;
	}
	openid_enabled(get_option('oid_plugin_enabled') == true);
	return openid_enabled();
}
// XXX - figure out when to perform  uptodate() checks and such (since late_bind is no more)


/**
 * Get the internal SQL Store.  If it is not already initialized, do so.
 *
 * @return WordPressOpenID_Store internal SQL store
 */
function openid_getStore() {
	static $store;

	if (!$store) {
		$store = new WordPress_OpenID_OptionStore();
	}

	return $store;
}


/**
 * Get the internal OpenID Consumer object.  If it is not already initialized, do so.
 *
 * @return Auth_OpenID_Consumer OpenID consumer object
 */
function openid_getConsumer() {
	static $consumer;

	if (!$consumer) {
		// setup source of randomness
		$f = @fopen( '/dev/urandom', 'r');
		if ($f === false) {
			define( 'Auth_OpenID_RAND_SOURCE', null );
		}

		set_include_path( dirname(__FILE__) . PATH_SEPARATOR . get_include_path() );
		require_once 'Auth/OpenID/Consumer.php';
		restore_include_path();

		$store = openid_getStore();
		$consumer = new Auth_OpenID_Consumer($store);
		if( null === $consumer ) {
			error_log('OpenID consumer could not be created properly.');
			openid_enabled(false);
		}

	}

	return $consumer;
}


/**
 * Called on plugin activation.
 *
 * @see register_activation_hook
 */
function openid_activate_plugin() {
	//$start_mem = memory_get_usage();
	openid_create_tables();
	openid_migrate_old_data();

	wp_schedule_event(time(), 'hourly', 'cleanup_openid');
	//error_log("activation memory usage: " . (int)((memory_get_usage() - $start_mem) / 1000));
}


/**
 * Cleanup expired nonces and associations from the OpenID store.
 */
function openid_cleanup() {
	$store =& openid_getStore();
	$store->cleanupNonces();
	$store->cleanupAssociations();
}


/**
 * Called on plugin deactivation.  Cleanup all transient tables.
 *
 * @see register_deactivation_hook
 */
function openid_deactivate_plugin() {
	delete_option('openid_server_associations');
	delete_option('openid_server_nonces');
}


/*
 * Customer error handler for calls into the JanRain library
 */
function openid_customer_error_handler($errno, $errmsg, $filename, $linenum, $vars) {
	if( (2048 & $errno) == 2048 ) return;
	error_log( "Library Error $errno: $errmsg in $filename :$linenum");
}


/**
 * Send the user to their OpenID provider to authenticate.
 *
 * @param Auth_OpenID_AuthRequest $auth_request OpenID authentication request object
 * @param string $trust_root OpenID trust root
 * @param string $return_to URL where the OpenID provider should return the user
 */
function openid_doRedirect($auth_request, $trust_root, $return_to) {
	if ($auth_request->shouldSendRedirect()) {
		$trust_root = trailingslashit($trust_root);
		$redirect_url = $auth_request->redirectURL($trust_root, $return_to);

		if (Auth_OpenID::isFailure($redirect_url)) {
			error_log('Could not redirect to server: '.$redirect_url->message);
		} else {
			wp_redirect( $redirect_url );
		}
	} else {
		// Generate form markup and render it
		$request_message = $auth_request->getMessage($trust_root, $return_to, false);

		if (Auth_OpenID::isFailure($request_message)) {
			error_log('Could not redirect to server: '.$request_message->message);
		} else {
			openid_repost($auth_request->endpoint->server_url, $request_message->toPostArgs());
		}
	}
}


/**
 * Finish OpenID Authentication.
 *
 * @return String authenticated identity URL, or null if authentication failed.
 */
function finish_openid_auth() {

	//set_error_handler( 'openid_customer_error_handler'));
	$consumer = openid_getConsumer();
	$response = $consumer->complete($_SESSION['oid_return_to']);
	openid_response($response);
	//restore_error_handler();
		
	switch( $response->status ) {
		case Auth_OpenID_CANCEL:
			openid_message('OpenID assertion cancelled');
			openid_status('error');
			break;

		case Auth_OpenID_FAILURE:
			openid_message('OpenID assertion failed: ' . $response->message);
			openid_status('error');
			break;

		case Auth_OpenID_SUCCESS:
			openid_message('OpenID assertion successful');
			openid_status('success');

			$identity_url = $response->identity_url;
			$escaped_url = htmlspecialchars($identity_url, ENT_QUOTES);
			return $escaped_url;

		default:
			openid_message('Unknown Status. Bind not successful. This is probably a bug');
			openid_status('error');
	}

	return null;
}


/**
 * Generate a unique WordPress username for the given OpenID URL.
 *
 * @param string $url OpenID URL to generate username for
 * @return string generated username
 */
function openid_generate_new_username($url) {
	$base = openid_normalize_username($url);
	$i='';
	while(true) {
		$username = openid_normalize_username( $base . $i );
		$user = get_userdatabylogin($username);
		if ( $user ) {
			$i++;
			continue;
		}
		return $username;
	}
}


/**
 * Normalize the OpenID URL into a username.  This includes rules like:
 *  - remove protocol prefixes like 'http://' and 'xri://'
 *  - remove the 'xri.net' domain for i-names
 *  - substitute certain characters which are not allowed by WordPress
 *
 * @param string $username username to be normalized
 * @return string normalized username
 */
function openid_normalize_username($username) {
	$username = preg_replace('|^https?://(xri.net/([^@]!?)?)?|', '', $username);
	$username = preg_replace('|^xri://([^@]!?)?|', '', $username);
	$username = preg_replace('|/$|', '', $username);
	$username = sanitize_user( $username );
	$username = preg_replace('|[^a-z0-9 _.\-@]+|i', '-', $username);
	return $username;
}

function openid_begin_consumer($url) {
	global $openid_auth_request;

	if ($openid_auth_request == NULL) {
		set_error_handler( 'openid_customer_error_handler');

		if (openid_isValidEmail($url)) {
			$_SESSION['openid_login_email'] = $url;
			set_include_path( dirname(__FILE__) . PATH_SEPARATOR . get_include_path() );
			require_once 'Auth/Yadis/Email.php';
			$mapped_url = Auth_Yadis_Email_getID($url, trailingslashit(get_option('home')));
			if ($mapped_url) {
				$url = $mapped_url;
			}
		}

		$consumer = openid_getConsumer();
		$openid_auth_request = $consumer->begin($url);

		restore_error_handler();
	}

	return $openid_auth_request;
}


/**
 * Check if the provided string looks like an email address.
 */
function openid_isValidEmail($email) {
	return eregi("^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$", $email);
}


/**
 * Start the OpenID authentication process.
 *
 * @param string $claimed_url claimed OpenID URL
 * @param action $action OpenID action being performed
 * @param array $arguments array of additional arguments to be included in the 'return_to' URL
 */
function openid_start_login( $claimed_url, $action, $arguments = null) {
	if ( empty($claimed_url) ) return; // do nothing.
		
	$auth_request = openid_begin_consumer( $claimed_url );

	if ( null === $auth_request ) {
		openid_status('error');
		openid_message('Could not discover an OpenID identity server endpoint at the url: '
		. htmlentities( $claimed_url ));
		if( strpos( $claimed_url, '@' ) ) {
			openid_message(openid_message() . '<br />It looks like you entered an email address, but it '
				. 'was not able to be transformed into a valid OpenID.');
		}
		return;
	}
		
	// build return_to URL
	$return_to = trailingslashit(get_option('home'));
	$auth_request->return_to_args['openid_consumer'] = '1';
	$auth_request->return_to_args['action'] = $action;
	if (is_array($arguments) && !empty($arguments)) {
		foreach ($arguments as $k => $v) {
			if ($k && $v) {
				$auth_request->return_to_args[urlencode($k)] = urlencode($v);
			}
		}
	}
		

	/* If we've never heard of this url before, do attribute query */
	$identity_user = get_user_by_openid($auth_request->endpoint->identity_url);
	if(!$identity_user) {
		$extensions = apply_filters('openid_attribute_query_extensions', array());
		foreach ($extensions as $e) {
			if (is_a($e, 'Auth_OpenID_Extension')) {
				$auth_request->addExtension($e);
			}
		}
	}
		
	$_SESSION['oid_return_to'] = $return_to;
	openid_doRedirect($auth_request, get_option('home'), $return_to);
	exit(0);
}


/**
 * Build an SReg attribute query extension.
 */
function openid_add_sreg_extension($extensions) {

	set_include_path( dirname(__FILE__) . PATH_SEPARATOR . get_include_path() );
	require_once('Auth/OpenID/SReg.php');
	restore_include_path();

	$extensions[] = Auth_OpenID_SRegRequest::build(array(),array('nickname','email','fullname'));
	return $extensions;
}


/**
 * Login user with specified identity URL.  This will find the WordPress user account connected to this
 * OpenID and set it as the current user.  Only call this function AFTER you've verified the identity URL.
 *
 * @param string $identity userID or OpenID to set as current user
 * @param boolean $remember should we set the "remember me" cookie
 * @return void
 */
function openid_set_current_user($identity, $remember = true) {
	if (is_numeric($identity)) {
		$user_id = $identity;
	} else {
		$user_id = get_user_by_openid($identity);
	}

	if (!$user_id) return;

	$user = set_current_user($user_id);
		
	if (function_exists('wp_set_auth_cookie')) {
		wp_set_auth_cookie($user->ID, $remember);
	} else {
		wp_setcookie($user->user_login, md5($user->user_pass), true, '', '', $remember);
	}

	do_action('wp_login', $user->user_login);
}


/**
 * Finish OpenID authentication. 
 *
 * @param string $action login action that is being performed
 */
function finish_openid($action) {
	$identity_url = finish_openid_auth();
	do_action('openid_finish_auth', $identity_url);
		
	global $action;
	$action = openid_status();
}


/**
 * Create a new WordPress user with the specified identity URL and user data.
 *
 * @param string $identity_url OpenID to associate with the newly
 * created account
 * @param array $user_data array of user data
 */
function openid_create_new_user($identity_url, &$user_data) {
	global $wpdb;

	// Identity URL is new, so create a user
	@include_once( ABSPATH . 'wp-admin/upgrade-functions.php');	// 2.1
	@include_once( ABSPATH . WPINC . '/registration-functions.php'); // 2.0.4

	// use email address for username if URL is from emailtoid.net
	$username = $identity_url;
	if (null != $_SESSION['openid_login_email'] and strpos($username, 'http://emailtoid.net/') == 0) {
		if($user_data['user_email'] == NULL) {
			$user_data['user_email'] = $_SESSION['openid_login_email'];
		}
		$username = $_SESSION['openid_login_email'];
		unset($_SESSION['openid_login_email']);
	}

	$user_data['user_login'] = $wpdb->escape( openid_generate_new_username($username) );
	$user_data['user_pass'] = substr( md5( uniqid( microtime() ) ), 0, 7);
	$user_id = wp_insert_user( $user_data );
		
	if( $user_id ) { // created ok

		$user_data['ID'] = $user_id;
		// XXX this all looks redundant, see openid_set_current_user

		$user = new WP_User( $user_id );

		if( ! wp_login( $user->user_login, $user_data['user_pass'] ) ) {
			openid_message('User was created fine, but wp_login() for the new user failed. '
			. 'This is probably a bug.');
			openid_action('error');
			error_log(openid_message());
			return;
		}

		// notify of user creation
		wp_new_user_notification( $user->user_login );

		wp_clearcookie();
		wp_setcookie( $user->user_login, md5($user->user_pass), true, '', '', true );

		// Bind the provided identity to the just-created user
		openid_add_user_identity($user_id, $identity_url);

		openid_status('redirect');

		if ( !$user->has_cap('edit_posts') ) $redirect_to = '/wp-admin/profile.php';

	} else {
		// failed to create user for some reason.
		openid_message('OpenID authentication successful, but failed to create WordPress user. '
		. 'This is probably a bug.');
		openid_status('error');
		error_log(openid_message());
	}

}


/**
 * Get user data for the given identity URL.  Data is returned as an associative array with the keys:
 *   ID, user_url, user_nicename, display_name
 *
 * Multiple soures of data may be available and are attempted in the following order:
 *   - OpenID Attribute Exchange      !! not yet implemented
 * 	 - OpenID Simple Registration
 * 	 - hCard discovery                !! not yet implemented
 * 	 - default to identity URL
 *
 * @param string $identity_url OpenID to get user data about
 * @return array user data
 */
function openid_get_user_data($identity_url) {
	$data = array(
			'ID' => null,
			'user_url' => $identity_url,
			'user_nicename' => $identity_url,
			'display_name' => $identity_url 
	);

	// create proper website URL if OpenID is an i-name
	if (preg_match('/^[\=\@\+].+$/', $identity_url)) {
		$data['user_url'] = 'http://xri.net/' . $identity_url;
	}

	$data = apply_filters('openid_user_data', $identity_url, $data);

	return $data;
}


/**
 * Retrieve user data from OpenID Attribute Exchange.
 *
 * @param string $identity_url OpenID to get user data about
 * @param reference $data reference to user data array
 * @see get_user_data
 */
function openid_get_user_data_ax($identity_url, $data) {
	// TODO implement attribute exchange
	return $data;
}


/**
 * Retrieve user data from OpenID Simple Registration.
 *
 * @param string $identity_url OpenID to get user data about
 * @param reference $data reference to user data array
 * @see get_user_data
 */
function openid_get_user_data_sreg($identity_url, $data) {
	require_once(dirname(__FILE__) . '/Auth/OpenID/SReg.php');
	$response = openid_response();
	$sreg_resp = Auth_OpenID_SRegResponse::fromSuccessResponse($response);
	$sreg = $sreg_resp->contents();

	if (!$sreg) return $data;

	if (array_key_exists('email', $sreg) && $sreg['email']) {
		$data['user_email'] = $sreg['email'];
	}

	if (array_key_exists('nickname', $sreg) && $sreg['nickname']) {
		$data['nickname'] = $sreg['nickname'];
		$data['user_nicename'] = $sreg['nickname'];
		$data['display_name'] = $sreg['nickname'];
	}

	if (array_key_exists('fullname', $sreg) && $sreg['fullname']) {
		$namechunks = explode( ' ', $sreg['fullname'], 2 );
		if( isset($namechunks[0]) ) $data['first_name'] = $namechunks[0];
		if( isset($namechunks[1]) ) $data['last_name'] = $namechunks[1];
		$data['display_name'] = $sreg['fullname'];
	}

	return $data;;
}


/**
 * Retrieve user data from hCard discovery.
 *
 * @param string $identity_url OpenID to get user data about
 * @param reference $data reference to user data array
 * @see get_user_data
 */
function openid_get_user_data_hcard($identity_url, $data) {
	// TODO implement hcard discovery
	return $data;
}


function openid_consumer_xrds_simple($xrds) {
	// OpenID Consumer Service
	$xrds = xrds_add_service($xrds, 'main', 'OpenID Consumer Service', 
		array(
			'Type' => array(array('content' => 'http://specs.openid.net/auth/2.0/return_to') ),
			'URI' => array(array('content' => trailingslashit(get_option('home'))) ),
		)
	);

	// Identity in the Browser Login Service
	$siteurl = function_exists('site_url') ? site_url('/wp-login.php', 'login_post') : get_option('siteurl').'/wp-login.php';
	$xrds = xrds_add_service($xrds, 'main', 'Identity in the Browser Login Service', 
		array(
			'Type' => array(array('content' => 'http://specs.openid.net/idib/1.0/login') ),
			'URI' => array(
				array(
					'simple:httpMethod' => 'POST',
					'content' => $siteurl,
				),
			),
		)
	);

	// Identity in the Browser Indicator Service
	$xrds = xrds_add_service($xrds, 'main', 'Identity in the Browser Indicator Service', 
		array(
			'Type' => array(array('content' => 'http://specs.openid.net/idib/1.0/indicator') ),
			'URI' => array(array('content' => trailingslashit(get_option('home')) . '?openid_check_login')),
		)
	);

	return $xrds;
}


/**
 * Parse the WordPress request.  If the pagename is 'openid_consumer', then the request
 * is an OpenID response and should be handled accordingly.
 *
 * @param WP $wp WP instance for the current request
 */
function openid_parse_request($wp) {
	
	// Identity in the Browser Indicator Service
	if (array_key_exists('openid_check_login', $_REQUEST)) {
		echo is_user_logged_in() ? 'true' : 'false';
		exit;
	}

	// OpenID Consumer Service
	if (array_key_exists('openid_consumer', $_REQUEST) && $_REQUEST['action']) {
		finish_openid($_REQUEST['action']);
	}
	
	// OpenID Provider Service
	if (array_key_exists('openid_server', $_REQUEST)) {
		openid_server_request($_REQUEST['action']);
	}
}


function openid_set_error($error) {
	$_SESSION['oid_error'] = $error;
	return;
}


function openid_init_errors() {
	global $error;
	$error = $_SESSION['oid_error'];
	unset($_SESSION['oid_error']);
}


function openid_table_prefix() {
	global $wpdb;
	return isset($wpdb->base_prefix) ? $wpdb->base_prefix : $wpdb->prefix;
}

function openid_associations_table() { return openid_table_prefix() . 'openid_associations'; }
function openid_nonces_table() { return openid_table_prefix() . 'openid_nonces'; }
function openid_comments_table() { return openid_table_prefix() . 'comments'; }
function openid_usermeta_table() { 
	return (defined('CUSTOM_USER_META_TABLE') ? CUSTOM_USER_META_TABLE : openid_table_prefix() . 'usermeta'); 
}
function openid_identity_table() { 
	return (defined('CUSTOM_OPENID_IDENTITY_TABLE') ? CUSTOM_OPENID_IDENTITY_TABLE : openid_table_prefix() . 'openid_identities'); 
}


/**
 * Delete user.
 */
function delete_user_openids($userid) {
	openid_drop_all_identities($userid);
}


function openid_add_user_identity($user_id, $identity_url) {
	openid_add_identity($user_id, $identity_url);
}

function openid_status($new = null) {
	static $status;

	if ($new !== null) $status = $new;

	if ($status == null && $_SESSION['openid_status']) {
		$status = $_SESSION['openid_status'];
		unset($_SESSION['openid_status']);
	}

	return $status;
}

function openid_message($new = null) {
	static $message;

	if ($new !== null) $message = $new;

	if ($message == null && $_SESSION['openid_message']) {
		$message = $_SESSION['openid_message'];
		unset($_SESSION['openid_message']);
	}

	return $message;
}

function openid_response($new = null) {
	static $response;
	return ($new == null) ? $response : $response = $new;
}

function openid_enabled($new = null) {
	static $enabled;
	if ($enabled == null) $enabled = true;
	return ($new == null) ? $enabled : $enabled = $new;
}


function openid_print_messages() {
}

/**
 * Enqueue required javascript libraries.
 *
 * @action: init
 **/
function openid_js_setup() {
	if (is_single() || is_comments_popup() || is_admin()) {
		wp_enqueue_script( 'jquery' );
		wp_enqueue_script('jquery.textnode', '/' . PLUGINDIR . '/openid/files/jquery.textnode.min.js', 
			array('jquery'), WPOPENID_PLUGIN_REVISION);
		wp_enqueue_script('jquery.xpath', '/' . PLUGINDIR . '/openid/files/jquery.xpath.min.js', 
			array('jquery'), WPOPENID_PLUGIN_REVISION);
		wp_enqueue_script('openid', '/' . PLUGINDIR . '/openid/files/openid.min.js', 
			array('jquery','jquery.textnode'), WPOPENID_PLUGIN_REVISION);
	}
}

/**
 * Include internal stylesheet.
 *
 * @action: wp_head, login_head
 **/
function openid_style() {
	$css_path = get_option('siteurl') . '/' . PLUGINDIR . '/openid/files/openid.css?ver='.WPOPENID_PLUGIN_REVISION;
	echo '
		<link rel="stylesheet" type="text/css" href="'.$css_path.'" />';
}


/**
 * Add identity url to user.
 *
 * @param int $user_id user id
 * @param string $url identity url to add
 */
function openid_add_identity($user_id, $url) {
	global $wpdb;
	return $wpdb->query( $wpdb->prepare('INSERT INTO '.openid_identity_table().' (user_id,url,hash) VALUES ( %s, %s, MD5(%s) )', $user_id, $url, $url) );
}

/**
 * Get OpenID identities for the specified user.
 * @param int $user_id user id
 */
function openid_get_identities($user_id) {
	global $wpdb;
	return $wpdb->get_col( $wpdb->prepare('SELECT url FROM '.openid_identity_table().' WHERE user_id = %s', $user_id) );
}


/**
 * Format OpenID for display... namely, remove the fragment if present.
 * @param string $url url to display
 * @return url formatted for display
 */
function openid_display_identity($url) {
	return preg_replace('/#.+$/', '', $url);
}


/**
 * Remove identity url from user.
 *
 * @param int $user_id user id
 * @param string $identity_url identity url to remove
 */
function openid_drop_identity($user_id, $identity_url) {
	global $wpdb;
	return $wpdb->query( $wpdb->prepare('DELETE FROM '.openid_identity_table().' WHERE user_id = %s AND url = %s', $user_id, $identity_url) );
}

/**
 * Remove all identity urls from user.
 *
 * @param int $user_id user id
 */
function openid_drop_all_identities($user_id) {
	global $wpdb;
	return $wpdb->query( $wpdb->prepare('DELETE FROM '.openid_identity_table().' WHERE user_id = %s', $user_id ) );
}


?>
