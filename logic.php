<?php
/**
 * logic.php
 *
 * Dual License: GPL & Modified BSD
 */
if  ( !class_exists('WordpressOpenIDLogic') ) {
	class WordpressOpenIDLogic {

		var $core;
		var $_store;	  // WP_OpenIDStore
		var $_consumer;   // Auth_OpenID_Consumer
		
		var $error;		  // User friendly error message, defaults to ''.
		var $action;	  // Internal action tag. '', 'error', 'redirect'.

		var $enabled = true;

		var $identity_url_table_name;
		var $flag_doing_openid_comment = false;

		var $bind_done = false;

		/**
		 * Constructor.
		 */
		function WordpressOpenIDLogic($core) {
			$this->core =& $core;
		}


		/* Soft verification of plugin activation OK */
		function uptodate() {
			$this->core->log->debug('checking if database is up to date');
			if( get_option('oid_db_version') != WPOPENID_DB_VERSION ) {  
				// Database version mismatch, force dbDelta() in admin interface.
				$this->enabled = false;
				$this->core->setStatus('Plugin Database Version', false, 'Plugin database is out of date. ' 
					. get_option('oid_db_version') . ' != ' . WPOPENID_DB_VERSION );
				update_option('oid_plugin_enabled', false);
				return false;
			}
			$this->enabled = (get_option('oid_plugin_enabled') == true );
			return $this->enabled;
		}
		
		/**
		 * Get the internal SQL Store.  If it is not already initialized, do so.
		 */
		function getStore() {
			if (!isset($this->_store)) {
				require_once 'wpdb-pear-wrapper.php';

				$this->_store = new WP_OpenIDStore();
				if (null === $this->_store) {

					$this->core->setStatus('object: OpenID Store', false, 
						'OpenID store could not be created properly.');

					$this->enabled = false;
				} else {
					$this->core->setStatus('object: OpenID Store', true, 'OpenID store created properly.');
				}
			}

			return $this->_store;
		}

		/**
		 * Get the internal OpenID Consumer object.  If it is not already initialized, do so.
		 */
		function getConsumer() {
			if (!isset($this->_consumer)) {
				require_once 'Auth/OpenID/Consumer.php';

				$store = $this->getStore();
				$this->_consumer = new Auth_OpenID_Consumer($store);
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
			require_once('Auth/OpenID/Discover.php');
			require_once('Auth/OpenID/DatabaseConnection.php');
			require_once('Auth/OpenID/MySQLStore.php');
			require_once('Auth/OpenID/Consumer.php');
			require_once('Auth/OpenID/SReg.php');
			restore_include_path();

			global $wpdb;
			$this->core->setStatus('database: Wordpress\' table prefix', 'info', $wpdb->prefix );
			$this->identity_url_table_name = ($wpdb->prefix . 'openid_identities');

			if( false === get_option('oid_trust_root') or '' === get_option('oid_trust_root') ) {
				$this->core->setStatus('Option: Trust Root', 'info', 'You must specify the Trust Root '
					. 'paramater on the OpenID Options page. Commenters will be asked whether they trust '
					. 'this url, and its decedents, to know that they are logged in and control their '
					. 'identity url. Include the trailing slash.');
			}
			
			$this->core->log->debug("Bootstrap -- checking tables");
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
			global $wp_version;
			$this->late_bind();
			$store =& $this->getStore();

			if( false == $this->enabled ) {  // do nothing if something bad happened
				$this->error = 'OpenID Consumer could not be activated, something bad happened. Skipping '
					. 'table create. Check libraries.';
				$this->core->log->debug($this->error);
				echo $this->error;
				return false;
			}
			if( null == $store ) {
				$this->error = 'OpenID Consumer could not be activated, because the store could not be '
					. 'created properly. Are the database files in place?';
				$this->core->log->debug($this->error);
				echo $this->error;
				return false;				
			}

			if ($wp_version >= '2.3') {
				require_once(ABSPATH . '/wp-admin/includes/upgrade.php');
			} else {
				require_once(ABSPATH . 'wp-admin/admin-db.php');
				require_once(ABSPATH . '/wp-admin/upgrade-functions.php');
			}

			$store->dbDelta();
			
			// Table for storing UserID <--> URL associations.
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
		 * Cleanup by dropping nonce and association tables. Called on plugin deactivate.
		 */
		function destroy_tables() {
			global $wpdb;
			$this->late_bind();
			if( $this->getStore() == null) {
				$this->error = 'OpenIDConsumer: Disabled. Cannot locate libraries, therefore cannot clean '
					. 'up database tables. Fix the libraries, or drop the tables yourself.';
				$this->core->log->notice($this->error);
				return;
			}
			$this->core->log->debug('Dropping all database tables.');
			$store =& $this->getStore();
			$sql = 'drop table '. $store->associations_table_name;
			$wpdb->query($sql);
			$sql = 'drop table '. $store->nonces_table_name;
			$wpdb->query($sql);
		}
		

		/*
		 * Check to see whether the none, association, and settings tables exist.
		 */
		function check_tables($retry=true) {
			$this->late_bind();
			$store =& $this->getStore();
			if( null === $store ) return false; // Can't check tables if the store object isn't created

			global $wpdb;
			$ok = true;
			$message = '';
			$tables = array( $store->associations_table_name, $store->nonces_table_name,
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
				$this->core->setStatus( 'database tables', false, 'Tables not created properly. Trying to '
					. 'create..' );
				$this->create_tables();
				$ok = $this->check_tables( false );
			} else {
				$this->core->setStatus( 'database tables', $ok?'info':false, $message );
			}
			return $ok;
		}


		/*
		 * Customer error handler for calls into the JanRain library
		 */
		function customer_error_handler($errno, $errmsg, $filename, $linenum, $vars) {
			if( (2048 & $errno) == 2048 ) return;
			$this->core->log->notice( "Library Error $errno: $errmsg in $filename :$linenum");
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


		/**
		 * Start and finish the redirect loop, for the admin pages profile.php & users.php
		 **/
		function admin_page_handler() {
			global $wp_version;

			if( !isset( $_GET['page'] )) return;
			if( 'your-openid-identities' != plugin_basename( stripslashes($_GET['page']) ) ) return;

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
			
			// Construct self-referential url for redirects.
			if ( current_user_can('edit_users') ) $parent_file = 'users.php';
			else $parent_file = 'profile.php';
			$self = get_option('siteurl') . '/wp-admin/' . $parent_file . '?page=your-openid-identities';
			
			switch( $this->action ) {
				case 'add_identity':			// Verify identity, return with add_identity_ok
					$claimed_url = $_POST['openid_url'];
					
					if ( empty( $claimed_url ) ) return;
					$this->core->log->debug('OpenIDConsumer: Attempting bind for "' . $claimed_url . '"');

					set_error_handler( array($this, 'customer_error_handler'));
					$consumer = $this->getConsumer();
					$auth_request = $consumer->begin( $claimed_url );
					restore_error_handler();

					// TODO: Better error handling.
					if ( null === $auth_request ) {
						$this->error = 'Could not discover an OpenID identity server endpoint at the url: '
							. htmlentities( $claimed_url );
						if( strpos( $claimed_url, '@' ) ) {
							// Special case a failed url with an @ sign in it.
							// Users entering email addresses are probably chewing soggy crayons.
							$this->error .= '<br/>The address you specified had an @ sign in it, but '
								. 'OpenID Identities are not email addresses, and should probably not '
								. 'contain an @ sign.';
						}
						break;
					}

					global $userdata;
					if($userdata->ID === $this->get_user_by_identity($auth_request->endpoint->identity_url)) {
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
						$this->error = 'OpenID assertion successful, but this URL is already claimed by '
							. 'another user on this blog. This is probably a bug';
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
						$this->error = 'Identity url delete successful. <b>' . $deleted_identity_url 
							. '</b> removed';
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
					$this->core->log->error('Could not redirect to server: '.$redirect_url->message);
				} else {
					wp_redirect( $redirect_url );
				}
			} else {
				// Generate form markup and render it
				$form_id = 'openid_message';
				$form_html = $auth_request->formMarkup($trust_root, $return_to, false, array('id'=>$form_id));

				if (Auth_OpenID::isFailure($form_html)) {
					$this->core->log->error('Could not redirect to server: '.$form_html->message);
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
			$consumer = $this->getConsumer();
			$response = $consumer->complete();
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
					$this->core->log->notice('Got back identity URL ' . $esc_identity);

					if ($response->endpoint->canonicalID) {
						$this->core->log->notice('XRI CanonicalID: ' . $response->endpoint->canonicalID);
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
			$store =& $this->getStore();
			global $userdata;
			if( !$this->enabled ) return array();
			if( $id ) {
				return $store->connection->getOne( 
					"SELECT url FROM $this->identity_url_table_name WHERE user_id = %s AND uurl_id = %s",
					array( (int)$userdata->ID, (int)$id ) );
			} else {

				return $store->connection->getAll( 
					"SELECT uurl_id,url FROM $this->identity_url_table_name WHERE user_id = %s",
					array( (int)$userdata->ID ) );
			}
		}

		function insert_identity($url) {
			global $userdata, $wpdb;
			$this->late_bind();
			$store =& $this->getStore();

			if( !$this->enabled ) return false;
			$old_show_errors = $wpdb->show_errors;
			if( $old_show_errors ) $wpdb->hide_errors();
			$ret = @$store->connection->query( 
				"INSERT INTO $this->identity_url_table_name (user_id,url,hash) VALUES ( %s, %s, MD5(%s) )",
				array( (int)$userdata->ID, $url, $url ) );
			if( $old_show_errors ) $wpdb->show_errors();

			if (get_option('oid_enable_foaf')) {
				if($foaf = $this->fetch_foaf_profile($url)) 
					update_usermeta((int)$userdata->ID, 'foaf', $foaf);

				if($sioc = $this->fetch_sioc_profile($url)) 
					update_usermeta((int)$userdata->ID, 'sioc', $sioc);
			}

			return $ret;
		}

		function fetch_foaf_profile($url) {
			return $this->fetch_auto_discovery($url, 'foaf');
		}

		function fetch_sioc_profile($url) {
			return $this->fetch_auto_discovery($url, 'sioc');
		}
		 
		/*
		 * FOAF and SIOC auto-discovery thanks to Alexandre Passant
		 * (http://apassant.net/blog/2007/09/23/retrieving-foaf-profile-from-openid/)
		 */
		function fetch_auto_discovery($url, $type) {	
			$profile = null;
			$html = file_get_contents($url);
			preg_match_all('/<head.*<link.*rel="meta".*title="'.$type.'".*href="(.*)".*\/>.*<\/head>/Usi', 
				$html, $links);

			if($links) {
				if($link = $links[1][0]) {
					$ex = parse_url($link);
					if ($ex['scheme']) {
						$profile = $link;
					}
					elseif (substr($ex['path'], 0, 1) == '/') {
						$ex = parse_url($url);
						$profile = $ex['scheme'].'://'.$ex['host'].$link;
					}
					else {
						$profile =  $url.$link;
					}
				}
			}

			if ($profile) {
				$this->core->log->debug("found $type profile: $profile");
			}

			return $profile;
		}

		
		function drop_all_identities_for_user($userid) {
			$this->late_bind();
			$store =& $this->getStore();

			if( !$this->enabled ) return false;
			return $store->connection->query( 
				"DELETE FROM $this->identity_url_table_name WHERE user_id = %s", 
				array( (int)$userid ) );
		}
		
		function drop_identity($id) {
			global $userdata;
			$this->late_bind();
			$store =& $this->getStore();

			if( !$this->enabled ) return false;
			return $store->connection->query( 
				"DELETE FROM $this->identity_url_table_name WHERE user_id = %s AND uurl_id = %s",
				array( (int)$userdata->ID, (int)$id ) );
		}
		
		function get_user_by_identity($url) {
			$this->late_bind();
			$store =& $this->getStore();

			if( !$this->enabled ) return false;
			return $store->connection->getOne( 
				"SELECT user_id FROM $this->identity_url_table_name WHERE url = %s",
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
						$this->core->log->debug('OpenIDConsumer: Returning user logged in: '
							.$user->user_login); 
						do_action('wp_login', $user_login);
						wp_clearcookie();
						wp_setcookie($user->user_login, md5($user->user_pass), true, '', '', true);
						$this->action = 'redirect';
						if ( !$user->has_cap('edit_posts') ) $redirect_to = '/wp-admin/profile.php';

					} else {
						$this->error = 'OpenID authentication valid, but Wordpress login failed. '
							. 'OpenID login disabled for this account.';
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
			

			$this->core->log->debug('OpenIDConsumer: Finish Auth for "' . $response->identity_url . 
				'". ' . $this->error );
			
			if( $this->action == 'redirect' ) {
				if ( !empty( $_GET['redirect_to'] )) $redirect_to = $_GET['redirect_to'];
				
				if( $_GET['action'] == 'commentopenid' ) {
					$this->post_comment($oid_user_data);
				}

				if( $redirect_to == '/wp-admin' and !$user->has_cap('edit_posts') ) 
					$redirect_to = '/wp-admin/profile.php';

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
			$this->core->log->debug("wp_create_user( $username, $password )  returned $user_id ");

			if( $user_id ) {	// created ok

				$oid_user_data['ID'] = $user_id;
				update_usermeta( $user_id, 'registered_with_openid', true );

				$this->core->log->debug("OpenIDConsumer: Created new user $user_id : $username and metadata: "
					. var_export( $oid_user_data, true ) );
				
				// Insert the new wordpress user into the database
				wp_update_user( $oid_user_data );
				$user = new WP_User( $user_id );

				if( ! wp_login( $user->user_login, md5($user->user_pass), true ) ) {
					$this->error = 'User was created fine, but wp_login() for the new user failed. '
						. 'This is probably a bug.';
					$this->action= 'error';
					$this->core->log->error( $this->error );
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
				$this->error = 'OpenID authentication successful, but failed to create Wordpress user. '
					. 'This is probably a bug.';
				$this->action= 'error';
				$this->core->log->error( $this->error );
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
				if( isset( $sreg['nickname'])) {
					$oid_user_data['nickname'] = $sreg['nickname'];
					$oid_user_data['user_nicename'] = $sreg['nickname'];
					$oid_user_data['display_name'] = $sreg['nickname'];
				}
				if( isset($sreg['fullname']) ) {
					$namechunks = explode( ' ', $sreg['fullname'], 2 );
					if( isset($namechunks[0]) ) $oid_user_data['first_name'] = $namechunks[0];
					if( isset($namechunks[1]) ) $oid_user_data['last_name'] = $namechunks[1];
					$oid_user_data['display_name'] = $sreg['fullname'];
				}
			} else {
				$comment = $this->get_comment();
				if( isset( $comment['comment_author_email'])) 
					$oid_user_data['user_email'] = $comment['comment_author_email'];
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
			
			$this->core->log->debug('OpenIDConsumer: action=commentopenid  redirect_to=' . $redirect_to);
			$this->core->log->debug('OpenIDConsumer: comment_content = ' . $comment_content);
			
			nocache_headers();
			
			// Do essentially the same thing as wp-comments-post.php
			global $wpdb;
			$comment_post_ID = (int) $_GET['wordpressid'];
			$status = $wpdb->get_row("SELECT post_status, comment_status FROM $wpdb->posts "
				. "WHERE ID = '$comment_post_ID'");
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

			if ( !$user_id ) {
				setcookie('comment_author_' . COOKIEHASH, $comment['comment_author'], 
					time() + 30000000, COOKIEPATH, COOKIE_DOMAIN);
				setcookie('comment_author_email_' . COOKIEHASH, $comment['comment_author_email'], 
					time() + 30000000, COOKIEPATH, COOKIE_DOMAIN);
				setcookie('comment_author_url_' . COOKIEHASH, clean_url($comment['comment_author_url']), 
					time() + 30000000, COOKIEPATH, COOKIE_DOMAIN);

				// save openid url in a separate cookie so wordpress doesn't muck with it when we 
				// read it back in later
				setcookie('comment_author_openid_' . COOKIEHASH, $comment['comment_author_openid'], 
					time() + 30000000, COOKIEPATH, COOKIE_DOMAIN);
			}	

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
						$consumer = $this->getConsumer();
						$openid_auth_request = $consumer->begin( $_POST['url'] );
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
			
			$url_field = (get_option('oid_enable_unobtrusive') ? 'url' : 'openid_url');

			if( !empty( $_POST[$url_field] ) ) {  // Comment form's OpenID url is filled in.
				$comment['comment_author_openid'] = $_POST[$url_field];
				$this->set_comment($comment);
				$this->start_login( $_POST[$url_field], get_permalink( $comment['comment_post_ID'] ), 
					'commentopenid', $comment['comment_post_ID'] );
				
				// Failure to redirect at all, the URL is malformed or unreachable. 
				// Display the login form with the error.
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

			$additional = $wpdb->get_results("SELECT * FROM $wpdb->comments WHERE "
				. "comment_post_ID = '$post_id' AND (comment_author_url = '$url_db' OR "
				. "(user_id != 0 AND user_id = '$user_ID')) AND comment_author != '$author_db' "
				. "AND comment_author_email != '$email_db' AND comment_approved = '0' "
				. "ORDER BY comment_date");

			if ($additional) {
				$comments = array_merge($comments, $additional);
				usort($comments, create_function('$a,$b', 
					'return strcmp($a->comment_date_gmt, $b->comment_date_gmt);'));
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

				$comment_author_url = apply_filters('pre_comment_author_url', 
					$_COOKIE['comment_author_openid_'.COOKIEHASH]);
				$comment_author_url = stripslashes($comment_author_url);
				$_COOKIE['comment_author_url_'.COOKIEHASH] = $comment_author_url;
			}
		}


	} // end class definition
} // end if-class-exists test

?>
