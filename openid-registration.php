<?php
/*
Plugin Name: OpenID Registration
Plugin URI: http://wpopenid.sourceforge.net/
Description: Wordpress OpenID Registration and Authentication. Uses JanRain consumer library.
Author: Alan J Castonguay, Hans Granqvist, ...
Author URI: http://wpopenid.sourceforge.net
Version: 2006-06-19
*/


require_once "Auth/OpenID/Consumer.php";
require_once "Auth/OpenID/DatabaseConnection.php";

require_once 'wpdb-pear-wrapper.php';

@session_start();	// required by Services_Yadis_PHPSession:40

if  ( !class_exists('WordpressOpenIDRegistration') ) {
	class WordpressOpenIDRegistration {
	
		var $_store;
		var $_consumer;
		
		var $error;
		var $action;
		

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
		function openid_sql_tables_broken() {
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

		/*  Hook - called as wp_authenticate
		 *
		 */
		function wp_authenticate( &$username, &$password ) {
			ob_start("openid_wp_login_ob");
			
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
		 *		
		 */
		function start_login( $claimed_url, $redirect_to ) {
		
			if ( empty( $claimed_url ) ) return;
			
			$auth_request = $this->_consumer->begin( $claimed_url );
			if (!$auth_request) {
				$this->error = 'Expected an OpenID URL. Got:<br/>' . htmlentities( $claimed_url );
				return;
			}

			error_log('OpenIDConsumer: Attempting auth for "' . $claimed_url . '"');

			$return_to = 'http://openid.verselogic.net/wp-login.php?action=loginopenid';
			if( !empty( $redirect_to ) ) $return_to .= '&redirect_to=' . urlencode( $redirect_to );
			
			$redirect_url = $auth_request->redirectURL( get_option('oid_trust_root'), $return_to );

			wp_redirect( $redirect_url );
			exit(0);
		}
		
	
		function finish_login() {
			global $_GET;
			if ( $_GET['action'] !== 'loginopenid' ) return;
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
				
				$this->action='';
				$redirect_to = 'wp-admin/';

				$matching_logins = $wpdb->get_results("SELECT ID, user_login, user_pass FROM $wpdb->users WHERE( user_url = '" . $wpdb->escape( $response->identity_url ) . "' )" );

				if( count( $matching_logins ) > 1 ) {
					$this->error = "Multiple user accounts are associated with this url. Cannot proceed.";
					
				} elseif( count( $matching_logins ) == 1 ) {
					// 1.1 If url is found, check user_meta[permit_openid]
					$user = new WP_User(0, $matching_logins[0]->user_login );
					
					if( get_usermeta( $user->ID, 'permit_openid_login' ) ) {
						if( wp_login( $user->user_login, md5($user->user_pass), true ) ) {
							do_action('wp_login', $user_login);
							wp_clearcookie();
							wp_setcookie($user->user_login, md5($user->user_pass), true, '', '', true);
							$this->action = 'redirect';
							if ( !$user->has_cap('edit_posts') ) $redirect_to = '/wp-admin/profile.php';

						} else {
							$this->error = "Failed to login via OpenID, wp_login() returned false.";
						}
					} else {
						// 1.1.2. Offer to bind accout to identity url. Display username/password form
						$this->error = "A user has already claimed this URL. If this is your account, type your password to authorize its use with OpenID.";
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
						
						update_usermeta( $ID, 'permit_openid_login', true );
						wp_update_user( array( 'ID' => $ID, 'user_url' => $response->identity_url ) );
						
						$user = new WP_User(0, $username );
					
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
				wp_redirect( $_GET['redirect_to'] );
			}


		}





		function openid_wp_comment_tagging( $user ) {
			global $current_user;		
			if( $current_user->permit_openid_login ) {
				$user['comment_type']='openid';
			}
			return $user;
		}


	
		/*
		 * Maybe styles should not be included, and left up to the template designer?
		 * Especially since they will probably have absolute urls to images.
		 */
		function css() {
			?>
	
			<style type="text/css">
				input.openid_url_sidebar {
					background: url(http://blog.verselogic.net/wp-content/themes/plains-in-the-dreaming/images/openid.gif) no-repeat;
					background-position: 0 50%;
					padding-left: 18px;
				}
				input#openid_url_comment_form {
					background: url(http://blog.verselogic.net/wp-content/themes/plains-in-the-dreaming/images/openid.gif) no-repeat;
					background-position: 0 50%;
					padding-left: 18px;
					//width: 75%;
				}
 			</style>
 			<?
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
				array('WordpressOpenID', 'oid_options_page')  );
		}
		
		/*
		 * Display and handle updates from the Admin screen options page
		 */
		function oid_options_page() {
			global $oid_unset, $oid_store;
		
			// if we're posted back an update, let's set the values here
			if ( isset($_POST['info_update']) ) {
				$trust = $_POST['oid_trust_root'];
				$ret_to = $_POST['oid_ret_to'];
				if ($trust == null){$trust = $oid_unset;}
				if ($ret_to == null){$ret_to = $oid_unset;}
	
				$error = '';
				if ( $this->openid_is_url($trust) ) {
					update_option('oid_trust_root', $trust);
				} else {
					$error .= "<p/>".$trust." is not a url!";
				}
				if ( $this->openid_is_url($ret_to) ) {
					update_option('oid_ret_to', $ret_to);
				} else {
					$error .= "<p/>".$ret_to." is not a url!";
				}
			
				if ($error != '') {
					echo '<div class="updated"><p><strong>At least one of Open ID options was NOT updated</strong>'.$error.'</p></div>';
				} else {
					echo '<div class="updated"><p><strong>Open ID options updated</strong></p></div>';
				}
				/*
				if ( isset( $_POST['install_tables'] ) ) {
					$oid_store->dbDelta();
					echo '<div class="updated"><p><strong>Assocation and nonce tables created.</strong></p></div>';
				}
				*/
				
				
			}

			$table_ok_color='green';
			$table_ok_warn='Tables are OK';
			if( $broken_tables = WordpressOpenID::openid_sql_tables_broken() ) {
				$table_ok_color='red';
				$table_ok_warn='Some tables are not installed: ' . implode(', ', $broken_tables);
			}

			// Display the options page form
			?>
			<form method="post"><div class="wrap">
				<h2>Open ID options</h2>
     				<fieldset class="options">
     					<p><em>Please refer to <a href="http://verisign.com">http://[TBD]</a> 
     					specification for more information.</em></p>
     					
     					<table class="editform" cellspacing="2" cellpadding="5" width="100%">
     					<tr><th valign="top" style="padding-top: 10px;">
     						<label for="oid_trust_root">Trust root:</label>
     					</th><td>
     						<p><input type="text" size="80" name="oid_trust_root" id="oid_trust_root"
     						value="<?php echo htmlentities(get_option('oid_trust_root')); ?>" /></p>
     						<p style="margin: 5px 10px;">Commenters will be asked whether they trust this url,
     						and its decendents, to know that they are logged in and own their identity url.
     						Include the trailing slash: <em>http://example.com</em><strong>/</strong>.
     						This should probably be <strong><?php echo get_settings('siteurl'); ?></strong></p>
     					</td></tr>
     					
     					<tr><th valign="top" style="padding-top: 10px;">
     						<label for="oid_ret_to">Return-to URL:</label>
     					</th><td>
     						<p><input type="text" size="80" name="oid_ret_to" id="oid_ret_to"
     						value="<?php echo htmlentities(get_option('oid_ret_to')); ?>" /></p>
     						<p style="margin: 5px 10px;">The url to return to after authentication.
     						This <em>must</em> be a decendent of the <em>trust root</em> above.
     						This should probably be <strong><?php echo get_settings('home'); ?></strong></p>
     					</td></tr>
     					
     					<tr><th valign="top" style="padding-top: 10px;">
     						Assoication Tables:
     					</th><td>
     						<p><input type="checkbox" name="install_tables" id="install_tables" />
     						<label for="install_tables">Install association tables.</label></p>
     						<p style="margin: 5px 10px; color:<?php echo $table_ok_color; ?> ;">
     						 <?php echo $table_ok_warn; ?></p>
     					</table>
     				</fieldset>
     				<input type="submit" name="info_update" value="<?php _e('Update options', 'Localization name') ?> »" />
     			</div></form>
     			<?php
 		} // end function oid_options_page



	} // end class definition
} // end if-class-exists test




$wordpressOpenIDRegistration = new WordpressOpenIDRegistration();



/*
 * Exposed functions, designed for use in templates.
 * Specifically inside `foreach ($comments as $comment)` in comments.php
 */

/*  get_comment_openid()
 *  If the current comment was submitted with OpenID, output an <img> tag with the OpenID logo
 */
function get_comment_openid() {
	if( get_comment_type() == 'openid' ) {
		echo '<img src="/openid.gif" height="16" width="16" alt="OpenID" />';
	}
}

/* is_openid_comment()
 * If the current comment was submitted with OpenID, return true
 * useful for  <?php echo ( is_openid_comment() ? 'Submitted with OpenID' : '' ); ?>
 */

function is_openid_comment() {
	return ( get_comment_type() == 'openid' );
}




/*

// Safely add options to the database if not already present
$oid_unset = get_settings('home');
add_option( 'oid_trust_root', $oid_unset, 'The Open ID trust root' );
add_option( 'oid_ret_to', $oid_unset, 'URL to return to after authentication' );

// Add handlers for action hooks
add_action( 'wp_head', array('WordpressOpenID', 'css'), 10, 2 );	// inside <head>
add_action( 'comment_form', array('WordpressOpenID', 'comment_form'), 10, 2 ); // bottom of comment form
add_action( 'admin_menu', array('WordpressOpenID', 'oid_add_pages') );	// about to display the admin screen

*/


/*  Output Buffer handler
 *  @param $form - String of html
 *  @return - String of html
 *  Replaces parts of the wp-login.php form.
 */

function openid_wp_login_ob( $form ) {
	global $redirect_to, $action;

	
	if( $action == 'bind' ) {
		$page='<h2>Bind Account</h2>
		<p>The OpenID identifier <strong>whatever</strong> is currently claimed by a user.
		   To authorizes logins with this identifier in the future, login with your Wordpress username
		   and password.</p>';
		
		$form = preg_replace( '#<form.*</form>#s', $page, $form, 1 );	// strip the whole form
		return $form;
	}



	$newform = '<form name="loginformopenid" id="loginformopenid" action="wp-login.php" method="post">
	<p><label>'.__('OpenID Url:').'<br/><input type="text" class="openid_url" name="openid_url" id="log" size="20" tabindex="5" /></label></p>
	<p class="submit">
		<input type="submit" name="submit" id="submit" value="'. __('Login') . ' &raquo;" tabindex="6" />
		<input type="hidden" name="rememberme" value="forever" />
		<input type="hidden" name="redirect_to" value="' . $redirect_to . '" />
	</p>
	</form>';

	$newform = '<p><label>'.__('OpenID Url:').'<br/><input type="text" class="openid_url" name="openid_url" id="log" size="20" tabindex="5" /></label></p>';

	$newhead = '<style type="text/css">
				#login input.openid_url {
					background: #f4f4f4 url(http://blog.verselogic.net/wp-content/themes/plains-in-the-dreaming/images/openid.gif) no-repeat;
					background-position: 0 50%; padding-left: 18px;
				}
				#login input.openid_url:focus {
					background: #fff url(http://blog.verselogic.net/wp-content/themes/plains-in-the-dreaming/images/openid.gif) no-repeat;
					background-position: 0 50%; padding-left: 18px;
				}  </style>';
	
	$form = preg_replace( '#<p class="submit">#', $newform . '<p class="submit">' , $form, 1 );
	$form = preg_replace( '#<link rel#', $newhead . '<link rel', $form, 1);
	return $form;
}








add_action('wp_authenticate',	array( $wordpressOpenIDRegistration, 'wp_authenticate' ) );  // start an auth cycle

add_action('init',		array( $wordpressOpenIDRegistration, 'finish_login'    ) );  // finish an auth cycle

add_action('preprocess_comment',array( $wordpressOpenIDRegistration, 'openid_wp_comment_tagging' ) );


/*
function openid_wp_user_profile($a) {
	echo "FOO";
	print_r($a);
}
add_action( 'edit_user_profile', 'openid_wp_user_profile' );
add_action( 'profile_personal_options', 'openid_wp_user_profile' );
*/


/*
 * Add sidebar login form, editing Register link.
 */

function openid_wp_sidebar_register( $link ) {
	global $current_user;
	if( !$current_user->has_cap('edit_posts')  ) {
		$link = preg_replace( '#<a href="' . get_settings('siteurl') . '/wp-admin/">Site Admin</a>#', '<a href="' . get_settings('siteurl') . '/wp-admin/profile.php">' . __('Profile') . '</a>', $link );
	}
	if( $current_user->display_name ) {
		$chunk ='<li>Logged in as <a href="' . $current_user->display_name . '">' . htmlentities( $current_user->display_name ) . '</a></li>';
	
	} else {
		$chunk ='<li><form method="post" action="wp-login.php" style="display:inline;">
			<input class="openid_url_sidebar" name="openid_url" size="17" />
			<input type="hidden" name="redirect_to" value="'
			. $_SERVER["REQUEST_URI"] . '" /></form></li>';
	}
	return $link . $chunk;
}

add_filter( 'register', 'openid_wp_sidebar_register' );  // turn SiteAdmin into Profile link in sidebar. Makes more sense.



function openid_wp_comment_form($id) {
	global $current_user;
	if( ! $current_user->id ) { // not logged in, draw a login form
		?>
		<label for="openid_url_comment_form">Sign in with OpenID:</label><br/>	
		<input type="textbox" name="openid_url" id="openid_url_comment_form" size="30" />
		<?php
	}


}

add_filter( 'comment_form', 'openid_wp_comment_form' );





add_action( 'wp_head', array( 'WordpressOpenIDRegistration', 'css'), 10, 2 );

?>