<?php
/*
Plugin Name: Wordpress OpenID
Plugin URI: http://wpopenid.sourceforge.net
Description: Wordpress OpenID comments. Uses JanRain consumer library.
Author: Alan J Castonguay, ...
Author URI: http://verselogic.net
Version: 0.4
*/



function erase_openid_session() {
	global $_SESSION;
	//unset($_SESSION['openid_token']);
	//unset($_SESSION['openid_display']);
	unset($_SESSION['openid_post_ID']);
	unset($_SESSION['openid_content']);            
}

function do_openid_comment( $user_identity, $user_url, $comment_post_ID, $comment ) {
	// contents of wp-comments-post.php
	global $wpdb;
	$comment_content      = trim($comment);
	$comment_author       = $wpdb->escape($user_identity);
	$comment_author_email = 'openid.consumer@verselogic.net';
	$comment_author_url   = $wpdb->escape($user_url);
	$comment_type = 'openid';
  
	if ( '' == $comment_content )
		die( __('Error: please type a comment.') );
	
	$commentdata = compact('comment_post_ID', 'comment_author', 'comment_author_email', 'comment_author_url', 'comment_content', 'comment_type', 'user_ID');
	$comment_id = wp_new_comment( $commentdata );
  
	//setcookie('comment_openid_url_'.COOKIEHASH, clean_url($comment->comment_author_url), time() + 30000000, COOKIEPATH, COOKIE_DOMAIN);
	$location = ( empty( $_POST['redirect_to'] ) ) ? get_permalink( $comment_post_ID ) : $_POST['redirect_to'];
  
	return $location;
}

function openid_is_url($url) {
	return (!preg_match('#^http\\:\\/\\/[a-z0-9\-]+\.([a-z0-9\-]+\.)?\\/[a-z]+#i', $url));
} 

require_once "Auth/OpenID/Consumer.php";


$openid_store_type = get_option('oid_store_type');
/*
// Store association data on the filesystem.
require_once "Auth/OpenID/FileStore.php";
$store_path = "/tmp/_php_consumer_test";
if (!file_exists($store_path) &&!mkdir($store_path))
	die( "Could not create the FileStore directory '$store_path'. Please check the effective permissions.");
$store = new Auth_OpenID_FileStore($store_path);
*/


/* Mysql Store, used to store association and nonce
 *   This should be changed to use Auth_OpenID_OpenIDStore instead of a PEAR DB object */

require_once "Auth/OpenID/MySQLStore.php";
$oid_peardb_dsn = array( 'phptype'=>'mysql', 'username'=>DB_USER, 'password'=>DB_PASSWORD, 'hostspec'=>DB_HOST, 'database'=>DB_NAME );
$oid_peardb_connection = & DB::connect($oid_peardb_dsn);
if (PEAR::isError($oid_peardb_connection)) die("Error connecting to database: " . $db->getMessage());
$store = new Auth_OpenID_MySQLStore( $oid_peardb_connection );


$consumer = new Auth_OpenID_Consumer($store);
          

$openid_claimed_url = $_POST['commentAuthOpenID'];
$oid_unset = "[unset]";


$openid_trust_root = get_option('oid_trust_root');  // Root of Wordpress URL by default?
$openid_return_to = get_option('oid_ret_to') . '?post=' . $_POST['comment_post_ID']; // Wordpress index.php by defalt?

if( empty( $openid_trust_root )) echo "Warning: OpenID Trust Root not set!";
if( empty( $openid_return_to  )) echo "Warning: OpenID Return To not set!";

$openid_return_to = get_option('siteurl') . '/wp-comments-post.php'; // Standard comment parser sounds like a sane default.


/* Automagical Livejournal Support, this mangles the form data for USER.livejournal.com
 * Re-implement this as a plugin of some kind */
 
/*if( isset( $_POST['commentAuthMode'] )) {
	if( $_POST['commentAuthMode'] == 'livejournal' ) {
		$_POST['commentAuthMode'] = 'openid';
		$_POST['commentAuthOpenID'] = 'http://www.livejournal.com/users/' . $_POST['commentAuthLivejournal'] . '/'; 
	}
	if( $_POST['commentAuthMode'] == 'openid' )
		if( isset( $_POST['commentAuthOpenID'] ))
			$openid_claimed_url = $_POST['commentAuthOpenID'];
}
*/


session_start();

if( !empty( $openid_claimed_url ) ) {

   $auth_request= $consumer->begin($openid_claimed_url);

   if (!$auth_request) {
	$error = "Expected an OpenID URL. Authentication Error.";
	die($error);
   }
 
   erase_openid_session();
   $_SESSION['openid_token'] = $info->token;
   $_SESSION['openid_content'] = $_POST['comment'];
   $_SESSION['openid_post_ID'] = $_POST['comment_post_ID'];
 
   $redirect_url = $auth_request->redirectURL($openid_trust_root, $openid_return_to);
   header("Location: ".$redirect_url);
   exit(0);

} elseif( isset( $_GET['openid_mode'] ) ) {	// OpenID authentication already started, return and complete the attempt 
 $response = $consumer->complete( $_GET );

 switch( $response->status ) {
 	case Auth_OpenID_CANCEL:
 		$msg = 'OpenID verification cancelled.'; break;

 	case Auth_OpenID_FAILURE:
 		$msg = "OpenID authentication failed: " . $response->message; break;

 	case Auth_OpenID_SUCCESS:
 		$msg = FALSE;
 		$identity = $response->identity_url;
 		$esc_identity = htmlspecialchars( $identity, ENT_QUOTES);
		$location = do_openid_comment( $esc_identity, $identity, $_SESSION['openid_post_ID'], $_SESSION['openid_content'] );
		require_once (ABSPATH . WPINC . '/pluggable-functions.php');	// Maybe use header('Location: ' . $location); instead?
		wp_redirect( $location );
		exit(0);

 	default:
 		$msg = 'OpenID authentication failed: Unknown problem: ' . $response->status; break;
 } 

 erase_openid_session();
 if($msg) die($msg);

}

if  ( !class_exists('WordpressOpenID') ) {
	class WordpressOpenID {
	  function comment_form( $postid ) {
/*	  	echo '<ul id="commentAuthOptions">
	  		<li><input type="radio" name="commentAuthMode" value="auth" /> <div id="commentAuth0X" ></div>
	  		<li><input type="radio" name="commentAuthMode" value="anon" checked="checked" /> Anonymous <div id="commentAuth2X" ></div> </li>
	  		<li><input type="radio" name="commentAuthMode" value="openid" /> OpenID: <input name="commentAuthOpenID" id="openid_url"/> DYSFUNCTIONAL!</li>
	  		</ul>';
 */	  		
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
	  	<?php
	  }
	  
	  function insert_server() {
	    if ( is_home() ) {
	      //	    	echo "\n"
		  //	    	.'<link rel="openid.server" href="http://www.myopenid.com/server" />'."\n"
		  //	    	.'<link rel="openid.delegate" href="http://verselogic.myopenid.com/" />'."\n";
	    }
	  }
	  
	  function h() {
	   echo "HELLO";
	  }

	  function css() {
	   print '<style type="text/css">
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
	  	</style>'; 
	  }

	function preprocess_comment( $c ) {
		global $_POST;
		// executed when a new comment is submitted, $_POST is populated with our extra form variables
		// commentAuthMode   (flag = openid)
		// commentAuthOpenID (claimed url)
/*		if( isset($_POST['commentAuthMode']) && ($_POST['commentAuthMode'] == 'openid' )) {
			// we need to save some of the information from the comment form
			// like the $c[comment_content]
			print_r($c);
			echo("OpenID Attempt");
			exit;
			setcookie('openid_comment_body', $c['comment_content'], time() + 30000000, COOKIEPATH);
			
			
		}
*/		return $c;
	
	}
	function pre_comment_approved( $approved ) {
		// if OpenID was used to submit the comment, default to approve? return 1;
	}

	function oid_options_page() {
	  global $oid_unset;

	  // if we're posted back an update, let's set the values here
	  //
	  if (isset($_POST['info_update'])) {
	    $trust = $_POST['oid_trust_root'];
	    $ret_to = $_POST['oid_ret_to'];

	    if ($trust == null){$trust = $oid_unset;}
	    if ($ret_to == null){$ret_to = $oid_unset;}

	    $error = '';
	    if (!openid_is_url($trust)) {
	      $error .= "<p/>".$trust." is not a url";
	    } else {
	      update_option('oid_trust_root', $trust);
	    }
	    if (!openid_is_url($ret_to)) {
	      $error .= "<p/>".$ret_to." is not a url";
	    } else {
	      update_option('oid_ret_to', $ret_to);
	    }

	    if ($error != '') {
	      echo '<div class="updated"><p><strong>At least one of Open ID options was NOT updated</strong>'.$error.'</p></div>';
	    } else {
	      echo '<div class="updated"><p><strong>Open ID options updated</strong>'.$original.'</p></div>';
	    }
	  }

	  // the options page comes here
	  //
	    ?>
  <form method="post">
     <div class="wrap">
     <h2>Open ID options</h2>
     <fieldset class="options">
     <p><em>Please refer to <a href="http://verisign.com">http://[TBD]</a> 
             specification for more information.</em></p>
     <table class="editform" cellspacing="2" cellpadding="5" width="100%">

     <tr>
       <th valign="top" style="padding-top: 10px;">
       <label for="oid_trust_root">Trust root:</label>
       </th>
       <td>
         <input type="text" size="80" name="oid_trust_root" id="oid_trust_root"
         	value="<?php echo htmlentities(get_option('oid_trust_root')); ?>" />
          <p style="margin: 5px 10px;">Users will be asked whether they trust this url,
           and its decendents, to know that they are logged in and own their identity url.
           This should probably be <strong><?php echo get_settings('siteurl'); ?></strong></p>
       </td>
     </tr>

     <tr>
       <th valign="top" style="padding-top: 10px;">
       <label for="<?php echo oid_ret_to; ?>">Return-to URL:</label>
       </th>
       <td>
         <input type="text" size="80" name="oid_ret_to" id="oid_ret_to"
         	value="<?php echo htmlentities(get_option('oid_ret_to')); ?>" />
         <p style="margin: 5px 10px;">The url to return to after authentication.
           This <em>must</em> be a decendent of the <em>trust root</em> above.
           This should probably be <strong><?php echo get_settings('home'); ?></strong></p>
       </td>
     </tr>
    </table>
  </fieldset>
  <input type="submit" name="info_update" value="<?php _e('Update options', 'Localization name') ?> »" /></div>
  </form>
 </div><?php
 
        } // end function for displaying options page

  }
}



// bind actions, filters and options to 

function get_comment_openid() {
	if( get_comment_type() == 'openid' ) {
		echo '<img src="/openid.gif" height="16" width="16" alt="OpenID" />';
	}
}

function alan_test($s) {
	return $s;
}

add_filter('get_comment_type', 'alan_test', 1);

add_option('oid_trust_root', $oid_unset, 'The Open ID trust root');
add_option('oid_ret_to',     $oid_unset, 'URL to return to after authentication');
add_action('wp_head',        array('WordpressOpenID', 'css'), 10, 2);
add_action('comment_form',   array('WordpressOpenID', 'comment_form'), 10, 2);
//add_action('wp_authenticate', array('WordpressOpenID', 'authenticate'), 10, 2);
//add_action('wp_head',         array('WordpressOpenID', 'insert_server'), 10, 2);
//add_action('preprocess_comment', array('WordpressOpenID','preprocess_comment'), 10, 2);

add_action('admin_menu', 'oid_add_pages');
function oid_add_pages() {
   add_options_page('Open ID options', 'Open ID', 8, __FILE__, array('WordpressOpenID', 'oid_options_page')  );
}



?>