<?php
if  ( !class_exists('WordpressOpenIDLogic') ) {
	class WordpressOpenIDLogic {

		var $_store;	// Hold the WP_OpenIDStore and
		var $_consumer; // Auth_OpenID_Consumer internally.
		
		var $error;		// User friendly error message, defaults to ''.
		var $action;	// Internal action tag. '', 'error', 'redirect'.

		var $enabled = true;

		var $identity_url_table_name;
		var $flag_doing_openid_comment = false;


		/* Soft verification of plugin activation OK */
		function uptodate() {
			if( get_option('oid_db_version') != WPOPENID_DB_VERSION ) {  // Database version mismatch, force dbDelta() in admin interface.
				$this->enabled = false;
				wordpressOpenIDRegistration_Status_Set('Plugin Database Version', false, 'Plugin database is out of date. ' . get_option('oid_db_version') . ' != ' . WPOPENID_DB_VERSION );
				update_option('oid_plugin_enabled', false);
				return false;
			}
			$this->enabled = (get_option('oid_plugin_enabled') == true );
			return $this->enabled;
		}
		
		function getStore() {
			if (!isset($this->_store)) {
				require_once 'wpdb-pear-wrapper.php';

				$this->_store = new WP_OpenIDStore();
				if (null === $this->_store) {
					wordpressOpenIDRegistration_Status_Set('object: OpenID Store', false, 'OpenID store could not be created properly.');
					wordpressOpenIDRegistration_Status_Set('class: Auth_OpenID_MySQLStore', class_exists('Auth_OpenID_MySQLStore'), 'This class is provided by the JanRain library, used to store association and nonce data.');
					wordpressOpenIDRegistration_Status_Set('class: WP_OpenIDStore', class_exists('WP_OpenIDStore'),  'This class is provided by the plugin, used to wrap the Wordpress database for PEAR-style database access. It\'s provided by <code>wpdb-pear-wrapper.php</code>, did you upload it?');
					$this->enabled = false;
				} else {
					wordpressOpenIDRegistration_Status_Set('object: OpenID Store', true, 'OpenID store created properly.');
				}
			}

			return $this->_store;
		}

		function getConsumer() {
			if (!isset($this->_consumer)) {
				require_once 'Auth/OpenID/Consumer.php';

				$this->_consumer = new Auth_OpenID_Consumer($this->getStore());
				if( null === $this->_consumer ) {
					wordpressOpenIDRegistration_Status_Set('object: OpenID Consumer', false, 'OpenID consumer could not be created properly.');
					wordpressOpenIDRegistration_Status_Set('class: Auth_OpenID_Consumer', class_exists('Auth_OpenID_Consumer'),  'This class is provided by the JanRain library, does the heavy lifting.');
					$this->enabled = false;
				} else {
					wordpressOpenIDRegistration_Status_Set('object: OpenID Consumer', true, 'OpenID consumer created properly.');
				}
			}

			return $this->_consumer;
		}
		
		/* 
		 * Initialize required store and consumer, making a few sanity checks.
		 */
		function late_bind($reload = false) {
			static $done = false;
			$this->enabled = true; // Be Optimistic
			if( $done && !$reload ) return $this->uptodate();
			$done = true;

			if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log('WPOpenID Plugin: Late Binding Now');
			
			$f = @fopen( '/dev/urandom', 'r');
            if ($f === false) {
                define( 'Auth_OpenID_RAND_SOURCE', null );
            }
			
			/* include_once() all required library files */
			global $wordpressOpenIDRegistration_Required_Files;
			wordpressOpenIDRegistration_Load_Required_Files( $wordpressOpenIDRegistration_Required_Files );

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
			}
			

			if( false === get_option('oid_trust_root') or '' === get_option('oid_trust_root') ) {
				wordpressOpenIDRegistration_Status_Set('Option: Trust Root', 'info', 'You must specify the Trust Root paramater on the OpenID Options page. Commenters will be asked whether they trust this url, and its decedents, to know that they are logged in and control their identity url. Include the trailing slash.');
			}
			
			if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log("Bootstrap -- checking tables");
			if( $this->enabled ) {
				$this->enabled = $this->check_tables();
				if( !$this->uptodate() ) {
					update_option('oid_plugin_enabled', true);
					update_option('oid_plugin_version', WPOPENID_PLUGIN_VERSION );
					update_option('oid_db_version', WPOPENID_DB_VERSION );
					$this->uptodate();
				}
			} else {
				$this->error = 'WPOpenID Core is Disabled!';
				update_option('oid_plugin_enabled', false);
			}
			return $this->enabled;
		}
		
		/*
		 * Create tables if needed by running dbDelta calls. Upgrade safe. Called on plugin activate.
		 */
		function create_tables() {
			$this->late_bind();
			if( false == $this->enabled ) {  // do nothing if something bad happened
				$this->error = 'OpenID Consumer could not be activated, something bad happened. Skipping table create. Check libraries.';
				if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log($this->error);
				echo $this->error;
				return false;
			}
			if( null == $this->getStore() ) {
				$this->error = 'OpenID Consumer could not be activated, because the store could not be created properly. Are the database files in place?';
				if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log($this->error);
				echo $this->error;
				return false;				
			}
			require_once(ABSPATH . '/wp-admin/upgrade-functions.php');
			$this->getStore()->dbDelta();
			
			// Table for storing UserID <---> URL associations.
			$identity_url_table_sql = "CREATE TABLE $this->identity_url_table_name (
				uurl_id bigint(20) NOT NULL auto_increment,
				user_id bigint(20) NOT NULL default '0',
				url text,
				hash char(32),
				PRIMARY KEY  (uurl_id),
				UNIQUE KEY uurl (hash),
				KEY url (url(30)),
				KEY user_id (user_id)
				);";
			
			dbDelta($identity_url_table_sql);
		}
		
		/*
		 * Cleanup by dropping nonce, association, and settings tables. Called on plugin deactivate.
		 */
		function destroy_tables() {
			$this->late_bind();
			global $wpdb;
			if( $this->getStore() == null) {
				$this->error = 'OpenIDConsumer: Disabled. Cannot locate libraries, therefore cannot clean up database tables. Fix the libraries, or drop the tables yourself.';
				error_log($this->error);
				return;
			}
			if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log('Dropping all database tables.');
			$sql = 'drop table '. $this->getStore()->associations_table_name;
			$wpdb->query($sql);
			$sql = 'drop table '. $this->getStore()->nonces_table_name;
			$wpdb->query($sql);
			$sql = 'drop table '. $this->getStore()->settings_table_name;
			$wpdb->query($sql);
		}
		
		/*
		 * Check to see whether the none, association, and settings tables exist.
		 */
		function check_tables($retry=true) {
			$this->late_bind();
			if( null === $this->getStore() ) return false; // Can't check tables if the store object isn't created

			global $wpdb;
			$ok = true;
			$message = '';
			$tables = array( $this->getStore()->associations_table_name, $this->getStore()->nonces_table_name,
				$this->identity_url_table_name );
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
		 * Uses output buffering to modify the form. 
		 */
		function wp_authenticate( &$username ) {
			if( !empty( $_POST['openid_url'] ) ) {
				if( !$this->late_bind() ) return; // something is broken
				$redirect_to = '';
				if( !empty( $_REQUEST['redirect_to'] ) ) $redirect_to = $_REQUEST['redirect_to'];
				$this->start_login( $_POST['openid_url'], $redirect_to );
			}
			if( !empty( $this->error ) ) {
				global $error;
				$error = $this->error;
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

			if( !$this->late_bind() ) return; // something is broken
			
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
					$auth_request = $this->getConsumer()->begin( $claimed_url );
					restore_error_handler();

					// TODO: Better error handling.
					if ( null === $auth_request ) {
						$this->error = 'Could not discover an OpenID identity server endpoint at the url: ' . htmlentities( $claimed_url );
						if( strpos( $claimed_url, '@' ) ) {
							// Special case a failed url with an @ sign in it.
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

					$this->doRedirect($auth_request, get_option('oid_trust_root'), $return_to);

					exit(0);
					break;
					
				case 'add_identity_ok': // Return from verify loop.

					if ( !isset( $_GET['openid_mode'] ) ) break; // no mode? probably a spoof or bad cancel.

					list($identity_url, $sreg) = $this->finish_openid_auth();

					if (!$identity_url) break;

					if( !$this->insert_identity( $identity_url ) ) {
						$this->error = 'OpenID assertion successful, but this URL is already claimed by another user on this blog. This is probably a bug';
					} else {
						$this->action = 'success';
					}

					break;
					
				case 'drop_identity':  // Remove a binding.
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


		function doRedirect($auth_request, $trust_root, $return_to) {
			if ($auth_request->shouldSendRedirect()) {
				$redirect_url = $auth_request->redirectURL($trust_root, $return_to);

				if (Auth_OpenID::isFailure($redirect_url)) {
					error_log('Could not redirect to server: '.$redirect_url->message);
				} else {
					wp_redirect( $redirect_url );
				}
			} else {
				// Generate form markup and render it
				$form_id = 'openid_message';
				$form_html = $auth_request->formMarkup($trust_root, $return_to, false, array('id'=>$form_id));

				if (Auth_OpenID::isFailure($form_html)) {
					error_log('Could not redirect to server: '.$form_html->message);
				} else {
					?>
						<html>
							<head>
								<title>Redirecting to OpenID Provider</title>
							</head>
							<body onload="document.getElementById('<?php echo $form_id ?>').submit();">
								<h3>Redirecting to OpenID Provider</h3>
								<?php echo $form_html ?>
							</body>
						</html>
					<?php
				}
			}
		}

		
		function finish_openid_auth() {
			set_error_handler( array($this, 'customer_error_handler'));
			$response = $this->getConsumer()->complete();
			restore_error_handler();
			
			switch( $response->status ) {
				case Auth_OpenID_CANCEL:
					$this->error = 'OpenID assertion cancelled'; 
					break;

				case Auth_OpenID_FAILURE:
					$this->error = 'OpenID assertion failed: ' . $response->message; 
					break;

				case Auth_OpenID_SUCCESS:
					$this->error = 'OpenID assertion successful';

					$openid = $response->identity_url;
					$esc_identity = htmlspecialchars($openid, ENT_QUOTES);
					error_log('Got back identity URL ' . $esc_identity);

					if ($response->endpoint->canonicalID) {
						error_log('XRI CanonicalID: ' . $response->endpoint->canonicalID);
					}

					$sreg_resp = Auth_OpenID_SRegResponse::fromSuccessResponse($response);
					$sreg = $sreg_resp->contents();
					
					return array($esc_identity, $sreg);

				default:
					$this->error = 'Unknown Status. Bind not successful. This is probably a bug';
			}

			return null;
		}
		

		/* Application-specific database operations */
		function get_my_identities( $id = 0 ) {
			$this->late_bind();
			global $userdata;
			if( !$this->enabled ) return array();
			if( $id ) return $this->getStore()->connection->getOne( "SELECT url FROM $this->identity_url_table_name WHERE user_id = %s AND uurl_id = %s",
					array( (int)$userdata->ID, (int)$id ) );
			return $this->getStore()->connection->getAll( "SELECT uurl_id,url FROM $this->identity_url_table_name WHERE user_id = %s",
				array( (int)$userdata->ID ) );
		}

		function insert_identity($url) {
			$this->late_bind();
			global $userdata, $wpdb;
			if( !$this->enabled ) return false;
			$old_show_errors = $wpdb->show_errors;
			if( $old_show_errors ) $wpdb->hide_errors();
			$ret = @$this->getStore()->connection->query( "INSERT INTO $this->identity_url_table_name (user_id,url,hash) VALUES ( %s, %s, MD5(%s) )",
				array( (int)$userdata->ID, $url, $url ) );
			if( $old_show_errors ) $wpdb->show_errors();
			return $ret;
		}
		
		function drop_all_identities_for_user($userid) {
			$this->late_bind();
			if( !$this->enabled ) return false;
			return $this->getStore()->connection->query( "DELETE FROM $this->identity_url_table_name WHERE user_id = %s", 
				array( (int)$userid ) );
		}
		
		function drop_identity($id) {
			$this->late_bind();
			global $userdata;
			if( !$this->enabled ) return false;
			return $this->getStore()->connection->query( "DELETE FROM $this->identity_url_table_name WHERE user_id = %s AND uurl_id = %s",
				array( (int)$userdata->ID, (int)$id ) );
		}
		
		function get_user_by_identity($url) {
			$this->late_bind();
			if( !$this->enabled ) return false;
			return $this->getStore()->connection->getOne( "SELECT user_id FROM $this->identity_url_table_name WHERE url = %s",
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
		 * Called from wp_authenticate (for login form) and comment_tagging (for comment form)
		 * If using comment form, specify optional parameters action=commentopenid and wordpressid=PostID.
		 */
		function start_login( $claimed_url, $redirect_to, $action='loginopenid', $wordpressid=0 ) {

			if ( empty( $claimed_url ) ) return; // do nothing.
			
			if( !$this->late_bind() ) return; // something is broken

			if ( null !== $openid_auth_request) {
				$auth_request = $openid_auth_request;
			} else {
				set_error_handler( array($this, 'customer_error_handler'));
				$auth_request = $this->getConsumer()->begin( $claimed_url );
				restore_error_handler();
			}

			if ( null === $auth_request ) {
				$this->error = 'Could not discover an OpenID identity server endpoint at the url: ' . htmlentities( $claimed_url );
				if( strpos( $claimed_url, '@' ) ) { $this->error .= '<br/>The address you specified had an @ sign in it, but OpenID Identities are not email addresses, and should probably not contain an @ sign.'; }
				if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log('OpenIDConsumer: ' . $this->error );
				return;
			}
			
			if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log('OpenIDConsumer: Is an OpenID url. Starting redirect.');
			
			$return_to = get_option('siteurl') . "/wp-login.php?action=$action";
			if( $wordpressid ) $return_to .= "&wordpressid=$wordpressid";
			if( !empty( $redirect_to ) ) $return_to .= '&redirect_to=' . urlencode( $redirect_to );
			
			/* If we've never heard of this url before, add the SREG extension.
				NOTE: Anonymous clients could attempt to authenticate with a series of OpenID urls, and
				the presence or lack of SREG exposes whether a given OpenID has an account at this site. */
			if( $this->get_user_by_identity( $auth_request->endpoint->identity_url ) == NULL ) {
				$sreg_request = Auth_OpenID_SRegRequest::build(
					// required
					array(), 
					//optional
					array('nickname', 'email', 'fullname'));

				if ($sreg_request) {
					$auth_request->addExtension($sreg_request);	
				}
			}
			
			$this->doRedirect($auth_request, get_option('oid_trust_root'), $return_to);
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
						return;
					}
					if ( !isset( $_GET['openid_mode'] ) ) return;
					if( $_GET['action'] == 'loginopenid' ) break;
					if( $_GET['action'] == 'commentopenid' ) break;
					return;
					break;
					

				case 'wp-register.php':
					return;

				default:
					return;				
			}						
			
			if( !$this->late_bind() ) return; // something is broken
			
			// We're doing OpenID login, so zero out these variables
			unset( $_POST['user_login'] );
			unset( $_POST['user_pass'] );

			list($identity_url, $sreg) = $this->finish_openid_auth();

			if ($identity_url) {
				$this->error = 'OpenID Authentication Success.';

				$this->action = '';
				$redirect_to = 'wp-admin/';

				$matching_user_id = $this->get_user_by_identity( $identity_url );
				
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

					global $oid_user_data;
					global $oid_user;
					$oid_user_data =& $this->get_user_data($identity_url, $sreg);

					if ( get_option('users_can_register') && 
						( $_GET['action'] == 'loginopenid' || get_option('oid_enable_localaccounts'))) {
							$oid_user = $this->create_new_user($identity_url, $oid_user_data);
					} else {
						$this->action = 'redirect';
					}

				}
			} else {
				//XXX: option to comment anonymously
				$this->error = "We were unable to authenticate your OpenID";
			}
			

			if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log('OpenIDConsumer: Finish Auth for "' . $response->identity_url . '". ' . $this->error );
			
			if( $this->action == 'redirect' ) {
				if ( !empty( $_GET['redirect_to'] )) $redirect_to = $_GET['redirect_to'];
				
				if( $_GET['action'] == 'commentopenid' ) {
					$this->post_comment($oid_user_data);
				}

				if( $redirect_to == '/wp-admin' and !$user->has_cap('edit_posts') ) $redirect_to = '/wp-admin/profile.php';
				wp_redirect( $redirect_to );
			}

			global $action;
			$action=$this->action; 

		}


		function create_new_user($identity_url, &$oid_user_data) {
			// Identity URL is new, so create a user with md5()'d password
			@include_once( ABSPATH . 'wp-admin/upgrade-functions.php');	// 2.1
			@include_once( ABSPATH . WPINC . '/registration-functions.php'); // 2.0.4

			$username = $this->generate_new_username( $identity_url );
			$password = substr( md5( uniqid( microtime() ) ), 0, 7);
			
			$user_id = wp_create_user( $username, $password );
			if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log("wp_create_user( $username, $password )  returned $user_id ");

			if( $user_id ) {	// created ok

				$oid_user_data['ID'] = $user_id;
				update_usermeta( $user_id, 'registered_with_openid', true );

				if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log("OpenIDConsumer: Created new user $user_id : $username and metadata: " . var_export( $oid_user_data, true ) );
				
				// Insert the new wordpress user into the database
				wp_update_user( $oid_user_data );
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
				$this->insert_identity( $identity_url );
				
				$this->action = 'redirect';
				
				if ( !$user->has_cap('edit_posts') ) $redirect_to = '/wp-admin/profile.php';
				
			} else {
				// failed to create user for some reason.
				$this->error = "OpenID authentication successful, but failed to create Wordpress user. This is probably a bug.";
				$this->action= 'error';
				error_log( $this->error );
			}

		}


		function get_user_data($identity_url, $sreg) {
			$oid_user_data = array( 'ID' => null,
				'user_url' => $identity_url,
				'user_nicename' => $identity_url,
				'display_name' => $identity_url );
		
			// create proper website URL if OpenID is an i-name
			if (preg_match('/^[\=\@\+].+$/', $identity_url)) {
				$oid_user_data['user_url'] = 'http://xri.net/' . $identity_url;
			}

			if ($sreg) {
				if( isset( $sreg['email'])) $oid_user_data['user_email'] = $sreg['email'];
				if( isset( $sreg['nickname'])) $oid_user_data['nickname'] = $oid_user_data['user_nicename'] = $oid_user_data['display_name'] =$sreg['nickname'];
				if( isset( $sreg['fullname'])) {
					$namechunks = explode( ' ', $sreg['fullname'], 2 );
					if( isset($namechunks[0]) ) $oid_user_data['first_name'] = $namechunks[0];
					if( isset($namechunks[1]) ) $oid_user_data['last_name'] = $namechunks[1];
					$oid_user_data['display_name'] = $sreg['fullname'];
				}
			} else {
				$comment = $this->get_comment();
				if( isset( $comment['comment_author_email'])) $oid_user_data['user_email'] = $comment['comment_author_email'];
				if( isset( $comment['comment_author'])) {
					$namechunks = explode( ' ', $comment['comment_author'], 2 );
					if( isset($namechunks[0]) ) $oid_user_data['first_name'] = $namechunks[0];
					if( isset($namechunks[1]) ) $oid_user_data['last_name'] = $namechunks[1];
					$oid_user_data['display_name'] = $comment['comment_author'];
				}
			}

			return $oid_user_data;
		}


		function post_comment(&$oid_user_data) {
			/* Transparent inline login and commenting.
			 * The comment text is in the session.
			 * Post it and redirect to the permalink.
			 */
			
			$comment = $this->get_comment();
			$comment_content = $comment['comment_content'];
			$this->clear_comment();
			
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
			
			/*
			if ( !$user->ID )
				die( __('Sorry, you must be logged in to post a comment.')
					.' If OpenID isn\'t working for you, try anonymous commenting.' );
			 */
			
			$comment_author       = $wpdb->escape($oid_user_data['display_name']);
			$comment_author_email = $wpdb->escape($oid_user_data['user_email']);
			$comment_author_url   = $wpdb->escape($oid_user_data['user_url']);
			$comment_type         = 'openid';
			$user_ID              = $oid_user_data['ID'];
			$this->flag_doing_openid_comment = true;

			$commentdata = compact('comment_post_ID', 'comment_author', 'comment_author_email',
										'comment_author_url', 'comment_content', 'comment_type', 'user_ID');

			//error_log(var_export($commentdata, true));
			//error_log(var_export($_SESSION, true));
			
			if ( !$user_id ) :
				setcookie('comment_author_' . COOKIEHASH, $comment['comment_author'], time() + 30000000, COOKIEPATH, COOKIE_DOMAIN);
				setcookie('comment_author_email_' . COOKIEHASH, $comment['comment_author_email'], time() + 30000000, COOKIEPATH, COOKIE_DOMAIN);
				setcookie('comment_author_url_' . COOKIEHASH, clean_url($comment['comment_author_url']), time() + 30000000, COOKIEPATH, COOKIE_DOMAIN);

				// save openid url in a separate cookie so wordpress doesn't muck with it when we read it back in later
				setcookie('comment_author_openid_' . COOKIEHASH, $comment['comment_author_openid'], time() + 30000000, COOKIEPATH, COOKIE_DOMAIN);
			endif;
				
			$comment_id = wp_new_comment( $commentdata );
		}



		/* These functions are used to store the comment
		 * temporarily while doing an OpenID redirect loop.
		 */
		function set_comment( $content ) {
			$_SESSION['oid_comment'] = $content;
		}

		function clear_comment( ) {
			unset($_SESSION['oid_comment']);
		}

		function get_comment( ) {
			return $_SESSION['oid_comment'];
		}

		/* Called when comment is submitted by get_option('require_name_email') */
		function bypass_option_require_name_email( $value ) {
			global $openid_auth_request;

			if (get_option('oid_enable_unobtrusive')) {
				if (!empty($_POST['url'])) {
					if ($this->late_bind()) { 
						// check if url is valid OpenID by forming an auth request
						set_error_handler( array($this, 'customer_error_handler'));
						$openid_auth_request = $this->getConsumer()->begin( $_POST['url'] );
						restore_error_handler();

						if (null !== $openid_auth_request) {
							return false;
						}
					}
				}
			} else {
				if( !empty( $_POST['openid_url'] ) ) {	// same criteria as the hijack in comment_tagging
					return false;
				}
			}

			return $value;
		}
		
		/*
		 * Called when comment is submitted via preprocess_comment hook.
		 * Set the comment_type to 'openid', so it can be drawn differently by theme.
		 * If comment is submitted along with an openid url, store comment, and do authentication.
		 *
		 * regarding comment_type: http://trac.wordpress.org/ticket/2659
		 */
		function comment_tagging( $comment ) {
			global $current_user;

			if (!$this->enabled) return $comment;
			
			if( get_usermeta($current_user->ID, 'registered_with_openid') ) {
				$comment['comment_type']='openid';
			}
			
			//error_log(var_dump($comment, true));
			$url_field = (get_option('oid_enable_unobtrusive') ? 'url' : 'openid_url');

			if( !empty( $_POST[$url_field] ) ) {  // Comment form's OpenID url is filled in.
				$comment['comment_author_openid'] = $_POST[$url_field];
				$this->set_comment($comment);
				$this->start_login( $_POST[$url_field], get_permalink( $comment['comment_post_ID'] ), 'commentopenid', $comment['comment_post_ID'] );
				
				// Failure to redirect at all, the URL is malformed or unreachable. Display the login form with the error.
				if (!get_option('oid_enable_unobtrusive')) {
					global $error;
					$error = $this->error;
					$_POST['openid_url'] = '';
					include( ABSPATH . 'wp-login.php' );
					exit();
				}
			}
			
			return $comment;
		}



		/* Hooks to clean up wp_notify_postauthor() emails
		 * Tries to call as few functions as required */
		/* These are necessary because our comment_type is 'openid', but wordpress is expecting 'comment' */
		function comment_notification_text( $notify_message_original, $comment_id ) {
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
		function comment_notification_subject( $subject, $comment_id ) {
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


		/**
		 * Wordpress only displays comments that are awaiting moderation if the 
		 * name and email address stored in the user's cookies match those on 
		 * the comment.  This is a problem since if the user logged in with 
		 * OpenID, they may never have filled out the name and email input 
		 * fields but their comment _will_ have values resulting in them never 
		 * seeing their comment.  
		 *
		 * This filter performs an additional query if the current user is 
		 * logged in, and grabs any comments awaiting moderation that they 
		 * posted.
		 *
		 * @bug-filed http://trac.wordpress.org/ticket/4108/
		 */
		function comments_awaiting_moderation(&$comments, $post_id) {
			global $wpdb, $user_ID;

			$commenter = wp_get_current_commenter();
			extract($commenter);

			$author_db = $wpdb->escape($comment_author);
			$email_db  = $wpdb->escape($comment_author_email);
			$url_db  = $wpdb->escape($comment_author_url);

			$additional = $wpdb->get_results("SELECT * FROM $wpdb->comments WHERE comment_post_ID = '$post_id' AND " .
				"(comment_author_url = '$url_db' OR (user_id != 0 AND user_id = '$user_ID')) AND comment_author != '$author_db' " .
				"AND comment_author_email != '$email_db' AND comment_approved = '0' ORDER BY comment_date");

			if ($additional) {
				$comments = array_merge($comments, $additional);
				usort($comments, create_function('$a,$b', 'return strcmp($a->comment_date_gmt, $b->comment_date_gmt);'));
			}

			return $comments;
		}


		/**
		 *
		 */
		function sanitize_comment_cookies() {
			if ( isset($_COOKIE['comment_author_openid_'.COOKIEHASH]) ) { 

				// this might be an i-name, so we don't want to run clean_url()
				remove_filter('pre_comment_author_url', 'clean_url');

				$comment_author_url = apply_filters('pre_comment_author_url', $_COOKIE['comment_author_openid_'.COOKIEHASH]);
				$comment_author_url = stripslashes($comment_author_url);
				$_COOKIE['comment_author_url_'.COOKIEHASH] = $comment_author_url;
			}
		}


	} // end class definition
} // end if-class-exists test

$wordpressOpenIDRegistration_Required_Files = array(
	'Auth/OpenID/Discover.php' => 'Do you have the <a href="http://www.openidenabled.com/openid/libraries/php/">JanRain PHP OpenID library</a> installed in your path?',
	'Auth/OpenID/DatabaseConnection.php' => 'Do you have the <a href="http://www.openidenabled.com/openid/libraries/php/">JanRain PHP OpenID library</a> installed in your path?',
	'Auth/OpenID/MySQLStore.php' => 'Do you have the <a href="http://www.openidenabled.com/openid/libraries/php/">JanRain PHP OpenID library</a> installed in your path?',
	'Auth/OpenID/Consumer.php' => 'Do you have the <a href="http://www.openidenabled.com/openid/libraries/php/">JanRain PHP OpenID library</a> installed in your path?',
	'Auth/OpenID/SReg.php' => 'Do you have the <a href="http://www.openidenabled.com/openid/libraries/php/">JanRain PHP OpenID library</a> installed in your path?',
	);


function wordpressOpenIDRegistration_Load_Required_Files( $wordpressOpenIDRegistration_Required_Files ) {
	/* Library may declare global variables. Some of these are required by other
	 * classes or functions in the library, and some are not. We're going to 
	 * permit only the required global variables to be created.
	 */
	global $__Auth_Yadis_defaultParser, $__Auth_Yadis_xml_extensions,
		$_Auth_Yadis_ns_map, $_Auth_OpenID_namespaces, $__UCSCHAR, $__IPRIVATE, $DEFAULT_PROXY,
		$XRI_AUTHORITIES, $_escapeme_re, $_xref_re, $__Auth_OpenID_PEAR_AVAILABLE,
		$_Auth_OpenID_math_extensions, $_Auth_OpenID_DEFAULT_MOD, $_Auth_OpenID_DEFAULT_GEN,
		$Auth_OpenID_OPENID_PROTOCOL_FIELDS, $Auth_OpenID_registered_aliases, $Auth_OpenID_SKEW,
		$Auth_OpenID_sreg_data_fields;
	$absorb = array( 'parts','pair','n','m', '___k','___v','___local_variables' );  // Unnessessary global variables absorbed
	$___local_variables = array_keys( get_defined_vars() );
	set_include_path( dirname(__FILE__) . PATH_SEPARATOR . get_include_path() );   // Add plugin directory to include path temporarily
	foreach( $wordpressOpenIDRegistration_Required_Files as $___k => $___v ) {
		//if( file_exists_in_path( $___k ) ) {
			if( @include_once( $___k ) ) {
				wordpressOpenIDRegistration_Status_Set('loading file: '.$___k, true, '');
				continue;
			}
		//}
		wordpressOpenIDRegistration_Status_Set('file:'.$___k, false, $___v );
		break;
	}
	restore_include_path();  // Leave no footprints behind

	$___local_variables = array_diff( array_keys( get_defined_vars() ), $___local_variables );
	foreach( $___local_variables as $___v ) if( !in_array( $___v, $absorb )) {
		wordpressOpenIDRegistration_Status_Set('unknown library variable: '.$___v, false, 'This library variable is unknown, left unset.' );
	}
}
?>
