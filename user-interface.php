<?php
/*
  user-interface.php
  licence: modified bsd
  author: Alan J Castonguay
  purpose: User Interface Elements for wpopenid
 */


	/*  Output Buffer handler
	 *  @param $form - String of html
	 *  @return - String of html
	 *  Replaces parts of the wp-login.php form.
	 */
	function ajc_openid_wp_login_ob( $form ) {
			global $redirect_to, $action;

			switch( $action ) {
			case 'bind':
				$page = $this->page;
				
				$form = preg_replace( '#<form.*</form>#s', $page, $form, 1 );	// strip the whole form
				break;

			default:	
				$style = get_option('oid_enable_selfstyle') ? ('style="background: #f4f4f4 url('.OPENIDIMAGE.') no-repeat;
					background-position: 0 50%; padding-left: 18px;" ') : '';
					
				$newform = '<h2>WordPress User</h2>';
				$form = preg_replace( '#<form[^>]*>#', '\\0 <h2>WordPress User:</h2>', $form, 1 );
				
				$newform = '<p align="center">-or-</p><h2>OpenID Identity:</h2><p><label>'
					.__('OpenID Identity Url:').
					' <small><a href="http://openid.net/">' . __('What is this?') . '</a></small><br/><input ' . $style
					.'type="text" class="openid_url" name="openid_url" id="log" size="20" tabindex="5" /></label></p>';
				$form = preg_replace( '#<p class="submit">#', $newform . '\\0' , $form, 1 );
			}
			return $form;
	}


	/*
	 * Hook. Add sidebar login form, editing Register link.
	 * Turns SiteAdmin into Profile link in sidebar.
	 */
	function ajc_openid_wp_sidebar_register( $link ) {
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
		
	function ajc_openid_wp_sidebar_loginout( $link ) {
		return preg_replace( '#action=logout#', 'action=logout&redirect_to=' . urlencode($_SERVER["REQUEST_URI"]), $link );
	}
		
	/*
	 * Hook. Add OpenID login-n-comment box below the comment form.
	 */
	function ajc_openid_wp_comment_form( $id ) {
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


	/*
	 * Display and handle updates from the Admin screen options page.
	 */
	function ajc_openid_global_options_page() {
			// if we're posted back an update, let's set the values here
			if ( isset($_POST['info_update']) ) {
			
				$trust = $_POST['oid_trust_root'];
				if($trust == null ) $trust = get_settings('siteurl');
	
				$error = '';
				if( $this->openid_is_url($trust) ) {
					update_option('oid_trust_root', $trust);
				} else {
					$error .= "<p/>".$trust." is not a url!";
				}
				
				update_option( 'oid_enable_selfstyle', isset($_POST['enable_selfstyle']) ? true : false );
				update_option( 'oid_enable_loginform', isset($_POST['enable_loginform']) ? true : false );
				update_option( 'oid_enable_commentform', isset($_POST['enable_commentform']) ? true : false );
				
				if ($error != '') {
					echo '<div class="updated"><p><strong>At least one of Open ID options was NOT updated</strong>'.$error.'</p></div>';
				} else {
					echo '<div class="updated"><p><strong>Open ID options updated</strong></p></div>';
				}
				
			}

			if( !$this->enabled ) {
				global $wordpressOpenIDRegistrationErrors;
				?>
				<div class="error"><p><strong>There was a problem loading required libraries. Plugin disabled.</strong></p><ul>
				<?php
				foreach( $wordpressOpenIDRegistrationErrors as $k=>$v ) {
					echo "<li>$k - $v</li>";
				}
				?>
				</ul><p>You can place the requisite files in any of these directories:</p><ul>
				<?php
				$relativeto = dirname( __FILE__ ) . DIRECTORY_SEPARATOR;
				$paths = explode(PATH_SEPARATOR, get_include_path());
				foreach( $paths as $path ) {
					$fullpath = $path . DIRECTORY_SEPARATOR;
					if( $path == '.' ) $fullpath = '';
					if( substr( $path, 0, 1 ) !== '/' ) $fullpath = $relativeto . $fullpath;
					echo "<li><em>$fullpath</em></li>";
				}
				?></ul></div>
				<?php
			}
			
			// Display the options page form
			$siteurl = get_settings('siteurl');
			if( substr( $siteurl, -1, 1 ) !== '/' ) $siteurl .= '/';
			?>
			<form method="post"><div class="wrap">
				<h2>OpenID Registration Options</h2>
     				<fieldset class="options">
     					<p><em>Please refer to <a href="http://verisign.com">http://[TBD]</a> 
     					specification for more information.</em></p>
     					
     					<table class="editform" cellspacing="2" cellpadding="5" width="100%">
     					<tr valign="top"><th style="width: 10em; padding-top: 1.5em;">
     						<label for="oid_trust_root">Trust root:</label>
     					</th><td>
     						<p><input type="text" size="80" name="oid_trust_root" id="oid_trust_root"
     						value="<?php echo htmlentities(get_option('oid_trust_root')); ?>" /></p>
     						<p>Commenters will be asked whether they trust this url,
     						and its decendents, to know that they are logged in and control their identity url.
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
     						post comment form.</p>
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
	} // end function oid_options_page



	function ajc_openid_wp_admin_panel_add() {
		add_options_page('Open ID options', 'Open ID', 8, __FILE__, array( $this, 'oid_options_page')  );
		add_submenu_page('profile.php', 'Your Open ID Identities', 'Your Open ID Identities', 'read', 'openidpreferences', 'ajc_openid_wp_profilephp_panel' );
	}
	add_action( 'admin_menu', 'ajc_openid_wp_admin_panel_add' );
					

	function ajc_openid_wp_profilephp_panel() {
		if( current_user_can('read') ) {
		?>

		<div class="wrap">
		<h2>OpenID Identities</h2>
		<p>The following OpenID Identity Urls<a title="What is OpenID?" href="http://openid.net/">?</a> are tied to
		this user account. You can login with equivilent permissions using any of the following identity urls.</p>

		<?php
	
		// fetch a list of identifiers from the database
	
		?>
		</div>
		<?php
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