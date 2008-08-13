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
			$xrds = xrds_add_service($xrds, 'main', 'OpenID Provider Service', 
				array(
					'Type' => array(
						array('content' => 'http://specs.openid.net/auth/2.0/signon'),
						array('content' => 'http://openid.net/signon/1.1'),
					),
					'URI' => array(array('content' => $server )),
					'LocalID' => array($identifier),
					'openid:Delegate' => array($identifier),
				)
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

		if (!is_user_logged_in()) {
			$_SESSION['openid_server_request'] = $request;
			auth_redirect();
		}

		if ($request->identity == 'http://specs.openid.net/auth/2.0/identifier_select') {
			$user = wp_get_current_user();
			$author_url = get_author_posts_url($user->ID);
			if (!empty($author_url)) {
				$response = $request->answer(true, null, $author_url);
			} else {
				$response = $request->answer(false);
			}
		} else {
			$response = $request->answer(openid_server_check_user_login($request->identity));
		}
	} else {
		$response = $server->handleRequest($request);
	}

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

function openid_server_check_user_login($claimed) {
	$user = wp_get_current_user();
	if (!$user) return false;

	$identifier = get_author_posts_url($user->ID);
	return ($claimed == $identifier);
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

?>
