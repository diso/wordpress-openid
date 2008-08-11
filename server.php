<?php


add_filter( 'xrds_simple', 'openid_provider_xrds_simple');

function openid_provider_xrds_simple($xrds) {
	$user = openid_server_requested_user();
	
	if (!$user && get_option('openid_blog_owner')) {
		$user = get_userdatabylogin(get_option('openid_blog_owner'));
	}

	if ($user) {
		$identifier = get_author_posts_url($user->ID);

		// OpenID Provider Service
		$xrds = xrds_add_service($xrds, 'main', 'OpenID Provider Service', 
			array(
				'Type' => array(
					array('content' => 'http://specs.openid.net/auth/2.0/signon'),
					array('content' => 'http://openid.net/signon/1.1'),
				),
				'URI' => array(array('content' => trailingslashit(get_option('siteurl')) . '?openid_server=1') ),
				'LocalID' => array($identifier),
				'openid:Delegate' => array($identifier),
			)
		);
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
	$request = $server->decodeRequest();
	if (in_array($request->mode, array('check_immediate', 'checkid_setup'))) {
		$response = $request->answer(openid_server_check_user_login($request->identity));
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
		set_include_path( dirname(__FILE__) . PATH_SEPARATOR . get_include_path() );
		require_once 'Auth/OpenID/Server.php';
		$server = new Auth_OpenID_Server(openid_getStore(), trailingslashit(get_option('siteurl')) . '?openid_server=1');
		restore_include_path();
	}

	return $server;
}
?>
