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
/*	
			$oid_peardb_dsn = array( 'phptype'=>'mysql', 'username'=>DB_USER, 
				 'password'=>DB_PASSWORD, 'hostspec'=>DB_HOST, 
				 'database'=>DB_NAME );
			$oid_peardb_connection = & DB::connect($oid_peardb_dsn);
			if (PEAR::isError($oid_peardb_connection)) {
				die("Error connecting to database: " . $db->getMessage());
			}

*/


			$oid_peardb_connection = new WP_OpenIDConnection( & $wpdb );
			
			
			$store = new Auth_OpenID_MySQLStore( $oid_peardb_connection,
				$table_prefix . 'oid_settings',
				$table_prefix . 'oid_associations',
				$table_prefix . 'oid_nonces' );
			return $store;
		}


		function start_openid_comment_loop( $consumer, $openid_claimed_url ) {
			if ( empty( $openid_claimed_url ) ) return;
			$openid_return_to  = get_option('oid_ret_to');
			$openid_trust_root = get_option('oid_trust_root');
		
			$auth_request = $consumer->begin( $openid_claimed_url );
	
			if (!$auth_request) {
				$error = "Expected an OpenID URL. Authentication Error.";
				die($error);
			}
	
			WordpressOpenID::erase_openid_session();
			$_SESSION['openid_token'] = $info->token;
			$_SESSION['openid_content'] = $_POST['comment'];
			$_SESSION['openid_post_ID'] = $_POST['comment_post_ID'];
  
			$redirect_url = $auth_request->redirectURL( $openid_trust_root, $openid_return_to );
			header("Location: ".$redirect_url);
			exit(0);
		}
		
		function finish_openid_comment_loop( $consumer, $get ) {
			if ( !isset( $get['openid_mode'] ) ) return;
			
			// OpenID authentication already started, return and complete the attempt 
			$response = $consumer->complete( $_GET );

			switch( $response->status ) {
			case Auth_OpenID_CANCEL:
				$msg = 'OpenID verification cancelled.';
				break;
			case Auth_OpenID_FAILURE:
				$msg = "OpenID authentication failed: " . $response->message;
				break;
			case Auth_OpenID_SUCCESS:
				$msg = false;
				$identity = $response->identity_url;
				$esc_identity = htmlspecialchars( $identity, ENT_QUOTES);
				$location = WordpressOpenID::do_openid_comment( $esc_identity, $identity, 
				   $_SESSION['openid_post_ID'], $_SESSION['openid_content'] );
				
				require_once (ABSPATH . WPINC . '/pluggable-functions.php');
				// Maybe use header('Location: ' . $location); instead?
				wp_redirect( $location );
				exit(0);
			default:
				$msg = 'OpenID authentication failed: Unknown problem: ' . $response->status;
				break;
			} // end switch
			WordpressOpenID::erase_openid_session();
			if($msg) die($msg); // There needs to be a better way to show these errors
		}



		/*
		 * Javascript to show/hide groups of the comment form
		 */
		function comment_form( $postid ) {
  ?>
 <script type="text/javascript">
    a = document.getElementById( "commentAuthModeAnon" );
 b = document.getElementById( "commentAuthModeOpenid" );
 c = document.getElementById( "commentAuthModeLivejournal" );
 a.onclick = commentOptionsCheckHandler;
 b.onclick = commentOptionsCheckHandler;
 c.onclick = commentOptionsCheckHandler;
 
 if( ! ( a.checked || b.checked || c.checked )) {
   b.checked=true;
 }
	  		
 function commentOptionsCheckHandler() {
   x = document.getElementById( "commentOptionsBlockAnon" );
   y = document.getElementById( "commentOptionsBlockOpenid" );
   z = document.getElementById( "commentOptionsBlockLivejournal" );
   if( b.checked ) {
     x.style.display = "none";
     y.style.display = "block";
     z.style.display = "none";
   } else if( c.checked ) {
     x.style.display = "none";
     y.style.display = "none";
     z.style.display = "block";
   } else if( a.checked ) {
     x.style.display = "block";
     y.style.display = "none";
     z.style.display = "none";
   }
 }
 setTimeout(commentOptionsCheckHandler,1);	  			   
 </script>	  		  	
	<?php	}
		
		/*
		 * A handy place to insert things in the <head> block,
		 * such as the old Openid.Server link rel= tag
		 */
		function insert_server() {
			if ( is_home() ) {
			}
		}
	
		/* DEBUG
		 * Alan is using this function to test obscure wordpress hooks
		 */  
		function h() {
			echo "HELLO";
		}
	
		/*
		 * Maybe styles should not be included, and left up to the template designer?
		 * Especially since they will probably have absolute urls to images.
		 */
		function css() {
			?>
			<style type="text/css">
			ul#commentAuthOptions {
				list-style: none;
				margin: 0;padding: 0;
			}
			ul#commentAuthOptions li {
				margin:0; padding:0;
			}
			ul#commentAuthOptions li input {
				width:auto;
			}
			input#openid_url {
			  background: url(http://blog.verselogic.net/wp-content/themes/plains-in-the-dreaming/images/openid.gif) no-repeat;
			  background-color: #fff; 
			  background-position: 0 50%;
			  color: #000;
			  padding-left: 18px; 
			}
			input#livejournal_username {
			  background: url(http://blog.verselogic.net/wp-content/themes/plains-in-the-dreaming/images/lj.gif) no-repeat;
			  background-color: #fff;
			  background-position: 0 50%;
			  color: #000;
			  padding-left: 18px;
			}
			.commentOptionsBlock {
			  margin-left: 2em;
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



// Instansiate consumer
//$oid_store = WordpressOpenID::openid_get_mysql_store();
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




function openid_wp_authenticate( $username, $password ) {

	echo "<h2>Username: $username.  Password: $password ";
	if( $_POST['log_openid'] ) echo ' OpenID: ' . $_POST['log_openid'];
	echo '</h2>';

	if( $_GET['action'] == 'openid' ) {
		echo "!!!!";
	}

}


//add_action( 'wp_authenticate', 'openid_wp_authenticate' );



?>