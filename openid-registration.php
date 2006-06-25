<?php
/*
Plugin Name: OpenID Registration
Plugin URI: http://wpopenid.sourceforge.net/
Description: Wordpress OpenID Registration, Authentication, and Commenting. Uses JanRain consumer library.
Author: Alan J Castonguay, Hans Granqvist, ...
Author URI: http://wpopenid.sourceforge.net
Version: 2006-06-25
*/


require_once "Auth/OpenID/Consumer.php";
require_once "Auth/OpenID/DatabaseConnection.php";
require_once 'wpdb-pear-wrapper.php';


@session_start();	// required by Services_Yadis_PHPSession:40

if  ( !class_exists('WordpressOpenIDRegistration') ) {
	class WordpressOpenIDRegistration {

		var $_store;	// Allocate the WP_OpenIDStore and JanRain Auth_OpenID_Consumer internally
		var $_consumer;
		
		var $error;	// User friendly error message, defaults to null.
		var $action;	// Internal action tag. '' or 'redirect'.

		/* 
		 * Initialize required store and consumer.
		 */		
		function WordpressOpenIDRegistration() {
			$this->_store = new WP_OpenIDStore();
			$this->_consumer = new Auth_OpenID_Consumer( $this->_store );
			$this->error = '';
			$this->action = '';
		}

		/* 
		 * openid_sql_tables_broken
		 * return FALSE if everything's ok
		 * return array of broken tables if there's a problem
		 */
/*		function openid_sql_tables_broken() {
			global $oid_store, $wpdb;
			$missing_tables = array();
			$tables = array(
				$oid_store->settings_table_name,
				$oid_store->associations_table_name,
				$oid_store->nonces_table_name );
			foreach( $tables as $table_name_raw ) {
				$table_name = $table_prefix . $table_name_raw;
				if($wpdb->get_var("show tables like '$table_name'") != $table_name) {
					$missing_tables[] = $table_name;
				}
			}
			if( empty( $missing_tables ) ) return false;
			return $missing_tables;
		}
 */
 
		/*
		 * Hook - called as wp_authenticate
		 * If we're doing openid authentication ($_POST['openid_url'] is set), start the consumer & redirect
		 * Otherwise, return and let Wordpress handle the login and/or draw the form.
		 * Uses output buffering to modify the form. See openid_wp_login_ob()
		 */
		function wp_authenticate( &$username, &$password ) {
			ob_start( array( $this, 'openid_wp_login_ob' ) );
			
			if( !empty( $_POST['openid_url'] ) ) {
				$redirect_to = '';
				if( !empty( $_REQUEST['redirect_to'] ) ) $redirect_to = $_REQUEST['redirect_to'];
				$this->start_login( $_POST['openid_url'], $redirect_to );
			}
			if( !empty( $this->error ) ) {
				global $error;
				$error = $this->error;
			}
		}


		
		/*  
		 * Prepare to start the redirect loop
		 * This function is mainly for assembling urls
		 * Called from wp_authenticate (for login form) and openid_wp_comment_tagging (for comment form)
		 * If using comment form, specify optional parameters action=commentopenid and wordpressid=PostID.
		 */
		function start_login( $claimed_url, $redirect_to, $action='loginopenid', $wordpressid=0 ) {
		
			if ( empty( $claimed_url ) ) return;
			
			$auth_request = $this->_consumer->begin( $claimed_url );
			if (!$auth_request) {
				$this->error = 'Expected an OpenID URL. Got:<br/>' . htmlentities( $claimed_url );
				return;
			}

			error_log('OpenIDConsumer: Attempting auth for "' . $claimed_url . '"');

			$return_to = "http://openid.verselogic.net/wp-login.php?action=$action";
			if( $wordpressid ) $return_to .= "&wordpressid=$wordpressid";
			if( !empty( $redirect_to ) ) $return_to .= '&redirect_to=' . urlencode( $redirect_to );
			
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
			global $_GET;
			if ( $_GET['action'] !== 'loginopenid' && $_GET['action'] !== 'commentopenid' ) return;
			if ( !isset( $_GET['openid_mode'] ) ) return;
			
			$response = $this->_consumer->complete( $_GET );

			switch( $response->status ) {
			case Auth_OpenID_CANCEL:
				$this->error = 'OpenID verification cancelled.';
				break;
			case Auth_OpenID_FAILURE:
				$this->error = 'OpenID authentication failed: ' . $response->message;
				break;
			case Auth_OpenID_SUCCESS:
				$this->error = 'OpenID success.';
				
				/*
				 * Strategy:
				 *   1. Search the wp_users list for the identity url
				 *   1.1. If url is found, check user_meta[permit_openid]
				 *   1.1.1. If true, set wordpress logged-in cookies
				 *   1.1.2. If false, offer to bind accout to identity url. Display username/password form
				 *   1.1.2.1. If credentials fail, redisplay form
				 *   1.1.2.2. If credentials OK, set permit=true
				 *   1.2. If url is not found, create a user with md5()'d password, permit=true
				 *   2. wp_redirect( $redirect_to )
				 */
				 
				// 1. Search the wp_users list for the identity url
				global $wpdb;
				
				$this->action = '';
				$redirect_to = 'wp-admin/';

				$matching_logins = $wpdb->get_results("SELECT ID, user_login, user_pass FROM $wpdb->users WHERE( user_url = '" . $wpdb->escape( $response->identity_url ) . "' )" );

				if( count( $matching_logins ) > 1 ) {
					$this->error = 'Multiple user accounts are associated with this url. Cannot proceed.';
					
				} elseif( count( $matching_logins ) == 1 ) {
					// 1.1 If url is found, check user_meta[permit_openid]
					$user = new WP_User( 0, $matching_logins[0]->user_login );
					
					if( get_usermeta( $user->ID, 'permit_openid_login' ) ) {
						if( wp_login( $user->user_login, md5($user->user_pass), true ) ) {
							error_log('OpenIDConsumer: Returning user logged in: ' .$user->ID); 
							do_action('wp_login', $user_login);
							wp_clearcookie();
							wp_setcookie($user->user_login, md5($user->user_pass), true, '', '', true);
							$this->action = 'redirect';
							if ( !$user->has_cap('edit_posts') ) $redirect_to = '/wp-admin/profile.php';

						} else {
							$this->error = 'Failed to login via OpenID, wp_login() returned false.';
							$this->action = 'error';
						}
					} else {
						/* 1.1.2. Offer to bind accout to identity url. Display username/password form.
						 * Accept form data in return somehow, and toggle 
						 */
						$this->error = 'A user has already claimed this URL. If this is your account,
							type your password to authorize its use with OpenID.';
						$this->action= 'bind';
						$page='<h2>Bind Account</h2>
							<p>The OpenID identity <strong>' .htmlentities( $response->identity_url ). '</strong>
								is currently claimed by a user. If you are ' . htmlentities( $user->user_login )
								. ', you can authorize use of this OpenID identity.</p>
							<p>Login with your Wordpress username/password, and check the
								<em>Use OpenID<em> box on your profile.</p>';
					}
					
				} else {
					// 1.2. If url is not found, create a user with md5()'d password, permit=true
					 	
					require_once( ABSPATH . WPINC . '/registration-functions.php');
				 	
					$username = sanitize_user ( $response->identity_url );
					$password = substr( md5( uniqid( microtime() ) ), 0, 7);
					$email    = '';
				 	
					$ID = create_user( $username, $password, $email );
					if( $ID ) {
						// created ok
						error_log('OpenIDConsumer: Created new user '.$ID.' : '.$username);
						
						update_usermeta( $ID, 'permit_openid_login', true );
						wp_update_user( array( 'ID' => $ID, 'user_url' => $response->identity_url ) );
						
						$user = new WP_User( $ID );
					
						wp_login( $user->user_login, md5($user->user_pass), true );
						do_action('wp_login', $user_login);
					
						wp_clearcookie();
						wp_setcookie( $username, $password, true, '', '', true );
					
						$this->action = 'redirect';
						if ( !$user->has_cap('edit_posts') ) $redirect_to = '/wp-admin/profile.php';

					} else {
						// failed to create user for some reason.
						$this->error = "OpenID authentication OK, but failed to create Wordpress user.";
					}
				}
				break;

			default:
				$this->error = 'OpenID authentication failed, unknown problem #' . $response->status;
				break;
			}
			
			error_log('OpenIDConsumer: Finish Auth for "' . $response->identity_url . '". ' . $this->error );
			

			// Possibly delay this while binding user account.
			if( $this->action == 'redirect' ) {
				if ( !empty( $_GET['redirect_to'] )) $redirect_to = $_GET['redirect_to'];
				
				if( $_GET['action'] == 'commentopenid' ) { // inline comment & auth
					// there's a cookie containing the comment text. post it and redirect to the permalink.
					
					$comment_content = $this->comment_get_cookie();
					if ( '' == trim($comment_content) )
						die( __('Error: please type a comment.') );
					
					error_log('OpenIDConsumer: action=commentopenid  redirect_to=' . $redirect_to);
					error_log('OpenIDConsumer: comment_content = ' . $comment_content);
					
					nocache_headers();
					
					// duplicate purpose of wp-comments-post.php
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
							." If OpenID isn't working for you, try anonymous commenting." );
					
					$comment_author       = $wpdb->escape($user->display_name);
					$comment_author_email = $wpdb->escape($user->user_email);
					$comment_author_url   = $wpdb->escape($user->user_url);
					$comment_type = 'openid';
					$user_ID = $user->ID;
					
					$commentdata = compact('comment_post_ID', 'comment_author', 'comment_author_email',
						'comment_author_url', 'comment_content', 'comment_type', 'user_ID');

					$comment_id = wp_new_comment( $commentdata );
				}
				
				wp_redirect( $_GET['redirect_to'] );
			}

			global $action;
			$action=$this->action; 

		}

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
			if( !empty($_COOKIE) ) {
				if ( !empty($_COOKIE[ 'comment_content_' . COOKIEHASH ] ) ) {
					return trim( $_COOKIE[ 'comment_content_' . COOKIEHASH ] );
				}
			}
			return false;
		}


		/*
		 * Called when comment is submitted via preprocess_comment hook.
		 * Set the comment_type to 'openid', so it can be drawn differently by theme.
		 * If comment is submitted along with an openid url, store comment in cookie, and do authentication.
		 */

		function openid_wp_comment_tagging( $comment ) {
			global $current_user;		
			
			if( $current_user->permit_openid_login ) {
				$comment['comment_type']='openid';
			}
			
			if( !empty( $_POST['openid_url'] ) ) {
				/* comment form's OpenID url is filled in.
				 * Strategy:
				 *  Store comment in a cookie
				 *  start_login() with action=commentopenid, redirect_to=postpermalink, wordpressid=postID
				 *  finish_login(), check for commentopenid, grab cookie, post comment, delete cookie, redirect to the post permalink
				 */
				$this->comment_set_cookie( $comment['comment_content'] );
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
		 * Only UI below this line
		 */

		/*  Output Buffer handler
		 *  @param $form - String of html
		 *  @return - String of html
		 *  Replaces parts of the wp-login.php form.
		 */
		function openid_wp_login_ob( $form ) {
			global $redirect_to, $action;

			switch( $action ) {
			case 'bind':
				$page = $this->page;
				
				$form = preg_replace( '#<form.*</form>#s', $page, $form, 1 );	// strip the whole form
				break;

			default:	
				$style = get_option('oid_enable_selfstyle') ? 'style="background: #f4f4f4 url(http://blog.verselogic.net/wp-content/themes/plains-in-the-dreaming/images/openid.gif) no-repeat;
					background-position: 0 50%; padding-left: 18px;" ' : '';
					

		/*$newform = '<form name="loginformopenid" id="loginformopenid" action="wp-login.php" method="post">
			<p><label>'.__('OpenID Url:').'<br/><input ' . $style . 'type="text" class="openid_url" name="openid_url" id="log" size="20" tabindex="5" /></label></p>
			<p class="submit">
				<input type="submit" name="submit" id="submit" value="'. __('Login') . ' &raquo;" tabindex="6" />
				<input type="hidden" name="rememberme" value="forever" />
				<input type="hidden" name="redirect_to" value="' . $redirect_to . '" />
			</p></form>'; */

				$newform = '<p><label>'.__('OpenID Url:').'<br/><input ' . $style . 'type="text" class="openid_url" name="openid_url" id="log" size="20" tabindex="5" /></label></p>';
				$form = preg_replace( '#<p class="submit">#', $newform . '<p class="submit">' , $form, 1 );
			}
			return $form;
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
			if( $current_user->display_name ) {
				$chunk ='<li>Logged in as ' . ( isset($current_user->user_url) ?
					('<a href="' . $current_user->display_name . '">' . htmlentities( $current_user->display_name ) . '</a>')
					: htmlentities( $current_user->display_name )        ) . '</li>';
			
			} else {
				$style = get_option('oid_enable_selfstyle') ? 'style="background: url(http://blog.verselogic.net/wp-content/themes/plains-in-the-dreaming/images/openid.gif) no-repeat;
					background-position: 0 50%; padding-left: 18px; " ' : '';
				$chunk ='<li><form method="post" action="wp-login.php" style="display:inline;">
					<input ' . $style . 'class="openid_url_sidebar" name="openid_url" size="17" />
					<input type="hidden" name="redirect_to" value="'
					. $_SERVER["REQUEST_URI"] . '" /></form></li>';
			}
			return $link . $chunk;
		}
		
		/*
		 * Hook. Add OpenID login-n-comment box below the comment form.
		 */
		function openid_wp_comment_form( $id ) {
			global $current_user;
			if( ! $current_user->id ) { // not logged in, draw a login form below the comment form
				$style = get_option('oid_enable_selfstyle') ? 'style="background: url(http://blog.verselogic.net/wp-content/themes/plains-in-the-dreaming/images/openid.gif) no-repeat;
					background-position: 0 50%; padding-left: 18px;" ' : '';	
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
			return !preg_match( '#^http\\:\\/\\/[a-z0-9\-]+\.([a-z0-9\-]+\.)?\\/[a-z]+#i',$url );
		}
		
		/*
		 * Hook to display the options page in the Admin screen
		 */
		function oid_add_pages() {
			add_options_page('Open ID options', 'Open ID', 8, __FILE__,
				array( $this, 'oid_options_page')  );
		}
		
		/*
		 * Display and handle updates from the Admin screen options page.
		 */
		function oid_options_page() {
		
			// if we're posted back an update, let's set the values here
			if ( isset($_POST['info_update']) ) {
			
				$trust = $_POST['oid_trust_root'];
				if($trust == null ) $trust = get_settings('siteurl');
	
				$error = '';
				if( $this->openid_is_url($trust) ) {
					update_option('oid_trust_root', $trust);
				} else {
					$error .= "<p/>".$trust." is not a url!";
				}
				
				update_option( 'oid_enable_selfstyle', isset($_POST['enable_selfstyle']) ? true : false );
				update_option( 'oid_enable_loginform', isset($_POST['enable_loginform']) ? true : false );
				update_option( 'oid_enable_commentform', isset($_POST['enable_commentform']) ? true : false );
				
				if ($error != '') {
					echo '<div class="updated"><p><strong>At least one of Open ID options was NOT updated</strong>'.$error.'</p></div>';
				} else {
					echo '<div class="updated"><p><strong>Open ID options updated</strong></p></div>';
				}
				
			}
			
			// Display the options page form
			$siteurl = get_settings('siteurl');
			if( substr( $siteurl, -1, 1 ) !== '/' ) $siteurl .= '/';
			?>
			<form method="post"><div class="wrap">
				<h2>OpenID Registration Options</h2>
     				<fieldset class="options">
     					<p><em>Please refer to <a href="http://verisign.com">http://[TBD]</a> 
     					specification for more information.</em></p>
     					
     					<table class="editform" cellspacing="2" cellpadding="5" width="100%">
     					<tr valign="top"><th style="width: 10em; padding-top: 1.5em;">
     						<label for="oid_trust_root">Trust root:</label>
     					</th><td>
     						<p><input type="text" size="80" name="oid_trust_root" id="oid_trust_root"
     						value="<?php echo htmlentities(get_option('oid_trust_root')); ?>" /></p>
     						<p>Commenters will be asked whether they trust this url,
     						and its decendents, to know that they are logged in and control their identity url.
     						Include the trailing slash.
     						This should probably be <strong><?php echo $siteurl; ?></strong></p>
     					</td></tr>
     					
     					<tr><th>
     						<label for="enable_loginform">Login Form:</label>
     					</th><td>
     						<p><input type="checkbox" name="enable_loginform" id="enable_loginform" <?php
     						if( get_option('oid_enable_loginform') ) echo 'checked="checked"'
     						?> />
     						<label for="enable_loginform">Add OpenID url box to the WordPress
     						<a href="<?php bloginfo('wpurl'); ?>/wp-login.php"><?php _e('Login') ?></a>
     						form.</p>
     					</td></tr>

     					<tr><th>
     						<label for="enable_commentform">Comment Form:</label>
     					</th><td>
     						<p><input type="checkbox" name="enable_commentform" id="enable_commentform" <?php
     						if( get_option('oid_enable_commentform') ) echo 'checked="checked"'
     						?> />
     						<label for="enable_commentform">Add OpenID url box to the WordPress
     						post comment form.</p>
     					</td></tr>
     					
     					<tr><th>
     						<label for="enable_selfstyle">Internal Style:</label>
     					</th><td>
     						<p><input type="checkbox" name="enable_selfstyle" id="enable_selfstyle" <?php
     						if( get_option('oid_enable_selfstyle') ) echo 'checked="checked"'
     						?> />
     						<label for="enable_selfstyle">Use Internal Style Rules</label></p>
     						<p>These rules affect the visual appearance of various OpenID login boxes,
     						such as those in the wp-login page, the comments area, and the sidebar.
     						The included styles are tested to work with the default themes.
     						For custom themeing, turn this off and apply your own styles to the form elements.</p>
     					</td></tr>

     					</table>
     				</fieldset>
     				<input type="submit" name="info_update" value="<?php _e('Update options') ?> »" />
     			</div></form>
     			<?php
 		} // end function oid_options_page



	} // end class definition
} // end if-class-exists test

$wordpressOpenIDRegistration = new WordpressOpenIDRegistration();  // Instantiate main class.



/*
 * Exposed functions, designed for use in templates.
 * Specifically inside `foreach ($comments as $comment)` in comments.php
 */



/*  get_comment_openid()
 *  If the current comment was submitted with OpenID, output an <img> tag with the OpenID logo
 */
if( !function_exists( 'get_comment_openid' ) ) {
	function get_comment_openid() {
		if( get_comment_type() == 'openid' ) echo '<img src="/openid.gif" height="16" width="16" alt="OpenID" />';
	}
}

/* is_openid_comment()
 * If the current comment was submitted with OpenID, return true
 * useful for  <?php echo ( is_openid_comment() ? 'Submitted with OpenID' : '' ); ?>
 */
if( !function_exists( 'is_openid_comment' ) ) {
	function is_openid_comment() {
		return ( get_comment_type() == 'openid' );
	}
}








add_option( 'oid_trust_root', '', 'The Open ID trust root' );
add_option( 'oid_enable_selfstyle', true, 'Use internal style rules' );
add_option( 'oid_enable_loginform', true, 'Display OpenID box in login form' );
add_option( 'oid_enable_commentform', true, 'Display OpenID box in comment form' );

add_action( 'init',       array( $wordpressOpenIDRegistration, 'finish_login'  ) );  // finish an auth cycle
add_action( 'admin_menu', array( $wordpressOpenIDRegistration, 'oid_add_pages' ) );  // about to display the admin screen

if( get_option('oid_enable_loginform') )   add_action('wp_authenticate', array( $wordpressOpenIDRegistration, 'wp_authenticate' ) );  // start an auth cycle
if( get_option('oid_enable_commentform') ) add_filter( 'comment_form',   array( $wordpressOpenIDRegistration, 'openid_wp_comment_form' ) );

add_action( 'preprocess_comment', array( $wordpressOpenIDRegistration, 'openid_wp_comment_tagging' ) );
add_filter( 'register', array( $wordpressOpenIDRegistration, 'openid_wp_sidebar_register' ) );




/*
function openid_wp_user_profile($a) {
	echo "FOO";
	print_r($a);
}
add_action( 'edit_user_profile', 'openid_wp_user_profile' );
add_action( 'profile_personal_options', 'openid_wp_user_profile' );
*/


?>