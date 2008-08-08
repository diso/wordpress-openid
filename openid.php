<?php
/*
 Plugin Name: OpenID
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

require_once('common.php');
require_once('admin_panels.php');
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

}
endif;

?>
