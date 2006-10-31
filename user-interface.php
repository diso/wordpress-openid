<?php
/*
  user-interface.php
  licence: modified bsd
  author: Alan J Castonguay
  purpose: User Interface Elements for wpopenid
 */

{
  class WordpressOpenIDRegistrationUI {

	var $oid;  // Hold core logic instance
	var $__flag_use_Viper007Bond_login_form = false;
	
	function WordpressOpenIDRegistrationUI() {}
	
	function startup() {
		global $wordpressOpenIDRegistration_Status;
		
		add_action( 'admin_menu', array( $this, 'add_admin_panels' ) );
		
		if( class_exists('WordpressOpenIDRegistration'))
			$this->oid = new WordpressOpenIDRegistration(); // Bootstrap Level 1

		if( null !== $this->oid and $this->oid->enabled ) {  // Add hooks to the Public Wordpress User Interface
			if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log("Bootstrap Level 1 OK");
			
			foreach( $wordpressOpenIDRegistration_Status as $k=>$v) {
				if( false === $v['state'] ) {
					$this->oid->enabled = false;
					error_log( 'OpenID consumer is Disabled: ' . $v['message'] . ' : By some requirement before starting UI.');
				}
			}
			
			$this->oid->startup(); // Bootstrap Level 2
			if( $this->oid->enabled ) {  // Add hooks to the Public Wordpress User Interface
				if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log("Bootstrap Level 2 OK");
				add_action('wp_authenticate', array( $this->oid, 'wp_authenticate' ));
				if( get_option('oid_enable_commentform') ) add_filter( 'comment_form', array( $this, 'openid_wp_comment_form' ) );
				if( get_option('oid_enable_loginform') ) {
					add_action( 'login_form', array( $this, 'login_form_v2_insert_fields'));
					add_action( 'register_form', array( $this, 'openid_wp_register_v2'));
					add_filter( 'login_errors', array( $this, 'login_form_v2_hide_username_password_errors'));
					add_filter( 'register', array( $this, 'openid_wp_sidebar_register' ));
				}
				add_filter( 'loginout', array( $this, 'openid_wp_sidebar_loginout' ));
			} else {
				if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log("Bootstrap Level 2 Fail");
				add_action('admin_notices', array( $this, 'admin_notices_plugin_problem_warning' ));
			}
		} else {
			if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log("Bootstrap Level 1 Fail");
			add_action('admin_notices', array( $this, 'admin_notices_plugin_problem_warning' ));
		}
	}
	
	function login_form_v2_hide_username_password_errors($r) {
		if( $_POST['openid_url']
			or $_GET['action'] == 'loginopenid'
			or $_GET['action'] == 'commentopenid' ) return $this->oid->error;
		return $r;
	}

	function login_form_v2_insert_fields() {
		$this->__flag_use_Viper007Bond_login_form = true;
		$style = get_option('oid_enable_selfstyle') ? ('style="background: #f4f4f4 url('.OPENIDIMAGE.') no-repeat;
			background-position: 0 50%; padding-left: 18px;" ') : '';
		?>
		<hr />
		<p>
			<label>Or login using your <img src="<?php echo OPENIDIMAGE; ?>" />OpenID<a title="<?php echo __('What is this?'); ?>" href="http://openid.net/">?</a> url:<br/>
			<input type="text" name="openid_url" id="openid_url" class="input openid_url" value="" size="20" tabindex="25" <?php echo $style; ?>/></label>
		</p>
		<?php
	}

	/*  Output Buffer handler
	 *  @param $form - String of html
	 *  @return - String of html
	 *  Replaces parts of the wp-login.php form.
	 */
	function openid_wp_login_ob( $form ) {
			if( $this->__flag_use_Viper007Bond_login_form ) return $form;
			global $redirect_to;

			$style = get_option('oid_enable_selfstyle') ? ('style="background: #f4f4f4 url('.OPENIDIMAGE.') no-repeat;
				background-position: 0 50%; padding-left: 18px;" ') : '';
				
			$newform = '<h2>WordPress User</h2>';
			$form = preg_replace( '#<form[^>]*>#', '\\0 <h2>WordPress User:</h2>', $form, 1 );
			
			$newform = '<p align="center">-or-</p><h2>OpenID Identity:</h2><p><label>'
				.__('OpenID Identity Url:').
				' <small><a href="http://openid.net/">' . __('What is this?') . '</a></small><br/><input ' . $style
				.'type="text" class="input openid_url" name="openid_url" id="log" size="20" tabindex="5" /></label></p>';
			$form = preg_replace( '#<p class="submit">#', $newform . '\\0' , $form, 1 );
			return $form;
	}


	/* Hook. Add information about OpenID registration to wp-register.php */
	function openid_wp_register_ob($form) {
		$newform = '<p>For faster registration, just <a href="' . get_settings('siteurl')
			. '/wp-login.php">login with <img src="'.OPENIDIMAGE.'" />OpenID!</a></p></form>';
		$form = preg_replace( '#</form>#', $newform, $form, 1 );
		return $form;
	}
	
	/* Hook. Add information about registration to wp-login.php?action=register */
	function openid_wp_register_v2() {
		?><p>For faster registration, just <a style="color:white;" href="?">login with <img src="<?php echo OPENIDIMAGE; ?>" />OpenID!</a></p><?php
	}

	/*
	 * Hook. Add sidebar login form, editing Register link.
	 * Turns SiteAdmin into Profile link in sidebar.
	 */
	function openid_wp_sidebar_register( $link ) {
			global $current_user;
			if( !$current_user->has_cap('edit_posts')  ) {
				$link = preg_replace( '#<a href="' . get_settings('siteurl') . '/wp-admin/">Site Admin</a>#', '<a href="' . get_settings('siteurl') . '/wp-admin/profile.php">' . __('Profile') . '</a>', $link );
			}
			if( $current_user->ID ) {
				$chunk ='<li>Logged in as '
					. ( get_usermeta($current_user->ID, 'permit_openid_login')
					? ('<img src="'.OPENIDIMAGE.'" height="16" width="16" alt="[oid]" />') : '' )
					. ( !empty($current_user->user_url)
					? ('<a href="' . $current_user->user_url . '">' . htmlentities( $current_user->display_name ) . '</a>')
					: htmlentities( $current_user->display_name )        ) . '</li>';
			
			} else {
				$style = get_option('oid_enable_selfstyle') ? ('style="border: 1px solid #ccc; background: url('.OPENIDIMAGE.') no-repeat;
					background-position: 0 50%; padding-left: 18px; " ') : '';
				$chunk ='<li><form method="post" action="wp-login.php" style="display:inline;">
					<input ' . $style . 'class="openid_url_sidebar" name="openid_url" size="17" />
					<input type="hidden" name="redirect_to" value="'. $_SERVER["REQUEST_URI"] .'" /></form></li>';
			}
			return $chunk . $link;
	}

	function openid_wp_sidebar_loginout( $link ) {
		return preg_replace( '#action=logout#', 'action=logout&redirect_to=' . urlencode($_SERVER["REQUEST_URI"]), $link );
	}
		
	/*
	 * Hook. Add OpenID login-n-comment box below the comment form.
	 */
	function openid_wp_comment_form( $id ) {
			global $current_user;
			if( ! $current_user->id ) { // not logged in, draw a login form below the comment form
				$style = get_option('oid_enable_selfstyle') ? ('style="background: url('.OPENIDIMAGE.') no-repeat;
					background-position: 0 50%; padding-left: 18px;" ') : '';	
				?>
				<label for="openid_url_comment_form">Sign in with OpenID:</label><br/>	
				<input <?php echo $style; ?> type="textbox" name="openid_url" tabindex="6" id="openid_url_comment_form" size="30" />
				<?php
			}
	}


	/* Spam up the admin interface with warnings */
	function admin_notices_plugin_problem_warning() {
		?><div class="error"><p><strong>The Wordpress OpenID plugin is not active.</strong>
		Check <a href="options-general.php?page=global-openid-options">OpenID Options</a> for
		a full diagnositic report.</p></div><?php
	}
	/*
	 * Display and handle updates from the Admin screen options page.
	 */
	function options_page() {
			if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log("Poststrap Level 3: OID " . ($this->oid->enabled? 'Enabled':'Disabled' ) );
		
			// if we're posted back an update, let's set the values here
			if ( isset($_POST['info_update']) ) {
			
				$trust = $_POST['oid_trust_root'];
				if( $trust == null ) $trust = get_settings('siteurl');
				
				$error = '';
				if( $this->oid->openid_is_url($trust) ) {
					update_option('oid_trust_root', $trust);
				} else {
					$error .= "<p/>".$trust." is not a url!";
				}
				
				update_option( 'oid_enable_selfstyle', isset($_POST['enable_selfstyle']) ? true : false );
				update_option( 'oid_enable_loginform', isset($_POST['enable_loginform']) ? true : false );
				update_option( 'oid_enable_commentform', isset($_POST['enable_commentform']) ? true : false );
				
				if ($error !== '') {
					echo '<div class="error"><p><strong>At least one of Open ID options was NOT updated</strong>'.$error.'</p></div>';
				} else {
					echo '<div class="updated"><p><strong>Open ID options updated</strong></p></div>';
				}
				
			}

			$relativeto = dirname( __FILE__ ) . DIRECTORY_SEPARATOR;
			$paths = explode(PATH_SEPARATOR, get_include_path());
			foreach( $paths as $path ) {
				$fullpath = $path . DIRECTORY_SEPARATOR;
				if( $path == '.' ) $fullpath = '';
				if( substr( $path, 0, 1 ) !== '/' ) $fullpath = $relativeto . $fullpath;
				$list_of_paths[] = $fullpath;
			}
			
			wordpressOpenIDRegistration_Status_Set( 'Include Path', 'info', implode('<br/>', $list_of_paths ) );
			wordpressOpenIDRegistration_Status_Set( 'PHP version', 'info', phpversion() );
			wordpressOpenIDRegistration_Status_Set( 'library: GMP compiled into in PHP', ( extension_loaded('gmp') and @gmp_init(1) ), '<a href="http://www.php.net/gmp">GMP</a> does not appear to be built into PHP. This is highly recommended for performance reasons.' );
			wordpressOpenIDRegistration_Status_Set( 'library: BCMath compiled into in PHP', ( extension_loaded('bcmath') and @bcadd(1,1)==2 ), '<a href="http://www.php.net/bc">BCMath</a> does not appear to be built into PHP. GMP is preferred.' );
			
			$loaded_long_integer_library = false;
			if( function_exists('Auth_OpenID_detectMathLibrary') ) {
				global $_Auth_OpenID_math_extensions;
				$loaded_long_integer_library = Auth_OpenID_detectMathLibrary( $_Auth_OpenID_math_extensions );
			}
			wordpressOpenIDRegistration_Status_Set( 'Loaded long integer library', $loaded_long_integer_library==null?false:'info', $loaded_long_integer_library?$loaded_long_integer_library['extension']:'No long integer library is loaded! Key calculation will be very slow!' );
			
			if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log("Poststrap Level 3.5: OID " . ($this->oid->enabled? 'Enabled':'Disabled' ) );
			
			global $wp_version;
			wordpressOpenIDRegistration_Status_Set( 'Wordpress version', 'info', $wp_version );
			wordpressOpenIDRegistration_Status_Set( 'MySQL version', 'info', function_exists('mysql_get_client_info') ? mysql_get_client_info() : 'Mysql client information not available. Very strange, as Wordpress requires MySQL.' );
			
			$curl_message = '';
			if( function_exists('curl_version') ) {
				$curl_version = curl_version;
				if(isset($curl_version['version']))  	$curl_message = 'Version ' . $curl_version['version'] . '. ';
				if(isset($curl_version['ssl_version']))	$curl_message = 'SSL: ' . $curl_version['ssl_version'] . '. ';
			}
 			wordpressOpenIDRegistration_Status_Set( 'Curl version', function_exists('curl_version'), function_exists('curl_version') ? $curl_message :
					'This PHP installation does not have support for libcurl. Some functionality, such as fetching https:// URLs, will be missing and performance will not be as good. See <a href="http://www.php.net/manual/en/ref.curl.php">php.net/manual/en/ref.curl.php</a> about enabling libcurl support for PHP.');
			
			/* Check for updates via SF RSS feed */
			@include_once (ABSPATH . WPINC . '/rss.php');
			@include_once (ABSPATH . WPINC . '/rss-functions.php');
			$plugins = get_plugins();
			$plugin_version = (int)str_replace( '$Rev: ', '', $plugins['wpopenid/openid-registration.php']['Version'] );
			$matches = array();
			if( function_exists( 'fetch_simplepie' )) {
				$rss = @fetch_simplepie('http://sourceforge.net/export/rss2_projfiles.php?group_id=167532');
				if ( $rss and $rss->get_item_quantity() > 0 ) {
					$items = $rss->get_items(0,0);
					preg_match( '/wpopenid ([0-9]+) released/', $items[0]->get_title(), $matches );
				}
			} elseif ( function_exists( 'fetch_rss' )) {
				$rss = @fetch_rss('http://sourceforge.net/export/rss2_projfiles.php?group_id=167532');
				if( isset( $rss->items ) and 0 != count( $rss->items )) {
					preg_match( '/wpopenid ([0-9]+) released/', $rss->items[0]['title'], $matches );
				}				
			}

			if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log("Poststrap Level 3.6: OID " . ($this->oid->enabled? 'Enabled':'Disabled' ) );

			$vercmp_message = "Running version $plugin_version. ";
			if( $matches[1] ) {
				$vercmp_message .= "Latest stable release is $matches[1]. ";
				switch( version_compare( (int)$plugin_version, (int)$matches[1] ) ) {
					case 1: $vercmp_message .= 'Life on the edge.'; break;
					case 0: $vercmp_message .= 'Up to date.'; break;
					case -1: $vercmp_message .= '<a href="http://sourceforge.net/project/showfiles.php?group_id=167532&package_id=190501">A new version of this plugin is available</a>.'; break;
				}
			} else {
				$vercmp_message .= 'Could not contact sourceforge for latest version information.';
			}
			wordpressOpenIDRegistration_Status_Set( 'Plugin version', 'info', $vercmp_message);
			wordpressOpenIDRegistration_Status_Set( '<strong>Overall Plugin Status</strong>', ($this->oid->enabled), 'There are problems above that must be dealt with before the plugin can be used.' );

			?>
			<style>
				div#openidrollup:hover dl { display: block; }
				div#openidrollup dl { display: none; }
			</style>
			<?php
			if( $this->oid->enabled ) {	// Display status information
				?><div id="openidrollup" class="updated"><p><strong>Status information:</strong></p><?php
			} else {
				?><div class="error"><p><strong>Plugin is currently disabled. Fix the problem, then Deactivate/Reactivate the plugin.</strong></p><?php
			}
			global $wordpressOpenIDRegistration_Status;
			
			?>
			<dl>
			<?php
				foreach( $wordpressOpenIDRegistration_Status as $k=>$v ) {
					if( $v['state'] === false ) { echo "<dt><span style='color:red;'>[FAIL]</span> $k </dt>"; }
					elseif( $v['state'] === true ) { echo "<dt><span style='color:green;'>[OK]</span> $k </dt>"; }
					else { echo "<dt><span style='color:grey;'>[INFO]</span> $k </dt>"; }
					if( $v['state']!==true and $v['message'] ) echo '<dd>' . $v['message'] . '</dd>';
				}
			?>
			</dl></div>
			<?php
			
			
			// Display the options page form
			$siteurl = get_settings('siteurl');
			if( substr( $siteurl, -1, 1 ) !== '/' ) $siteurl .= '/';
			?>
			<form method="post"><div class="wrap">
				<h2>OpenID Registration Options</h2>
     				<fieldset class="options">
     									
     					<table class="editform" cellspacing="2" cellpadding="5" width="100%">
     					<tr valign="top"><th style="width: 10em; padding-top: 1.5em;">
     						<label for="oid_trust_root">Trust root:</label>
     					</th><td>
     						<p><input type="text" size="50" name="oid_trust_root" id="oid_trust_root"
     						value="<?php echo htmlentities(get_option('oid_trust_root')); ?>" /></p>
     						<p>Commenters will be asked whether they trust this url,
     						and its decedents, to know that they are logged in and control their identity url.
     						Include the trailing slash.
     						This should probably be <strong><?php echo $siteurl; ?></strong></p>
     					</td></tr>
     					
     					<tr><th>
     						<label for="enable_loginform">Login Form:</label>
     					</th><td>
     						<p><input type="checkbox" name="enable_loginform" id="enable_loginform" <?php
     						if( get_option('oid_enable_loginform') ) echo 'checked="checked"'
     						?> />
     						<label for="enable_loginform">Add OpenID url box to the WordPress
     						<a href="<?php bloginfo('wpurl'); ?>/wp-login.php"><?php _e('Login') ?></a>
     						form.</p>
     					</td></tr>

     					<tr><th>
     						<label for="enable_commentform">Comment Form:</label>
     					</th><td>
     						<p><input type="checkbox" name="enable_commentform" id="enable_commentform" <?php
     						if( get_option('oid_enable_commentform') ) echo 'checked="checked"'
     						?> />
     						<label for="enable_commentform">Add OpenID url box to the WordPress
     						post comment form. In general, this should not be used. Rather, the
							comment form should be tweaked as mentioned in the <em>readme</em></p>
     					</td></tr>
     					
     					<tr><th>
     						<label for="enable_selfstyle">Internal Style:</label>
     					</th><td>
     						<p><input type="checkbox" name="enable_selfstyle" id="enable_selfstyle" <?php
     						if( get_option('oid_enable_selfstyle') ) echo 'checked="checked"'
     						?> />
     						<label for="enable_selfstyle">Use Internal Style Rules</label></p>
     						<p>These rules affect the visual appearance of various OpenID login boxes,
     						such as those in the wp-login page, the comments area, and the sidebar.
     						The included styles are tested to work with the default themes.
     						For custom themeing, turn this off and apply your own styles to the form elements.</p>
     					</td></tr>

     					</table>
     				</fieldset>
     				<input type="submit" name="info_update" value="<?php _e('Update options') ?> »" />
     			</div></form>
    			<?php
	} // end function options_page

	function add_admin_panels() {
		add_options_page('Open ID options', 'OpenID', 8, 'global-openid-options', array( $this, 'options_page')  );
		if( $this->oid->enabled ) add_submenu_page('profile.php', 'Your OpenID Identities', 'Your OpenID Identities', 'read', 'your-openid-identities', array($this, 'profile_panel') );
	}

	function profile_panel() {
		if( current_user_can('read') ) {
		?>

		<?php if( 'success' == $this->oid->action ) { ?>
			<div class="updated"><p><strong>Success: <?php echo $this->oid->error; ?>.</strong></p></div>
		<?php } elseif( '' !== $this->oid->error ) { ?>
			<div class="error"><p><strong>Error: <?php echo $this->oid->error; ?>.</strong></p></div>
		<?php } ?>

		<div class="wrap">
		<h2>OpenID Identities</h2>
		<p>The following OpenID Identity Urls<a title="What is OpenID?" href="http://openid.net/">?</a> are tied to
		this user account. You can login with equivalent permissions using any of the following identity urls.</p>

		<?php
		
		$urls = $this->oid->get_my_identities();
		if( count($urls) ) {
			?>
			<p>There are <?php echo count($urls); ?> OpenID identities associated with this Wordpress user.
			You can login with any of these urls, or your Wordpress username and password.</p>

			<table class="widefat">
			<thead>
				<tr><th scope="col" style="text-align: center">ID</th><th scope="col">Identity Url</th><th scope="col" style="text-align: center">Action</th></tr>
			</thead>
			<?php
			foreach( $urls as $k=>$v ) {
				?><tr class="alternate">
					<th scope="row" style="text-align: center"><?php echo $v['uurl_id']; ?></td>
					<td><a href="<?php echo $v['meta_value']; ?>"><?php echo $v['meta_value']; ?></a></td>
					<td style="text-align: center"><a class="delete" href="?page=your-openid-identities&action=drop_identity&id=<?php echo $v['uurl_id']; ?>">Delete</a></td>
				</tr><?php
			}
			?>
			</table>
			<?php
		} else {
		?>
		<p>There are no OpenID identity urls associated with this Wordpress user.
		You can login with your Wordpress username and password.</p>
		<?php
		}
		?>
		<p><form method="post">Add identity: <input name="openid_url" /> <input type="submit" value="Add" />
			<input type="hidden" name="action" value="add_identity" ></form></p>
		</div>
		<?php
		}
	}

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
		if( get_comment_type() == 'openid' ) echo '<img src="'.OPENIDIMAGE.'" height="16" width="16" alt="OpenID" />';
	}
}

/* is_comment_openid()
 * If the current comment was submitted with OpenID, return true
 * useful for  <?php echo ( is_comment_openid() ? 'Submitted with OpenID' : '' ); ?>
 */
if( !function_exists( 'is_comment_openid' ) ) {
	function is_comment_openid() {
		return ( get_comment_type() == 'openid' );
	}
}


/* openid_comment_form()
 * Replace the form provided by comments.php
 * Uses javascript to provide visual confirmation of identity duality (anon XOR openid)
 */
if( !function_exists( 'wpopenid_comment_form' ) ) {
	function wpopenid_comment_form() {
		openid_comment_form_pre();
		openid_comment_form_anon();
		openid_comment_form_post();
	}
}

if( !function_exists( 'wpopenid_comment_form' ) ) {
	function wpopenid_comment_form_anon() {
		?>
			<p><input type="text" name="author" id="author" value="<?php echo $comment_author; ?>" size="22" tabindex="1" />
			<label for="author"><small>Name <?php if ($req) _e('(required)'); ?></small></label></p>
			<p><input type="text" name="email" id="email" value="<?php echo $comment_author_email; ?>" size="22" tabindex="2" />
			<label for="email"><small>Mail (will not be published) <?php if ($req) _e('(required)'); ?></small></label></p>
			<p><input type="text" name="url" id="url" value="<?php echo $comment_author_url; ?>" size="22" tabindex="3" />
			<label for="url"><small>Website</small></label></p>
		<?php
	}
}

if( !function_exists( 'wpopenid_comment_form_pre' ) ) {
	function wpopenid_comment_form_pre() {
		?>
		<ul id="commentAuthOptions">
		<li><label><input id="commentAuthModeAnon" type="radio" checked="checked" name="commentAuthMode" value="anon" />Anonymous Coward</label>
		<div id="commentOptionsBlockAnon">
		<?php
	}
}
if( !function_exists( 'wpopenid_comment_form_post' ) ) {
	function wpopenid_comment_form_post() {
		$style = get_option('oid_enable_selfstyle') ? ('style="background: url('.OPENIDIMAGE.') no-repeat;
					background-position: 0 50%; padding-left: 18px;" ') : ' ';
		?>
		</div>
		</li>
		<li><label><input id="commentAuthModeOpenid" type="radio" name="commentAuthMode" value="openid" />OpenID</label>
			<div id="commentOptionsBlockOpenid"><p><input <?php echo $style; ?>name="openid_url" id="openid_url_comment_form" size="22" tabindex="3.5"/>
			<label for="openid_url_comment_form"><small>OpenID Identity URL</small></label></p></div>
		</li>
		</ul>
		<script type="text/javascript">
			a = document.getElementById( "commentAuthModeAnon" );
			b = document.getElementById( "commentAuthModeOpenid" );
			a.onclick = commentOptionsCheckHandler;
			b.onclick = commentOptionsCheckHandler;
			if( ! ( a.checked || b.checked )) { b.checked=true; }
			function commentOptionsCheckHandler() {
				x = document.getElementById( "commentOptionsBlockAnon" );
				y = document.getElementById( "commentOptionsBlockOpenid" );
				if(b.checked)        {x.style.display = "none"; y.style.display = "block";
				} else if(a.checked) {x.style.display = "block"; y.style.display = "none";
				}
			}
			setTimeout(commentOptionsCheckHandler,1);
		</script>
		<?php
	}
}



?>
