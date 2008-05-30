<?php
/**
 * interface.php
 *
 * User Interface Elements for wp-openid
 * Dual Licence: GPL & Modified BSD
 */
if (!class_exists('WordPressOpenID_Interface')):
class WordPressOpenID_Interface {

	var $logic;  // Hold core logic instance
	var $core;  // Hold core instance

	var $profile_page_name = 'openid';

	/**
	 * Constructor.
	 */
	function WordPressOpenID_Interface($core) {
		$this->core =& $core;
		$this->logic =& $core->logic;
	}

	
	/**
	 * Provide more useful OpenID error message to the user.
	 *
	 * @filter: login_errors
	 **/
	function login_form_hide_username_password_errors($r) {
		if( $_POST['openid_url']
			or $_REQUEST['action'] == 'login'
			or $_REQUEST['action'] == 'comment' ) return $this->logic->error;
		return $r;
	}


	/**
	 * Add OpenID input field to wp-login.php
	 *
	 * @action: login_form
	 **/
	function login_form() {
		global $wp_version;

		$link_class = 'openid_link';
		if ($wp_version < '2.5') {
			$link_class .= ' legacy';
		}

		?>
		<hr />
		<p style="margin-top: 1em;">
			<label><?php printf(__('Or login using your %s url:', 'openid'), '<a class="'.$link_class.'" href="http://openid.net/">'.__('OpenID', 'openid').'</a>') ?><br/>
			<input type="text" name="openid_url" id="openid_url" class="input openid_url" value="" size="20" tabindex="25" /></label>
		</p>
		<?php
	}


	/**
	 * Add information about registration to wp-login.php?action=register 
	 *
	 * @action: register_form
	 **/
	function register_form() {
		echo '<p>';
		printf(__('For faster registration, just %s login with %s.', 'openid'), '<a href="'.get_option('siteurl').'/wp-login.php">', '<span class="openid_link">'.__('OpenID', 'openid').'</span></a>');
		echo '</p>';
	}

	
	/**
	 * Add OpenID class to author link.
	 *
	 * @filter: get_comment_author_link
	 **/
	function comment_author_link( $html ) {
		if( is_comment_openid() ) {
			if (preg_match('/<a[^>]* class=[^>]+>/', $html)) {
				return preg_replace( '/(<a[^>]* class=[\'"]?)/', '\\1openid_link ' , $html );
			} else {
				return preg_replace( '/(<a[^>]*)/', '\\1 class="openid_link"' , $html );
			}
		}
		return $html;
	}


	/**
	 * Enqueue required javascript libraries.
	 *
	 * @action: init
	 **/
	function js_setup() {
		wp_enqueue_script( 'jquery' );
		wp_enqueue_script('jquery.textnode', $this->core->path . '/files/jquery.textnode.js', 
			array('jquery'), WPOPENID_PLUGIN_REVISION);
		wp_enqueue_script('jquery.xpath', $this->core->path . '/files/jquery.xpath.js', 
			array('jquery'), WPOPENID_PLUGIN_REVISION);
		wp_enqueue_script('openid', $this->core->path . '/files/openid.js', 
			array('jquery','jquery.textnode'), WPOPENID_PLUGIN_REVISION);
	}


	/**
	 * Include internal stylesheet.
	 *
	 * @action: wp_head, login_head
	 **/
	function style() {
		$css_path = $this->core->fullpath . '/files/openid.css?ver='.WPOPENID_PLUGIN_REVISION;
		echo '
			<link rel="stylesheet" type="text/css" href="'.$css_path.'" />';
	}


	/**
	 * Print jQuery call for slylizing profile link.
	 *
	 * @action: comment_form
	 **/
	function comment_profilelink() {
		if (is_user_openid()) {
			echo '<script type="text/javascript">stylize_profilelink()</script>';
		}
	}


	/**
	 * Print jQuery call to modify comment form.
	 *
	 * @action: comment_form
	 **/
	function comment_form() {
		global $user_ID;
		if (!$user_ID) {
			echo '<script type="text/javascript">add_openid_to_comment_form()</script>';
		}
	}


	/**
	 * Spam up the admin interface with warnings.
	 **/
	function admin_notices_plugin_problem_warning() {
		echo'<div class="error"><p><strong>'.__('The WordPress OpenID plugin is not active.', 'openid').'</strong>';
		printf(_('Check %sOpenID Options%s for a full diagnositic report.', 'openid'), '<a href="options-general.php?page=global-openid-options">', '</a>');
		echo '</p></div>';
	}
	

	/**
	 * Setup admin menus for OpenID options and ID management.
	 *
	 * @action: admin_menu
	 **/
	function add_admin_panels() {
		$hookname = add_options_page(__('OpenID options', 'openid'), __('WP-OpenID', 'openid'), 8, 'global-openid-options', 
			array( $this, 'options_page')  );
		add_action("load-$hookname", array( $this, 'js_setup' ));
		add_action("admin_head-$hookname", array( $this, 'style' ));

		if( $this->logic->enabled ) {
			$hookname =	add_submenu_page('profile.php', __('Your Identity URLs', 'openid'), __('Your Identity URLs', 'openid'), 
				'read', $this->profile_page_name, array($this, 'profile_panel') );
			add_action("admin_head-$hookname", array( $this, 'style' ));
			add_action("load-$hookname", array( $this->logic, 'openid_profile_management' ));
		}
	}


	/*
	 * Display and handle updates from the Admin screen options page.
	 *
	 * @options_page
	 */
	function options_page() {
		global $wp_version;

			$this->logic->late_bind();
			$this->core->log->debug("WP-OpenID Plugin: " . ($this->logic->enabled? 'Enabled':'Disabled' ) 
				. ' (start of WordPress options page)' );
		
			if ( isset($_REQUEST['action']) ) {
				switch($_REQUEST['action']) {
					case 'rebuild_tables' :
						check_admin_referer('wp-openid-info_rebuild_tables');
						$this->logic->store->destroy_tables();
						$this->logic->store->create_tables();
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

				<?php if ($wp_version >= '2.3') { $this->printSystemStatus(); } ?>

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
								'<a href="'.$this->core->fullpath.'/readme.txt">', '</a>') ?></p>
								<br />
							</td>
						</tr>

     				</table>

					<p><?php printf(__('Occasionally, the WP-OpenID tables don\'t get setup properly, and it may help '
						. 'to %srebuild the tables%s.  Don\'t worry, this won\'t cause you to lose any data... it just '
						. 'rebuilds a couple of tables that hold only temprory data.', 'openid'), 
					'<a href="'.wp_nonce_url(sprintf('?page=%s&action=rebuild_tables', $_REQUEST['page']), 'wp-openid-info_rebuild_tables').'">', '</a>') ?></p>

					<?php wp_nonce_field('wp-openid-info_update'); ?>
     				<p class="submit"><input type="submit" name="info_update" value="<?php _e('Update Options') ?> &raquo;" /></p>
     			</form>

			</div>
    			<?php
			if ($wp_version < '2.3') {
				echo '<br />';
				$this->printSystemStatus();
			}
	} // end function options_page


	/**
	 * Handle user management of OpenID associations.
	 *
	 * @submenu_page: profile.php
	 **/
	function profile_panel() {
		if( !current_user_can('read') ) {
			return;
		}

		$this->logic->late_bind();

		if( 'success' == $this->logic->action ) {
			echo '<div class="updated"><p><strong>'.__('Success:', 'openid').'</strong> '.$this->logic->error.'</p></div>';
		}
		elseif( 'warning' == $this->logic->action ) {
			echo '<div class="error"><p><strong>'.__('Warning:', 'openid').'</strong> '.$this->logic->error.'</p></div>';
		}
		elseif( $this->logic->error ) {
			echo '<div class="error"><p><strong>'.__('Error:', 'openid').'</strong> '.$this->logic->error.'</p></div>';
		}

		?>

		<div class="wrap">
			<h2><?php _e('Your Identity URLs', 'openid') ?></h2>

			<p><?php printf(__('The following Identity URLs %s are tied to this user account. You can login '
			. 'with equivalent permissions using any of the following identities.', 'openid'), 
			'<a title="'.__('What is OpenID?', 'openid').'" href="http://openid.net/">'.__('?', 'openid').'</a>') ?>
			</p>
		<?php
		
		$urls = $this->logic->store->get_my_identities();

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
					echo wp_nonce_url(sprintf('?page=%s&action=drop_identity&id=%s', $this->profile_page_name, $v['uurl_id']), 
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
	function printSystemStatus() {
		$relativeto = dirname( __FILE__ ) . DIRECTORY_SEPARATOR;
		$paths = explode(PATH_SEPARATOR, get_include_path());
		foreach( $paths as $path ) {
			$fullpath = $path . DIRECTORY_SEPARATOR;
			if( $path == '.' ) $fullpath = '';
			if( substr( $path, 0, 1 ) !== '/' ) $fullpath = $relativeto . $fullpath;
			$list_of_paths[] = $fullpath;
		}
		
		$this->core->setStatus( 'Include Path', 'info', implode('<br/>', $list_of_paths ) );
		
		global $wp_version;
		$this->core->setStatus( 'WordPress version', 'info', $wp_version );
		$this->core->setStatus( 'MySQL version', 'info', function_exists('mysql_get_client_info') ? mysql_get_client_info() : 'Mysql client information not available. Very strange, as WordPress requires MySQL.' );

		$this->core->setStatus( 'PHP version', 'info', phpversion() );
		$this->core->setStatus( 'PHP memory limit', 'info', ini_get('memory_limit') );
		
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
		$this->core->setStatus( 'Curl ' . $curl_message, function_exists('curl_version'), function_exists('curl_version') ? $curl_message :
				'This PHP installation does not have support for libcurl. Some functionality, such as fetching https:// URLs, will be missing and performance will slightly impared. See <a href="http://www.php.net/manual/en/ref.curl.php">php.net/manual/en/ref.curl.php</a> about enabling libcurl support for PHP.');

		/* Check for Long Integer math library */
		$this->core->setStatus( 'library: GMP compiled into in PHP', ( extension_loaded('gmp') and @gmp_init(1) ), '<a href="http://www.php.net/gmp">GMP</a> does not appear to be built into PHP. This is highly recommended for performance reasons.' );
		$this->core->setStatus( 'library: BCMath compiled into in PHP', ( extension_loaded('bcmath') and @bcadd(1,1)==2 ), '<a href="http://www.php.net/bc">BCMath</a> does not appear to be built into PHP. GMP is preferred.' );

		if( defined( 'Auth_OpenID_NO_MATH_SUPPORT' ) ) {
			$this->core->setStatus( 'Loaded long integer library', false, 'The OpenID Library is operating Dumb Mode, since it doesn\'t have a big integer library. Recommend installing GMP support.' );
		}
		if( defined( 'Auth_OpenID_RAND_SOURCE' ) ) {
			$this->core->setStatus( 'Cryptographic Randomness Source', (Auth_OpenID_RAND_SOURCE===null) ? false: 'info' ,
				(Auth_OpenID_RAND_SOURCE===null)
				? '/dev/urandom unavailable, using an <a href="http://php.net/mt_rand">insecure random number generator</a>. <a href="http://www.php.net/manual/en/features.safe-mode.php#ini.open-basedir">open_basedir</a> is "' . ini_get('open_basedir') . '"'
				: Auth_OpenID_RAND_SOURCE );
		}

		
		$this->core->setStatus( 'Plugin Revision', 'info', WPOPENID_PLUGIN_REVISION);
		$this->core->setStatus( 'Plugin Database Revision', 'info', 'Plugin database is currently at revision '
			. get_option('oid_db_revision') . '.' );
		
		$this->core->setStatus( '<strong>Overall Plugin Status</strong>', ($this->logic->enabled), 
			'There are problems above that must be dealt with before the plugin can be used.' );


		if( $this->logic->enabled ) {	// Display status information
			?><div id="openid_rollup" class="updated"><p><strong><?php _e('Status information:', 'openid') ?></strong> <?php _e('All Systems Nominal', 'openid') ?> 
				<small>(<a href="#" id="openid_rollup_link"><?php _e('Toggle More/Less', 'openid') ?></a>)</small> </p><?php
		} else {
			?><div class="error"><p><strong><?php _e('Plugin is currently disabled. Fix the problem, then Deactivate/Reactivate the plugin.', 'openid') ?></strong></p><?php
		}
		
		?>
		<dl>
		<?php
			foreach( $this->core->status as $k=>$v ) {
				if( $v['state'] === false ) { echo "<dt><span style='color:red;'>[".__('FAIL', 'openid')."]</span> $k </dt>"; }
				elseif( $v['state'] === true ) { echo "<dt><span style='color:green;'>[".__('OK', 'openid')."]</span> $k </dt>"; }
				else { echo "<dt><span style='color:grey;'>[".__('INFO', 'openid')."]</span> $k </dt>"; }
				if( $v['state']!==true and $v['message'] ) echo '<dd>' . $v['message'] . '</dd>';
			}
		?>
		</dl></div>
		<?php
	}

	function repost($action, $parameters) {
		echo '<html><head></head><body>
		<noscript><p>Since your browser does not support JavaScript, you must press the Continue button once to proceed.</p></noscript>
		<form action="'.$action.'" method="post">';

		foreach ($parameters as $k => $v) {
			if ($k == 'submit') continue;
			echo "\n" . '<input type="hidden" name="'.$k.'" value="'.$v.'" />';
		}
		echo '
			<noscript><div><input type="submit" value="Continue" /></div></noscript>
		</form>
		
		<script type="text/javascript">document.forms[0].submit()</script>
		
		</body></html>';
		exit;
	}
	
	function display_error($error) {
		echo '<html><head></head><body>' . $error . '</body></html>';
		exit;
	}
}
endif;

?>
