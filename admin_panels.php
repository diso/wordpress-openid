<?php
/**
 * All the code required for handling OpenID comments.  These functions should not be considered public, 
 * and may change without notice.
 */


// -- WordPress Hooks
add_action( 'admin_menu', 'openid_admin_panels' );
add_action( 'personal_options_update', 'openid_personal_options_update' );

/**
 * Enqueue required javascript libraries.
 *
 * @action: init
 **/
function openid_js_setup() {
	if (is_single() || is_comments_popup() || is_admin()) {
		wp_enqueue_script( 'jquery' );
		wp_enqueue_script('jquery.textnode', '/' . PLUGINDIR . '/openid/files/jquery.textnode.min.js', 
			array('jquery'), WPOPENID_PLUGIN_REVISION);
		wp_enqueue_script('jquery.xpath', '/' . PLUGINDIR . '/openid/files/jquery.xpath.min.js', 
			array('jquery'), WPOPENID_PLUGIN_REVISION);
		wp_enqueue_script('openid', '/' . PLUGINDIR . '/openid/files/openid.min.js', 
			array('jquery','jquery.textnode'), WPOPENID_PLUGIN_REVISION);
	}
}


/**
 * Include internal stylesheet.
 *
 * @action: wp_head, login_head
 **/
function openid_style() {
	$css_path = get_option('siteurl') . '/' . PLUGINDIR . '/openid/files/openid.css?ver='.WPOPENID_PLUGIN_REVISION;
	echo '
		<link rel="stylesheet" type="text/css" href="'.$css_path.'" />';
}




/**
 * Spam up the admin interface with warnings.
 **/
function openid_admin_notices_plugin_problem_warning() {
	echo'<div class="error"><p><strong>'.__('The WordPress OpenID plugin is not active.', 'openid').'</strong>';
	printf(_('Check %sOpenID Options%s for a full diagnositic report.', 'openid'), '<a href="options-general.php?page=global-openid-options">', '</a>');
	echo '</p></div>';
}


/**
 * Setup admin menus for OpenID options and ID management.
 *
 * @action: admin_menu
 **/
function openid_admin_panels() {
	$hookname = add_options_page(__('OpenID options', 'openid'), __('OpenID', 'openid'), 8, 'global-openid-options', 'openid_options_page' );
	add_action("load-$hookname", 'openid_js_setup' );
	add_action("admin_head-$hookname", 'openid_style' );

	$hookname =	add_submenu_page('profile.php', __('Your Identity URLs', 'openid'), __('Your Identity URLs', 'openid'), 
		'read', 'openid', 'openid_profile_panel' );
	add_action("admin_head-$hookname", 'openid_style' );
	add_action("load-$hookname", 'openid_profile_management' );
}


/*
 * Display and handle updates from the Admin screen options page.
 *
 * @options_page
 */
function openid_options_page() {
	global $wp_version;
	$openid = openid_init();

		openid_late_bind();
	
		if ( isset($_REQUEST['action']) ) {
			switch($_REQUEST['action']) {
				case 'rebuild_tables' :
					check_admin_referer('wp-openid-info_rebuild_tables');
					$openid->store->destroy_tables();
					$openid->store->create_tables();
					echo '<div class="updated"><p><strong>'.__('OpenID tables rebuilt.', 'openid').'</strong></p></div>';
					break;
			}
		}

		// if we're posted back an update, let's set the values here
		if ( isset($_POST['info_update']) ) {
		
			check_admin_referer('wp-openid-info_update');

			$error = '';
			
			update_option( 'oid_enable_commentform', isset($_POST['enable_commentform']) ? true : false );
			update_option( 'oid_enable_approval', isset($_POST['enable_approval']) ? true : false );
			update_option( 'oid_enable_email_mapping', isset($_POST['enable_email_mapping']) ? true : false );

			if ($error !== '') {
				echo '<div class="error"><p><strong>'.__('At least one of OpenID options was NOT updated', 'openid').'</strong>'.$error.'</p></div>';
			} else {
				echo '<div class="updated"><p><strong>'.__('Open ID options updated', 'openid').'</strong></p></div>';
			}
			
		}

		
		// Display the options page form
		$siteurl = get_option('home');
		if( substr( $siteurl, -1, 1 ) !== '/' ) $siteurl .= '/';
		?>
		<div class="wrap">
			<h2><?php _e('WP-OpenID Registration Options', 'openid') ?></h2>

			<?php if ($wp_version >= '2.3') { openid_printSystemStatus(); } ?>

			<form method="post">

				<?php if ($wp_version < '2.3') { ?>
				<p class="submit"><input type="submit" name="info_update" value="<?php _e('Update Options') ?> &raquo;" /></p>
				<?php } ?>

				<table class="form-table optiontable editform" cellspacing="2" cellpadding="5" width="100%">
					<tr valign="top">
						<th style="width: 33%" scope="row"><?php _e('Automatic Approval:', 'openid') ?></th>
						<td>
							<p><input type="checkbox" name="enable_approval" id="enable_approval" <?php 
								echo get_option('oid_enable_approval') ? 'checked="checked"' : ''; ?> />
								<label for="enable_approval"><?php _e('Enable OpenID comment auto-approval', 'openid') ?></label>

							<p><?php _e('For now this option will cause comments made with OpenIDs '
							. 'to be automatically approved.  Since most spammers haven\'t started '
							. 'using OpenID yet, this is probably pretty safe.  More importantly '
							. 'however, this could be a foundation on which to build more advanced '
							. 'automatic approval such as whitelists or a third-party trust service.', 'openid') ?>
							</p>

							<p><?php _e('Note that this option will cause OpenID authenticated comments '
							. 'to appear, even if you have enabled the option, "An administrator must '
							. 'always approve the comment".', 'openid') ?></p>
							
						</td>
					</tr>

					<tr valign="top">
						<th style="width: 33%" scope="row"><?php _e('Comment Form:', 'openid') ?></th>
						<td>
							<p><input type="checkbox" name="enable_commentform" id="enable_commentform" <?php
							if( get_option('oid_enable_commentform') ) echo 'checked="checked"'
							?> />
								<label for="enable_commentform"><?php _e('Add OpenID text to the WordPress post comment form.', 'openid') ?></label></p>

							<p><?php printf(__('This will work for most themes derived from Kubrick or Sandbox.  '
							. 'Template authors can tweak the comment form as described in the %sreadme%s.', 'openid'), 
							'<a href="'. get_option('siteurl') . '/' . PLUGINDIR . '/openid/readme.txt">', '</a>') ?></p>
							<br />
						</td>
					</tr>

					<?php /*
					<tr valign="top">
						<th style="width: 33%" scope="row"><?php _e('Email Mapping:', 'openid') ?></th>
						<td>
							<p><input type="checkbox" name="enable_email_mapping" id="enable_email_mapping" <?php
							if( get_option('oid_enable_email_mapping') ) echo 'checked="checked"'
							?> />
								<label for="enable_email_mapping"><?php _e('Enable email addresses to be mapped to OpenID URLs.', 'openid') ?></label></p>

							<p><?php printf(__('This feature uses the Email-To-URL mapping specification to allow OpenID authentication'
							. ' based on an email address.  If enabled, commentors who do not supply a valid OpenID URL will have their'
							. ' supplied email address mapped to an OpenID.  If their email provider does not currently support email to'
							. ' url mapping, the default provider %s will be used.', 'openid'), '<a href="http://emailtoid.net/" target="_blank">Emailtoid.net</a>') ?></p>
							<br />
						</td>
					</tr>
					*/ ?>

				</table>

				<p><?php printf(__('Occasionally, the WP-OpenID tables don\'t get setup properly, and it may help '
					. 'to %srebuild the tables%s.  Don\'t worry, this won\'t cause you to lose any data... it just '
					. 'rebuilds a couple of tables that hold only temporary data.', 'openid'), 
				'<a href="'.wp_nonce_url(sprintf('?page=%s&action=rebuild_tables', $_REQUEST['page']), 'wp-openid-info_rebuild_tables').'">', '</a>') ?></p>

				<?php wp_nonce_field('wp-openid-info_update'); ?>
				<p class="submit"><input type="submit" name="info_update" value="<?php _e('Update Options') ?> &raquo;" /></p>
			</form>

		</div>
			<?php
		if ($wp_version < '2.3') {
			echo '<br />';
			openid_printSystemStatus();
		}
} // end function options_page


/**
 * Handle user management of OpenID associations.
 *
 * @submenu_page: profile.php
 **/
function openid_profile_panel() {
	global $error;
	$openid = openid_init();

	if( !current_user_can('read') ) {
		return;
	}
	$user = wp_get_current_user();

	openid_late_bind();

	if (!$openid->action && $_SESSION['oid_action']) {
		$openid->action = $_SESSION['oid_action'];
		unset($_SESSION['oid_action']);
	}

	if (!$openid->message && $_SESSION['oid_message']) {
		$openid->message = $_SESSION['oid_message'];
		unset($_SESSION['oid_message']);
	}

	if( 'success' == $openid->action ) {
		echo '<div class="updated"><p><strong>'.__('Success:', 'openid').'</strong> '.$openid->message.'</p></div>';
	}
	elseif( 'warning' == $openid->action ) {
		echo '<div class="error"><p><strong>'.__('Warning:', 'openid').'</strong> '.$openid->message.'</p></div>';
	}
	elseif( 'error' == $openid->action ) {
		echo '<div class="error"><p><strong>'.__('Error:', 'openid').'</strong> '.$openid->message.'</p></div>';
	}

	if (!empty($error)) {
		echo '<div class="error"><p><strong>'.__('Error:', 'openid').'</strong> '.$error.'</p></div>';
		unset($error);
	}


	?>

	<div class="wrap">
		<h2><?php _e('Your Identity URLs', 'openid') ?></h2>

		<p><?php printf(__('The following Identity URLs %s are tied to this user account. You can login '
		. 'with equivalent permissions using any of the following identities.', 'openid'), 
		'<a title="'.__('What is OpenID?', 'openid').'" href="http://openid.net/">'.__('?', 'openid').'</a>') ?>
		</p>
	<?php
	
	$urls = $openid->store->get_identities($user->ID);

	if( count($urls) ) : ?>
		<p>There are <?php echo count($urls); ?> identities associated with this WordPress user.</p>

		<table class="widefat">
		<thead>
			<tr>
				<th scope="col" style="text-align: center"><?php _e('ID', 'openid') ?></th>
				<th scope="col"><?php _e('Identity Url', 'openid') ?></th>
				<th scope="col" style="text-align: center"><?php _e('Action', 'openid') ?></th>
			</tr>
		</thead>

		<?php foreach( $urls as $k=>$v ): ?>

			<tr class="alternate">
				<th scope="row" style="text-align: center"><?php echo $v['uurl_id']; ?></th>
				<td><a href="<?php echo $v['url']; ?>"><?php echo $v['url']; ?></a></td>
				<td style="text-align: center"><a class="delete" href="<?php 
				echo wp_nonce_url(sprintf('?page=%s&action=drop_identity&id=%s', 'openid', $v['uurl_id']), 
				'wp-openid-drop-identity_'.$v['url']);
				?>"><?php _e('Delete', 'openid') ?></a></td>
			</tr>

		<?php endforeach; ?>

		</table>

		<?php
	else:
		echo '
		<p class="error">'.__('There are no OpenIDs associated with this WordPress user.', 'openid').'</p>';
	endif; ?>

	<p>
		<form method="post"><?php _e('Add identity:', 'openid') ?>
			<?php wp_nonce_field('wp-openid-add_identity'); ?>
			<input id="openid_url" name="openid_url" /> 
			<input type="submit" value="<?php _e('Add', 'openid') ?>" />
			<input type="hidden" name="action" value="add_identity" >
		</form>
	</p>
	</div>
	<?php
}


/**
 * Print the status of various system libraries.  This is displayed on the main OpenID options page.
 **/
function openid_printSystemStatus() {
	global $wp_version, $wpdb;
	$openid = openid_init();

	$paths = explode(PATH_SEPARATOR, get_include_path());
	for($i=0; $i<sizeof($paths); $i++ ) { 
		$paths[$i] = realpath($paths[$i]); 
	}
	
	$openid->setStatus( 'PHP version', 'info', phpversion() );
	$openid->setStatus( 'PHP memory limit', 'info', ini_get('memory_limit') );
	$openid->setStatus( 'Include Path', 'info', $paths );
	
	$openid->setStatus( 'WordPress version', 'info', $wp_version );
	$openid->setStatus( 'MySQL version', 'info', function_exists('mysql_get_client_info') ? mysql_get_client_info() : 'Mysql client information not available. Very strange, as WordPress requires MySQL.' );

	$openid->setStatus('WordPress\' table prefix', 'info', isset($wpdb->base_prefix) ? $wpdb->base_prefix : $wpdb->prefix );
	
	
	if ( extension_loaded('suhosin') ) {
		$openid->setStatus( 'Curl', false, 'Hardened php (suhosin) extension active -- curl version checking skipped.' );
	} else {
		$curl_message = '';
		if( function_exists('curl_version') ) {
			$curl_version = curl_version();
			if(isset($curl_version['version']))  	
				$curl_message .= 'Version ' . $curl_version['version'] . '. ';
			if(isset($curl_version['ssl_version']))	
				$curl_message .= 'SSL: ' . $curl_version['ssl_version'] . '. ';
			if(isset($curl_message['libz_version']))
				$curl_message .= 'zlib: ' . $curl_version['libz_version'] . '. ';
			if(isset($curl_version['protocols'])) {
				if (is_array($curl_version['protocols'])) {
					$curl_message .= 'Supports: ' . implode(', ',$curl_version['protocols']) . '. ';
				} else {
					$curl_message .= 'Supports: ' . $curl_version['protocols'] . '. ';
				}
			}
		}
		$openid->setStatus( 'Curl Support', function_exists('curl_version'), function_exists('curl_version') ? $curl_message :
				'This PHP installation does not have support for libcurl. Some functionality, such as fetching https:// URLs, will be missing and performance will slightly impared. See <a href="http://www.php.net/manual/en/ref.curl.php">php.net/manual/en/ref.curl.php</a> about enabling libcurl support for PHP.');
	}

	if (extension_loaded('gmp') and @gmp_init(1)) {
		$openid->setStatus( 'Big Integer support', true, 'GMP is installed.' );
	} elseif (extension_loaded('bcmath') and @bcadd(1,1)==2) {
		$openid->setStatus( 'Big Integer support', true, 'BCMath is installed (though <a href="http://www.php.net/gmp">GMP</a> is preferred).' );
	} elseif (defined('Auth_OpenID_NO_MATH_SUPPORT')) {
		$openid->setStatus( 'Big Integer support', false, 'The OpenID Library is operating in Dumb Mode. Recommend installing <a href="http://www.php.net/gmp">GMP</a> support.' );
	}

	
	$openid->setStatus( 'Plugin Revision', 'info', WPOPENID_PLUGIN_REVISION);
	$openid->setStatus( 'Plugin Database Revision', 'info', get_option('oid_db_revision'));
	
	$openid->setStatus( '<strong>Overall Plugin Status</strong>', ($openid->enabled), 
		($openid->enabled ? '' : 'There are problems above that must be dealt with before the plugin can be used.') );


		
	if( $openid->enabled ) {	// Display status information
		echo'<div id="openid_rollup" class="updated">
		<p><strong>' . __('Status information:', 'openid') . '</strong> ' . __('All Systems Nominal', 'openid') 
		. '<small> (<a href="#" id="openid_rollup_link">' . __('Toggle More/Less', 'openid') . '</a>)</small> </p>';
	} else {
		echo '<div class="error"><p><strong>' . __('Plugin is currently disabled. Fix the problem, then Deactivate/Reactivate the plugin.', 'openid') 
		. '</strong></p>';
	}
	echo '<div>';
	foreach( $openid->status as $k=>$v ) {
		echo '<div><strong>';
		if( $v['state'] === false ) {
			echo "<span style='color:red;'>[".__('FAIL', 'openid')."]</span> $k";
		} elseif( $v['state'] === true ) {
			echo "<span style='color:green;'>[".__('OK', 'openid')."]</span> $k";
		} else {
			echo "<span style='color:grey;'>[".__('INFO', 'openid')."]</span> $k";
		}
		echo ($v['message'] ? ': ' : '') . '</strong>';
		echo (is_array($v['message']) ? '<ul><li>' . implode('</li><li>', $v['message']) . '</li></ul>' : $v['message']);
		echo '</div>';
	}
	echo '</div></div>';
}

function openid_repost($action, $parameters) {
	$html = '
	<noscript><p>Since your browser does not support JavaScript, you must press the Continue button once to proceed.</p></noscript>
	<form action="'.$action.'" method="post">';

	foreach ($parameters as $k => $v) {
		if ($k == 'submit') continue;
		$html .= "\n" . '<input type="hidden" name="'.$k.'" value="' . htmlspecialchars(stripslashes($v), ENT_COMPAT, get_option('blog_charset')) . '" />';
	}
	$html .= '
		<noscript><div><input type="submit" value="Continue" /></div></noscript>
	</form>
	
	<script type="text/javascript">
		document.write("<h2>Please Wait...</h2>"); 
		document.forms[0].submit()
	</script>';

	wp_die($html, 'OpenID Authentication Redirect');
}

function openid_init_errors() {
	global $error;
	$error = $_SESSION['oid_error'];
	unset($_SESSION['oid_error']);
}


/**
 * Handle OpenID profile management.
 */
function openid_profile_management() {
	global $wp_version;
   	$openid = openid_init();
	
	if( !isset( $_REQUEST['action'] )) return;
		
	$openid->action = $_REQUEST['action'];
		
	require_once(ABSPATH . 'wp-admin/admin-functions.php');

	if ($wp_version < '2.3') {
		require_once(ABSPATH . 'wp-admin/admin-db.php');
		require_once(ABSPATH . 'wp-admin/upgrade-functions.php');
	}

	auth_redirect();
	nocache_headers();
	get_currentuserinfo();

	if( !openid_late_bind() ) return; // something is broken
		
	switch( $openid->action ) {
		case 'add_identity':
			check_admin_referer('wp-openid-add_identity');

			$user = wp_get_current_user();

			$store =& openid_getStore();
			$auth_request = openid_begin_consumer($_POST['openid_url']);

			$userid = $store->get_user_by_identity($auth_request->endpoint->claimed_id);

			if ($userid) {
				global $error;
				if ($user->ID == $userid) {
					$error = 'You already have this Identity URL!';
				} else {
					$error = 'This Identity URL is already connected to another user.';
				}
				return;
			}

			openid_start_login($_POST['openid_url'], 'verify');
			break;

		case 'drop_identity':  // Remove a binding.
			openid_profile_drop_identity($_REQUEST['id']);
			break;
	}
}


/**
 * Remove identity URL from current user account.
 *
 * @param int $id id of identity URL to remove
 */
function openid_profile_drop_identity($id) {
	$openid = openid_init();

	$user = wp_get_current_user();

	if( !isset($id)) {
		$openid->message = 'Identity url delete failed: ID paramater missing.';
		$openid->action = 'error';
		return;
	}

	$store =& openid_getStore();
	$deleted_identity_url = $store->get_identities($user->ID, $id);
	if( FALSE === $deleted_identity_url ) {
		$openid->message = 'Identity url delete failed: Specified identity does not exist.';
		$openid->action = 'error';
		return;
	}

	$identity_urls = $store->get_identities($user->ID);
	if (sizeof($identity_urls) == 1 && !$_REQUEST['confirm']) {
		$openid->message = 'This is your last identity URL.  Are you sure you want to delete it? Doing so may interfere with your ability to login.<br /><br /> '
		. '<a href="?confirm=true&'.$_SERVER['QUERY_STRING'].'">Yes I\'m sure.  Delete it</a>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;'
		. '<a href="?page=openid">No, don\'t delete it.</a>';
		$openid->action = 'warning';
		return;
	}

	check_admin_referer('wp-openid-drop-identity_'.$deleted_identity_url);
		

	if( $store->drop_identity($user->ID, $id) ) {
		$openid->message = 'Identity url delete successful. <b>' . $deleted_identity_url
		. '</b> removed.';
		$openid->action = 'success';

		// ensure that profile URL is still a verified Identity URL
		set_include_path( dirname(__FILE__) . PATH_SEPARATOR . get_include_path() );
		require_once 'Auth/OpenID.php';
		if ($GLOBALS['wp_version'] >= '2.3') {
			require_once(ABSPATH . 'wp-admin/includes/admin.php');
		} else {
			require_once(ABSPATH . WPINC . '/registration.php');
		}
		$identities = $store->get_identities($user->ID);
		$current_url = Auth_OpenID::normalizeUrl($user->user_url);

		$verified_url = false;
		if (!empty($identities)) {
			foreach ($identities as $id) {
				if ($id['url'] == $current_url) {
					$verified_url = true;
					break;
				}
			}

			if (!$verified_url) {
				$user->user_url = $identities[0]['url'];
				wp_update_user( get_object_vars( $user ));
				$openid->message .= '<br /><strong>Note:</strong> For security reasons, your profile URL has been updated to match your Identity URL.';
			}
		}
		return;
	}
		
	$openid->message = 'Identity url delete failed: Unknown reason.';
	$openid->action = 'error';
}


/**
 * Action method for completing the 'verify' action.  This action is used adding an identity URL to a
 * WordPress user through the admin interface.
 *
 * @param string $identity_url verified OpenID URL
 */
function _finish_openid_verify($identity_url) {
	$openid = openid_init();

	$user = wp_get_current_user();
	if (empty($identity_url)) {
		openid_set_error('Unable to authenticate OpenID.');
	} else {
		$store =& openid_getStore();
		if( !$store->insert_identity($user->ID, $identity_url) ) {
			openid_set_error('OpenID assertion successful, but this URL is already claimed by '
			. 'another user on this blog. This is probably a bug. ' . $identity_url);
		} else {
			$openid->action = 'success';
			$openid->message = "Successfully added Identity URL: $identity_url.";
			
			// ensure that profile URL is a verified Identity URL
			set_include_path( dirname(__FILE__) . PATH_SEPARATOR . get_include_path() );
			require_once 'Auth/OpenID.php';
			if ($GLOBALS['wp_version'] >= '2.3') {
				require_once(ABSPATH . 'wp-admin/includes/admin.php');
			} else {
				require_once(ABSPATH . WPINC . '/registration.php');
			}
			$identities = $store->get_identities($user->ID);
			$current_url = Auth_OpenID::normalizeUrl($user->user_url);

			$verified_url = false;
			if (!empty($identities)) {
				foreach ($identities as $id) {
					if ($id['url'] == $current_url) {
						$verified_url = true;
						break;
					}
				}

				if (!$verified_url) {
					$user->user_url = $identity_url;
					wp_update_user( get_object_vars( $user ));
					$openid->message .= '<br /><strong>Note:</strong> For security reasons, your profile URL has been updated to match your Identity URL.';
				}
			}
		}
	}

	$_SESSION['oid_message'] = $openid->message;
	$_SESSION['oid_action'] = $openid->action;	
	$wpp = parse_url(get_option('siteurl'));
	$redirect_to = $wpp['path'] . '/wp-admin/' . (current_user_can('edit_users') ? 'users.php' : 'profile.php') . '?page=openid';
	if (function_exists('wp_safe_redirect')) {
		wp_safe_redirect( $redirect_to );
	} else {
		wp_redirect( $redirect_to );
	}
	exit;
}


/**
 * hook in and call when user is updating their profile URL... make sure it is an OpenID they control.
 */
function openid_personal_options_update() {
	set_include_path( dirname(__FILE__) . PATH_SEPARATOR . get_include_path() );
	require_once 'Auth/OpenID.php';
	$claimed = Auth_OpenID::normalizeUrl($_POST['url']);

	$user = wp_get_current_user();

	openid_init();
	$store =& openid_getStore();
	$identities = $store->get_identities($user->ID);

	if (!empty($identities)) {
		$urls = array();
		foreach ($identities as $id) {
			if ($id['url'] == $claimed) {
				return; 
			} else {
				$urls[] = $id['url'];
			}
		}

		wp_die('For security reasons, your profile URL must be one of your claimed '
		   . 'Identity URLs: <ul><li>' . join('</li><li>', $urls) . '</li></ul>');
	}
}


?>
