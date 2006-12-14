<?php
/*
Plugin Name: OpenID Registration
Plugin URI: http://sourceforge.net/projects/wpopenid/
Description: Wordpress OpenID Registration, Authentication, and Commenting. Requires JanRain PHP OpenID library 1.2.0
Author: Alan J Castonguay, Hans Granqvist
Author URI: http://blog.verselogic.net/projects/wordpress/wordpress-openid-plugin/
Version: $Rev$
Licence: Modified BSD, http://www.fsf.org/licensing/licenses/index_html#ModifiedBSD
*/

define ( 'OPENIDIMAGE', get_bloginfo('url') . '/wp-content/plugins/wpopenid/images/openid.gif' );

/* Turn on logging of process via error_log() facility in PHP.
 * Used primarily for debugging, lots of output.
 * For production use, leave this set to false.
 */

define ( 'WORDPRESSOPENIDREGISTRATION_DEBUG', true );
if( WORDPRESSOPENIDREGISTRATION_DEBUG ) {
	ini_set('display_errors', true);   // try to turn on verbose PHP error reporting
	if( ! ini_get('error_log') ) ini_set('error_log', ABSPATH . get_option('upload_path') . '/php.log' );
	ini_set('error_reporting', 2039);
}

/* Sessions are required by Services_Yadis_PHPSession, in Manager.php line 40 */
@session_start();

if  ( !class_exists('WordpressOpenIDRegistration') ) {
	class WordpressOpenIDRegistration {

		var $_store;	// Hold the WP_OpenIDStore and
		var $_consumer; // Auth_OpenID_Consumer internally.
		
		var $error;		// User friendly error message, defaults to ''.
		var $action;	// Internal action tag. '', 'error', 'redirect'.

		var $enabled = true;

		var $identity_url_table_name;
		var $flag_doing_openid_comment = false;

		/* 
		 * Initialize required store and consumer, making a few sanity checks.
		 */
		function startup_child_objects() {
			$this->enabled = true;  // Be Optimisitic
			
			/* Fetch Wordpress' table prefix, preference to 2.1 $wpdb->prefix.
			   If passing paramater to WP_OpenIDStore() constructor is bad, we can
			   store the value in $wpdb->prefix ourselves. */
			global $wpdb;
			if( isset( $wpdb->prefix ) ) {
				wordpressOpenIDRegistration_Status_Set('database: Wordpress\' table prefix', 'info', $wpdb->prefix );
				$this->identity_url_table_name = ($wpdb->prefix . 'openid_identities');
			} else {
				wordpressOpenIDRegistration_Status_Set('database: Wordpress\' table prefix', false, 'Wordpress $wpdb->prefix must be set! Plugin is probably being loaded wrong.');
				$this->enabled = false;
				return false;				
			}
			
			/* Create and destroy tables on activate / deactivate of plugin. Everyone should clean up after themselves. */
			if( function_exists('register_activation_hook') ) {
				register_deactivation_hook( 'wpopenid/openid-registration.php', array( $this, 'destroy_tables' ) );
			} else {
				wordpressOpenIDRegistration_Status_Set('Unsupported Wordpress Version', false, 'The WPOpenID plugin requires at least Wordpress version 2.0. The <em>register_activation_hook</em> first appeared in wp 2.0, but cannot be found.');
				$this->error = 'Unsupported Wordpress Version: The wpopenid plugin requires at least version 2.0. Cannot activate.';
				$this->enabled = false;
				return false;
			}

			if( !class_exists('WP_OpenIDStore') ) {
				wordpressOpenIDRegistration_Status_Set('class: Auth_OpenID_MySQLStore', class_exists('Auth_OpenID_MySQLStore'), 'This class is provided by the JanRain library, used to store association and nonce data.');
				wordpressOpenIDRegistration_Status_Set('class: WP_OpenIDStore', class_exists('WP_OpenIDStore'),  'This class is provided by the plugin, used to wrap the Wordpress database for PEAR-style database access. It\'s provided by <code>wpdb-pear-wrapper.php</code>, did you upload it?');
				$this->enabled = false;
				return false;
			}
			$this->_store = new WP_OpenIDStore();
			if (null === $this->_store) {
				wordpressOpenIDRegistration_Status_Set('object: OpenID Store', false, 'OpenID store could not be created properly.');
				wordpressOpenIDRegistration_Status_Set('class: Auth_OpenID_MySQLStore', class_exists('Auth_OpenID_MySQLStore'), 'This class is provided by the JanRain library, used to store association and nonce data.');
				wordpressOpenIDRegistration_Status_Set('class: WP_OpenIDStore', class_exists('WP_OpenIDStore'),  'This class is provided by the plugin, used to wrap the Wordpress database for PEAR-style database access. It\'s provided by <code>wpdb-pear-wrapper.php</code>, did you upload it?');
				$this->enabled = false;
				return false;
			} else {
				wordpressOpenIDRegistration_Status_Set('object: OpenID Store', true, 'OpenID store created properly.');
			}
			
			if( !class_exists('Auth_OpenID_Consumer') ) {
				wordpressOpenIDRegistration_Status_Set('class: Auth_OpenID_Consumer', class_exists('Auth_OpenID_Consumer'),  'This class is provided by the JanRain library, does the heavy lifting.');
				$this->enabled = false;
				return false;
			}
			$this->_consumer = new Auth_OpenID_Consumer( $this->_store );
			if( null === $this->_consumer ) {
				wordpressOpenIDRegistration_Status_Set('object: OpenID Consumer', false, 'OpenID consumer could not be created properly.');
				wordpressOpenIDRegistration_Status_Set('class: Auth_OpenID_Consumer', class_exists('Auth_OpenID_Consumer'),  'This class is provided by the JanRain library, does the heavy lifting.');
				$this->enabled = false;
				return false;
			} else {
				wordpressOpenIDRegistration_Status_Set('object: OpenID Consumer', true, 'OpenID consumer created properly.');
			}

			if( false === get_option('oid_trust_root') or '' === get_option('oid_trust_root') ) {
				wordpressOpenIDRegistration_Status_Set('Option: Trust Root', 'info', 'You must specify the Trust Root paramater on the OpenID Options page. Commenters will be asked whether they trust this url, and its decedents, to know that they are logged in and control their identity url. Include the trailing slash.');
			}
			
			if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log("Bootstrap -- checking tables");
			if( $this->enabled ) {
				$this->enabled = $this->check_tables();
				$this->error = '';
				$this->action = '';
			}
			
			return $this->enabled;
		}
		
		/*
		 * Create tables if needed by running dbDelta calls. Upgrade safe. Called on plugin activate.
		 */
		function create_tables() {
			if( false == $this->enabled ) {  // do nothing if something bad happened
				$this->error = 'OpenID Consumer could not be activated, something bad happened. Skipping table create. Check libraries.';
				if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log($this->error);
				echo $this->error;
				return false;
			}
			if( null == $this->_store ) {
				$this->error = 'OpenID Consumer could not be activated, because the store could not be created properly. Are the database files in place?';
				if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log($this->error);
				echo $this->error;
				return false;				
			}
			require_once(ABSPATH . '/wp-admin/upgrade-functions.php');
			$this->_store->dbDelta();
			
			// Table for storing UserID <---> URL associations.
			$identity_url_table_sql = "CREATE TABLE $this->identity_url_table_name (
				uurl_id bigint(20) NOT NULL auto_increment,
				user_id bigint(20) NOT NULL default '0',
				url text,
				hash char(32),
				PRIMARY KEY (uurl_id),
				UNIQUE KEY uurl (hash),
				INDEX ( url(30) ),
				INDEX ( user_id) );";
			
			dbDelta($identity_url_table_sql);
		}
		
		/*
		 * Cleanup by dropping nonce, association, and settings tables. Called on plugin deactivate.
		 */
		function destroy_tables() {
			global $wpdb;
			if( !isset( $this->_store )) {
				$this->error = 'OpenIDConsumer: Disabled. Cannot locate libraries, therefore cannot clean up database tables. Fix the libraries, or drop the tables yourself.';
				if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log($this->error);
				echo $this->error;
				return;
			}
			if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log('Dropping all database tables.');
			$sql = 'drop table '. $this->_store->associations_table_name;
			$wpdb->query($sql);
			$sql = 'drop table '. $this->_store->nonces_table_name;
			$wpdb->query($sql);
			$sql = 'drop table '. $this->_store->settings_table_name;
			$wpdb->query($sql);
		}
		
		/*
		 * Check to see whether the none, association, and settings tables exist.
		 */
		function check_tables($retry=true) {
			if( null === $this->_store ) return false; // Can't check tables if the store object isn't created

			global $wpdb;
			$ok = true;
			$message = '';
			$tables = array( $this->_store->associations_table_name, $this->_store->nonces_table_name,
				$this->_store->settings_table_name, $this->identity_url_table_name );
			foreach( $tables as $t ) {
				$message .= empty($message) ? '' : '<br/>';
				if( $wpdb->get_var("SHOW TABLES LIKE '$t'") != $t ) {
					$ok = false;
					$message .= "Table $t doesn't exist.";
				} else {
					$message .= "Table $t exists.";
				}
			}
			
			if( $retry and !$ok) {
				wordpressOpenIDRegistration_Status_Set( 'database tables', false, 'Tables not created properly. Trying to create..' );
				$this->create_tables();
				$ok = $this->check_tables( false );
			} else {
				wordpressOpenIDRegistration_Status_Set( 'database tables', $ok?'info':false, $message );
			}
			return $ok;
		}
		
		/*
		 * Customer error handler for calls into the JanRain library
		 */
		function customer_error_handler($errno, $errmsg, $filename, $linenum, $vars) {
			if( (2048 & $errno) == 2048 ) return;
			error_log( "Library Error $errno: $errmsg in $filename :$linenum");
		}

 
		/*
		 * Hook - called as wp_authenticate
		 * If we're doing openid authentication ($_POST['openid_url'] is set), start the consumer & redirect
		 * Otherwise, return and let Wordpress handle the login and/or draw the form.
		 * Uses output buffering to modify the form. See openid_wp_login_ob()
		 */
		function wp_authenticate( &$username ) {
			if( !empty( $_POST['openid_url'] ) ) {
				$redirect_to = '';
				if( !empty( $_REQUEST['redirect_to'] ) ) $redirect_to = $_REQUEST['redirect_to'];
				$this->start_login( $_POST['openid_url'], $redirect_to );
			}
			if( !empty( $this->error ) ) {
				global $error;
				$error = $this->error;
			}
			
			if( get_option('oid_enable_loginform') ) {
				global $wordpressOpenIDRegistrationUI;
				ob_start( array( $wordpressOpenIDRegistrationUI, 'openid_wp_login_ob' ) );
			}
		}


		/* Start and finish the redirect loop, for the admin pages profile.php & users.php
		 */
		function admin_page_handler() {
			if( !isset( $_GET['page'] )) return;
			if( 'your-openid-identities' != plugin_basename( stripslashes($_GET['page']) ) ) return;

			if( !isset( $_REQUEST['action'] )) return;
			$this->action = $_REQUEST['action'];
			
			require_once(ABSPATH . 'wp-admin/admin-functions.php');
			require_once(ABSPATH . 'wp-admin/admin-db.php');
			require_once(ABSPATH . 'wp-admin/upgrade-functions.php');

			auth_redirect();
			nocache_headers();
			get_currentuserinfo();
			
			// Construct self-referential url for redirects.
			if ( current_user_can('edit_users') ) $parent_file = 'users.php';
			else $parent_file = 'profile.php';
			$self = get_option('siteurl') . '/wp-admin/' . $parent_file . '?page=your-openid-identities';
			
			switch( $this->action ) {
				case 'add_identity':			// Verify identity, return with add_identity_ok
					$claimed_url = $_POST['openid_url'];
					
					if ( empty( $claimed_url ) ) return;
					if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log('OpenIDConsumer: Attempting bind for "' . $claimed_url . '"');

					set_error_handler( array($this, 'customer_error_handler'));
					$auth_request = $this->_consumer->begin( $claimed_url );
					restore_error_handler();

					// TODO: Better error handling.
					if ( null === $auth_request ) {
						$this->error = 'Could not discover an OpenID identity server endpoint at the url: ' . htmlentities( $claimed_url );
						if( strpos( $claimed_url, '@' ) ) {
							// Special case a failued url with an @ sign in it.
							// Users entering email addresses are probably chewing soggy crayons.
							$this->error .= '<br/>The address you specified had an @ sign in it, but OpenID Identities are not email addresses, and should probably not contain an @ sign.';
						}
						break;
					}

					global $userdata;
					if( $userdata->ID === $this->get_user_by_identity( $auth_request->endpoint->identity_url )) {
						$this->error = 'The specified url is already bound to this account, dummy';
						break;
					}

					$return_to = $self . '&action=add_identity_ok';
					$redirect_url = $auth_request->redirectURL( get_option('oid_trust_root'), $return_to );

					if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log('OpenIDConsumer: redirect: ' . $redirect_url );
					wp_redirect( $redirect_url );
					exit(0);
					break;
					
				case 'add_identity_ok':					// Return from verify loop.
					if ( !isset( $_GET['openid_mode'] ) ) break;	// no mode? probably a spoof or bad cancel.

					set_error_handler( array($this, 'customer_error_handler'));
					$response = $this->_consumer->complete( $_GET );
					restore_error_handler();
					
					switch( $response->status ) {
						case Auth_OpenID_CANCEL:	$this->error = 'OpenID assertion cancelled'; break;
						case Auth_OpenID_FAILURE:	$this->error = 'OpenID assertion failed: ' . $response->message; break;
						case Auth_OpenID_SUCCESS:	$this->error = 'OpenID assertion successful';
							if( !$this->insert_identity( $response->identity_url ) ) {
								$this->error = 'OpenID assertion successful, but this URL is already claimed by another user on this blog. This is probably a bug';
							} else {
								$this->action = 'success';
							}
							break;
						default:					$this->error = 'Unknown Status. Bind not successful. This is probably a bug';
					}
					break;
					
				case 'drop_identity':					// Remove a binding.
					if( !isset( $_GET['id'])) {
						$this->error = 'Identity url delete failed: ID paramater missing.';
						break;
					}

					$deleted_identity_url = $this->get_my_identities( $_GET['id'] );
					if( FALSE === $deleted_identity_url ) {
						$this->error = 'Identity url delete failed: Specified identity does not exist.';
						break;
					}
					
					if( $this->drop_identity( $_GET['id'] ) ) {
						$this->error = 'Identity url delete successful. <b>' . $deleted_identity_url . '</b> removed';
						$this->action= 'success';
						break;
					}
					
					$this->error = 'Identity url delete failed: Unknown reason';
					break;
			}
		}


		/* Application-specific database operations */
		function get_my_identities( $id = 0 ) {
			global $userdata;
			if( !$this->enabled ) return array();
			if( $id ) return $this->_store->connection->getOne( "SELECT url FROM $this->identity_url_table_name WHERE user_id = %s AND uurl_id = %s",
					array( (int)$userdata->ID, (int)$id ) );
			return $this->_store->connection->getAll( "SELECT uurl_id,url FROM $this->identity_url_table_name WHERE user_id = %s",
				array( (int)$userdata->ID ) );
		}

		function insert_identity($url) {
			global $userdata, $wpdb;
			if( !$this->enabled ) return false;
			$old_show_errors = $wpdb->show_errors;
			if( $old_show_errors ) $wpdb->hide_errors();
			$ret = @$this->_store->connection->query( "INSERT INTO $this->identity_url_table_name (user_id,url,hash) VALUES ( %s, %s, MD5(%s) )",
				array( (int)$userdata->ID, $url, $url ) );
			if( $old_show_errors ) $wpdb->show_errors();
			return $ret;
		}
		
		function drop_all_identities_for_user($userid) {
			if( !$this->enabled ) return false;
			return $this->_store->connection->query( "DELETE FROM $this->identity_url_table_name WHERE user_id = %s", 
				array( (int)$userid ) );
		}
		
		function drop_identity($id) {
			global $userdata;
			if( !$this->enabled ) return false;
			return $this->_store->connection->query( "DELETE FROM $this->identity_url_table_name WHERE user_id = %s AND uurl_id = %s",
				array( (int)$userdata->ID, (int)$id ) );
		}
		
		function get_user_by_identity($url) {
			if( !$this->enabled ) return false;
			return $this->_store->connection->getOne( "SELECT user_id FROM $this->identity_url_table_name WHERE url = %s",
				array( $url ) );
		}

		/* Simple loop to reduce collisions for usernames for urls like:
		 * Eg: http://foo.com/80/to/magic.com
		 * and http://foo.com.80.to.magic.com
		 * and http://foo.com:80/to/magic.com
		 * and http://foo.com/80?to=magic.com
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
		
		function normalize_username($username) {
			$username = sanitize_user( $username );
			$username = preg_replace('|[^a-z0-9 _.\-@]+|i', '-', $username);
			return $username;
		}



		/*  
		 * Prepare to start the redirect loop
		 * This function is mainly for assembling urls
		 * Called from wp_authenticate (for login form) and openid_wp_comment_tagging (for comment form)
		 * If using comment form, specify optional parameters action=commentopenid and wordpressid=PostID.
		 */
		function start_login( $claimed_url, $redirect_to, $action='loginopenid', $wordpressid=0 ) {
			if ( empty( $claimed_url ) ) return; // do nothing.
			
			set_error_handler( array($this, 'customer_error_handler'));
			$auth_request = $this->_consumer->begin( $claimed_url );
			restore_error_handler();

			if ( null === $auth_request ) {
				$this->error = 'Could not discover an OpenID identity server endpoint at the url: ' . htmlentities( $claimed_url );
				if( strpos( $claimed_url, '@' ) ) { $this->error .= '<br/>The address you specified had an @ sign in it, but OpenID Identities are not email addresses, and should probably not contain an @ sign.'; }
				if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log('OpenIDConsumer: ' . $this->error );
				return;
			}
			
			if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log('OpenIDConsumer: Is an OpenID url. Starting redirect.');
			
			$return_to = get_bloginfo('url') . "/wp-login.php?action=$action";
			if( $wordpressid ) $return_to .= "&wordpressid=$wordpressid";
			if( !empty( $redirect_to ) ) $return_to .= '&redirect_to=' . urlencode( $redirect_to );
			
			/* If we've never heard of this url before, add the SREG extension.
				NOTE: Anonymous clients could attempt to authenticate with a series of OpenID urls, and
				the presence or lack of SREG exposes whether a given OpenID has an account at this site. */
				
			if( $this->get_user_by_identity( $auth_request->endpoint->identity_url ) == NULL ) $auth_request->addExtensionArg('sreg', 'optional', 'nickname,email,fullname');
			
			$redirect_url = $auth_request->redirectURL( get_option('oid_trust_root'), $return_to );
			wp_redirect( $redirect_url );
			exit(0);
		}

		/* 
		 * Finish the redirect loop.
		 * If returning from openid server with action set to loginopenid or commentopenid, complete the loop
		 * If we fail to login, pass on the error message.
		 */	
		function finish_login( ) {
			$self = basename( $GLOBALS['pagenow'] );
			
			switch ( $self ) {
				case 'wp-login.php':
					if( $action == 'register' ) {
						global $wordpressOpenIDRegistrationUI;
						ob_start( array( $wordpressOpenIDRegistrationUI, 'openid_wp_register_ob' ) );
						return;
					}
					if ( !isset( $_GET['openid_mode'] ) ) return;
					if( $_GET['action'] == 'loginopenid' ) break;
					if( $_GET['action'] == 'commentopenid' ) break;
					return;
					break;
					

				case 'wp-register.php':
					global $wordpressOpenIDRegistrationUI;
					ob_start( array( $wordpressOpenIDRegistrationUI, 'openid_wp_register_ob' ) );
					return;

				default:
					return;				
			}						
			
			// We're doing OpenID login, so zero out these variables
			unset( $_POST['user_login'] );
			unset( $_POST['user_pass'] );

			// The JanRain consumer can throw errors. We'll try to handle them ourselves.
			set_error_handler( array($this, 'customer_error_handler'));
			$response = $this->_consumer->complete( $_GET );
			restore_error_handler();
			
			switch( $response->status ) {
			case Auth_OpenID_CANCEL:
				$this->error = 'OpenID Verification Cancelled.';
				break;
			case Auth_OpenID_FAILURE:
				$this->error = 'OpenID Authentication Failed: <br/>' . stripslashes($response->message) . '.';
				if(stristr( $response->message, 'not under trust_root')) $this->error.='<br/>Plugin incorrectly configured.';
				break;
			case Auth_OpenID_SUCCESS:
				$this->error = 'OpenID Authentication Success.';

				$this->action = '';
				$redirect_to = 'wp-admin/';

				$matching_user_id = $this->get_user_by_identity( $response->identity_url );
				
				if( NULL !== $matching_user_id ) {
					$user = new WP_User( $matching_user_id );
					
					if( wp_login( $user->user_login, md5($user->user_pass), true ) ) {
						if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log("OpenIDConsumer: Returning user logged in: $user->user_login"); 
						do_action('wp_login', $user_login);
						wp_clearcookie();
						wp_setcookie($user->user_login, md5($user->user_pass), true, '', '', true);
						$this->action = 'redirect';
						if ( !$user->has_cap('edit_posts') ) $redirect_to = '/wp-admin/profile.php';

					} else {
						$this->error = "OpenID authentication valid, but Wordpress login failed. OpenID login disabled for this account.";
						$this->action = 'error';
					}
					
				} else {
					// Identity URL is new, so create a user with md5()'d password
					@include_once( ABSPATH . 'wp-admin/upgrade-functions.php');	// 2.1
				 	@include_once( ABSPATH . WPINC . '/registration-functions.php'); // 2.0.4
					
					$username = $this->generate_new_username( $response->identity_url );
					$password = substr( md5( uniqid( microtime() ) ), 0, 7);
					
					$user_id = wp_create_user( $username, $password );
					if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log("wp_create_user( $username, $password )  returned $user_id ");
					
					if( $user_id ) {	// created ok
						update_usermeta( $user_id, 'registered_with_openid', true );
						$temp_user_data=array( 'ID' => $user_id,
							'user_url' => $response->identity_url,
							'user_nicename' => $response->identity_url,
							'display_name' => $response->identity_url );

						$sreg = $response->extensionResponse('sreg');
						if( isset( $sreg['email'])) $temp_user_data['user_email'] = $sreg['email'];
						if( isset( $sreg['nickname'])) $temp_user_data['nickname'] = $temp_user_data['user_nicename'] = $temp_user_data['display_name'] =$sreg['nickname'];
						if( isset( $sreg['fullname'])) {
							$namechunks = explode( ' ', $sreg['fullname'], 2 );
							if( isset($namechunks[0]) ) $temp_user_data['first_name'] = $namechunks[0];
							if( isset($namechunks[1]) ) $temp_user_data['last_name'] = $namechunks[1];
						}
						if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log("OpenIDConsumer: Created new user $user_id : $username and metadata: " . var_export( $temp_user_data, true ) );
						
						// Insert the new wordpress user into the database
						wp_update_user( $temp_user_data );
						$user = new WP_User( $user_id );

						if( ! wp_login( $user->user_login, md5($user->user_pass), true ) ) {
							$this->error = "User was created fine, but wp_login() for the new user failed. This is probably a bug.";
							$this->action= 'error';
							error_log( $this->error );
							break;
						}
						
						// Call the usual user-registration hooks
						do_action('user_register', $user_id);
						wp_new_user_notification( $user->user_login );
						
						wp_clearcookie();
						wp_setcookie( $user->user_login, md5($user->user_pass), true, '', '', true );
						
						// Bind the provided identity to the just-created user
						global $userdata;
						$userdata = get_userdata( $user_id );
						$this->insert_identity( $response->identity_url );
						
						$this->action = 'redirect';
						
						if ( !$user->has_cap('edit_posts') ) $redirect_to = '/wp-admin/profile.php';
						
						
					} else {
						// failed to create user for some reason.
						$this->error = "OpenID authentication successful, but failed to create Wordpress user. This is probably a bug.";
						$this->action= 'error';
						error_log( $this->error );
					}
				}
				break;

			default:
				$this->error = "OpenID authentication failed, unknown problem #$response->status";
				$this->action= 'error';
				error_log( $this->error );
				break;
			}
			
			if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log('OpenIDConsumer: Finish Auth for "' . $response->identity_url . '". ' . $this->error );
			
			if( $this->action == 'redirect' ) {
				if ( !empty( $_GET['redirect_to'] )) $redirect_to = $_GET['redirect_to'];
				
				if( $_GET['action'] == 'commentopenid' ) {
					/* Transparent inline login and commenting.
					 * There's a cookie containing the comment text.
					 * Post it and redirect to the permalink.
					 */
					
					$comment_content = $this->comment_get_cookie();
					$this->comment_clear_cookie();
					
					if ( '' == trim($comment_content) )
						die( __('Error: please type a comment.') );
					
					if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log('OpenIDConsumer: action=commentopenid  redirect_to=' . $redirect_to);
					if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log('OpenIDConsumer: comment_content = ' . $comment_content);
					
					nocache_headers();
					
					// Do essentially the same thing as wp-comments-post.php
					global $wpdb;
					$comment_post_ID = (int) $_GET['wordpressid'];
					$status = $wpdb->get_row("SELECT post_status, comment_status FROM $wpdb->posts WHERE ID = '$comment_post_ID'");
					if ( empty($status->comment_status) ) {
						do_action('comment_id_not_found', $comment_post_ID);
						exit();
					} elseif ( 'closed' ==  $status->comment_status ) {
						do_action('comment_closed', $comment_post_ID);
						die( __('Sorry, comments are closed for this item.') );
					} elseif ( 'draft' == $status->post_status ) {
						do_action('comment_on_draft', $comment_post_ID);
						exit;
					}
					
					if ( !$user->ID )
						die( __('Sorry, you must be logged in to post a comment.')
							.' If OpenID isn\'t working for you, try anonymous commenting.' );
					
					$comment_author       = $wpdb->escape($user->display_name);
					$comment_author_email = $wpdb->escape($user->user_email);
					$comment_author_url   = $wpdb->escape($user->user_url);
					$comment_type         = 'openid';
					$user_ID              = $user->ID;
					$this->flag_doing_openid_comment = true;

					$commentdata = compact('comment_post_ID', 'comment_author', 'comment_author_email',
												'comment_author_url', 'comment_content', 'comment_type', 'user_ID');

					$comment_id = wp_new_comment( $commentdata );
				}
				
				if( $redirect_to == '/wp-admin' and !$user->has_cap('edit_posts') ) $redirect_to = '/wp-admin/profile.php';
				wp_redirect( $redirect_to );
			}

			global $action;
			$action=$this->action; 

		}


		/* These functions are used to store the comment
		 * in a cookie temporarily while doing an
		 * OpenID redirect loop.
		 */
		
		function comment_set_cookie( $content ) {
			$commenttext = trim( $content );
			setcookie('comment_content_' . COOKIEHASH, $commenttext, time() + 3600, COOKIEPATH, COOKIE_DOMAIN);
			setcookie('comment_content_' . COOKIEHASH, $commenttext, time() + 3600, SITECOOKIEPATH, COOKIE_DOMAIN);
		}

		function comment_clear_cookie( ) {
			setcookie('comment_content_' . COOKIEHASH, ' ', time() - 31536000, COOKIEPATH, COOKIE_DOMAIN);
			setcookie('comment_content_' . COOKIEHASH, ' ', time() - 31536000, SITECOOKIEPATH, COOKIE_DOMAIN);
		}

		function comment_get_cookie( ) {
			if( !empty($_COOKIE) )
				if ( !empty($_COOKIE[ 'comment_content_' . COOKIEHASH ] ) )
					return trim( $_COOKIE[ 'comment_content_' . COOKIEHASH ] );
			return false;
		}

		/* Called when comment is submitted by get_option('require_name_email') */
		function openid_bypass_option_require_name_email( $value ) {
			if( !empty( $_POST['openid_url'] ) ) {	// same criteria as the hijack in openid_wp_comment_tagging
				return false;
			}
		}
		
		/*
		 * Called when comment is submitted via preprocess_comment hook.
		 * Set the comment_type to 'openid', so it can be drawn differently by theme.
		 * If comment is submitted along with an openid url, store comment in cookie, and do authentication.
		 */
		function openid_wp_comment_tagging( $comment ) {
			global $current_user;		
			
			if( get_usermeta($current_user->ID, 'registered_with_openid') ) {
				$comment['comment_type']='openid';
			}
			
			if( !empty( $_POST['openid_url'] ) ) {  // Comment form's OpenID url is filled in.
				$this->comment_set_cookie( stripslashes( $comment['comment_content'] ) );
				$this->start_login( $_POST['openid_url'], get_permalink( $comment['comment_post_ID'] ), 'commentopenid', $comment['comment_post_ID'] );
				
				// Failure to redirect at all, the URL is malformed or unreachable. Display the login form with the error.
				global $error;
				$error = $this->error;
				$_POST['openid_url'] = '';
				include( ABSPATH . 'wp-login.php' );
				exit();
			}
			
			return $comment;
		}



		/*
		 * Sanity check for urls entered in Options pane. Needs to permit HTTPS.
		 */
		function openid_is_url($url) {
			return !preg_match( '#^http(s)?\\:\\/\\/[a-z0-9\-]+\.([a-z0-9\-]+\.)?\\/[a-z]+#i',$url );
		}
		
		
		/* Hooks to clean up wp_notify_postauthor() emails
		 * Tries to call as few functions as required */
		function openid_comment_notification_text( $notify_message_original, $comment_id ) {
			if( $this->flag_doing_openid_comment ) {
				$comment = get_comment( $comment_id );
				
				if( 'openid' == $comment->comment_type ) {
					$post = get_post($comment->comment_post_ID);
					$youcansee = __('You can see all comments on this post here: ');
					if( !strpos( $notify_message_original, $youcansee ) ) { // notification message missing, prepend it
						$notify_message  = sprintf( __('New comment on your post #%1$s "%2$s"'), $comment->comment_post_ID, $post->post_title ) . "\r\n";
						$notify_message .= sprintf( __('Author : %1$s (IP: %2$s , %3$s)'), $comment->comment_author, $comment->comment_author_IP, $comment_author_domain ) . "\r\n";
						$notify_message .= sprintf( __('E-mail : %s'), $comment->comment_author_email ) . "\r\n";
						$notify_message .= sprintf( __('URL    : %s'), $comment->comment_author_url ) . "\r\n";
						$notify_message .= sprintf( __('Whois  : http://ws.arin.net/cgi-bin/whois.pl?queryinput=%s'), $comment->comment_author_IP ) . "\r\n";
						$notify_message .= __('Comment: ') . "\r\n" . $comment->comment_content . "\r\n\r\n";
						$notify_message .= $youcansee . "\r\n";
						return $notify_message . $notify_message_original;
					}
				}
			}
			return $notify_message_original;
		}
		function openid_comment_notification_subject( $subject, $comment_id ) {
			if( $this->flag_doing_openid_comment ) {
				$comment = get_comment( $comment_id );
				
				if( 'openid' == $comment->comment_type and empty( $subject ) ) {
					$blogname = get_option('blogname');
					$post = get_post($comment->comment_post_ID);
					$subject = sprintf( __('[%1$s] OpenID Comment: "%2$s"'), $blogname, $post->post_title );
				}
			}
			return $subject;
		}

	} // end class definition
} // end if-class-exists test



/* Bootstap operations */

/* Check whether the specified file exists somewhere in PHP's path.
 * Used for sanity-checking require() or include().
 */
if( !function_exists( 'file_exists_in_path' ) ) {
	function file_exists_in_path ($file) {
		if( file_exists( $file ) ) return $file;
		$relativeto = dirname( __FILE__ ) . DIRECTORY_SEPARATOR;
		$paths = explode(PATH_SEPARATOR, get_include_path());
		foreach ($paths as $path) {
			$fullpath = $path . DIRECTORY_SEPARATOR . $file;
			if( substr( $path, 0, 1 ) !== '/' )
				$fullpath = $relativeto . $fullpath;
			if (file_exists($fullpath)) return $fullpath;
		}
		return false;
	}
}

/* State of the Plugin */
$wordpressOpenIDRegistration_Status = array();

function wordpressOpenIDRegistration_Status_Set($slug, $state, $message) {
	global $wordpressOpenIDRegistration_Status;
	$wordpressOpenIDRegistration_Status[$slug] = array('state'=>$state,'message'=>$message);
	if( !$state or WORDPRESSOPENIDREGISTRATION_DEBUG ) {
		if( $state === true ) { $_state = 'ok'; }
		elseif( $state === false ) { $_state = 'fail'; }
		else { $_state = ''.($state); }
		error_log('WPOpenID Status: ' . strip_tags($slug) . " [$_state]" . ( ($_state==='ok') ? '': strip_tags(str_replace('<br/>'," ", ': ' . $message))  ) );
	}
}

wordpressOpenIDRegistration_Status_Set('file:error_log', 'info', ini_get('error_log') ? ("Logging errors via PHP's error_log faculty to: " . ini_get('error_log')) : "PHP error_log is not set." );

$wordpressOpenIDRegistration_Required_Files = array(
	'user-interface.php' => 'Came with the plugin, but not found in include path. Did you remeber to upload it?',
	'Services/Yadis/PlainHTTPFetcher.php' => 'Do you have the <a href="http://www.openidenabled.com/yadis/libraries/php/">JanRain PHP Yadis library</a> installed in your path? (Comes with the OpenID library.)',
	'Services/Yadis/Yadis.php' => 'Do you have the <a href="http://www.openidenabled.com/yadis/libraries/php/">JanRain PHP Yadis library</a> installed in your path? (Comes with the OpenID library.)',
	'Auth/OpenID.php' => 'Do you have the <a href="http://www.openidenabled.com/openid/libraries/php/">JanRain PHP OpenID library</a> installed in your path?',
	'Auth/OpenID/Discover.php' => 'Do you have the <a href="http://www.openidenabled.com/openid/libraries/php/">JanRain PHP OpenID library</a> installed in your path?',
	'Auth/OpenID/DatabaseConnection.php' => 'Do you have the <a href="http://www.openidenabled.com/openid/libraries/php/">JanRain PHP OpenID library</a> installed in your path?',
	'Auth/OpenID/MySQLStore.php' => 'Do you have the <a href="http://www.openidenabled.com/openid/libraries/php/">JanRain PHP OpenID library</a> installed in your path?',
	'wpdb-pear-wrapper.php' => 'Came with the plugin, but not found in include path.  Did you remeber to upload it?',
	'Auth/OpenID/Consumer.php' => 'Do you have the <a href="http://www.openidenabled.com/openid/libraries/php/">JanRain PHP OpenID library</a> installed in your path?'
	);

function wordpressOpenIDRegistration_Load_Required_Files( $wordpressOpenIDRegistration_Required_Files ) {
	/* Library may declare global variables. Some of these are required by other
	 * classes or functions in the library, and some are not. We're going to 
	 * permit only the required global variables to be created.
	 */
	global $__Services_Yadis_defaultParser, $__Services_Yadis_xml_extensions,
		$_Services_Yadis_ns_map, $_Auth_OpenID_namespaces, $__UCSCHAR, $__IPRIVATE, $DEFAULT_PROXY,
		$XRI_AUTHORITIES, $_escapeme_re, $_xref_re, $__Auth_OpenID_PEAR_AVAILABLE,
		$_Auth_OpenID_math_extensions, $_Auth_OpenID_DEFAULT_MOD, $_Auth_OpenID_DEFAULT_GEN;
	// $parts, $pair, $n, $m;  // Unnessessary global variables absorbed
	$global_variables = array_keys($GLOBALS);
	ini_set('include_path',ini_get('include_path').':'.dirname(__FILE__));   // Add plugin directory to include path temporarily
	foreach( $wordpressOpenIDRegistration_Required_Files as $___k => $___v ) {
		if( file_exists_in_path( $___k ) ) {
			if( include_once( $___k ) ) {
				wordpressOpenIDRegistration_Status_Set('loading file: '.$___k, true, '');
				continue;
			}
		}
		wordpressOpenIDRegistration_Status_Set('file:'.$___k, false, $___v );
		break;
	}
	ini_restore('include_path');  // Leave no footprints behind
}

/* Load required libraries into global context. */
wordpressOpenIDRegistration_Load_Required_Files( $wordpressOpenIDRegistration_Required_Files );

/* Add custom OpenID options */
add_option( 'oid_trust_root', get_settings('siteurl'), 'The Open ID trust root' );
add_option( 'oid_enable_selfstyle', true, 'Use internal style rules' );
add_option( 'oid_enable_loginform', true, 'Display OpenID box in login form' );
add_option( 'oid_enable_commentform', true, 'Display OpenID box in comment form' );

/* Instantiate User Interface class */
if( class_exists('WordpressOpenIDRegistrationUI')) {
	$wordpressOpenIDRegistrationUI = new WordpressOpenIDRegistrationUI();
	$wordpressOpenIDRegistrationUI->startup();
	if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log("Poststrap Level 2.9: OID " . ($wordpressOpenIDRegistrationUI->oid->enabled? 'Enabled':'Disabled' ) . ' (finished including and instantiating, passing control back to wordpress)' );
} else {
	echo '<div class="error"><p><strong>The Wordpress OpenID Registration User Interface class could not be loaded. Make sure wpopenid/user-interface.php was uploaded properly.</strong></p></div>';
}

?>