<?php
/**
 * All the code required for handling OpenID comments.  These functions should not be considered public, 
 * and may change without notice.
 */


// -- WordPress Hooks
add_action( 'admin_menu', 'openid_admin_panels' );
add_action( 'personal_options_update', 'openid_personal_options_update' );
add_action( 'openid_finish_auth', 'openid_finish_verify', 10, 2 );
add_filter( 'pre_update_option_openid_cap', 'openid_set_cap', 10, 2);

if (version_compare($wp_version, '2.5', '<')) {
	add_filter('pre_user_url', 'openid_compat_pre_user_url');
}



/**
 * Spam up the admin interface with warnings.
 **/
function openid_admin_notices_plugin_problem_warning() {
	echo'<div class="error"><p><strong>'.__('The WordPress OpenID plugin is not active.', 'openid').'</strong>';
	printf(_('Check %sOpenID Options%s for a full diagnositic report.', 'openid'), '<a href="options-general.php?page=openid">', '</a>');
	echo '</p></div>';
}


/**
 * Setup admin menus for OpenID options and ID management.
 *
 * @action: admin_menu
 **/
function openid_admin_panels() {
	// global options page
	$hookname = add_options_page(__('OpenID options', 'openid'), __('OpenID', 'openid'), 8, 'openid', 'openid_options_page' );
	if (function_exists('add_thickbox')) {
		add_action("load-$hookname", create_function('', 'add_thickbox();'));
	} else {
		add_action("load-$hookname", 'openid_js_setup' );
	}
	add_action("admin_head-$hookname", 'openid_style' );
	add_filter('plugin_action_links', 'openid_plugin_action_links', 10, 2);
	
	// all users can setup external OpenIDs
	$hookname =	add_users_page(__('Your OpenIDs', 'openid'), __('Your OpenIDs', 'openid'), 
		'read', 'your_openids', 'openid_profile_panel' );
	add_action("admin_head-$hookname", 'openid_style' );
	add_action("load-$hookname", create_function('', 'wp_enqueue_script("admin-forms");'));
	add_action("load-$hookname", 'openid_profile_management' );

	// additional options for users authorized to use OpenID provider
	$user = wp_get_current_user();
	if ($user->has_cap('use_openid_provider')) {
		add_action('show_user_profile', 'openid_extend_profile', 5);
		add_action('profile_update', 'openid_profile_update');
		add_action('admin_head-profile.php', 'openid_style');

		if (!get_usermeta($user->ID, 'openid_delegate')) {
			$hookname =	add_submenu_page('profile.php', __('Your Trusted Sites', 'openid'), 
				__('Your Trusted Sites', 'openid'), 'read', 'openid_trusted_sites', 'openid_manage_trusted_sites' );
			add_action("admin_head-$hookname", 'openid_style' );
			add_action("load-$hookname", create_function('', 'wp_enqueue_script("admin-forms");'));
		}
	}

	add_settings_field('openid_general_settings', 'OpenID Settings', 'openid_general_settings', 'general', 'default');
	register_setting('general', 'openid_required_for_registration');

	add_settings_field('openid_disucssion_settings', 'OpenID Settings', 'openid_discussion_settings', 'discussion', 'default');
	register_setting('discussion', 'openid_no_require_name');
	register_setting('discussion', 'openid_enable_approval');
	register_setting('discussion', 'openid_enable_commentform');
}


/**
 * Intercept the call to set the openid_cap option.  Instead of storing 
 * this in the options table, set the capability on the appropriate roles.
 */
function openid_set_cap($newvalue, $oldvalue) {
	global $wp_roles;

	foreach ($wp_roles->role_names as $key => $name) {
		$role = $wp_roles->get_role($key);
		$option_set = $newvalue[htmlentities($key)] == 'on' ? true : false;
		if ($role->has_cap('use_openid_provider')) {
			if (!$option_set) $role->remove_cap('use_openid_provider');
		} else {
			if ($option_set) $role->add_cap('use_openid_provider');
		}
	}

	return $oldvalue;
}


/**
 * Add settings link to plugin page.
 */
function openid_plugin_action_links($links, $file) {
	$this_plugin = openid_plugin_file();

	if($file == $this_plugin) {
		$links[] = '<a href="options-general.php?page=openid">' . __('Settings') . '</a>';
	}

	return $links;
}


/*
 * Display and handle updates from the Admin screen options page.
 *
 * @options_page
 */
function openid_options_page() {
	global $wp_version, $wpdb, $wp_roles;

	if ( isset($_REQUEST['action']) ) {
		switch($_REQUEST['action']) {
			case 'rebuild_tables' :
				check_admin_referer('rebuild_tables');
				$store = openid_getStore();
				$store->reset();
				echo '<div class="updated"><p><strong>'.__('OpenID cache refreshed.', 'openid').'</strong></p></div>';
				break;
		}
	}

	$openid_options = array(
		'openid_blog_owner',
		'openid_cap',
	);
	
	// Display the options page form

	if (function_exists('screen_icon')):
		screen_icon('openid');
	?>
	<style type="text/css">
		#icon-openid { background-image: url("<?php echo plugins_url('openid/f/icon.png'); ?>"); }
	</style>
	<?php endif; ?>

	<div class="wrap">
		<form method="post" action="options.php">

			<h2><?php _e('OpenID Settings', 'openid') ?></h2>

			<div class="updated fade"><p>Please note that all OpenID Consumer options have been moved to their respective sections of the 
				<a href="options-general.php">General Settings</a> and <a href="options-discussion.php">Discussion Settings</a> pages.</p></div>

			<?php if ($wp_version < '2.3') { ?>
			<p class="submit"><input type="submit" class="button-primary" name="info_update" value="<?php _e('Save Changes') ?>" /></p>
			<?php } ?>


			<?php 
				$current_user = wp_get_current_user(); 
				$current_user_url = get_author_posts_url($current_user->ID);
			?>

			<p><?php _e('The OpenID Provider allows authorized '
			. 'users to use their author URL as an OpenID, either using their '
			. 'local WordPress username and password, or by delegating to another OpenID Provider.', 'openid'); ?></p>

			<table class="form-table optiontable editform">
				<tr valign="top">
					<th scope="row"><?php _e('Enable OpenID', 'openid') ?></th>
					<td>

						<p><?php _e('Enable the local OpenID Provider for these roles:', 'openid'); ?></p>

						<p>
							<?php 
				foreach ($wp_roles->role_names as $key => $name) {
					$role = $wp_roles->get_role($key);
					$checked = $role->has_cap('use_openid_provider') ? ' checked="checked"' : '';
					$option_name = 'openid_cap[' . htmlentities($key) . ']';
					echo '<input type="checkbox" id="'.$option_name.'" name="'.$option_name.'"'.$checked.' /><label for="'.$option_name.'"> '.$name.'</label><br />' . "\n";
				}
							?>
						</p>
					</td>
				</tr>

			<?php
				$users = get_users_of_blog();
				$users = array_filter($users, create_function('$u', '$u = new WP_User($u->user_id); return $u->has_cap("use_openid_provider");'));

				if (!empty($users)):
			?>
				<tr valign="top">
					<th scope="row"><?php _e('Blog Owner', 'openid') ?></th>
					<td>

						<p><?php printf(__('Authorized accounts on this blog can use their author URL (i.e. <em>%1$s</em>) as an OpenID. '
							. 'The Blog Owner will be able to use the blog address (%2$s) as their OpenID.  If this is a '
							. 'single-user blog, you should set this to your account.', 'openid'),
							sprintf('<a href="%1$s">%1$s</a>', $current_user_url), sprintf('<a href="%1$s">%1$s</a>', trailingslashit(get_option('home')))
						); ?>
						</p>

			<?php 
				if (defined('OPENID_DISALLOW_OWNER') && OPENID_DISALLOW_OWNER) {
					echo '
						<p class="error">' . __('A Blog Owner cannot be set for this blog.  To set a Blog Owner, '
							. 'first remove the following line from your <code>wp-config.php</code>:', 'openid') 
							. '<br /><code style="margin:1em;">define("OPENID_DISALLOW_OWNER", 1);</code>
						</p>';
				} else {
					$blog_owner = get_option('openid_blog_owner');

					if (empty($blog_owner) || $blog_owner == $current_user->user_login) {
						echo '<select id="openid_blog_owner" name="openid_blog_owner"><option value="">' . __('(none)', 'openid') . '</option>';


						foreach ($users as $user) {
							$selected = (get_option('openid_blog_owner') == $user->user_login) ? ' selected="selected"' : '';
							echo '<option value="'.$user->user_login.'"'.$selected.'>'.$user->user_login.'</option>';
						}
						echo '</select>';

					} else {
						echo '<p class="error">' . sprintf(__('Only the current Blog Owner (%s) can change this setting.', 'openid'), $blog_owner) . '</p>';
					}
				} 

			?>
					</td>
				</tr>
			<?php endif; //!empty($users) ?>
			</table>

			<table class="form-table optiontable editform">
				<tr valign="top">
					<th scope="row"><?php _e('Troubleshooting', 'openid') ?></th>
					<td>
						<?php openid_printSystemStatus(); ?>

						<p><?php printf(__('If users are experiencing problems logging in with OpenID, it may help to %1$srefresh the cache%2$s.', 'openid'),
						'<a href="' . wp_nonce_url(add_query_arg('action', 'rebuild_tables'), 'rebuild_tables') . '">', '</a>'); ?></p>
					</td>
				</tr>
			</table>

			<?php wp_nonce_field('update-options'); ?>
			<input type="hidden" name="action" value="update" />
			<input type="hidden" name="page_options" value="<?php echo join(',', $openid_options); ?>" />
			<p class="submit"><input type="submit" class="button-primary" name="info_update" value="<?php _e('Save Changes') ?>" /></p>
		</form>
	</div>
		<?php
}


/**
 * Handle user management of OpenID associations.
 *
 * @submenu_page: profile.php
 **/
function openid_profile_panel() {
	global $error;

	if( !current_user_can('read') ) return;
	$user = wp_get_current_user();

	$status = openid_status();
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

	if (function_exists('screen_icon')):
		screen_icon('openid');
	?>
	<style type="text/css">
		#icon-openid { background-image: url("<?php echo plugins_url('openid/f/icon.png'); ?>"); }
	</style>
	<?php endif; ?>

	<div class="wrap">
		<form action="<?php printf('%s?page=%s', $_SERVER['PHP_SELF'], $_REQUEST['page']); ?>" method="post">
			<h2><?php _e('Your Verified OpenIDs', 'openid') ?></h2>

			<p><?php _e('You may associate one or more OpenIDs with your account.  This will '
			. 'allow you to login to WordPress with your OpenID instead of a username and password.  '
			. '<a href="http://openid.net/what/" target="_blank">Learn more...</a>', 'openid')?></p>

		<div class="tablenav">
			<div class="alignleft actions">
				<select name="action">
					<option value="-1" selected="selected"><?php _e('Bulk Actions'); ?></option>
					<option value="delete"><?php _e('Delete'); ?></option>
				</select>
				<input type="submit" value="<?php _e('Apply'); ?>" name="doaction" id="doaction" class="button-secondary action" />
				<?php wp_nonce_field('openid-delete_openids'); ?>
			</div>
			<div class="clear"></div>
		</div>

		<div class="clear"></div>

		<table class="widefat">
			<thead>
				<tr>
					<th scope="col" class="check-column"><input type="checkbox" /></th>
					<th scope="col"><?php _e('Account', 'openid'); ?></th>
				</tr>
			</thead>
			<tbody>

			<?php
				$urls = get_user_openids($user->ID);

				if (empty($urls)) {
					echo '<tr><td colspan="2">'.__('No Verified Accounts.', 'openid').'</td></tr>';
				} else {
					foreach ($urls as $url) {
						echo '
						<tr>
							<th scope="row" class="check-column"><input type="checkbox" name="delete[]" value="'.md5($url).'" /></th>
							<td>'.openid_display_identity($url).'</td>
						</tr>';
					}
				}

			?>
			</tbody>
			</table>
		</form>

		<form method="post">
		<table class="form-table">
			<tr>
				<th scope="row"><label for="openid_identifier"><?php _e('Add OpenID', 'openid') ?></label></th>
				<td><input id="openid_identifier" name="openid_identifier" /></td>
			</tr>
		</table>
		<?php wp_nonce_field('openid-add_openid'); ?>
		<p class="submit">
			<input type="submit" class="button-primary" value="<?php _e('Add OpenID', 'openid') ?>" />
			<input type="hidden" name="action" value="add" >
		</p>
		</form>
	</div>
<?php
}


function openid_manage_trusted_sites() {
	$user = wp_get_current_user();

	switch ($_REQUEST['action']) {
	case 'add':
		check_admin_referer('openid-add_trusted_sites');

		$trusted_sites = get_usermeta($user->ID, 'openid_trusted_sites');
		if (!is_array($trusted_sites)) $trusted_sites = array();
		$sites = split("\n", $_REQUEST['sites']);

		$count = 0;
		foreach ($sites as $site) {
			$site = trim($site);
			if (empty($site)) continue;

			if (strpos($site, 'http') === false || strpos($sites, 'http') != 0) {
				$site = 'http://' . $site;
			}

			$site = clean_url($site);
			$site_hash = md5($site);

			if (array_key_exists($site_hash, $trusted_sites)) continue;

			$count++;
			$trusted_sites[$site_hash] = array('url' => $site);
		}

		if ($count) {
			update_usermeta($user->ID, 'openid_trusted_sites', $trusted_sites);
			echo '<div class="updated"><p>';
			printf( __ngettext('Added %d trusted site.', 'Added %d trusted sites.', $count, 'openid'), $count);
			echo '</p></div>';
		}
		break;

	case 'delete':
		if (empty($_REQUEST['delete'])) break;

		check_admin_referer('openid-delete_trusted_sites');

		$trusted_sites = get_usermeta($user->ID, 'openid_trusted_sites');
		$count = 0;
		foreach ($_REQUEST['delete'] as $site_hash) {
			if (array_key_exists($site_hash, $trusted_sites)) {
				$trusted_sites[$site_hash] = null;
				$count++;
			}
		}

		update_usermeta($user->ID, 'openid_trusted_sites', array_filter($trusted_sites));

		if ($count) {
			echo '<div class="updated"><p>';
			printf( __ngettext('Deleted %d trusted site.', 'Deleted %d trusted sites.', $count, 'openid'), $count);
			echo '</p></div>';
		}
		break;
	}

	if (function_exists('screen_icon')):
		screen_icon('openid');
	?>
	<style type="text/css">
		#icon-openid { background-image: url("<?php echo plugins_url('openid/f/icon.png'); ?>"); }
	</style>
	<?php endif; ?>

	<div class="wrap">
		<h2><?php _e('Your Trusted Sites', 'openid'); ?></h2>

		<p><?php _e('This is a list of sites that you can automatically login to using your OpenID account.  '
			. 'You will not be asked to approve OpenID login requests for your trusted sites.' , 'openid'); ?></p>

		<form method="post">
			<div class="tablenav">
				<div class="alignleft actions">
					<select name="action">
						<option value="-1" selected="selected"><?php _e('Bulk Actions'); ?></option>
						<option value="delete"><?php _e('Delete'); ?></option>
					</select>
					<input type="submit" value="<?php _e('Apply'); ?>" name="doaction" id="doaction" class="button-secondary action" />
					<?php wp_nonce_field('openid-delete_trusted_sites'); ?>
				</div>
				<div class="clear"></div>
			</div>

			<div class="clear"></div>

			<table class="widefat">
			<thead>
				<tr>
					<th scope="col" class="check-column"><input type="checkbox" /></th>
					<th scope="col"><?php _e('URL'); ?></th>
					<th scope="col"><?php _e('Last Login', 'openid'); ?></th>
				</tr>
			</thead>
			<tbody>

			<?php
				$trusted_sites = get_usermeta($user->ID, 'openid_trusted_sites');
				if(empty($trusted_sites)) {
					echo '<tr><td colspan="3">'.__('No Trusted Sites.', 'openid').'</td></tr>';
				} else {
					foreach( $trusted_sites as $site_hash => $site ) {
						if ($site['last_login']) {
							$last_login = date(get_option('date_format') . ' - ' . get_option('time_format'), $site['last_login']);
						} else {
							$last_login = '-';
						}

						echo '
						<tr>
							<th scope="row" class="check-column"><input type="checkbox" name="delete[]" value="'.$site_hash.'" /></th>
							<td>'.$site['url'].'</td>
							<td>'.$last_login.'</td>
						</tr>';
					}
				}
			?>

			</tbody>
			</table>

			<div class="tablenav">
				<br class="clear" />
			</div>
		</form>

		<br class="clear" />

		<form method="post">

			<h3><?php _e('Import Trusted Sites', 'openid'); ?></h3>

			<p><?php _e('Enter a list of URLs to be added to your Trusted Sites.', 'openid'); ?></p>

			<table class="form-table" style="margin-top: 0">
				<tr>
					<th scope="row"><label for="sites"><?php _e('Add Sites', 'openid') ?></label></th>
					<td>
						<textarea id="sites" name="sites" cols="60" rows="5"></textarea><br /><?php _e('(One URL per line)', 'openid'); ?>
					</td>
				</tr>
			</table>

			<?php wp_nonce_field('openid-add_trusted_sites'); ?>

			<p class="submit">
				<input type="submit" class="button-primary" value="<?php _e('Add Sites', 'openid') ?>" />
				<input type="hidden" name="action" value="add" >
			</p>

		</form>
	</div>
<?php
}


/**
 * Print the status of various system libraries.  This is displayed on the main OpenID options page.
 **/
function openid_printSystemStatus() {
	global $wp_version, $wpdb;

	$paths = explode(PATH_SEPARATOR, get_include_path());
	for($i=0; $i<sizeof($paths); $i++ ) { 
		$paths[$i] = @realpath($paths[$i]); 
		if (empty($paths[$i])) unset($paths[$i]);
	}
	
	$status = array();
	$status[] = array( 'PHP version', 'info', phpversion() );
	$status[] = array( 'PHP memory limit', 'info', ini_get('memory_limit') );
	$status[] = array( 'Include Path', 'info', $paths );
	
	$status[] = array( 'WordPress version', 'info', $wp_version );
	$status[] = array( 'PHP OpenID Library Version', 'info', Auth_OpenID_VERSION );
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
		} else {
			$curl_message =	'This PHP installation does not have support for libcurl. Some functionality, such as '
				. 'fetching https:// URLs, will be missing and performance will slightly impared. See '
				. '<a href="http://www.php.net/manual/en/ref.curl.php">php.net/manual/en/ref.curl.php</a> about '
				. 'enabling libcurl support for PHP.';
		}

		$status[] = array( 'Curl Support', isset($curl_version), $curl_message );
	}

	if (extension_loaded('gmp') and @gmp_init(1)) {
		$status[] = array( 'Big Integer support', true, 'GMP is installed.' );
	} elseif (extension_loaded('bcmath') and @bcadd(1,1)==2) {
		$status[] = array( 'Big Integer support', true, 'BCMath is installed (though <a href="http://www.php.net/gmp">GMP</a> is preferred).' );
	} elseif (defined('Auth_OpenID_NO_MATH_SUPPORT')) {
		$status[] = array( 'Big Integer support', false, 'The OpenID Library is operating in Dumb Mode. Recommend installing <a href="http://www.php.net/gmp">GMP</a> support.' );
	}

	
	$status[] = array( 'Plugin Revision', 'info', OPENID_PLUGIN_REVISION);
	$status[] = array( 'Plugin Database Revision', 'info', get_option('openid_db_revision'));

	if (function_exists('xrds_meta')) {
		$status[] = array( 'XRDS-Simple', 'info', 'XRDS-Simple plugin is installed.');
	} else {
		$status[] = array( 'XRDS-Simple', false, '<a href="http://wordpress.org/extend/plugins/xrds-simple/">XRDS-Simple</a> plugin is not installed.  Some features may not work properly (including providing OpenIDs).');
	}
	
	$openid_enabled = openid_enabled();
	$status[] = array( '<strong>Overall Plugin Status</strong>', ($openid_enabled), 
		($openid_enabled ? '' : 'There are problems above that must be dealt with before the plugin can be used.') );

	if( $openid_enabled ) {	// Display status information
		echo'<p><strong>' . __('Status information:', 'openid') . '</strong> ' . __('All Systems Nominal', 'openid') 
		. '<small> (<a href="#TB_inline?height=600&width=800&inlineId=openid_system_status" id="openid_status_link" class="thickbox" title="System Status">' . __('Toggle More/Less', 'openid') . '</a>)</small> </p>';
	} else {
		echo '<p><strong>' . __('Plugin is currently disabled. Fix the problem, then Deactivate/Reactivate the plugin.', 'openid') 
		. '</strong></p>';
	}
	echo '<div id="openid_system_status" class="updated">';
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
	echo '</div>';
	echo '
	<script type="text/javascript">
		jQuery("#openid_system_status").hide();';

	if (!function_exists('add_thickbox')) {
		echo '
		jQuery("#openid_status_link").click( function() {
			jQuery("#openid_system_status").toggle();
			return false;
		});';
	}

	echo '
	</script>';
}


/**
 * Handle OpenID profile management.
 */
function openid_profile_management() {
	global $action;
	
	wp_reset_vars( array('action') );

	switch( $action ) {
		case 'add':
			check_admin_referer('openid-add_openid');

			$user = wp_get_current_user();

			$auth_request = openid_begin_consumer($_POST['openid_identifier']);

			$userid = get_user_by_openid($auth_request->endpoint->claimed_id);

			if ($userid) {
				global $error;
				if ($user->ID == $userid) {
					$error = __('You already have this OpenID!', 'openid');
				} else {
					$error = __('This OpenID is already associated with another user.', 'openid');
				}
				return;
			}

			$finish_url = admin_url(current_user_can('edit_users') ? 'users.php' : 'profile.php');
			$finish_url = add_query_arg('page', $_REQUEST['page'], $finish_url);

			openid_start_login($_POST['openid_identifier'], 'verify', $finish_url);
			break;

		case 'delete':
			openid_profile_delete_openids($_REQUEST['delete']);
			break;

		default:
			if ( array_key_exists('message', $_REQUEST) ) {
				$message = $_REQUEST['message'];

				$messages = array(
					'',
					'Unable to authenticate OpenID.',
					'OpenID assertion successful, but this URL is already associated with another user on this blog. This is probably a bug.',
					'Added association with OpenID.',
				);

				if (is_numeric($message)) {
					$message = $messages[$message];
				} else {
					$message = htmlentities2( $message );
				}

				$message = __($message, 'openid');

				if ($_REQUEST['update_url']) {
					$message .= '<br />' .  __('<strong>Note:</strong> For security reasons, your profile URL has been updated to match your OpenID.', 'openid');
				}

				openid_message($message);
				openid_status($_REQUEST['status']);
			}
			break;
	}
}


/**
 * Remove identity URL from current user account.
 *
 * @param int $id id of identity URL to remove
 */
function openid_profile_delete_openids($delete) {

	if (empty($delete) || $_REQUEST['cancel']) return;
	check_admin_referer('openid-delete_openids');

	$user = wp_get_current_user();
	$urls = get_user_openids($user->ID);

	if (sizeof($urls) == sizeof($delete) && !$_REQUEST['confirm']) {
		$html = '
			<h1>'.__('OpenID Warning', 'openid').'</h1>
			<form action='.sprintf('%s?page=%s', $_SERVER['PHP_SELF'], $_REQUEST['page']).' method="post">
			<p>'.__('Are you sure you want to delete all of your OpenID associations? Doing so may prevent you from logging in.', 'openid').'</p>
			<div class="submit">
				<input type="submit" name="confirm" value="'.__("Yes I'm sure. Delete.", 'openid').'" />
				<input type="submit" name="cancel" value="'.__("No, don't delete.", 'openid').'" />
			</div>';

		foreach ($delete as $d) {
			$html .= '<input type="hidden" name="delete[]" value="'.$d.'" />';
		}


		$html .= wp_nonce_field('openid-delete_openids', '_wpnonce', true, false) . '
				<input type="hidden" name="action" value="delete" />
			</form>';

		openid_page($html, __('OpenID Warning', 'openid'));
		return;
	}


	$count = 0;
	foreach ($urls as $url) {
		if (in_array(md5($url), $_REQUEST['delete'])) {
			if (openid_drop_identity($user->ID, $url)) {
			   	$count++;
			}
		}
	}

	if ($count) {
		openid_message( sprintf(__ngettext('Deleted %d OpenID association.', 'Deleted %d OpenID associations.', $count, 'openid'), $count) );
		openid_status('success');

		// ensure that profile URL is still a verified OpenID
		set_include_path( dirname(__FILE__) . PATH_SEPARATOR . get_include_path() );
		require_once 'Auth/OpenID.php';
		@include_once(ABSPATH . WPINC . '/registration.php');	// WP < 2.3
		@include_once(ABSPATH . 'wp-admin/includes/admin.php');	// WP >= 2.3

		if (!openid_ensure_url_match($user)) {
			$identities = get_user_openids($user->ID);
			wp_update_user( array('ID' => $user->ID, 'user_url' => $identities[0]) );
			openid_message(openid_message() . '<br />'.__('<strong>Note:</strong> For security reasons, your profile URL has been updated to match your OpenID.', 'openid'));
		}

		return;
	}
		
	openid_message(__('OpenID association delete failed: Unknown reason.', 'openid'));
	openid_status('error');
}


/**
 * Action method for completing the 'verify' action.  This action is used adding an identity URL to a
 * WordPress user through the admin interface.
 *
 * @param string $identity_url verified OpenID URL
 */
function openid_finish_verify($identity_url, $action) {
	if ($action != 'verify') return;

	$message;
	$user = wp_get_current_user();
	if (empty($identity_url)) {
		$message = openid_message();
		if (empty($message)) $message = 1;
	} else {
		if( !openid_add_identity($user->ID, $identity_url) ) {
			$message = 2;
		} else {
			$message = 3;
			
			// ensure that profile URL is a verified OpenID
			set_include_path( dirname(__FILE__) . PATH_SEPARATOR . get_include_path() );
			require_once 'Auth/OpenID.php';
			if ($GLOBALS['wp_version'] >= '2.3') {
				require_once(ABSPATH . 'wp-admin/includes/admin.php');
			} else {
				require_once(ABSPATH . WPINC . '/registration.php');
			}

			if (!openid_ensure_url_match($user)) {
				wp_update_user( array('ID' => $user->ID, 'user_url' => $identity_url) );
				$update_url = 1;
			}
		}
	}

	$finish_url = $_SESSION['openid_finish_url'];
	$finish_url = add_query_arg('status', openid_status(), $finish_url);
	$finish_url = add_query_arg('message', $message, $finish_url);
	if ($update_url) {
		$finish_url = add_query_arg('update_url', $update_url, $finish_url);
	}

	wp_safe_redirect($finish_url);
	exit;
}


/**
 * Prior to WordPress 2.5, the 'personal_options_update' hook was called 
 * AFTER updating the user's profile.  We need to ensure the profile URL 
 * matches before then.
 */
function openid_compat_pre_user_url($url) {
	if ($_POST['from'] == 'profile') {
		openid_personal_options_update();
	}

	return $url;
}


/**
 * hook in and call when user is updating their profile URL... make sure it is an OpenID they control.
 */
function openid_personal_options_update() {
	$user = wp_get_current_user();

	if (!openid_ensure_url_match($user, $_POST['url'])) {
		wp_die(sprintf(__('For security reasons, your profile URL must be one of your claimed OpenIDs: %s', 'openid'),
			'<ul><li>' . join('</li><li>', get_user_openids($user->ID)) . '</li></ul>'));
	}
}


/**
 * Ensure that the user's profile URL matches one of their OpenIDs
 */
function openid_ensure_url_match($user, $url = null) {
	$identities = get_user_openids($user->ID);
	if (empty($identities)) return true;

	set_include_path( dirname(__FILE__) . PATH_SEPARATOR . get_include_path() );
	require_once 'Auth/OpenID.php';

	if ($url == null) $url = $user->user_url;
	$url = Auth_OpenID::normalizeUrl($url);

	foreach ($identities as $id) {
		$id = Auth_OpenID::normalizeUrl($id);
		if ($id == $url) return true; 
	}

	return false;
}


function openid_extend_profile() {
	$user = wp_get_current_user();

	echo '
<table class="form-table">
<tr>
	<th><label for="openid_delegate">'.__('OpenID Delegation', 'openid').'</label></th>
	<td>
		<p style="margin-top:0;">'.__('OpenID Delegation allows you to use an external OpenID provider of your choice.', 'openid').'</p>
		<p>
			<input type="text" id="openid_delegate" name="openid_delegate" class="openid_link" value="'.get_usermeta($user->ID, 'openid_delegate').'" />
			To delegate, enter a valid OpenID.  Otherwise leave this blank.
		</p>
	</td>
</tr>
</table>
';
}

function openid_profile_update($user_id) {
	if (empty($_POST['openid_delegate'])) {
		delete_usermeta($user_id, 'openid_delegate');
	} else {
		$old_delegate = get_usermeta($user_id, 'openid_delegate');
		$delegate = Auth_OpenID::normalizeUrl($_POST['openid_delegate']);

		if(openid_server_update_delegation_info($user_id, $delegate)) {
			openid_message(sprintf(__('Gathered OpenID information for delegate URL %s', 'openid'), '<strong>'.$delegate.'</strong>'));
			openid_status('success');
		} else {
			openid_message(sprintf(__('Unable to find any OpenID information for delegate URL %s', 'openid'), '<strong>'.$delegate.'</strong>'));
			openid_status('error');
		}
	}
}

function openid_general_settings() {
	if ( get_option('users_can_register') ): ?>
	<label for="openid_required_for_registration">
		<input type="checkbox" name="openid_required_for_registration" id="openid_required_for_registration" 
			<?php checked(true, get_option('openid_required_for_registration')) ?> />
		<?php _e('New accounts can only be created with verified OpenIDs.', 'openid') ?>
	</label>
	<?php endif; ?>

	<script type="text/javascript">
		jQuery(function() {
			jQuery('tr:has(#openid_required_for_registration)').hide();
			jQuery('#openid_required_for_registration').css('margin-left', '2em')
				.parent().prepend('<br />').insertAfter('label:has(#users_can_register)');
		});
	</script>
<?php
}

function openid_discussion_settings() {
	if ( get_option('require_name_email') ): ?>
	<label for="openid_no_require_name">
		<input type="checkbox" name="openid_no_require_name" id="openid_no_require_name" <?php 
			echo checked(true, get_option('openid_no_require_name')) ; ?> />
		<?php _e('Don\'t require name and e-mail for comments left with a verified OpenID.', 'openid') ?>
	</label>
	<br />
	<?php endif; ?>

	<label for="openid_enable_approval">
		<input type="checkbox" name="openid_enable_approval" id="openid_enable_approval" <?php 
			echo checked(true, get_option('openid_enable_approval'));  ?> />
		<?php _e('Always approve comments left with a verified OpenID', 'openid'); ?>
	</label>
	<br />

	<label for="openid_enable_commentform">
		<input type="checkbox" name="openid_enable_commentform" id="openid_enable_commentform" <?php 
			echo checked(true, get_option('openid_enable_commentform'));  ?> />
		<?php printf(__('Add OpenID help text to the comment form. <em>(This will work for most themes derived from Kubrick or Sandbox.  '
			. 'Template authors can tweak the comment form as described %shere%s)</em>.', 'openid'), 
			'<a href="' . plugins_url('openid/readme.txt') . '">', '</a>') ?>
	</label>

	<script type="text/javascript">
		jQuery(function() {
			jQuery('tr:has(#openid_enable_approval)').hide();

			jQuery('#openid_no_require_name').css('margin-left', '2em')
				.parent().prepend('<br />').insertAfter('label:has(#require_name_email)');

			jQuery('label:has(#openid_enable_approval)').prepend('<br />')
				.insertAfter('label:has(#comment_whitelist)');

			jQuery('label:has(#openid_enable_commentform)').prepend('<br />')
				.insertAfter('label:has(#comment_order)');
		});
	</script>
<?php
}

?>
