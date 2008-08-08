<?php
/*
 Plugin Name: WP-OpenID
 Plugin URI: http://wordpress.org/extend/plugins/openid
 Description: Allows the use of OpenID for account registration, authentication, and commenting.  <em>By <a href="http://verselogic.net">Alan Castonguay</a>.</em>
 Author: Will Norris
 Author URI: http://willnorris.com/
 Version: 2.2.2
 License: Dual GPL (http://www.fsf.org/licensing/licenses/info/GPLv2.html) and Modified BSD (http://www.fsf.org/licensing/licenses/index_html#ModifiedBSD)
 */

define ( 'WPOPENID_PLUGIN_REVISION', preg_replace( '/\$Rev: (.+) \$/', 'svn-\\1',
	'$Rev$') ); // this needs to be on a separate line so that svn:keywords can work its magic

define ( 'WPOPENID_DB_REVISION', 24426);      // last plugin revision that required database schema changes


define ( 'WPOPENID_LOG_LEVEL', 'warning');     // valid values are debug, info, notice, warning, err, crit, alert, emerg

set_include_path( dirname(__FILE__) . PATH_SEPARATOR . get_include_path() );   // Add plugin directory to include path temporarily

require_once('logic.php');
require_once('interface.php');
require_once('comments.php');
require_once('wp-login.php');


@include_once('Log.php');                   // Try loading PEAR_Log from normal include_path.
if (!class_exists('Log')) {                 // If we can't find it, include the copy of
	require_once('OpenIDLog.php');          // PEAR_Log bundled with the plugin
}

restore_include_path();

@session_start();

if  (!class_exists('WordPressOpenID')):
class WordPressOpenID {
	var $store;
	var $consumer;

	var $log;
	var $status = array();

	var $message;	  // Message to be displayed to the user.
	var $action;	  // Internal action tag. 'success', 'warning', 'error', 'redirect'.

	var $response;

	var $enabled = true;

	var $bind_done = false;

	
	function WordPressOpenID() {
		$this->log = &Log::singleton('error_log', PEAR_LOG_TYPE_SYSTEM, 'OpenID');
		//$this->log = &Log::singleton('file', ABSPATH . get_option('upload_path') . '/php.log', 'WPOpenID');

		// Set the log level
		$wpopenid_log_level = constant('PEAR_LOG_' . strtoupper(WPOPENID_LOG_LEVEL));
		$this->log->setMask(Log::UPTO($wpopenid_log_level));
	}


	/**
	 * Set Status.
	 **/
	function setStatus($slug, $state, $message) {
		$this->status[$slug] = array('state'=>$state,'message'=>$message);
	}


	function textdomain() {
		$lang_folder = PLUGINDIR . '/openid/lang';
		load_plugin_textdomain('openid', $lang_folder);
	}

	function table_prefix() {
		global $wpdb;
		return isset($wpdb->base_prefix) ? $wpdb->base_prefix : $wpdb->prefix;
	}

	function associations_table_name() { return WordPressOpenID::table_prefix() . 'openid_associations'; }
	function nonces_table_name() { return WordPressOpenID::table_prefix() . 'openid_nonces'; }
	function identity_table_name() { return WordPressOpenID::table_prefix() . 'openid_identities'; }
	function comments_table_name() { return WordPressOpenID::table_prefix() . 'comments'; }
	function usermeta_table_name() { return WordPressOpenID::table_prefix() . 'usermeta'; }
}
endif;

if (!function_exists('openid_init')):
function openid_init() {
	if ($GLOBALS['openid'] && is_a($GLOBALS['openid'], 'WordPressOpenID')) {
		return;
	}
	
	$GLOBALS['openid'] = new WordPressOpenID();
}
endif;

// -- Register actions and filters -- //

register_activation_hook('openid/core.php', array('WordPressOpenID_Logic', 'activate_plugin'));
register_deactivation_hook('openid/core.php', array('WordPressOpenID_Logic', 'deactivate_plugin'));

add_action( 'admin_menu', array( 'WordPressOpenID_Interface', 'add_admin_panels' ) );

// Add hooks to handle actions in WordPress
add_action( 'init', array( 'WordPressOpenID_Logic', 'wp_login_openid' ) ); // openid loop done
add_action( 'init', array( 'WordPressOpenID', 'textdomain' ) ); // load textdomain


	
// include internal stylesheet
add_action( 'wp_head', array( 'WordPressOpenID_Interface', 'style'));


add_filter( 'init', array( 'WordPressOpenID_Interface', 'init_errors'));

// parse request
add_action('parse_request', array('WordPressOpenID_Logic', 'parse_request'));

// Add custom OpenID options
add_option( 'oid_enable_commentform', true );
add_option( 'oid_plugin_enabled', true );
add_option( 'oid_plugin_revision', 0 );
add_option( 'oid_db_revision', 0 );
add_option( 'oid_enable_approval', false );
add_option( 'oid_enable_email_mapping', false );

add_action( 'delete_user', array( 'WordPressOpenID_Logic', 'delete_user' ) );
add_action( 'cleanup_openid', array( 'WordPressOpenID_Logic', 'cleanup_nonces' ) );

add_action( 'personal_options_update', array( 'WordPressOpenID_Logic', 'personal_options_update' ) );

// hooks for getting user data
add_filter( 'openid_user_data', array('WordPressOpenID_Logic', 'get_user_data_form'), 10, 2);
add_filter( 'openid_user_data', array('WordPressOpenID_Logic', 'get_user_data_sreg'), 10, 2);

add_filter('xrds_simple', array('WordPressOpenID_Logic', 'xrds_simple'));

// ---------------------------------------------------------------------
// Exposed functions designed for use in templates, specifically inside
//   `foreach ($comments as $comment)` in comments.php
// ---------------------------------------------------------------------

/**
 * Get a simple OpenID input field, used for disabling unobtrusive mode.
 */
if(!function_exists('openid_input')):
function openid_input() {
	return '<input type="text" id="openid_url" name="openid_url" />';
}
endif;

function openid_style() {
	WordPressOpenID_Interface::style();
}

?>
