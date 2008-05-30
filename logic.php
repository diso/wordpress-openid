<?php
/**
 * logic.php
 *
 * Dual License: GPLv2 & Modified BSD
 */
if (!class_exists('WordPressOpenID_Logic')):

/**
 * Basic logic for wp-openid plugin.
 */
class WordPressOpenID_Logic {

	var $core;        // WordPressOpenID instance
	var $store;	      // WordPressOpenID_Store instance
	var $_consumer;   // Auth_OpenID_Consumer

	var $error;		  // User friendly error message, defaults to ''.
	var $action;	  // Internal action tag. '', 'error', 'redirect'.

	var $response;

	var $enabled = true;

	var $bind_done = false;


	/**
	 * Constructor.
	 *
	 * @param WordPressOpenID $core wp-openid core instance
	 * @return WordPressOpenID_Logic
	 */
	function WordPressOpenID_Logic($core) {
		$this->core =& $core;
	}


	/**
	 * Soft verification of plugin activation
	 *
	 * @return boolean if the plugin is okay
	 */
	function uptodate() {
		$this->core->log->debug('checking if database is up to date');
		if( get_option('oid_db_revision') != WPOPENID_DB_REVISION ) {
			// Database version mismatch, force dbDelta() in admin interface.
			$this->enabled = false;
			$this->core->setStatus('Plugin Database Version', false, 'Plugin database is out of date. '
			. get_option('oid_db_revision') . ' != ' . WPOPENID_DB_REVISION );
			update_option('oid_plugin_enabled', false);
			return false;
		}
		$this->enabled = (get_option('oid_plugin_enabled') == true );
		return $this->enabled;
	}


	/**
	 * Get the internal SQL Store.  If it is not already initialized, do so.
	 *
	 * @return WordPressOpenID_Store internal SQL store
	 */
	function getStore() {
		if (!isset($this->store)) {
			require_once 'store.php';

			$this->store = new WordPressOpenID_Store($this->core);
			if (null === $this->store) {

				$this->core->setStatus('object: OpenID Store', false,
						'OpenID store could not be created properly.');

				$this->enabled = false;
			} else {
				$this->core->setStatus('object: OpenID Store', true, 'OpenID store created properly.');
			}
		}

		return $this->store;
	}


	/**
	 * Get the internal OpenID Consumer object.  If it is not already initialized, do so.
	 *
	 * @return Auth_OpenID_Consumer OpenID consumer object
	 */
	function getConsumer() {
		if (!isset($this->_consumer)) {
			require_once 'Auth/OpenID/Consumer.php';

			$this->_consumer = new Auth_OpenID_Consumer($this->store);
			if( null === $this->_consumer ) {
				$this->core->setStatus('object: OpenID Consumer', false,
						'OpenID consumer could not be created properly.');

				$this->enabled = false;
			} else {
				$this->core->setStatus('object: OpenID Consumer', true,
						'OpenID consumer created properly.');
			}
		}

		return $this->_consumer;
	}


	/**
	 * Initialize required store and consumer and make a few sanity checks.  This method
	 * does a lot of the heavy lifting to get everything initialized, so we don't call it
	 * until we actually need it.
	 */
	function late_bind($reload = false) {
		global $wpdb;

		$this->core->log->debug('beginning late binding');

		$this->enabled = true; // Be Optimistic
		if( $this->bind_done && !$reload ) {
			$this->core->log->debug('we\'ve already done the late bind... moving on');
			return $this->uptodate();
		}
		$this->bind_done = true;

		$f = @fopen( '/dev/urandom', 'r');
		if ($f === false) {
			define( 'Auth_OpenID_RAND_SOURCE', null );
		}
			
		// include required JanRain OpenID library files
		set_include_path( dirname(__FILE__) . PATH_SEPARATOR . get_include_path() );
		$this->core->log->debug('temporary include path for importing = ' . get_include_path());
		require_once('Auth/OpenID/Discover.php');
		require_once('Auth/OpenID/DatabaseConnection.php');
		require_once('Auth/OpenID/MySQLStore.php');
		require_once('Auth/OpenID/Consumer.php');
		require_once('Auth/OpenID/SReg.php');
		restore_include_path();

		$this->core->setStatus('database: WordPress\' table prefix', 'info', isset($wpdb->base_prefix) ? $wpdb->base_prefix : $wpdb->prefix );

		$this->core->log->debug("Bootstrap -- checking tables");
		if( $this->enabled ) {

			$store =& $this->getStore();
			if (!$store) return; 	// something broke
			$this->enabled = $store->check_tables();

			if( !$this->uptodate() ) {
				update_option('oid_plugin_enabled', true);
				update_option('oid_plugin_revision', WPOPENID_PLUGIN_REVISION );
				update_option('oid_db_revision', WPOPENID_DB_REVISION );
				$this->uptodate();
			}
		} else {
			$this->error = 'WPOpenID Core is Disabled!';
			update_option('oid_plugin_enabled', false);
		}

		return $this->enabled;
	}


	/**
	 * Called on plugin activation.
	 *
	 * @see register_activation_hook
	 */
	function activate_plugin() {
		$this->late_bind();
	}


	/**
	 * Called on plugin deactivation.  Cleanup all transient tables.
	 *
	 * @see register_deactivation_hook
	 */
	function deactivate_plugin() {
		$this->late_bind();

		if( $this->store == null) {
			$this->error = 'OpenIDConsumer: Disabled. Cannot locate libraries, therefore cannot clean '
			. 'up database tables. Fix the libraries, or drop the tables yourself.';
			$this->core->log->notice($this->error);
			return;
		}

		$this->core->log->debug('Dropping all database tables.');
		$this->store->destroy_tables();
	}



	/*
	 * Customer error handler for calls into the JanRain library
	 */
	function customer_error_handler($errno, $errmsg, $filename, $linenum, $vars) {
		if( (2048 & $errno) == 2048 ) return;
		$this->core->log->notice( "Library Error $errno: $errmsg in $filename :$linenum");
	}


	/**
	 * If we're doing openid authentication ($_POST['openid_url'] is set), start the consumer & redirect
	 * Otherwise, return and let WordPress handle the login and/or draw the form.
	 *
	 * @param string $username username provided in login form
	 */
	function wp_authenticate( &$username ) {
		if( !empty( $_POST['openid_url'] ) ) {
			if( !$this->late_bind() ) return; // something is broken
			$redirect_to = '';
			if( !empty( $_REQUEST['redirect_to'] ) ) $redirect_to = $_REQUEST['redirect_to'];
			$this->start_login( $_POST['openid_url'], 'login', array('redirect_to' => $redirect_to) );
		}
		if( !empty( $this->error ) ) {
			global $error;
			$error = $this->error;
		}
	}


	/**
	 * Handle OpenID profile management.
	 */
	function openid_profile_management() {
		global $wp_version;

		if( !isset( $_REQUEST['action'] )) return;
			
		$this->action = $_REQUEST['action'];
			
		require_once(ABSPATH . 'wp-admin/admin-functions.php');

		if ($wp_version < '2.3') {
			require_once(ABSPATH . 'wp-admin/admin-db.php');
			require_once(ABSPATH . 'wp-admin/upgrade-functions.php');
		}

		auth_redirect();
		nocache_headers();
		get_currentuserinfo();

		if( !$this->late_bind() ) return; // something is broken
			
		switch( $this->action ) {
			case 'add_identity':
				check_admin_referer('wp-openid-add_identity');
				$this->start_login($_POST['openid_url'], 'verify');
				break;

			case 'drop_identity':  // Remove a binding.
				$this->_profile_drop_identity($_REQUEST['id']);
				break;
		}
	}


	/**
	 * Remove identity URL from current user account.
	 *
	 * @param int $id id of identity URL to remove
	 */
	function _profile_drop_identity($id) {

		if( !isset( $id)) {
			$this->error = 'Identity url delete failed: ID paramater missing.';
			return;
		}

		$deleted_identity_url = $this->store->get_my_identities($id);
		if( FALSE === $deleted_identity_url ) {
			$this->error = 'Identity url delete failed: Specified identity does not exist.';
			return;
		}

		$identity_urls = $this->store->get_my_identities();
		if (sizeof($identity_urls) == 1 && !$_REQUEST['confirm']) {
			$this->error = 'This is your last identity URL.  Are you sure you want to delete it? Doing so may interfere with your ability to login.<br /><br /> '
			. '<a href="?confirm=true&'.$_SERVER['QUERY_STRING'].'">Yes I\'m sure.  Delete it</a>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;'
			. '<a href="?page='.$this->core->interface->profile_page_name.'">No, don\'t delete it.</a>';
			$this->action = 'warning';
			return;
		}

		check_admin_referer('wp-openid-drop-identity_'.$deleted_identity_url);
			
		if( $this->store->drop_identity($id) ) {
			$this->error = 'Identity url delete successful. <b>' . $deleted_identity_url
			. '</b> removed.';
			$this->action = 'success';
			return;
		}
			
		$this->error = 'Identity url delete failed: Unknown reason.';
	}


	/**
	 * Send the user to their OpenID provider to authenticate.
	 *
	 * @param Auth_OpenID_AuthRequest $auth_request OpenID authentication request object
	 * @param string $trust_root OpenID trust root
	 * @param string $return_to URL where the OpenID provider should return the user
	 */
	function doRedirect($auth_request, $trust_root, $return_to) {
		if ($auth_request->shouldSendRedirect()) {
			if (substr($trust_root, -1, 1) != '/') $trust_root .= '/';
			$redirect_url = $auth_request->redirectURL($trust_root, $return_to);

			if (Auth_OpenID::isFailure($redirect_url)) {
				$this->core->log->error('Could not redirect to server: '.$redirect_url->message);
			} else {
				wp_redirect( $redirect_url );
			}
		} else {
			// Generate form markup and render it
			$form_id = 'openid_message';
			$form_html = $auth_request->formMarkup($trust_root, $return_to, false);

			if (Auth_OpenID::isFailure($form_html)) {
				$this->core->log->error('Could not redirect to server: '.$form_html->message);
			} else {
				$this->core->interface->display_openid_redirect_form($form_html);
			}
		}
	}


	/**
	 * Finish OpenID Authentication.
	 *
	 * @return String authenticated identity URL, or null if authentication failed.
	 */
	function finish_openid_auth() {
		set_error_handler( array($this, 'customer_error_handler'));
		$consumer = $this->getConsumer();
		$this->response = $consumer->complete($_SESSION['oid_return_to']);
		restore_error_handler();
			
		switch( $this->response->status ) {
			case Auth_OpenID_CANCEL:
				$this->error = 'OpenID assertion cancelled';
				break;

			case Auth_OpenID_FAILURE:
				$this->error = 'OpenID assertion failed: ' . $this->response->message;
				break;

			case Auth_OpenID_SUCCESS:
				$this->error = 'OpenID assertion successful';

				$identity_url = $this->response->identity_url;
				$escaped_url = htmlspecialchars($identity_url, ENT_QUOTES);
				$this->core->log->notice('Got back identity URL ' . $escaped_url);

				if ($this->response->endpoint->canonicalID) {
					$this->core->log->notice('XRI CanonicalID: ' . $this->response->endpoint->canonicalID);
				}

				return $escaped_url;

			default:
				$this->error = 'Unknown Status. Bind not successful. This is probably a bug';
		}

		return null;
	}


	/**
	 * Generate a unique WordPress username for the given OpenID URL.
	 *
	 * @param string $url OpenID URL to generate username for
	 * @return string generated username
	 */
	function generate_new_username($url) {
		$base = $this->normalize_username($url);
		$i='';
		while(true) {
			$username = $this->normalize_username( $base . $i );
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
	function normalize_username($username) {
		$username = preg_replace('|^https?://(xri.net/([^@]!?)?)?|', '', $username);
		$username = preg_replace('|^xri://([^@]!?)?|', '', $username);
		$username = preg_replace('|/$|', '', $username);
		$username = sanitize_user( $username );
		$username = preg_replace('|[^a-z0-9 _.\-@]+|i', '-', $username);
		return $username;
	}


	/**
	 * Start the OpenID authentication process.
	 *
	 * @param string $claimed_url claimed OpenID URL
	 * @param action $action OpenID action being performed
	 * @param array $arguments array of additional arguments to be included in the 'return_to' URL
	 */
	function start_login( $claimed_url, $action, $arguments = null) {

		if ( empty($claimed_url) ) return; // do nothing.
			
		if( !$this->late_bind() ) return; // something is broken

		if ( null !== $openid_auth_request) {
			$auth_request = $openid_auth_request;
		} else {
			set_error_handler( array($this, 'customer_error_handler'));
			$consumer = $this->getConsumer();
			$auth_request = $consumer->begin( $claimed_url );
			restore_error_handler();
		}

		if ( null === $auth_request ) {
			$this->error = 'Could not discover an OpenID identity server endpoint at the url: '
			. htmlentities( $claimed_url );
			if( strpos( $claimed_url, '@' ) ) {
				$this->error .= '<br/>The address you specified had an @ sign in it, but OpenID '
				. 'Identities are not email addresses, and should probably not contain an @ sign.';
			}
			$this->core->log->debug('OpenIDConsumer: ' . $this->error );
			return;
		}
			
		$this->core->log->debug('OpenIDConsumer: Is an OpenID url. Starting redirect.');


		// build return_to URL
		$return_to = get_option('home') . '/openid_consumer';
		$auth_request->return_to_args['action'] = $action;
		if (is_array($arguments) && !empty($arguments)) {
			foreach ($arguments as $k => $v) {
				if ($k && $v) {
					$auth_request->return_to_args[urlencode($k)] = urlencode($v);
				}
			}
		}
			

		/* If we've never heard of this url before, do attribute query */
		if( $this->store->get_user_by_identity( $auth_request->endpoint->identity_url ) == NULL ) {
			$attribute_query = true;
		}
		if ($attribute_query) {
			// SREG
			$sreg_request = Auth_OpenID_SRegRequest::build(array(),array('nickname','email','fullname'));
			if ($sreg_request) $auth_request->addExtension($sreg_request);

			// AX
		}
			
		$_SESSION['oid_return_to'] = $return_to;
		$this->doRedirect($auth_request, get_option('home'), $return_to);
		exit(0);
	}


	/**
	 * Intercept login requests on wp-login.php if they include an 'openid_url' value and start OpenID
	 * authentication.
	 */
	function wp_login_openid() {
		$self = basename( $GLOBALS['pagenow'] );
			
		if ($self == 'wp-login.php' && !empty($_POST['openid_url'])) {
			// TODO wp_signon only exists in wp2.5+
			wp_signon(array('user_login'=>'openid', 'user_password'=>'openid'));
		}
	}


	/**
	 * Login user with specified identity URL.  This will find the WordPress user account connected to this
	 * OpenID and set it as the current user.  Only call this function AFTER you've verified the identity URL.
	 *
	 * @param string $identity_url OpenID to set as current user
	 * @param boolean $remember should we set the "remember me" cookie
	 * @return void
	 */
	function set_current_user($identity_url, $remember = true) {
		$user_id = $this->store->get_user_by_identity( $identity_url );

		if (NULL == $user_id) return;

		$user = set_current_user($user_id);
			
		if (function_exists('wp_set_auth_cookie')) {
			wp_set_auth_cookie($user->ID, $remember);
		} else {
			wp_setcookie($user->user_login, $user->user_pass, false, '', '', $remember);
		}

		do_action('wp_login', $user->user_login);
	}


	/**
	 * Finish OpenID authentication.  After doing the basic stuff, the action method is called to complete the
	 * process.  Action methods are based on the action name passed in and are of the form
	 * '_finish_openid_$action'.  Action methods are passed the verified identity URL, or null if OpenID
	 * authentication failed.
	 *
	 * @param string $action login action that is being performed
	 */
	function finish_openid($action) {

		if( !$this->late_bind() ) return; // something is broken
			
		$identity_url = $this->finish_openid_auth();
			
		if (!empty($action) && method_exists($this, '_finish_openid_' . $action)) {
			call_user_method('_finish_openid_' . $action, $this, $identity_url);
		}
			
		global $action;
		$action = $this->action;
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
			// FIXME unable to authenticate OpenID
			$this->core->interface->display_error('unable to authenticate OpenID');
		}
			
		$this->set_current_user($identity_url);
			
		if (!is_user_logged_in()) {
			if ( get_option('users_can_register') ) {
				$user_data =& $this->get_user_data($identity_url);
				$user = $this->create_new_user($identity_url, $user_data);
				$this->set_current_user($identity_url);  // TODO this does an extra db hit to get user_id
			} else {
				// TODO - Start a registration loop in WPMU.
				$this->core->interface->display_error('OpenID authentication valid, but unable '
				. 'to find a WordPress account associated with this OpenID.<br /><br />'
				. 'Enable "Anyone can register" to allow creation of new accounts via OpenID.');
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
	 * Action method for completing the 'comment' action.  This action is used when leaving a comment.
	 *
	 * @param string $identity_url verified OpenID URL
	 */
	function _finish_openid_comment($identity_url) {
		if (empty($identity_url)) {
			// FIXME unable to authenticate OpenID - give option to post anonymously
			$this->core->interface->display_error('unable to authenticate OpenID');
		}
			
		$this->set_current_user($identity_url);
			
		if (is_user_logged_in()) {
			// simulate an authenticated comment submission
			$_SESSION['oid_comment_post']['author'] = null;
			$_SESSION['oid_comment_post']['email'] = null;
			$_SESSION['oid_comment_post']['url'] = null;
		} else {
			// try to get user data from the verified OpenID
			$user_data =& $this->get_user_data($identity_url);

			if (!empty($user_data['display_name'])) {
				$_SESSION['oid_comment_post']['author'] = $user_data['display_name'];
			}
			if ($oid_user_data['user_email']) {
				$_SESSION['oid_comment_post']['email'] = $user_data['user_email'];
			}
		}
			
		// record that we're about to post an OpenID authenticated comment.
		// We can't actually record it in the database until after the repost below.
		$_SESSION['oid_posted_comment'] = true;

		$wpp = parse_url(get_option('siteurl'));
		$this->core->interface->repost($wpp['path'] . '/wp-comments-post.php',
		array_filter($_SESSION['oid_comment_post']));
	}


	/**
	 * Action method for completing the 'verify' action.  This action is used adding an identity URL to a
	 * WordPress user through the admin interface.
	 *
	 * @param string $identity_url verified OpenID URL
	 */
	function _finish_openid_verify($identity_url) {
		if (empty($identity_url)) {
			// FIXME unable to authenticate OpenID - give option to post anonymously
			$this->core->interface->display_error('unable to authenticate OpenID');
		}
			
		if( !$this->store->insert_identity($identity_url) ) {
			// TODO should we check for this duplication *before* authenticating the ID?
			$this->core->interface->display_error('OpenID assertion successful, but this URL is already claimed by '
			. 'another user on this blog. This is probably a bug.');
		} else {
			$this->action = 'success';
		}
			
		$wpp = parse_url(get_option('siteurl'));
		$redirect_to = $wpp['path'] . '/wp-admin/' . (current_user_can('edit_users') ? 'users.php' : 'profile.php') . '?page=' . $this->core->interface->profile_page_name;
		if (function_exists('wp_safe_redirect')) {
			wp_safe_redirect( $redirect_to );
		} else {
			wp_redirect( $redirect_to );
		}
		// TODO display success message
		exit;
	}


	/**
	 * If last comment was authenticated by an OpenID, record that in the database.
	 *
	 * @param string $location redirect location
	 * @param object $comment comment that was just left
	 * @return string redirect location
	 */
	function comment_post_redirect($location, $comment) {
		if ($_SESSION['oid_posted_comment']) {
			$this->set_comment_openid($comment->comment_ID);
			$_SESSION['oid_posted_comment'] = null;
		}
			
		return $location;
	}


	/**
	 * Create a new WordPress user with the specified identity URL and user data.
	 *
	 * @param string $identity_url OpenID to associate with the newly
	 * created account
	 * @param array $user_data array of user data
	 */
	function create_new_user($identity_url, &$user_data) {
		global $wpdb;

		// Identity URL is new, so create a user
		@include_once( ABSPATH . 'wp-admin/upgrade-functions.php');	// 2.1
		@include_once( ABSPATH . WPINC . '/registration-functions.php'); // 2.0.4

		$user_data['user_login'] = $wpdb->escape( $this->generate_new_username($identity_url) );
		$user_data['user_pass'] = substr( md5( uniqid( microtime() ) ), 0, 7);
		$user_id = wp_insert_user( $user_data );
			
		$this->core->log->debug("wp_create_user( $user_data )  returned $user_id ");

		if( $user_id ) { // created ok

			$user_data['ID'] = $user_id;
			// XXX this all looks redundant, see $this->set_current_user

			$this->core->log->debug("OpenIDConsumer: Created new user $user_id : $username and metadata: "
			. var_export( $user_data, true ) );

			$user = new WP_User( $user_id );

			if( ! wp_login( $user->user_login, $user_data['user_pass'] ) ) {
				$this->error = 'User was created fine, but wp_login() for the new user failed. '
				. 'This is probably a bug.';
				$this->action= 'error';
				$this->core->log->err( $this->error );
				return;
			}

			// notify of user creation
			wp_new_user_notification( $user->user_login );

			wp_clearcookie();
			wp_setcookie( $user->user_login, md5($user->user_pass), true, '', '', true );

			// Bind the provided identity to the just-created user
			global $userdata;
			$userdata = get_userdata( $user_id );
			$this->store->insert_identity( $identity_url );

			$this->action = 'redirect';

			if ( !$user->has_cap('edit_posts') ) $redirect_to = '/wp-admin/profile.php';

		} else {
			// failed to create user for some reason.
			$this->error = 'OpenID authentication successful, but failed to create WordPress user. '
			. 'This is probably a bug.';
			$this->action= 'error';
			$this->core->log->error( $this->error );
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
	function get_user_data($identity_url) {

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


		$result = $this->get_user_data_sreg($identity_url, $data);

		return $data;
	}


	/**
	 * Retrieve user data from OpenID Attribute Exchange.
	 *
	 * @param string $identity_url OpenID to get user data about
	 * @param reference $data reference to user data array
	 * @see get_user_data
	 */
	function get_user_data_ax($identity_url, &$data) {
		// TODO
	}


	/**
	 * Retrieve user data from OpenID Simple Registration.
	 *
	 * @param string $identity_url OpenID to get user data about
	 * @param reference $data reference to user data array
	 * @see get_user_data
	 */
	function get_user_data_sreg($identity_url, &$data) {

		$sreg_resp = Auth_OpenID_SRegResponse::fromSuccessResponse($this->response);
		$sreg = $sreg_resp->contents();

		$this->core->log->debug(var_export($sreg, true));
		if (!$sreg) return false;

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

		return true;
	}


	/**
	 * Retrieve user data from hCard discovery.
	 *
	 * @param string $identity_url OpenID to get user data about
	 * @param reference $data reference to user data array
	 * @see get_user_data
	 */
	function get_user_data_hcard($identity_url, &$data) {
		// TODO
	}


	/**
	 * For comments that were handled by WordPress normally (not our code), check if the author
	 * registered with OpenID and set comment openid flag if so.
	 *
	 * @action post_comment
	 */
	function check_author_openid($comment_ID) {
		$comment = get_comment($comment_ID);
		if ( $comment->user_id && !$comment->openid && is_user_openid($comment->user_id) ) {
			$this->set_comment_openid($comment_ID);
		}
	}


	/**
	 * Mark the provided comment as an OpenID comment
	 *
	 * @param int $comment_ID id of comment to set as OpenID
	 */
	function set_comment_openid($comment_ID) {
		global $wpdb;

		$comments_table = $this->store->comments_table_name;
		$wpdb->query("UPDATE $comments_table SET openid='1' WHERE comment_ID='$comment_ID' LIMIT 1");
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
	function bypass_option_require_name_email( $value ) {
		global $openid_auth_request;
			
		if ($_REQUEST['oid_skip']) {
			return $value;
		}

		if (array_key_exists('openid_url', $_POST)) {
			if( !empty( $_POST['openid_url'] ) ) {
				return false;
			}
		} else {
			if (!empty($_POST['url'])) {
				if ($this->late_bind()) {
					// check if url is valid OpenID by forming an auth request
					set_error_handler( array($this, 'customer_error_handler'));
					$consumer = $this->getConsumer();
					$openid_auth_request = $consumer->begin( $_POST['url'] );
					restore_error_handler();

					if (null !== $openid_auth_request) {
						return false;
					}
				}
			}
		}

		return $value;
	}


	/**
	 * Intercept comment submission and check if it includes a valid OpenID.  If it does, save the entire POST
	 * array and begin the OpenID authentication process.
	 *
	 * regarding comment_type: http://trac.wordpress.org/ticket/2659
	 *
	 * @param object $comment comment object
	 * @return object comment object
	 */
	function comment_tagging( $comment ) {
		global $current_user;

		if (!$this->enabled) return $comment;
			
		if ($_REQUEST['oid_skip']) return $comment;
			
		$openid_url = (array_key_exists('openid_url', $_POST) ? $_POST['openid_url'] : $_POST['url']);

		if( !empty($openid_url) ) {  // Comment form's OpenID url is filled in.
			$_SESSION['oid_comment_post'] = $_POST;
			$_SESSION['oid_comment_post']['comment_author_openid'] = $openid_url;
			$_SESSION['oid_comment_post']['oid_skip'] = 1;

			$this->start_login( $openid_url, 'comment');

			// Failure to redirect at all, the URL is malformed or unreachable.

			// Display an error message only if an explicit OpenID field  was used.  Otherwise,
			// just ignore the error... it just means the user entered a normal URL.
			if (array_key_exists('openid_url', $_POST)) {
				// TODO give option to post without OpenID
				global $error;
				$error = $this->error;
				$_POST['openid_url'] = '';
				include( ABSPATH . 'wp-login.php' );
				exit();
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
	function comment_approval($approved) {
		if ($_SESSION['oid_posted_comment']) {
			return 1;
		}
			
		return $approved;
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
	function comments_awaiting_moderation(&$comments, $post_id) {
		global $wpdb, $user_ID;

		$commenter = wp_get_current_commenter();
		extract($commenter);

		$author_db = $wpdb->escape($comment_author);
		$email_db  = $wpdb->escape($comment_author_email);
		$url_db  = $wpdb->escape($comment_author_url);

		if ($url_db) {
			$store =& $this->getStore();
			$comments_table = $store->comments_table_name;
			$additional = $wpdb->get_results(
					"SELECT * FROM $comments_table"
			. " WHERE comment_post_ID = '$post_id'"
			. " AND openid = '1'"             // get OpenID comments
			. " AND comment_author_url = '$url_db'"      // where only the URL matches
			. ($user_ID ? " AND user_id != '$user_ID'" : '')
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
	function sanitize_comment_cookies() {
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
	 * Add OpenID consumer endpoing to wp_rewrite rules.
	 */
	function rewrite_rules() {
		global $wp_rewrite;

		$openid_rules = array(
            	'openid_consumer$' => 'index.php?openid_consumer=1',
            	'index.php/openid_consumer$' => 'index.php?openid_consumer=1',
		);

		$wp_rewrite->rules = $openid_rules + $wp_rewrite->rules;
	}


	/**
	 * Add 'openid_consumer' as a valid query variables.
	 *
	 * @param array $vars valid query variables
	 * @return array new valid query variables
	 */
	function query_vars($vars) {
		$vars[] = 'openid_consumer';
		return $vars;
	}


	/**
	 * Parse the WordPress query string.  If it contains the query variable 'openid_consumer', then the request
	 * is an OpenID response and should be handled accordingly.
	 *
	 * @param WP_Query $query WP_Query instance for the current request
	 */
	function parse_query($query) {
		if ($query) $openid = $query->query_vars['openid_consumer'];
		if (!empty($openid)) {
			$this->finish_openid($_REQUEST['action']);
		}
	}

} // end class definition
endif; // end if-class-exists test

?>
