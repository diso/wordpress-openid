<?php

require_once 'Auth/OpenID/Server.php';

add_filter( 'xrds_simple', 'openid_provider_xrds_simple');
add_action( 'wp_head', 'openid_provider_link_tags');

function openid_provider_xrds_simple($xrds) {
	$user = openid_server_requested_user();
	
	if (!$user && get_option('openid_blog_owner')) {
		$url_parts = parse_url(get_option('home'));
		if ('/' . $url_parts['path'] != $_SERVER['REQUEST_URI']) {
			return $xrds;
		}

		$user = get_userdatabylogin(get_option('openid_blog_owner'));
	}

	if ($user) {
		if (get_usermeta($user->ID, 'use_openid_provider') == 'local') {
			$server = trailingslashit(get_option('siteurl')) . '?openid_server=1';
			$identifier = get_author_posts_url($user->ID);
		} else if (get_usermeta($user->ID, 'use_openid_provider') == 'delegate') {
			$server = get_usermeta($user->ID, 'openid_server');
			$identifier = get_usermeta($user->ID, 'openid_delegate');
		}

		if ($server && $identifier) {
			// OpenID Provider Service
			$xrds = xrds_add_service($xrds, 'main', 'OpenID 2.0 Provider Service', 
				array(
					'Type' => array(
						array('content' => 'http://specs.openid.net/auth/2.0/signon'),
					),
					'URI' => array(array('content' => $server )),
					'LocalID' => array($identifier),
				), 0
			);

			$xrds = xrds_add_service($xrds, 'main', 'OpenID 1.0 Provider Service', 
				array(
					'Type' => array(
						array('content' => 'http://openid.net/signon/1.1'),
					),
					'URI' => array(array('content' => $server )),
					'openid:Delegate' => array($identifier),
				), 1
			);
		}
	} else {
		// OpenID Provider Service
		$xrds = xrds_add_service($xrds, 'main', 'OpenID Provider Service', 
			array(
				'Type' => array(
					array('content' => 'http://specs.openid.net/auth/2.0/server'),
				),
				'URI' => array(array('content' => trailingslashit(get_option('siteurl')) . '?openid_server=1') ),
				'LocalID' => array('http://specs.openid.net/auth/2.0/identifier_select'),
			)
		);
	}

	return $xrds;
}

function openid_server_requested_user() {
	global $wp_rewrite;

	if ($_REQUEST['author']) {
		return get_userdatabylogin($_REQUEST['author']);
	} else {
		$regex = preg_replace('/%author%/', '(.+)', $wp_rewrite->get_author_permastruct());
		preg_match('|'.$regex.'|', $_SERVER['REQUEST_URI'], $matches);
		$username = sanitize_user($matches[1], true);
		return get_userdatabylogin($username);
	}
}

function openid_server_request() {
	$server = openid_server();

	if ($_SESSION['openid_server_request']) {
		$request = $_SESSION['openid_server_request'];
		unset($_SESSION['openid_server_request']);
	} else {
		$request = $server->decodeRequest();
	}


	if (in_array($request->mode, array('check_immediate', 'checkid_setup'))) {
		// TODO: no prompt allowed for immediate

		if (!is_user_logged_in()) {
			$_SESSION['openid_server_request'] = $request;
			auth_redirect();
		}

		$user = wp_get_current_user();

		if ($request->identity == 'http://specs.openid.net/auth/2.0/identifier_select') {
			// OpenID Provider driven identity selection
			$author_url = get_author_posts_url($user->ID);
			if (!empty($author_url)) {
				$response = $request->answer(true, null, $author_url);
			} else {
				$response = $request->answer(false);
			}
		} else {
			if ($_REQUEST['openid_trust']) {
				$trust = openid_server_process_trust($request);
				$user_check = openid_server_check_user_login($request->identity);
				$response = $request->answer(($trust && $user_check));
			} else {
				$trusted_sites = get_usermeta($user->ID, 'openid_trusted_sites');
				if (in_array($request->trust_root, $trusted_sites)) {
					$response = $request->answer(openid_server_check_user_login($request->identity));
				} else {
					openid_server_trust_prompt($request);
				}
			}
		}
	} else {
		$response = $server->handleRequest($request);
	}

	openid_server_process_response($response);
}


function openid_server_check_user_login($claimed) {
	$user = wp_get_current_user();
	if (!$user) return false;

	$identifier = get_author_posts_url($user->ID);
	return ($claimed == $identifier);
}


function openid_server_process_response($response) {
	$server = openid_server();

	$web_response = $server->encodeResponse($response);

	if ($web_response->code != AUTH_OPENID_HTTP_OK) {
		header(sprintf('HTTP/1.1 %d', $web_response->code), true, $web_response->code);
	}
	foreach ($web_response->headers as $k => $v) {
		header("$k: $v");
	}

	print $web_response->body;
	exit;
}


function openid_server() {
	static $server;

	if (!$server || !is_a($server, 'Auth_OpenID_Server')) {
		$server = new Auth_OpenID_Server(openid_getStore(), trailingslashit(get_option('siteurl')) . '?openid_server=1');
	}

	return $server;
}


function openid_provider_link_tags() {

	if (is_front_page()) {
		$user = get_userdatabylogin(get_option('openid_blog_owner'));
	} else if (is_author()) {
		global $wp_query;
		$user = $wp_query->get_queried_object();
	}

	if ($user) {
		if (get_usermeta($user->ID, 'use_openid_provider') == 'local') {
			$server = trailingslashit(get_option('siteurl')) . '?openid_server=1';
			$identifier = get_author_posts_url($user->ID);
		} else if (get_usermeta($user->ID, 'use_openid_provider') == 'delegate') {
			$server = get_usermeta($user-ID, 'openid_server');
			$identifier = get_usermeta($user-ID, 'openid_delegate');
		}
	}

	if ($server && $identifier) {
		echo '
		<link rel="openid2.provider" href="'.$server.'" />
		<link rel="openid2.local_id" href="'.$identifier.'" />
		<link rel="openid.server" href="'.$server.'" />
		<link rel="openid.delegate" href="'.$identifier.'" />';
	}

}


function openid_server_trust_prompt($request) {
	$_SESSION['openid_server_request'] = $request;

	$html = '
	   	<form action="' . trailingslashit(get_option('siteurl')) . '?openid_server=1" method="post">
			<p>Do you want to trust the site <strong>'.$request->trust_root.'</strong>?</p>
			<input type="submit" name="openid_trust" value="No" />
			<input type="submit" name="openid_trust" value="Trust Once" />
			<input type="submit" name="openid_trust" value="Trust Always" />
	' . wp_nonce_field('wp-openid-server_trust', '_wpnonce', true, false) . '
		</form>';

	wp_die($html, 'OpenID Trust Request');
}

function openid_server_process_trust($request) {
	check_admin_referer('wp-openid-server_trust');

	switch ($_REQUEST['openid_trust']) {
		case 'Trust Always': 
			$user = wp_get_current_user();
			$trusted_sites = get_usermeta($user->ID, 'openid_trusted_sites');
			$trusted_sites[] = $request->trust_root;
			update_usermeta($user->ID, 'openid_trusted_sites', $trusted_sites);
			return true;
			
		case 'Trust Once': 
			return true;

		case 'No': 
			return false;
	}
}

?>
