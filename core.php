<?php
/*
Plugin Name: Wordpress OpenID (+)
Plugin URI: http://willnorris.com/projects/wpopenid/
Description: Wordpress OpenID Registration, Authentication, and Commenting.   This is a fork of the <a href="http://verselogic.net/projects/wordpress/wordpress-openid-plugin/">original wpopenid project</a> by <a href="http://verselogic.net">Alan Castonguay</a> and Hans Granqvist, with hopes of merging it upstream in the near future.  (URLs and such have been changed so as not to confuse the two plugins.)
Author: Will Norris
Author URI: http://willnorris.com/
Version: $Rev$
Licence: Modified BSD, http://www.fsf.org/licensing/licenses/index_html#ModifiedBSD
*/

#define ( 'WPOPENID_PLUGIN_PATH', '/wp-content/plugins/' . basename(dirname(__FILE__)) );  
define ( 'WPOPENID_PLUGIN_PATH', '/wp-content/plugins/openid');

define ( 'WPOPENID_PLUGIN_VERSION', preg_replace( '/\$Rev: (.+) \$/', 'svn-\\1', 
	'$Rev$') ); // this needs to be on a separate line so that svn:keywords can work its magic
define ( 'WPOPENID_DB_VERSION', 11260);

//set_include_path( dirname(__FILE__) . PATH_SEPARATOR . get_include_path() );   // Add plugin directory to include path temporarily
error_log(get_include_path());

require_once('logic.php');
require_once('interface.php');

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

@session_start();

if  ( !class_exists('WordpressOpenID') ) {
	class WordpressOpenID {
		var $path;
		var $fullpath;

		var $logic;
		var $interface;

		function __construct() {
			$this->interface = new WordpressOpenIDInterface($this);
			$this->logic = new WordpressOpenIDLogic();

			$this->path = '/wp-content/plugins/openid';
			$this->fullpath = get_option('siteurl').$this->path;
		}

		function startup() {
			if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log("WPOpenID Status: userinterface hooks: " . ($this->interface->oid->enabled? 'Enabled':'Disabled' ) . ' (finished including and instantiating, passing control back to wordpress)' );

			$this->interface->startup();
			
			// -- register actions and filters
			
			add_action( 'admin_menu', array( $this->interface, 'add_admin_panels' ) );

			// Kickstart
			register_activation_hook( $this->path.'/core.php', array( $this->logic, 'late_bind' ) );
			register_deactivation_hook( $this->path.'/core.php', array( $this->logic, 'destroy_tables' ) );

			// Add hooks to handle actions in Wordpress
			add_action( 'wp_authenticate', array( $this->logic, 'wp_authenticate' ) ); // openid loop start
			add_action( 'init', array( $this->logic, 'finish_login' ) ); // openid loop done

			// Start and finish the redirect loop, for the admin pages profile.php & users.php
			add_action( 'init', array( $this->logic, 'admin_page_handler' ) );

			// Comment filtering
			add_action( 'preprocess_comment', array( $this->logic, 'comment_tagging' ), -99999 );
			add_filter( 'option_require_name_email', array( $this->logic, 'bypass_option_require_name_email') );
			add_filter( 'comment_notification_subject', array( $this->logic, 'comment_notification_subject'), 10, 2 );
			add_filter( 'comment_notification_text', array( $this->logic, 'comment_notification_text'), 10, 2 );
			add_filter( 'comments_array', array( $this->logic, 'comments_awaiting_moderation'), 10, 2);
			add_action( 'sanitize_comment_cookies', array( $this->logic, 'sanitize_comment_cookies'), 15);
			
			add_action( 'delete_user', array( $this->logic, 'drop_all_identities_for_user' ) );	// If user is dropped from database, remove their identities too.

			if (get_option('oid_enable_selfstyle')) {
				add_action( 'wp_head', array( $this->interface, 'style'));
				add_action( 'login_head', array( $this->interface, 'style'));
			}

			add_action( 'init', array( $this->interface, 'js_setup'));

			if( get_option('oid_enable_commentform') ) {
				add_filter( 'get_comment_author_link', array( $this->interface, 'comment_author_link_prefx'));
				add_action( 'comment_form', array( $this->interface, 'comment_form'));
			}

			if( get_option('oid_enable_loginform') ) {
				add_action( 'login_form', array( $this->interface, 'login_form'));
				add_action( 'register_form', array( $this->interface, 'register_form'));
				add_filter( 'login_errors', array( $this->interface, 'login_form_hide_username_password_errors'));
				add_filter( 'register', array( $this->interface, 'sidebar_register' ));
			}
			add_filter( 'loginout', array( $this->interface, 'sidebar_loginout' ));


			// Add custom OpenID options
			add_option( 'oid_trust_root', get_settings('siteurl'), 'The Open ID trust root' );
			add_option( 'oid_enable_selfstyle', true, 'Use internal style rules' );
			add_option( 'oid_enable_loginform', true, 'Display OpenID box in login form' );
			add_option( 'oid_enable_commentform', true, 'Display OpenID box in comment form' );
			add_option( 'oid_plugin_enabled', true, 'Currently hooking into Wordpress' );
			add_option( 'oid_plugin_version', 0, 'OpenID plugin version' );
			add_option( 'oid_db_version', 0, 'OpenID plugin database store version' );
			add_option( 'oid_enable_unobtrusive', false, 'Look for OpenID in the existing website input field' );
			add_option( 'oid_enable_localaccounts', true, 'Create local wordpress accounts for new users who sign in with an OpenID.' );
		}


	}
}

if (isset($wp_version)) {
	$openid = new WordpressOpenID();
	$openid->startup();
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




/* Exposed functions, designed for use in templates.
 * Specifically inside `foreach ($comments as $comment)` in comments.php
 */


/*  get_comment_openid()
 *  If the current comment was submitted with OpenID, output an <img> tag with the OpenID logo
 */
if( !function_exists( 'get_comment_openid' ) ) {
	function get_comment_openid() {
		global $comment_is_openid;
		get_comment_type();
		if( $comment_is_openid === true ) echo '<img src="'.OPENIDIMAGE.'" height="16" width="16" alt="OpenID" />';
	}
}

/* is_comment_openid()
 * If the current comment was submitted with OpenID, return true
 * useful for  <?php echo ( is_comment_openid() ? 'Submitted with OpenID' : '' ); ?>
 */
if( !function_exists( 'is_comment_openid' ) ) {
	function is_comment_openid() {
		global $comment_is_openid;
		get_comment_type();
		return ( $comment_is_openid === true );
	}
}

if( !function_exists( 'mask_comment_type' ) ) {
	function mask_comment_type( $comment_type ) {
		global $comment_is_openid;
		if( $comment_type === 'openid' ) {
			$comment_is_openid = true;
			return 'comment';
		}
		$comment_is_openid = false;
		return $comment_type;
	}
	add_filter('get_comment_type', 'mask_comment_type' );
}

if( !function_exists('is_user_openid') ) {
	function is_user_openid() {
		global $current_user;
		return ( null !== $current_user && get_usermeta($current_user->ID, 'registered_with_openid') );
	}
}
?>
