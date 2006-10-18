<?php
/*
Plugin Name: OpenID Registration
Plugin URI: http://sourceforge.net/projects/wpopenid/
Description: Wordpress OpenID Registration, Authentication, and Commenting. Requires JanRain PHP OpenID library >1.1.1
Author: Alan J Castonguay, Hans Granqvist
Author URI: http://blog.verselogic.net/projects/wordpress/wordpress-openid-plugin/
Version: $Rev$
Licence: Modified BSD, http://www.fsf.org/licensing/licenses/index_html#ModifiedBSD
*/



/* Turn on logging of process via error_log() facility in PHP.
 * Used primarily for debugging, lots of output.
 * For production use, leave this set to false.
 */

define ( 'WORDPRESSOPENIDREGISTRATION_DEBUG', true );
define ( 'OPENIDIMAGE', get_bloginfo('url') . '/wp-content/plugins/wpopenid/images/openid.gif' );

/* Sessions are required by Services_Yadis_PHPSession, in Manager.php line 40 */
@session_start();


if  ( !class_exists('WordpressOpenIDRegistration') ) {
	class WordpressOpenIDRegistration {

		var $_store;	// Hold the WP_OpenIDStore and
		var $_consumer; // Auth_OpenID_Consumer internally.
		var $ui;	// Along with all the UI functions
		
		var $error;	// User friendly error message, defaults to ''.
		var $action;	// Internal action tag. '', 'error', 'redirect'.

		var $enabled = true;

		var $identity_url_table_name;
		var $flag_doing_openid_comment = false;

		/* 
		 * Initialize required store and consumer.
		 */		
		function WordpressOpenIDRegistration() {
			global $table_prefix,$wordpressOpenIDRegistrationErrors;
			$this->ui = new WordpressOpenIDRegistrationUI( $this );
			if( count( $wordpressOpenIDRegistrationErrors ) ) {
				if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log('OpenIDConsumer: Disabled. Check libraries.');
				$error = 'OpenID consumer is Disabled. Check libraries.';
				$this->enabled = false;
				return;
			}
			$this->_store = new WP_OpenIDStore();
			$this->_consumer = new Auth_OpenID_Consumer( $this->_store );
			$this->error = '';
			$this->action = '';
			$this->identity_url_table_name = ($table_prefix . 'openid_identities');
		}

		/*
		 * Create tables if needed by running dbDelta calls. Upgrade safe. Called on plugin activate.
		 */		
		function create_tables() {
			require_once(ABSPATH . '/wp-admin/upgrade-functions.php');
			$this->_store->dbDelta();
			$identity_url_table_sql = "CREATE TABLE $this->identity_url_table_name (
							uurl_id bigint(20) NOT NULL auto_increment,
							user_id bigint(20) NOT NULL default '0',
							meta_value longtext,
							PRIMARY KEY  (uurl_id),
							UNIQUE KEY uurl (meta_value(30)),
							KEY user_id (user_id) );";
			dbDelta($identity_url_table_sql);
		}
		
		/*
		 * Cleanup by dropping nonce, assoication, and settings tables. Called on plugin deactivate.
		 */
		function destroy_tables() {
			global $wpdb;
			$sql = 'drop table '. $this->_store->associations_table_name;
			$wpdb->query($sql);
			$sql = 'drop table '. $this->_store->nonces_table_name;
			$wpdb->query($sql);
			$sql = 'drop table '. $this->_store->settings_table_name;
			$wpdb->query($sql);			
		}			
		
		/*
		 * Customer error handler for calls into the JanRain library
		 */
		function customer_error_handler($errno, $errmsg, $filename, $linenum, $vars) {
			error_log( "Error $errno: $errmsg in $filename :$linenum");
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
			
			ob_start( array( $this->ui, 'openid_wp_login_ob' ) );
		}


		/* Start and finish the redirect loop, for the admin pages profile.php & users.php
		 */
		function admin_page_handler() {
			if( !isset( $_GET['page'] )) return;
			if( 'your-openid-identities' != plugin_basename( stripslashes($_GET['page']) ) ) return;

			if( !isset( $_REQUEST['action'] )) return;
			$action = $_REQUEST['action'];
			
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
			
			switch( $action ) {
				case 'add_identity':			// Verify identity, return with add_identity_ok
					$claimed_url = $_POST['openid_url'];
					
					if ( empty( $claimed_url ) ) return;
					if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log('OpenIDConsumer: Attempting bind for "' . $claimed_url . '"');

					set_error_handler( array($this, 'customer_error_handler'));
					$auth_request = $this->_consumer->begin( $claimed_url );
					restore_error_handler();
					
					global $userdata;
					if( $userdata->ID === $this->get_user_by_identity( $auth_request->endpoint->identity_url )) {
						$this->error = 'The specified url is already bound to this account, dummy';
						return;
					}
					if (!$auth_request) {
						$this->error = 'Expected an OpenID URL. Got: ' . htmlentities( $claimed_url );
						return;
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
						case Auth_OpenID_CANCEL:	$this->error = 'OpenID assertion cancelled.'; break;
						case Auth_OpenID_FAILURE:	$this->error = 'OpenID assertion failed: ' . $response->message; break;
						case Auth_OpenID_SUCCESS:	$this->error = 'OpenID assertion successful.';
							if( !$this->insert_identity( $response->identity_url ) ) {
								$this->error = 'OpenID assertion successful, but this URL is already claimed by another user on this blog. This is probably a bug.';
							}
							break;
						default:					$this->error = 'Unknown Status. Bind not successful. This is probably a bug.';
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
						$this->error = 'Identity url delete successful. <b>' . $deleted_identity_url . '</b> removed.';
						break;
					}
					
					$this->error = 'Identity url delete failed: Unknown reason.';
					break;
			}
		}


		/* Application-specific database operatiorns */
		function get_my_identities( $id = 0 ) {
			global $userdata;
			if( $id ) return $this->_store->connection->getOne( "SELECT meta_value FROM $this->identity_url_table_name WHERE user_id = %s AND uurl_id = %s",
					array( (int)$userdata->ID, (int)$id ) );
			return $this->_store->connection->getAll( "SELECT uurl_id,meta_value FROM $this->identity_url_table_name WHERE user_id = %s",
				array( (int)$userdata->ID ) );
		}

		function insert_identity($url) {
			global $userdata, $wpdb;
			$old_show_errors = $wpdb->show_errors;
			if( $old_show_errors ) $wpdb->hide_errors();
			$ret = @$this->_store->connection->query( "INSERT INTO $this->identity_url_table_name (user_id,meta_value) VALUES ( %s, %s )",
				array( (int)$userdata->ID, $url ) );
			if( $old_show_errors ) $wpdb->show_errors();
			return $ret;
		}
		
		function drop_identity($id) {
			global $userdata;
			return $this->_store->connection->query( "DELETE FROM $this->identity_url_table_name WHERE user_id = %s AND uurl_id = %s",
				array( (int)$userdata->ID, (int)$id ) );
		}
		
		function get_user_by_identity($url) {
			return $this->_store->connection->getOne( "SELECT user_id FROM $this->identity_url_table_name WHERE meta_value = %s",
				array( $url ) );
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

			if (!$auth_request) {
				$this->error = 'Could not find OpenID endpoint at specified url.';
				if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log('OpenIDConsumer: ' . $this->error );
				return;
			}

			if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log('OpenIDConsumer: Is an OpenID url. Starting redirect.');
			
			$return_to = get_bloginfo('url') . "/wp-login.php?action=$action";
			if( $wordpressid ) $return_to .= "&wordpressid=$wordpressid";
			if( !empty( $redirect_to ) ) $return_to .= '&redirect_to=' . urlencode( $redirect_to );
			
			// If we've never heard of this url before, add the SREG extension.
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
						ob_start( array( $this->ui, 'openid_wp_register_ob' ) );
						return;
					}
					if ( !isset( $_GET['openid_mode'] ) ) return;
					if( $_GET['action'] == 'loginopenid' ) break;
					if( $_GET['action'] == 'commentopenid' ) break;
					return;
					break;
					

				case 'wp-register.php':
					ob_start( array( $this->ui, 'openid_wp_register_ob' ) );
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
					// 1.1 If url is found, login.
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
					// 1.2. If url is not found, create a user with md5()'d password, permit=true
					@include_once( ABSPATH . 'wp-admin/upgrade-functions.php');	// 2.1
				 	@include_once( ABSPATH . WPINC . '/registration-functions.php'); // 2.0.4
				
					$username = sanitize_user ( $response->identity_url );
					$password = substr( md5( uniqid( microtime() ) ), 0, 7);
					
					$user_id = wp_create_user( $username, $password );
					if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log("wp_create_user( $username, $password )  returned $user_id ");
					
					if( $user_id ) {
						// created ok
						
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
						$this->error = "OpenID authentication OK, but failed to create Wordpress user. This is probably a bug.";
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
				
				if( $redirect_to == '/wp-admin' && !$user->has_cap('edit_posts') ) $redirect_to = '/wp-admin/profile.php';
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
			
			if( !empty( $_POST['openid_url'] ) ) {
				/* comment form's OpenID url is filled in.
				 * Strategy:
				 *  Store comment in a cookie
				 *  start_login() with action=commentopenid, redirect_to=postpermalink, wordpressid=postID
				 *  finish_login(), check for commentopenid, grab cookie, post comment, delete cookie, redirect to the post permalink
				 */
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
		 * Hook. Add sidebar login form, editing Register link.
		 * Turns SiteAdmin into Profile link in sidebar.
		 */

		function openid_wp_sidebar_register( $link ) {
			global $current_user;
			if( !$current_user->has_cap('edit_posts')  ) {
				$link = preg_replace( '#<a href="' . get_settings('siteurl') . '/wp-admin/">Site Admin</a>#', '<a href="' . get_settings('siteurl') . '/wp-admin/profile.php">' . __('Profile') . '</a>', $link );
			}
			if( $current_user->ID ) {
				$chunk ='<li>Logged in as '
					. ( get_usermeta($current_user->ID, 'registered_with_openid')
					? ('<img src="'.OPENIDIMAGE.'" height="16" width="16" alt="[oid]" />') : '' )
					. ( !empty($current_user->user_url)
					? ('<a href="' . $current_user->user_url . '">' . htmlentities( $current_user->display_name ) . '</a>')
					: htmlentities( $current_user->display_name )        ) . '</li>';
			
			} else {
				$style = get_option('oid_enable_selfstyle') ? ('style="border: 1px solid #ccc; background: url('.OPENIDIMAGE.') no-repeat;
					background-position: 0 50%; padding-left: 18px; " ') : '';
				$chunk ='<li><form method="post" action="wp-login.php" style="display:inline;">
					<input ' . $style . 'class="openid_url_sidebar" name="openid_url" size="17" />
					<input type="hidden" name="redirect_to" value="'. $_SERVER["REQUEST_URI"] .'" /></form></li>';
			}
			return $chunk . $link;
		}
		
		function openid_wp_sidebar_loginout( $link ) {
			return preg_replace( '#action=logout#', 'action=logout&redirect_to=' . urlencode($_SERVER["REQUEST_URI"]), $link );
		}
		
		/*
		 * Hook. Add OpenID login-n-comment box below the comment form.
		 */
		function openid_wp_comment_form( $id ) {
			global $current_user;
			if( ! $current_user->ID ) { // not logged in, draw a login form below the comment form
				$style = get_option('oid_enable_selfstyle') ? ('style="background: url('.OPENIDIMAGE.') no-repeat;
					background-position: 0 50%; padding-left: 18px;" ') : '';	
				?>
				<label for="openid_url_comment_form">Sign in with OpenID:</label><br/>	
				<input <?php echo $style; ?> type="textbox" name="openid_url" tabindex="6" id="openid_url_comment_form" size="30" />
				<?php
			}
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
				if( 'openid' == $comment['comment_type'] ) {
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
							$comment = get_comment( $comment_id );
							error_log("This comment is being made with " . $comment['comment_type']);
			if( $this->flag_doing_openid_comment ) {
				$comment = get_comment( $comment_id );
				if( 'openid' == $comment['comment_type'] && empty( $subject ) ) {
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


/* Load required libraries, throw nice errors on failure. */
$wordpressOpenIDRegistrationErrors = array(
	'Auth/OpenID/Consumer.php' => 'Do you have the <a href="http://www.openidenabled.com/openid/libraries/php/">JanRain PHP OpenID library</a> installed in your path?',
	'Auth/OpenID/DatabaseConnection.php' => 'Do you have the <a href="http://www.openidenabled.com/openid/libraries/php/">JanRain PHP OpenID library</a> installed in your path?',
	'Auth/OpenID/MySQLStore.php' => 'Do you have the <a href="http://www.openidenabled.com/openid/libraries/php/">JanRain PHP OpenID library</a> installed in your path?',
	//ABSPATH . 'wp-admin/upgrade-functions.php' => 'Built in to Wordpress. If we can\'t find this, there\'s something really wrong.<strong>.</strong>?',
	'wpdb-pear-wrapper.php' => 'Came with the plugin, but not found in include path. Does it include the current directory: <strong>.</strong>?',
	'user-interface.php' => 'Came with the plugin, but not found in include path. Does it include current directory: <strong>.</strong>?'
	);

foreach( $wordpressOpenIDRegistrationErrors as $k => $v ) {
	if( file_exists_in_path( $k ) ) {
		require_once( $k );
		unset( $wordpressOpenIDRegistrationErrors[ $k ] );
	} else { error_log( " ERROR: Could not load the file $k"); }
}
unset($m);  // otherwise JanRain's XRI.php will leave $m = 1048576
if( !extension_loaded( 'gmp' )) $wordpressOpenIDRegistrationErrors['GMP Support'] = '<a href="http://www.php.net/gmp">GMP</a> does not appear to be built into PHP. This is required.';

/* Instantiate main class */
$wordpressOpenIDRegistration = new WordpressOpenIDRegistration();

/* Add custom OpenID options */
add_option( 'oid_trust_root', '', 'The Open ID trust root' );
add_option( 'oid_enable_selfstyle', true, 'Use internal style rules' );
add_option( 'oid_enable_loginform', true, 'Display OpenID box in login form' );
add_option( 'oid_enable_commentform', true, 'Display OpenID box in comment form' );


/* Create and destroy tables on activate / deactivate of plugin. Everyone should clean up after themselves. */
register_activation_hook( 'wpopenid/openid-registration.php', array( $wordpressOpenIDRegistration, 'create_tables' ) );
register_deactivation_hook( 'wpopenid/openid-registration.php', array( $wordpressOpenIDRegistration, 'destroy_tables' ) );

/* If everthing's ok, add hooks for core logic */
if( $wordpressOpenIDRegistration->enabled ) {
	add_action( 'init', array( $wordpressOpenIDRegistration, 'finish_login' ) );
	add_action( 'init', array( $wordpressOpenIDRegistration, 'admin_page_handler' ) );

	if( get_option('oid_enable_loginform') )   add_action('wp_authenticate', array( $wordpressOpenIDRegistration, 'wp_authenticate' ) );
	if( get_option('oid_enable_commentform') ) add_filter( 'comment_form',   array( $wordpressOpenIDRegistration, 'openid_wp_comment_form' ) );

	add_action( 'preprocess_comment', array( $wordpressOpenIDRegistration, 'openid_wp_comment_tagging' ) );
	add_filter( 'register', array( $wordpressOpenIDRegistration, 'openid_wp_sidebar_register' ) );
	add_filter( 'loginout', array( $wordpressOpenIDRegistration, 'openid_wp_sidebar_loginout' ) );
	add_filter( 'option_require_name_email', array( $wordpressOpenIDRegistration, 'openid_bypass_option_require_name_email') );
	add_filter( 'comment_notification_subject', array( $wordpressOpenIDRegistration, 'openid_comment_notification_subject'), 10, 2 );
	add_filter( 'comment_notification_text', array( $wordpressOpenIDRegistration, 'openid_comment_notification_text'), 10, 2 );
}


?>