<?php
/**
 * Implement a few of the functions available only in recent versions of 
 * WordPress.  I'd much rather reimplement these functions here, and keep the 
 * rest of the plugin code clean.
 */


/* since 2.6 */
if (!function_exists('site_url')):
function site_url($path = '', $scheme = null) {
	$url =  get_option('siteurl');
	if ( !empty($path) && is_string($path) && strpos($path, '..') === false ) {
		$url .= '/' . ltrim($path, '/');
	}
	return $url;
}
endif;


/* since 2.6 */
if (!function_exists('admin_url')):
function admin_url($path = '') {
	$url = site_url('wp-admin/', 'admin');
	if ( !empty($path) && is_string($path) && strpos($path, '..') === false ) {
		$url .= ltrim($path, '/');
	}
	return $url;
}
endif;

/* since 2.6 */
if (!function_exists('plugins_url')):
function plugins_url($path = '') {
	$url = site_url(PLUGINDIR);
	if ( !empty($path) && is_string($path) && strpos($path, '..') === false ) {
		$url .= '/' . ltrim($path, '/');
	}
	return $url;
}
endif;

/* since 2.5 */
if (!function_exists('is_front_page')):
function is_front_page() {
	return is_home();
}
endif;

?>
