<?php
/**
 * All the code required for handling OpenID comments.  These functions should not be considered public, 
 * and may change without notice.
 */


// -- WordPress Hooks
add_action( 'admin_menu', 'openid_admin_panels' );
add_action( 'personal_options_update', 'openid_personal_options_update' );
add_action( 'openid_finish_auth', 'openid_finish_verify' );




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
	global $wp_version, $wpdb;

	if ( isset($_REQUEST['action']) ) {
		switch($_REQUEST['action']) {
			case 'rebuild_tables' :
				check_admin_referer('wp-openid-info_rebuild_tables');
				$store = openid_getStore();
				$store->reset();
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
		update_option( 'force_openid_registration', isset($_POST['force_openid_registration']) ? true : false );
		update_option( 'openid_blog_owner', $_POST['openid_blog_owner']);

		if ($error !== '') {
			echo '<div class="error"><p><strong>'.__('At least one of OpenID options was NOT updated', 'openid').'</strong>'.$error.'</p></div>';
		} else {
			echo '<div class="updated"><p><strong>'.__('OpenID options updated', 'openid').'</strong></p></div>';
		}
		
	}

	
	// Display the options page form
	$siteurl = get_option('home');
	if( substr( $siteurl, -1, 1 ) !== '/' ) $siteurl .= '/';
	?>
	<div class="wrap">
		<form method="post">

			<h2><?php _e('OpenID Consumer Options', 'openid') ?></h2>

			<?php if ($wp_version >= '2.3') { openid_printSystemStatus(); } ?>

			<?php if ($wp_version < '2.3') { ?>
			<p class="submit"><input type="submit" name="info_update" value="<?php _e('Update Options') ?> &raquo;" /></p>
			<?php } ?>

			<table class="form-table optiontable editform" cellspacing="2" cellpadding="5" width="100%">
				<tr valign="top">
					<th scope="row"><?php _e('Automatic Approval:', 'openid') ?></th>
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
					<th scope="row"><?php _e('Comment Form:', 'openid') ?></th>
					<td>
						<p><input type="checkbox" name="enable_commentform" id="enable_commentform" <?php
						if( get_option('oid_enable_commentform') ) echo 'checked="checked"'
						?> />
							<label for="enable_commentform"><?php _e('Add OpenID text to the WordPress post comment form.', 'openid') ?></label></p>

						<p><?php printf(__('This will work for most themes derived from Kubrick or Sandbox.  '
						. 'Template authors can tweak the comment form as described in the %sreadme%s.', 'openid'), 
						'<a href="'.clean_url(openid_plugin_url().'/readme.txt').'">', '</a>') ?></p>
						<br />
					</td>
				</tr>

				<?php if (get_option('users_can_register')): ?>
				<tr valign="top">
					<th scope="row"><?php _e('Force OpenID Registration:', 'openid') ?></th>
					<td>
						<p><input type="checkbox" name="force_openid_registration" id="force_openid_registration" <?php
						if( get_option('force_openid_registration') ) echo 'checked="checked"'
						?> />
							<label for="force_openid_registration"><?php _e('Force use of OpenID for new account registration.', 'openid') ?></label></p>
					</td>
				</tr>
				<?php endif; ?>

				<?php /*
				<tr valign="top">
					<th scope="row"><?php _e('Email Mapping:', 'openid') ?></th>
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

			<h2><?php _e('OpenID Provider Options', 'openid') ?></h2>
			<?php 
				$current_user = wp_get_current_user(); 
				$current_user_url = get_author_posts_url($current_user->ID);
			?>

			<table class="form-table optiontable editform" cellspacing="2" cellpadding="5" width="100%">
				<tr valign="top">
					<th scope="row"><?php _e('Blog Owner:', 'openid') ?></th>
					<td>

						<p>Users on this blog can use their author URL (ie. 
						<em><?php printf('<a href="%1$s">%1$s</a>', $current_user_url); ?></em>) as an 
						OpenID, either using the local OpenID server, or delegating to another provider.  
						The user designated as the "Blog Owner" will also be able to use
						the blog root (<?php printf('<a href="%1$s">%1$s</a>', trailingslashit(get_option('home'))); ?>), 
						as their OpenID.  If this is a single-user blog, you should set this to your main account.</p>

						<p>If no blog owner is selected, then any user may use the blog root to initiate OpenID 
						authentication and OP-driven identity selection will be used.</p>

			<?php 
				if (defined('OPENID_DISALLOW_OWNER') && OPENID_DISALLOW_OWNER) {
					echo '
						<p class="error">
							A blog owner cannot be set for this WordPress blog.  To enable setting a blog owner, remove the follwoing line from your <code>wp-config.php</code>:<br />
							<code style="margin:1em;">define("OPENID_DISALLOW_OWNER", 1);</code>
						</p>';
				} else {
					$blog_owner = get_option('openid_blog_owner');

					if (empty($blog_owner) || $blog_owner == $current_user->user_login) {
						echo '<select id="openid_blog_owner" name="openid_blog_owner"><option value="">(none)</option>';

						$users = $wpdb->get_results("SELECT user_login FROM $wpdb->users ORDER BY user_login");
						foreach($users as $user) { 
							$selected = (get_option('openid_blog_owner') == $user->user_login) ? ' selected="selected"' : '';
							echo '<option value="'.$user->user_login.'"'.$selected.'>'.$user->user_login.'</option>';
						}
						echo '</select>';

					} else {
						echo '<p class="error">Only the current blog owner ('.$blog_owner.') can set another user as the owner.</p>';
					}
				} 
			?>

						</td>
					</tr>
				</table>

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
	$status = openid_status();

	if( !current_user_can('read') ) {
		return;
	}
	$user = wp_get_current_user();

	if( 'success' == $status ) {
		echo '<div class="updated"><p><strong>'.__('Success:', 'openid').'</strong> '.openid_message().'</p></div>';
	}
	elseif( 'warning' == $status ) {
		echo '<div class="error"><p><strong>'.__('Warning:', 'openid').'</strong> '.openid_message().'</p></div>';
	}
	elseif( 'error' == $status ) {
		echo '<div class="error"><p><strong>'.__('Error:', 'openid').'</strong> '.openid_message().'</p></div>';
	}

	if (!empty($error)) {
		echo '<div class="error"><p><strong>'.__('Error:', 'openid').'</strong> '.$error.'</p></div>';
		unset($error);
	}


	?>

	<div class="wrap">
		<h2><?php _e('Your Identity URLs', 'openid') ?></h2>

		<p><?php printf(__('The following Identity URLs %s are tied to this user account. '
		. 'You may use any of them to login to this account.' , 'openid'), 
		'<a title="'.__('What is OpenID?', 'openid').'" href="http://openid.net/">'.__('?', 'openid').'</a>') ?>
		</p>
	<?php
	
	$urls = get_user_openids($user->ID);

	if( count($urls) ) : ?>
		<p>There are <?php echo count($urls); ?> identities associated with this WordPress user.</p>

		<table class="widefat">
		<thead>
			<tr>
				<th scope="col"><?php _e('Identity Url', 'openid') ?></th>
				<th scope="col" style="text-align: center"><?php _e('Action', 'openid') ?></th>
			</tr>
		</thead>

		<?php for($i=0; $i<sizeof($urls); $i++): ?>

			<tr class="<?php _e($i%2==0 ? 'alternate' : '') ?>">
				<td><a href="<?php echo $urls[$i]; ?>"><?php echo openid_display_identity($urls[$i]); ?></a></td>
				<td style="text-align: center"><a class="delete" href="<?php 
				echo wp_nonce_url(sprintf('?page=%s&action=drop_identity&url=%s', 'openid', urlencode($urls[$i])), 
				'wp-openid-drop-identity_'.$urls[$i]);
				?>"><?php _e('Delete', 'openid') ?></a></td>
			</tr>

		<?php endfor; ?>

		</table>

		<?php
	else:
		echo '
		<p><strong>'.__('There are no OpenIDs associated with this WordPress user.', 'openid').'</strong></p>';
	endif; ?>

		<h3><?php _e('Add Identity', 'openid') ?></h3>
		<form method="post">
		<table class="form-table">
			<tr>
				<th scope="row"><label for="openid_identifier"><?php _e('Identity URL', 'openid') ?></label></th>
				<td><input id="openid_identifier" name="openid_identifier" /></td>
			</tr>
		</table>
		<?php wp_nonce_field('wp-openid-add_identity'); ?>
		<p class="submit">
			<input type="submit" value="<?php _e('Add Identity', 'openid') ?>" />
			<input type="hidden" name="action" value="add_identity" >
		</p>
		</form>


		<br class="clear" />
		<h2><?php _e('Local OpenID', 'openid') ?></h2>

		<form method="post">
		<table class="form-table optiontable editform" cellspacing="2" cellpadding="5" width="100%">
			<tr valign="top">
				<th scope="row"><?php _e('Local OpenID:', 'openid') ?></th>
				<td>

				<p>You may optionally use your author URL (<?php printf('<a 
				href="%1$s">%1$s</a>', get_author_posts_url($user->ID)); ?>) as an OpenID using 
				your local WordPress username and password, or may delegate to another 
				provider.</p>

			<?php
				$use_openid_provider = get_usermeta($user->ID, 'use_openid_provider');
			?>
				<p><input type="radio" name="use_openid_provider" id="no_provider" value="none" <?php echo ($use_openid_provider == 'none' || empty($use_openid_provider)) ? 'checked="checked"' : ''; ?>><label for="no_provider">Don't use local OpenID</label></p>
				<p><input type="radio" name="use_openid_provider" id="use_local_provider" value="local" <?php echo $use_openid_provider == 'local' ? 'checked="checked"' : ''; ?>><label for="use_local_provider">Use local OpenID Provider</label></p>
				<p><input type="radio" name="use_openid_provider" id="delegate_provider" value="delegate" <?php echo $use_openid_provider == 'delegate' ? 'checked="checked"' : ''; ?>><label for="delegate_provider">Delegate to another OpenID</label>
					<div id="delegate_info" style="margin-left: 2em;">
						<p><input type="text" id="openid_delegate" name="openid_delegate" class="openid_link" value="<?php echo get_usermeta($user->ID, 'openid_delegate') ?>" size="30" /></p>
					</div>
				</p>
				</td>
			</tr>
		</table>

		<?php wp_nonce_field('wp-openid-update_options'); ?>
		<input type="hidden" name="action" value="update" />
		<p class="submit"><input type="submit" value="<?php _e('Update Options') ?> &raquo;" /></p>
		</form>


		<br class="clear" />
		<h2><?php _e('Your Trusted Sites', 'openid') ?></h2>

		<p><?php _e(' OpenID allows you to log in to other sites that support the OpenID standard.  
		If a site is on your trusted sites list, you will not be asked if you trust that site when you 
		attempt to log in to it.', 'openid'); ?></p>
		
	<?php
	
	$urls = get_usermeta($user-ID, 'openid_trusted_sites');
	if (!is_array($urls)) {
		$urls = array();
	}

	if( count($urls) ) : ?>
		<p>You have <?php echo count($urls); ?> trusted sites.</p>

		<table class="widefat">
		<thead>
			<tr>
				<th scope="col"><?php _e('URL', 'openid') ?></th>
				<th scope="col" style="text-align: center"><?php _e('Action', 'openid') ?></th>
			</tr>
		</thead>

		<?php foreach( $urls as $url ): ?>

			<tr class="alternate">
				<td><a href="<?php echo $url; ?>"><?php echo $url; ?></a></td>
				<td style="text-align: center"><a class="delete" href="<?php 
				echo wp_nonce_url(sprintf('?page=%s&action=drop_trusted_site&url=%s', 'openid', $url), 
				'wp-openid-drop_trusted_site_'.$url);
				?>"><?php _e('Delete', 'openid') ?></a></td>
			</tr>

		<?php endforeach; ?>

		</table>

		<?php
	else:
		echo '
		<p><strong>'.__('You have no trusted sites.', 'openid').'</strong></p>';
	endif; ?>

		<h3><?php _e('Add Trusted Site', 'openid') ?></h3>
		<form method="post">
		<table class="form-table">
			<tr>
				<th scope="row"><label for="url"><?php _e('Site URL', 'openid') ?></label></th>
				<td><input id="url" name="url" /></td>
			</tr>
		</table>
		<?php wp_nonce_field('wp-openid-add_trusted_site'); ?>
		<p class="submit">
			<input type="submit" value="<?php _e('Add Site', 'openid') ?>" />
			<input type="hidden" name="action" value="add_trusted_site" >
		</p>
		</form>
	
	</div>

	<script type="text/javascript">
	jQuery(function() {
		<?php if ($use_openid_provider != 'delegate') echo 'jQuery(\'#delegate_info\').hide();'; ?>

		jQuery('#no_provider').change(function() { jQuery('#delegate_info').hide(); });
		jQuery('#use_local_provider').change(function() { jQuery('#delegate_info').hide(); });
		jQuery('#delegate_provider').change(function() { jQuery('#delegate_info').show(); });
	});
	</script>
	<?php
}


/**
 * Print the status of various system libraries.  This is displayed on the main OpenID options page.
 **/
function openid_printSystemStatus() {
	global $wp_version, $wpdb;

	$paths = explode(PATH_SEPARATOR, get_include_path());
	for($i=0; $i<sizeof($paths); $i++ ) { 
		$paths[$i] = realpath($paths[$i]); 
	}
	
	$status = array();
	$status[] = array( 'PHP version', 'info', phpversion() );
	$status[] = array( 'PHP memory limit', 'info', ini_get('memory_limit') );
	$status[] = array( 'Include Path', 'info', $paths );
	
	$status[] = array( 'WordPress version', 'info', $wp_version );
	$status[] = array( 'MySQL version', 'info', function_exists('mysql_get_client_info') ? mysql_get_client_info() : 'Mysql client information not available. Very strange, as WordPress requires MySQL.' );

	$status[] = array('WordPress\' table prefix', 'info', isset($wpdb->base_prefix) ? $wpdb->base_prefix : $wpdb->prefix );
	
	
	if ( extension_loaded('suhosin') ) {
		$status[] = array( 'Curl', false, 'Hardened php (suhosin) extension active -- curl version checking skipped.' );
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
		$status[] = array( 'Curl Support', function_exists('curl_version'), function_exists('curl_version') ? $curl_message :
				'This PHP installation does not have support for libcurl. Some functionality, such as fetching https:// URLs, will be missing and performance will slightly impared. See <a href="http://www.php.net/manual/en/ref.curl.php">php.net/manual/en/ref.curl.php</a> about enabling libcurl support for PHP.');
	}

	if (extension_loaded('gmp') and @gmp_init(1)) {
		$status[] = array( 'Big Integer support', true, 'GMP is installed.' );
	} elseif (extension_loaded('bcmath') and @bcadd(1,1)==2) {
		$status[] = array( 'Big Integer support', true, 'BCMath is installed (though <a href="http://www.php.net/gmp">GMP</a> is preferred).' );
	} elseif (defined('Auth_OpenID_NO_MATH_SUPPORT')) {
		$status[] = array( 'Big Integer support', false, 'The OpenID Library is operating in Dumb Mode. Recommend installing <a href="http://www.php.net/gmp">GMP</a> support.' );
	}

	
	$status[] = array( 'Plugin Revision', 'info', WPOPENID_PLUGIN_REVISION);
	$status[] = array( 'Plugin Database Revision', 'info', get_option('oid_db_revision'));

	if (function_exists('xrds_meta')) {
		$status[] = array( 'XRDS-Simple', 'info', 'XRDS-Simple plugin is installed.');
	} else {
		$status[] = array( 'XRDS-Simple', false, '<a href="http://diso.googlecode.com/svn/wordpress/wp-xrds-simple/branches/refactoring/">XRDS-Simple</a> plugin is not installed.  Some features may not work properly (including providing OpenIDs).');
	}
	
	$openid_enabled = openid_enabled();
	$status[] = array( '<strong>Overall Plugin Status</strong>', ($openid_enabled), 
		($openid_enabled ? '' : 'There are problems above that must be dealt with before the plugin can be used.') );

	if( $openid_enabled ) {	// Display status information
		echo'<div id="openid_rollup" class="updated">
		<p><strong>' . __('Status information:', 'openid') . '</strong> ' . __('All Systems Nominal', 'openid') 
		. '<small> (<a href="#" id="openid_rollup_link">' . __('Toggle More/Less', 'openid') . '</a>)</small> </p>';
	} else {
		echo '<div class="error"><p><strong>' . __('Plugin is currently disabled. Fix the problem, then Deactivate/Reactivate the plugin.', 'openid') 
		. '</strong></p>';
	}
	echo '<div>';
	foreach( $status as $s ) {
		list ($name, $state, $message) = $s;
		echo '<div><strong>';
		if( $state === false ) {
			echo "<span style='color:red;'>[".__('FAIL', 'openid')."]</span> $name";
		} elseif( $state === true ) {
			echo "<span style='color:green;'>[".__('OK', 'openid')."]</span> $name";
		} else {
			echo "<span style='color:grey;'>[".__('INFO', 'openid')."]</span> $name";
		}
		echo ($message ? ': ' : '') . '</strong>';
		echo (is_array($message) ? '<ul><li>' . implode('</li><li>', $message) . '</li></ul>' : $message);
		echo '</div>';
	}
	echo '</div></div>';
}


/**
 * Handle OpenID profile management.
 */
function openid_profile_management() {
	global $wp_version;
	
	if( !isset( $_REQUEST['action'] )) return;
		
	switch( $_REQUEST['action'] ) {
		case 'add_identity':
			check_admin_referer('wp-openid-add_identity');

			$user = wp_get_current_user();

			$auth_request = openid_begin_consumer($_POST['openid_identifier']);

			$userid = get_user_by_openid($auth_request->endpoint->claimed_id);

			if ($userid) {
				global $error;
				if ($user->ID == $userid) {
					$error = 'You already have this Identity URL!';
				} else {
					$error = 'This Identity URL is already connected to another user.';
				}
				return;
			}

			openid_start_login($_POST['openid_identifier'], 'verify');
			break;

		case 'drop_identity':  // Remove a binding.
			openid_profile_drop_identity($_REQUEST['url']);
			break;

		case 'update': // update information
			check_admin_referer('wp-openid-update_options');
			$user = wp_get_current_user();

			if ($_POST['use_openid_provider'] == 'delegate') {
				$delegate = Auth_OpenID::normalizeUrl($_POST['openid_delegate']);
				if(openid_server_update_delegation_info($user->ID, $delegate)) {
					openid_message('Successfully gathered OpenID information for delegate URL <strong>'.$delegate.'</strong>');
					openid_status('success');
				} else {
					openid_message('Unable to find any OpenID information for delegate URL <strong>'.$delegate.'</strong>');
					openid_status('error');
					break;
				}
			}

			update_usermeta($user->ID, 'use_openid_provider', $_POST['use_openid_provider']);
			break;

		case 'add_trusted_site':
			check_admin_referer('wp-openid-add_trusted_site');

			$user = wp_get_current_user();
			$trusted_sites = get_usermeta($user->ID, 'openid_trusted_sites');
			if (!is_array($trusted_sites)) {
				$trusted_sites = array();
			}
			$trusted_sites[] = $_REQUEST['url'];
			update_usermeta($user->ID, 'openid_trusted_sites', $trusted_sites);

			openid_message('Added trusted site: <b>' . $_REQUEST['url'] . '</b>.');
			openid_status('success');
			break;

		case 'drop_trusted_site':
			check_admin_referer('wp-openid-drop_trusted_site_' . $_REQUEST['url']);

			$user = wp_get_current_user();
			$trusted_sites = get_usermeta($user->ID, 'openid_trusted_sites');
			$new = array();
			foreach ($trusted_sites as $site) {
				if ($site != $_REQUEST['url']) {
					$new[] = $site;
				}
			}
			update_usermeta($user->ID, 'openid_trusted_sites', $new);

			openid_message('Removed trusted site: <b>' . $_REQUEST['url'] . '</b>.');
			openid_status('success');
			break;
	}
}


/**
 * Remove identity URL from current user account.
 *
 * @param int $id id of identity URL to remove
 */
function openid_profile_drop_identity($id) {

	$user = wp_get_current_user();

	if( !isset($id)) {
		openid_message('Identity url delete failed: ID paramater missing.');
		openid_status('error');
		return;
	}

	$identity_urls = get_user_openids($user->ID);
	if( !in_array($id, $identity_urls) ) {
		openid_message('Identity url delete failed: Specified identity does not exist or does not belong to you.');
		openid_status('error');
		return;
	}

	if (sizeof($identity_urls) == 1 && !$_REQUEST['confirm']) {
		openid_message('This is your last identity URL.  Are you sure you want to delete it? Doing so may interfere with your ability to login.<br /><br /> '
		. '<a href="?confirm=true&'.$_SERVER['QUERY_STRING'].'">Yes I\'m sure.  Delete it</a>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;'
		. '<a href="?page=openid">No, don\'t delete it.</a>');
		openid_status('warning');
		return;
	}

	check_admin_referer('wp-openid-drop-identity_'.$id);
		

	if( openid_drop_identity($user->ID, $id) ) {
		openid_message('Identity url delete successful. <b>' . $deleted_identity_url . '</b> removed.');
		openid_status('success');

		// ensure that profile URL is still a verified Identity URL
		set_include_path( dirname(__FILE__) . PATH_SEPARATOR . get_include_path() );
		require_once 'Auth/OpenID.php';
		if ($GLOBALS['wp_version'] >= '2.3') {
			require_once(ABSPATH . 'wp-admin/includes/admin.php');
		} else {
			require_once(ABSPATH . WPINC . '/registration.php');
		}
		$identities = get_user_openids($user->ID);
		$current_url = Auth_OpenID::normalizeUrl($user->user_url);

		$verified_url = false;
		if (!empty($identities)) {
			foreach ($identities as $id) {
				if ($id == $current_url) {
					$verified_url = true;
					break;
				}
			}

			if (!$verified_url) {
				$user->user_url = $identities[0];
				wp_update_user( get_object_vars( $user ));
				openid_message(openid_message() . '<br /><strong>Note:</strong> For security reasons, your profile URL has been updated to match your Identity URL.');
			}
		}
		return;
	}
		
	openid_message('Identity url delete failed: Unknown reason.');
	openid_status('error');
}


/**
 * Action method for completing the 'verify' action.  This action is used adding an identity URL to a
 * WordPress user through the admin interface.
 *
 * @param string $identity_url verified OpenID URL
 */
function openid_finish_verify($identity_url) {
	if ($_REQUEST['action'] != 'verify') return;

	$user = wp_get_current_user();
	if (empty($identity_url)) {
		openid_set_error('Unable to authenticate OpenID.');
	} else {
		if( !openid_add_identity($user->ID, $identity_url) ) {
			openid_set_error('OpenID assertion successful, but this URL is already claimed by '
			. 'another user on this blog. This is probably a bug. ' . $identity_url);
		} else {
			openid_message('Successfully added Identity URL: ' . openid_display_identity($identity_url));
			openid_status('success');
			
			// ensure that profile URL is a verified Identity URL
			set_include_path( dirname(__FILE__) . PATH_SEPARATOR . get_include_path() );
			require_once 'Auth/OpenID.php';
			if ($GLOBALS['wp_version'] >= '2.3') {
				require_once(ABSPATH . 'wp-admin/includes/admin.php');
			} else {
				require_once(ABSPATH . WPINC . '/registration.php');
			}
			$identities = get_user_openids($user->ID);
			$current_url = Auth_OpenID::normalizeUrl($user->user_url);

			$verified_url = false;
			if (!empty($identities)) {
				foreach ($identities as $id) {
					if ($id == $current_url) {
						$verified_url = true;
						break;
					}
				}

				if (!$verified_url) {
					$user->user_url = $identity_url;
					wp_update_user( get_object_vars( $user ));
					openid_message(openid_message() . '<br /><strong>Note:</strong> For security reasons, your profile URL has been updated to match your Identity URL.');
				}
			}
		}
	}

	$_SESSION['openid_message'] = openid_message();
	$_SESSION['openid_status'] = openid_status();
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

	$identities = get_user_openids($user->ID);

	if (!empty($identities)) {
		$urls = array();
		foreach ($identities as $id) {
			if ($id == $claimed) {
				return; 
			} else {
				$urls[] = $id;
			}
		}

		wp_die('For security reasons, your profile URL must be one of your claimed '
		   . 'Identity URLs: <ul><li>' . join('</li><li>', $urls) . '</li></ul>');
	}
}


?>
