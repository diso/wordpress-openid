<?php
/*
  user-interface.php
  licence: modified bsd
  author: Alan J Castonguay
  purpose: User Interface Elements for wpopenid
 */

if ( !class_exists('WordpressOpenIDRegistrationUI') ) {
  class WordpressOpenIDRegistrationUI {

	var $oid;  // Hold core logic instance
	var $__flag_use_Viper007Bond_login_form = false;
	
	function startup() {
		global $wordpressOpenIDRegistration_Status, $wordpressOpenIDRegistrationUI;
		
		add_action( 'admin_menu', array( $wordpressOpenIDRegistrationUI, 'add_admin_panels' ) );
		
		if( !class_exists('WordpressOpenIDRegistration')) {
			error_log('WPOpenID plugin core is disabled -- WordpressOpenIDRegistration class not found. Ensure files are uploaded correctly.');
			add_action('admin_notices', array( $wordpressOpenIDRegistrationUI, 'admin_notices_plugin_problem_warning' ));
			return;
		}
		
		$this->oid = new WordpressOpenIDRegistration();
		
		if( null === $this->oid ) {
			error_log('WPOpenID plugin core is disabled -- Could not create WordpressOpenIDRegistration object. Ensure files are uploaded correctly.');
			add_action('admin_notices', array( $wordpressOpenIDRegistrationUI, 'admin_notices_plugin_problem_warning' ));
			return;
		}
		
		$this->oid->uptodate(); // Quick check for plugin OK state.

		if( !$this->oid->enabled ) { // Something broke, can't start UI
			error_log('WPOpenID plugin core is disabled -- Check Options -> OpenID tab for a full diagnositic report.');
			add_action('admin_notices', array( $wordpressOpenIDRegistrationUI, 'admin_notices_plugin_problem_warning' ));
			return;
		}
		
		// Kickstart
		register_activation_hook( 'wpopenid/openid-registration.php', array( $wordpressOpenIDRegistrationUI->oid, 'late_bind' ) );

		// Add hooks to handle actions in Wordpress
		add_action( 'wp_authenticate', array( $wordpressOpenIDRegistrationUI->oid, 'wp_authenticate' ) ); // openid loop start
		add_action( 'init', array( $wordpressOpenIDRegistrationUI->oid, 'finish_login' ) ); // openid loop done

		// Start and finish the redirect loop, for the admin pages profile.php & users.php
		add_action( 'init', array( $wordpressOpenIDRegistrationUI->oid, 'admin_page_handler' ) );

		// Comment filtering
		add_action( 'preprocess_comment', array( $wordpressOpenIDRegistrationUI->oid, 'openid_wp_comment_tagging' ), -99999 );
		add_filter( 'option_require_name_email', array( $wordpressOpenIDRegistrationUI->oid, 'openid_bypass_option_require_name_email') );
		add_filter( 'comment_notification_subject', array( $wordpressOpenIDRegistrationUI->oid, 'openid_comment_notification_subject'), 10, 2 );
		add_filter( 'comment_notification_text', array( $wordpressOpenIDRegistrationUI->oid, 'openid_comment_notification_text'), 10, 2 );
		add_filter( 'comments_array', array( $wordpressOpenIDRegistrationUI->oid, 'comments_awaiting_moderation'), 10, 2);
		add_action( 'sanitize_comment_cookies', array( $wordpressOpenIDRegistrationUI->oid, 'sanitize_comment_cookies'), 15);
		
		add_action( 'delete_user', array( $wordpressOpenIDRegistrationUI->oid, 'drop_all_identities_for_user' ) );	// If user is dropped from database, remove their identities too.

		if( get_option('oid_enable_unobtrusive') ) {
			add_action( 'wp_head', array( $wordpressOpenIDRegistrationUI, 'openid_unobtrusive_js'));
		}

		if( get_option('oid_enable_commentform') ) {
			add_filter( 'comments_template', array( $wordpressOpenIDRegistrationUI, 'setup_openid_wp_login_ob'));
			add_filter( 'get_comment_author_link', array( $wordpressOpenIDRegistrationUI, 'openid_comment_author_link_prefx'));
		}

		if( get_option('oid_enable_loginform') ) {
			add_action( 'login_form', array( $wordpressOpenIDRegistrationUI, 'login_form_v2_insert_fields'));
			add_action( 'register_form', array( $wordpressOpenIDRegistrationUI, 'openid_wp_register_v2'));
			add_filter( 'login_errors', array( $wordpressOpenIDRegistrationUI, 'login_form_v2_hide_username_password_errors'));
			add_filter( 'register', array( $wordpressOpenIDRegistrationUI, 'openid_wp_sidebar_register' ));
		}
		add_filter( 'loginout', array( $wordpressOpenIDRegistrationUI, 'openid_wp_sidebar_loginout' ));
	}
	
	function login_form_v2_hide_username_password_errors($r) {
		if( $_POST['openid_url']
			or $_GET['action'] == 'loginopenid'
			or $_GET['action'] == 'commentopenid' ) return $this->oid->error;
		return $r;
	}

	function login_form_v2_insert_fields() {
		global $wordpressOpenIDRegistrationUI;
		$wordpressOpenIDRegistrationUI->__flag_use_Viper007Bond_login_form = true;
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
		global $wordpressOpenIDRegistrationUI;
		if( $wordpressOpenIDRegistrationUI->__flag_use_Viper007Bond_login_form ) return $form;

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
				$chunk ='<li class="wpopenid_login_item">Logged in as '
					. ( is_user_openid()
					  ? ('<img src="'.OPENIDIMAGE.'" height="16" width="16" alt="[oid]" />') : '' )
					. ( !empty($current_user->user_url)
					  ? ('<a href="' . $current_user->user_url . '">' . htmlentities( $current_user->display_name ) . '</a>')
					  : htmlentities( $current_user->display_name )   ) . '</li>';
			
			} else {
				$style = get_option('oid_enable_selfstyle') ? ('style="border: 1px solid #ccc; background: url('.OPENIDIMAGE.') no-repeat;
					background-position: 0 50%; padding-left: 18px; " ') : '';
				$chunk ='<li class="wpopenid_login_item"><form method="post" action="'.get_settings('siteurl').'/wp-login.php" style="display:inline;">
					<input ' . $style . 'class="openid_url_sidebar" name="openid_url" size="17" />
					<input type="hidden" name="redirect_to" value="'. $_SERVER["REQUEST_URI"] .'" /></form></li>';
			}
			return $chunk . $link;
	}

	function openid_wp_sidebar_loginout( $link ) {
		if( '' == $link ) return '';
		if( strpos('redirect_to', $link )) return $link;
		return str_replace( 'action=logout', 'action=logout' . ini_get('arg_separator.output') . 'redirect_to=' . urlencode($_SERVER["REQUEST_URI"]), $link );
	}
	
	// Add OpenID logo beside username for theme.
	function openid_comment_author_link_prefx( $html ) {
		global $comment_is_openid;
		get_comment_type();
		if( $comment_is_openid === true )
			return '<img src="'.OPENIDIMAGE.'" height="16" width="16" alt="OpenID" />' . $html;
		return $html;
	}
	
	/*
	 * Hook. Add OpenID login-n-comment box above the comment form.
	 * Uses Output Buffering to rewrite the comment form html.
	 */
	function setup_openid_wp_login_ob($string) {
		global $wordpressOpenIDRegistrationUI;
		ob_start( array( $wordpressOpenIDRegistrationUI, "openid_wp_comment_form_ob" ) );
		return $string;
	}
	
	function openid_unobtrusive_js() {
		global $wp_version;

		# jQuery is standard in wordpress 2.2+
		if ($wp_version < '2.2') echo '<script type="text/javascript" src="http://code.jquery.com/jquery-latest.pack.js"></script>';

		echo '
		<style type="text/css">
			#openid_enabled_link { background: url(\''.OPENIDIMAGE.'\') center left no-repeat; padding-left: 18px; }
		</style>

		<script type="text/javascript">
			jQuery(document).ready( function() {
				jQuery(\'#openid_unobtrusive_text\').hide();

				jQuery(\'#openid_enabled_link\').click( function() {
					jQuery(\'#openid_unobtrusive_text\').toggle(400); 
					return false;
				});
			});
		</script>';
	}

	function openid_wp_comment_form_ob( $html ) {
		$block = array('address','blockquote','dsiv','dl','span',
			'fieldset','h1','h2','h3','h4','h5','h6',
			'p','ul','li', 'dd','dt');
		
		global $user_ID, $user_identity;
		
		if( $user_ID ) {
			// Logged in already. Add the OpenID logo to the username?
			if( is_user_openid() ) {
				return preg_replace( '|(Logged in as (<a[^>]*>)?)|', '\\1<img src="'.OPENIDIMAGE.'" height="16" width="16" alt="[oid]" />' , $html );
			}
			return $html;
			
		} elseif ( get_option('comment_registration') ) {
			// Not logged in already, login is required to leave comments. Add the form.
			$openid = '<form method="post" action="' . get_option('siteurl') . 
			'/wp-login.php?redirect_to= ' .  apply_filters('the_permalink', get_permalink() ) . '#respond">
			 <p><label for="openid_url_comment_form">You can login with your OpenID!</label><br/>
			 <input style="background: url(' . OPENIDIMAGE . ') no-repeat;
			 background-position: 0 50%; padding-left: 18px;" type="text" name="openid_url" tabindex="6" id="openid_url_comment_form" size="22" />
			 <input name="submit" type="submit" value="Login" /></p>
			</form>';
			$html = preg_replace( '%<(' . implode('|', $block) . ')( [^>]*)?>'.
				'You must be <a href="[^"]*">logged in</a> to post a comment.</\\1>%',
				'\\0' . $openid, $html );
			return $html;
		}
		
		if (get_option('oid_enable_unobtrusive') && get_option('oid_enable_selfstyle')) {
			$unobtrusive_html = '<a id="openid_enabled_link" href="http://openid.net">(OpenID Enabled)</a>
				<div id="openid_unobtrusive_text">
					If you have an OpenID, you may fill it in here.  If your 
					OpenID provider provides a name and email, those values 
					will be used instead of the values here.  <a 
					href="http://openid.net">Learn more about OpenID</a> or <a 
					href="http://openid.net/wiki/index.php/Public_OpenID_providers">find 
					an OpenID provider</a>.
				</div>
				';

			$newhtml = preg_replace( '|(.*<label for="url">(.*?)?)((</small>)?</label>.*)|', '\\1'.$unobtrusive_html.'\\3', $html );
			return $newhtml;
		} else {
			
		// 1. Set aside everything outside the <FORM>
		$matches = array();
		$foundform = preg_match( '|(.*)<form([^>]*)>(.*)</form>(.*)|ism', $html, $matches );
		$form_pre = $matches[1];
		$form_post = $matches[4];
		$form_inner = $matches[2];

		// 2. Working on the segment in <form> ... </form>
		$work = $matches[3];
		$matches = array();
		$foundform = preg_match( '|<form(.*)>(.+)</form>|', $html, $matches );
		
		// 3. Find the <input ... name ... > segments, with block level containers.
		$rinput = '(<input[^>]*?name="([^"]+)"[^>]*?>)';
		$rblock = '<(' . implode('|', $block) . ')( [^>]*)?>';
		$rs = '(.*?)';
		$rblockend = '</\\1>(\s)+';
		$r = '%' . $rblock . $rs . $rinput . $rs . $rblockend . '%ism';

		$matches = array();
		$num = preg_match_all( $r, $work, $matches, PREG_OFFSET_CAPTURE );

		// 4. Set aside the standard anonymous fields
		$fields = array( 'author','url','email' );
		$chunks = array();
		foreach( $matches[5] as $k=>$v ) {
			if( in_array( strtolower($v[0]), $fields ) ) {
				$chunks[] = array( 'line' => $matches[0][$k][0],
									'startpos' => $matches[0][$k][1],
									'starttag' => strtolower( $matches[1][$k][0] ),
									'length' => strlen( $matches[0][$k][0] ) );
			}
		}

		// 5. Grab starting position for re-insertion
		$insert_point = $chunks[0]['startpos'];
		$insert_tag = $chunks[0]['starttag'];

		// 6. Create OpenID version of the Author line
		$author = $chunks[0]['line'];
		$author_name = trim(strip_tags($author));

		$style = get_option('oid_enable_selfstyle') ? ('style="background: url('.OPENIDIMAGE.') no-repeat;
			background-position: 0 50%; padding-left: 18px; " ') : '';
			
		$openid = str_replace(  array('name="author"', "$author_name"),
			array( $style.'name="openid_url" class="commentform_openid"',
			'Sign in with your OpenID <a href="http://openid.net/">?</a>'), $author );

		if( preg_match( '/id="[^"]+"/', $openid )) {
			$openid = preg_replace( '/id="[^"]+"/', 'id="commentform_openid"', $openid );
			$openid = preg_replace( '/for="[^"]+"/', 'for="commentform_openid"', $openid );
		} else {
			$openid = preg_replace( '/name="/', 'id="commentform_openid" name="', $openid );
		}

		// 6. Remove the Anonymous chunks from the html source
		$blocklength = 0;
		$anonymous = '';
		$chunks = array_reverse($chunks);
		foreach( $chunks as $k=>$v ) {
			$work = substr_replace( $work, '', $v['startpos'], $v['length'] );
			$blocklength += $v['length'];
			$anonymous = $v['line'] . $anonymous;
		}

		if( count( $chunks )) {
			// 7. Custom template
			switch ($insert_tag) {
				case 'li':
					$n = "<li><h4>OpenID</h4></li>$openid\n<li><h4>Anonymous</h4></li>$anonymous\n";
					break;
				default:
					$n = "<dl class=\"commentform_openid_list\"><dt><h4>OpenID</h4></dt><dd>$openid\n</dd><dt><h4>Anonymous</h4></dt><dd>$anonymous</dd></dl>\n";
			}
			$work = substr_replace( $work, $n, $insert_point, 0 );
		

			// 8. Reassemble
			$final = "$form_pre<form$form_inner>\n$work\n</form>$form_post";
			return $final;
		} else {
			return $html;
		}
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
			$this->oid->late_bind();
			if( WORDPRESSOPENIDREGISTRATION_DEBUG ) error_log("WPOpenID Plugin: " . ($this->oid->enabled? 'Enabled':'Disabled' ) . ' (start of wordpress options page)' );
		
			// if we're posted back an update, let's set the values here
			if ( isset($_POST['info_update']) ) {
			
				$trust = $_POST['oid_trust_root'];
				if( $trust == null ) $trust = get_settings('siteurl');
				
				$error = '';
				if( $trust = clean_url($trust) ) {
					update_option('oid_trust_root', $trust);
				} else {
					$error .= "<p/>".$trust." is not a url!";
				}
				
				update_option( 'oid_enable_selfstyle', isset($_POST['enable_selfstyle']) ? true : false );
				update_option( 'oid_enable_loginform', isset($_POST['enable_loginform']) ? true : false );
				update_option( 'oid_enable_commentform', isset($_POST['enable_commentform']) ? true : false );
				update_option( 'oid_enable_unobtrusive', isset($_POST['enable_unobtrusive']) ? true : false );
				update_option( 'oid_enable_localaccounts', isset($_POST['enable_localaccounts']) ? true : false );
				
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
			
			global $wp_version;
			wordpressOpenIDRegistration_Status_Set( 'WordPress version', 'info', $wp_version );
			wordpressOpenIDRegistration_Status_Set( 'MySQL version', 'info', function_exists('mysql_get_client_info') ? mysql_get_client_info() : 'Mysql client information not available. Very strange, as Wordpress requires MySQL.' );

			wordpressOpenIDRegistration_Status_Set( 'PHP version', 'info', phpversion() );
			
			$curl_message = '';
			if( function_exists('curl_version') ) {
				$curl_version = curl_version;
				if(isset($curl_version['version']))  	$curl_message = 'Version ' . $curl_version['version'] . '. ';
				if(isset($curl_version['ssl_version']))	$curl_message = 'SSL: ' . $curl_version['ssl_version'] . '. ';
			}
 			wordpressOpenIDRegistration_Status_Set( 'Curl version', function_exists('curl_version'), function_exists('curl_version') ? $curl_message :
					'This PHP installation does not have support for libcurl. Some functionality, such as fetching https:// URLs, will be missing and performance will slightly impared. See <a href="http://www.php.net/manual/en/ref.curl.php">php.net/manual/en/ref.curl.php</a> about enabling libcurl support for PHP.');

			/* Check for Long Integer math library */
			wordpressOpenIDRegistration_Status_Set( 'library: GMP compiled into in PHP', ( extension_loaded('gmp') and @gmp_init(1) ), '<a href="http://www.php.net/gmp">GMP</a> does not appear to be built into PHP. This is highly recommended for performance reasons.' );
			wordpressOpenIDRegistration_Status_Set( 'library: BCMath compiled into in PHP', ( extension_loaded('bcmath') and @bcadd(1,1)==2 ), '<a href="http://www.php.net/bc">BCMath</a> does not appear to be built into PHP. GMP is preferred.' );

			$loaded_long_integer_library = false;
			if( function_exists('Auth_OpenID_detectMathLibrary') ) {
				global $_Auth_OpenID_math_extensions;
				$loaded_long_integer_library = Auth_OpenID_detectMathLibrary( $_Auth_OpenID_math_extensions );
				wordpressOpenIDRegistration_Status_Set( 'Loaded long integer library', $loaded_long_integer_library==null?false:'info', $loaded_long_integer_library?$loaded_long_integer_library['extension']:'No long integer library is loaded! Key calculation will be very slow!' );
			} else {
				wordpressOpenIDRegistration_Status_Set( 'Loaded long integer library', false, 'The underlying OpenID library function Auth_OpenID_detectMathLibrary is not available. Install library first.' );
			}
			
			if( defined( 'Auth_OpenID_NO_MATH_SUPPORT' ) ) {
				wordpressOpenIDRegistration_Status_Set( 'Loaded long integer library', false, 'The OpenID Library is operating Dumb Mode, since it doesn\'t have a big integer library. Recommend installing GMP support.' );
			}
			if( defined( 'Auth_OpenID_RAND_SOURCE' ) ) {
				wordpressOpenIDRegistration_Status_Set( 'Cryptographic Randomness Source', (Auth_OpenID_RAND_SOURCE===null) ? false: 'info' ,
					(Auth_OpenID_RAND_SOURCE===null)
					? '/dev/urandom unavailable, using an <a href="http://php.net/mt_rand">insecure random number generator</a>. <a href="http://www.php.net/manual/en/features.safe-mode.php#ini.open-basedir">open_basedir</a> is "' . ini_get('open_basedir') . '"'
					: Auth_OpenID_RAND_SOURCE );
			}

			
			/* Check for updates via SF RSS feed */
			@include_once (ABSPATH . WPINC . '/rss.php');
			@include_once (ABSPATH . WPINC . '/rss-functions.php');
			$plugins = get_plugins();
			$matches = array();
			if( function_exists( 'fetch_simplepie' )) {
				$rss = @fetch_simplepie('http://sourceforge.net/export/rss2_projfiles.php?group_id=167532');
				if ( $rss and $rss->get_item_quantity() > 0 ) {
					$items = $rss->get_items(0,0);
					preg_match( '/wpopenid ([^ ]+) released/', $items[0]->get_title(), $matches );
				}
			} elseif ( function_exists( 'fetch_rss' )) {
				$rss = @fetch_rss('http://sourceforge.net/export/rss2_projfiles.php?group_id=167532');
				if( isset( $rss->items ) and 0 != count( $rss->items )) {
					preg_match( '/wpopenid ([^ ]+) released/', $rss->items[0]['title'], $matches );
				}
			}


			$vercmp_message = 'Running version ' . (int)WPOPENID_PLUGIN_VERSION . '. ';
			if( $matches[1] ) {
				$vercmp_message .= "Latest stable release is $matches[1]. ";
				switch( version_compare( (int)WPOPENID_PLUGIN_VERSION, (int)$matches[1] ) ) {
					case 1: $vercmp_message .= 'This revision is newer than the latest stable.'; break;
					case 0: $vercmp_message .= 'Up to date.'; break;
					case -1: $vercmp_message .= '<a href="http://sourceforge.net/project/showfiles.php?group_id=167532&package_id=190501">A new version of this plugin is available</a>.'; break;
				}
			} else {
				$vercmp_message .= 'Could not contact sourceforge for latest version information.';
			}
			wordpressOpenIDRegistration_Status_Set( 'Plugin version', 'info', $vercmp_message);
			wordpressOpenIDRegistration_Status_Set( 'Plugin Database Version', 'info', 'Plugin database is currently at revision ' . get_option('oid_plugin_version') . '.' );
			
			wordpressOpenIDRegistration_Status_Set( '<strong>Overall Plugin Status</strong>', ($this->oid->enabled), 'There are problems above that must be dealt with before the plugin can be used.' );

			?>
			<style>
				/*div#openidrollup:hover dl { display: block; } */
				div#openidrollup dl { display: none; }
			</style>
			<?php
			if( $this->oid->enabled ) {	// Display status information
				?><div id="openidrollup" class="updated"><p><strong>Status information:</strong> All Systems Nominal</p><?php
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
     					<tr valign="top"><th style="width: 10em;">
     						<p><label for="oid_trust_root">Trust root:</label></p>
     					</th><td>
							<p><input type="text" size="50" name="oid_trust_root" id="oid_trust_root"
     						value="<?php echo htmlentities(get_option('oid_trust_root')); ?>" /></p>
     						<p>Commenters will be asked whether they trust this url,
     						and its decedents, to know that they are logged in and control their identity url.
     						Include the trailing slash.
     						This should probably be <strong><?php echo $siteurl; ?></strong></p>
     					</td></tr>
     					
     					<tr valign="top"><th>
     						<p><label for="enable_loginform">Login Form:</label></p>
     					</th><td>
     						<p><input type="checkbox" name="enable_loginform" id="enable_loginform" <?php
     						if( get_option('oid_enable_loginform') ) echo 'checked="checked"'
     						?> />
     						<label for="enable_loginform">Add OpenID url box to the WordPress
     						<a href="<?php bloginfo('wpurl'); ?>/wp-login.php"><?php _e('Login') ?></a>
     						form.</p>
     					</td></tr>

     					<tr valign="top"><th>
     						<p><label for="enable_commentform">Comment Form:</label></p>
     					</th><td>
     						<p><input type="checkbox" name="enable_commentform" id="enable_commentform" <?php
     						if( get_option('oid_enable_commentform') ) echo 'checked="checked"'
     						?> />
     						<label for="enable_commentform">Add OpenID url box to the WordPress
     						post comment form. This will work for most themes derived from Kubrick.
							Template authors can tweak the comment form as mentioned in the
							<a href="http://svn.sourceforge.net/viewvc/*checkout*/wpopenid/trunk/README">readme</a>.</p>
     					</td></tr>
     					
     					<tr valign="top"><th>
     						<p><label for="enable_selfstyle">Internal Style:</label></p>
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

     					<tr valign="top"><th>
     						<p><label for="enable_unobtrusive">Unobtrusive Mode:</label></p>
     					</th><td>
     						<p><input type="checkbox" name="enable_unobtrusive" id="enable_unobtrusive" <?php
     						if( get_option('oid_enable_unobtrusive') ) echo 'checked="checked"'
     						?> />
     						<label for="enable_unobtrusive">Use Unobtrusive Mode</label></p>
							<p>Inspired by <a href="http://www.intertwingly.net/blog/2006/12/28/Unobtrusive-OpenID">Sam Ruby</a>, 
							unobtrusive mode causes the existing website field in the login form to be used for OpenIDs.  
							When a comment is submitted with a website, we first see if that is a valid OpenID.  If so, 
							then we continue on logging the user in with their OpenID, otherwise we treat it as a normal 
							comment.</p>
     					</td></tr>

     					<tr valign="top"><th>
     						<p><label for="enable_localaccounts">Local Accounts:</label></p>
     					</th><td>
     						<p><input type="checkbox" name="enable_localaccounts" id="enable_localaccounts" <?php
     						if( get_option('oid_enable_localaccounts') ) echo 'checked="checked"'
     						?> />
     						<label for="enable_localaccounts">Create Local Accounts</label></p>
							<p>If enabled, a local wordpress account will be created for each commenter who logs in with an OpenID.</p>
     					</td></tr>

     					</table>
     				</fieldset>
     				<p class="submit"><input type="submit" name="info_update" value="<?php _e('Update options') ?> »" /></p>
     			</div></form>
    			<?php
	} // end function options_page

	function add_admin_panels() {
		global $wordpressOpenIDRegistrationUI;
		add_options_page('Open ID options', 'OpenID', 8, 'global-openid-options', array( $wordpressOpenIDRegistrationUI, 'options_page')  );
		if( $this->oid->enabled ) add_submenu_page('profile.php', 'Your OpenID Identities', 'Your OpenID Identities', 'read', 'your-openid-identities', array($this, 'profile_panel') );
	}

	function profile_panel() {
		if( current_user_can('read') ) {
			$this->oid->late_bind();
		?>

		<?php if( 'success' == $this->oid->action ) { ?>
			<div class="updated"><p><strong>Success: <?php echo $this->oid->error; ?>.</strong></p></div>
		<?php } elseif( $this->oid->error ) { ?>
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
					<td><a href="<?php echo $v['url']; ?>"><?php echo $v['url']; ?></a></td>
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
