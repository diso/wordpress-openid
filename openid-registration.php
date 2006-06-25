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



if  ( !class_exists('WordpressOpenIDRegistration') ) {
	class WordpressOpenIDRegistration {
	

		function erase_openid_session() {
			global $_SESSION;
			unset($_SESSION['openid_post_ID']);
			unset($_SESSION['openid_content']);            
		}
		
		/*
		 * do_openid_comment ( displayurl, realurl, postIdNumber, commentext )
		 * functionally equivalent to contents of wp-comments-post.php
		 */

		function do_openid_comment( $user_identity, $user_url, $dirty_comment_post_ID, $comment ) {
			global $wpdb;

			$comment_content      = trim($comment);
			$comment_author       = $wpdb->escape($user_identity);
			$comment_author_email = 'openid.consumer@verselogic.net'; // TODO make this an option.
			$comment_author_url   = $wpdb->escape($user_url);
			$comment_type         = 'openid';   // like "comment" & "trackback" in get_comment_type()
			$comment_post_ID      = (int) $dirty_comment_post_ID;

			// don't permit comments on closed or draft posts
			$status = $wpdb->get_row("SELECT post_status, comment_status FROM $wpdb->posts WHERE ID = '$comment_post_ID'");
			if ( empty($status->comment_status) ) {
				do_action('comment_id_not_found', $comment_post_ID);
				exit;
			} elseif ( 'closed' ==  $status->comment_status ) {
				do_action('comment_closed', $comment_post_ID);
				die( __('Sorry, comments are closed for this item.') );
			} elseif ( 'draft' == $status->post_status ) {
				do_action('comment_on_draft', $comment_post_ID);
				exit;
			}
			
			// don't permit empty comments
			if ( '' == $comment_content ) {
			die( __('Error: please type a comment.') );
			}

			$commentdata = compact('comment_post_ID', 'comment_author', 
				 'comment_author_email', 'comment_author_url', 
				 'comment_content', 'comment_type', 'user_ID');
			$comment_id = wp_new_comment( $commentdata );

			$location = ( empty( $_POST['redirect_to'] ) ) ? get_permalink( $comment_post_ID ) : $_POST['redirect_to'];
			return $location;
		}

		/*
		 * Sanity check for urls
		 */
		function openid_is_url($url) {
			return !preg_match( '#^http\\:\\/\\/[a-z0-9\-]+\.([a-z0-9\-]+\.)?\\/[a-z]+#i',$url );
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

		/* 
		 * Mysql Store, used to store association and nonce.
		 * This should be changed to use a subclass of 
		 * Auth_OpenID_DatabaseConnection over $wpdb instead
		 * of a PEAR DB object for communication to the database
		 */
		function openid_get_mysql_store() {
			global $table_prefix, $wpdb;

			require_once "Auth/OpenID/MySQLStore.php";

			$oid_peardb_connection = new WP_OpenIDConnection( & $wpdb );
			
			$store = new Auth_OpenID_MySQLStore( $oid_peardb_connection,
				$table_prefix . 'oid_settings',
				$table_prefix . 'oid_associations',
				$table_prefix . 'oid_nonces' );
			return $store;
		}


		function start_login( $consumer, $claimed_url ) {
			if ( empty( $claimed_url ) ) return;
			
			$auth_request = $consumer->begin( $claimed_url );
			if (!$auth_request) {
				global $error;
				$error = 'Expected an OpenID URL. Got:<br/>' . htmlentities( $claimed_url );
				return;
			}
			$redirect_url = $auth_request->redirectURL( get_option('oid_trust_root'), 'http://openid.verselogic.net/wp-login.php?action=loginopenid' );
			wp_redirect( $redirect_url );
			exit(0);
		}
			
	
		function finish_login( $consumer, $get ) {
			if ( !isset( $get['openid_mode'] ) ) return;
			$response = $consumer->complete( $get );
			global $openid_error;

			switch( $response->status ) {
			case Auth_OpenID_CANCEL:
				$openid_error = 'OpenID verification cancelled.';
				break;
			case Auth_OpenID_FAILURE:
				$openid_error = 'OpenID authentication failed: ' . $response->message;
				break;
			case Auth_OpenID_SUCCESS:
				$openid_error = 'OpenID success';
				break;
			default:
				$openid_error = 'OpenID authentication failed: Unknown problem: ' . $response->status;
				break;
			}
			
			/*
			$identity = $response->identity_url;
			$esc_identity = htmlspecialchars( $identity, ENT_QUOTES);
			 */
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
 			</style>
 			<?
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
				if ( WordpressOpenID::openid_is_url($trust) ) {
					update_option('oid_trust_root', $trust);
				} else {
					$error .= "<p/>".$trust." is not a url!";
				}
				if ( WordpressOpenID::openid_is_url($ret_to) ) {
					update_option('oid_ret_to', $ret_to);
				} else {
					$error .= "<p/>".$ret_to." is not a url!";
				}
			
				if ($error != '') {
					echo '<div class="updated"><p><strong>At least one of Open ID options was NOT updated</strong>'.$error.'</p></div>';
				} else {
					echo '<div class="updated"><p><strong>Open ID options updated</strong></p></div>';
				}

				if ( isset( $_POST['install_tables'] ) ) {
					$oid_store->dbDelta();
					echo '<div class="updated"><p><strong>Assocation and nonce tables created.</strong></p></div>';
				}
				
				
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


/*
 * Exposed functions, designed for use in templates.
 * Specifically inside `foreach ($comments as $comment)` in comments.php
 */

/*  get_comment_openid()
 *  If the current comment was submitted with OpenID, output an <img> tag with the OpenID logo
 */
/*
function get_comment_openid() {
	if( get_comment_type() == 'openid' ) {
		echo '<img src="/openid.gif" height="16" width="16" alt="OpenID" />';
	}
}
*/

/* is_openid_comment()
 * If the current comment was submitted with OpenID, return true
 * useful for  <?php echo ( is_openid_comment() ? 'Submitted with OpenID' : '' ); ?>
 */
/*
function is_openid_comment() {
	return ( get_comment_type() == 'openid' );
}
*/



// Instansiate consumer
//$oid_store = WordpressOpenID::openid_get_mysql_store();
/*
$oid_store = new WP_OpenIDStore();
if( $oid_store == null ) echo "Warning: Null Store, the consumer's store tables probably arn't created properly.";
$oid_consumer = new Auth_OpenID_Consumer($oid_store);




@session_start();


// Kick off openid auth loop
if( false === ($broken_tables = WordpressOpenID::openid_sql_tables_broken() ) ) {
	WordpressOpenID::start_openid_comment_loop( $oid_consumer, $_POST['commentAuthOpenID'] );
	WordpressOpenID::finish_openid_comment_loop( $oid_consumer, $_GET );
}


// Safely add options to the database if not already present
$oid_unset = get_settings('home');
add_option( 'oid_trust_root', $oid_unset, 'The Open ID trust root' );
add_option( 'oid_ret_to', $oid_unset, 'URL to return to after authentication' );

// Add handlers for action hooks
add_action( 'wp_head', array('WordpressOpenID', 'css'), 10, 2 );	// inside <head>
add_action( 'comment_form', array('WordpressOpenID', 'comment_form'), 10, 2 ); // bottom of comment form
add_action( 'admin_menu', array('WordpressOpenID', 'oid_add_pages') );	// about to display the admin screen


*/

function openid_wp_login_ob( $form ) {
	global $redirect_to;
	$newform = '<form name="loginformopenid" id="loginformopenid" action="wp-login.php" method="post">
	<p><label>'.__('OpenID Url:').'<br/><input type="text" class="openid_url" name="openid_url" id="log" size="20" tabindex="5" /></label></p>
	<p class="submit">
		<input type="submit" name="submit" id="submit" value="'. __('Login') . ' &raquo;" tabindex="6" />
		<input type="hidden" name="rememberme" value="forever" />
		<input type="hidden" name="redirect_to" value="' . $redirect_to . '" />
	</p>
	</form>';

	$newhead = '<style type="text/css">
				#login input.openid_url {
					background: url(http://blog.verselogic.net/wp-content/themes/plains-in-the-dreaming/images/openid.gif) no-repeat;
					background-position: 0 50%; padding-left: 18px;
				}  </style>';
	
	$form = preg_replace( '#</form>#', '</form>' . $newform , $form, 1 );
	$form = preg_replace( '#<link rel#', $newhead . '<link rel', $form, 1);
	return $form;
}


function openid_wp_authenticate( $username, $password ) {
	ob_start("openid_wp_login_ob");

	global $error, $openid_error;
	global $register_openid_consumer;
	
	if( !empty( $openid_error ) ) {
		$error = $openid_error;
	}
	
	if( !empty( $_POST['openid_url'] ) ) {
		WordpressOpenIDRegistration::start_login( $register_openid_consumer, $_POST['openid_url'] );
	}
		
}


add_action( 'wp_authenticate', 'openid_wp_authenticate' );






function openid_wp_sidebar_login( $link ) {
	$link.='</li><li><form method="post" action="wp-login.php" style="display:inline;"><input class="openid_url_sidebar" name="openid_url" size="10" /><input type="hidden" name="redirect_to" value="'
		. $_SERVER["REQUEST_URI"] . '" /></form>';
	return $link;
}

add_action( 'loginout', 'openid_wp_sidebar_login' );




@session_start();	// required by Services_Yadis_PHPSession:40


$register_openid_store = new WP_OpenIDStore();
$register_openid_consumer = new Auth_OpenID_Consumer( $register_openid_store );


if( $_GET['action'] == 'loginopenid' ) {
	WordpressOpenIDRegistration::finish_login( $register_openid_consumer, $_GET );
}

function foobar() {
	echo "FOOBAR";
}

add_action( 'wp_head', array( 'WordpressOpenIDRegistration', 'css'), 10, 2 );
add_action( 'admin_menu', 'foobar' );

?>