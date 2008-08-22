<?php

require_once 'Auth/OpenID/Server.php';
require_once 'server_ext.php';

add_filter( 'xrds_simple', 'openid_provider_xrds_simple');
add_action( 'wp_head', 'openid_provider_link_tags');


/**
 * Add XRDS entries for OpenID Server.  Entries added will be highly 
 * dependant on the requested URL and plugin configuration.
 */
function openid_provider_xrds_simple($xrds) {
	$user = openid_server_requested_user();
	
	if (!$user && get_option('openid_blog_owner')) {
		$url_parts = parse_url(get_option('home'));
		if ('/' . $url_parts['path'] != $_SERVER['REQUEST_URI']) {
			return $xrds;
		}

		if (defined('OPENID_ALLOW_OWNER') && OPENID_ALLOW_OWNER) {
			$user = get_userdatabylogin(get_option('openid_blog_owner'));
		}
	}

	if ($user) {
		if (get_usermeta($user->ID, 'use_openid_provider') == 'local') {
			$services = array(
				0 => array(
					'types' => array('http://specs.openid.net/auth/2.0/signon'),
					'server' => trailingslashit(get_option('siteurl')) . '?openid_server=1',
					'local' => get_author_posts_url($user->ID),
				),
				1 => array(
					'types' => array('http://openid.net/signon/1.1'),
					'server' => trailingslashit(get_option('siteurl')) . '?openid_server=1',
					'local' => get_author_posts_url($user->ID),
				),
			);
		} else if (get_usermeta($user->ID, 'use_openid_provider') == 'delegate') {
			$services = get_usermeta($user->ID, 'openid_delegate_services');
		}

		if (!empty($services)) {
			$priority = 0;

			foreach ($services as $service) {
				$types = array();
				foreach ($service['types'] as $t) {
					$types[] = array('content' => $t);
				}

				$xrds = xrds_add_service($xrds, 'main', 'OpenID Provider Service ' . $priority, 
					array(
						'Type' => $types,
						'URI' => array(array('content' => $service['server'])),
						'LocalID' => array($service['local']),
						'openid:Delegate' => array($service['local']),
					), $priority++
				);
			}
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


/**
 * Parse the request URL to determine which author is associated with it.
 *
 * @return bool|object false on failure, User DB row object
 */
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


/**
 * Process an OpenID Server request.
 */
function openid_server_request() {
	$server = openid_server();

	// get OpenID request, either from session or HTTP request
	if ($_SESSION['openid_server_request']) {
		$request = $_SESSION['openid_server_request'];
		unset($_SESSION['openid_server_request']);
	} else {
		$request = $server->decodeRequest();
	}

	// process request
	if (in_array($request->mode, array('check_immediate', 'checkid_setup'))) {
		$response = openid_server_auth_request($request);
		$response = apply_filters('openid_server_auth_response', $response);
	} else {
		$response = $server->handleRequest($request);
	}

	openid_server_process_response($response);
}


/**
 * Process an OpenID Server authentication request.
 */
function openid_server_auth_request($request) {

	do_action('openid_server_pre_auth', $request);

	// user must be logged in
	if (!is_user_logged_in()) {
		if ($request->mode == 'check_immediate') {
			return $request->answer(false);
		} else {
			$_SESSION['openid_server_request'] = $request;
			auth_redirect();
		}
	}

	do_action('openid_server_post_auth', $request);

	// get some user data
	$user = wp_get_current_user();
	$author_url = get_author_posts_url($user->ID);
	$id_select = ($request->identity == 'http://specs.openid.net/auth/2.0/identifier_select');

	// bail if user doesn't own identity and not using id select
	if (!$id_select && ($author_url != $request->identity)) {
		return $request->answer(false);
	}

	// if user trusts site, we're done
	$trusted_sites = get_usermeta($user->ID, 'openid_trusted_sites');
	if (in_array($request->trust_root, $trusted_sites)) {
		return $id_select ? $request->answer(true, null, $author_url) : $request->answer(true);
	}

	// that's all we can do without interacting with the user... bail if using immediate
	if ($request->mode == 'check_immediate') {
		return $request->answer(false);
	}
		
	// finally, prompt the user to trust this site
	if (openid_server_user_trust($request)) {
		return $id_select ? $request->answer(true, null, $author_url) : $request->answer(true);
	} else {
		return $request->answer(false);
	}
}



/**
 * Check that the current user's author URL matches the claimed URL.
 *
 * @param string $claimed claimed url
 * @return bool whether the current user matches the claimed URL
 */
function openid_server_check_user_login($claimed) {
	$user = wp_get_current_user();
	if (!$user) return false;

	$identifier = get_author_posts_url($user->ID);
	return ($claimed == $identifier);
}


/**
 * Process OpenID server response
 *
 * @param object $response response object
 */
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


/**
 * Get Auth_OpenID_Server singleton.
 *
 * @return object Auth_OpenID_Server singleton instance
 */
function openid_server() {
	static $server;

	if (!$server || !is_a($server, 'Auth_OpenID_Server')) {
		$server = new Auth_OpenID_Server(openid_getStore(), trailingslashit(get_option('siteurl')) . '?openid_server=1');
	}

	return $server;
}


/**
 * Add OpenID HTML link tags when appropriate.
 */
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


/**
 * Determine if the current user trusts the the relying party of the OpenID authentication request.
 */
function openid_server_user_trust($request) {
	if ($_REQUEST['openid_trust']) {
		// the user has made a trust decision, now process it
		check_admin_referer('wp-openid-server_trust');
		$trust = null;

		switch ($_REQUEST['openid_trust']) {
			case 'Trust Always': 
				$user = wp_get_current_user();
				$trusted_sites = get_usermeta($user->ID, 'openid_trusted_sites');
				$trusted_sites[] = $request->trust_root;
				update_usermeta($user->ID, 'openid_trusted_sites', array_unique($trusted_sites));
				$trust = true;
				break;
				
			case 'Trust Once': 
				$trust = true;
				break;

			case 'No': 
				$trust = false;
				break;
		}

		do_action('openid_server_trust_submit', $trust, $_REQUEST);
		return $trust;

	} else {
		// prompt the user to make a trust decision
		$_SESSION['openid_server_request'] = $request;

		ob_start();
		echo '
			<form action="' . trailingslashit(get_option('siteurl')) . '?openid_server=1" method="post">
			<h1>OpenID Trust Request</h1>
			<p>Do you want to trust the site <strong>'.$request->trust_root.'</strong>?</p>';

		do_action('openid_server_trust_form');

		echo '
			<p class="submit">
				<input type="submit" name="openid_trust" value="No" />
				<input type="submit" name="openid_trust" value="Trust Once" />
				<input type="submit" name="openid_trust" value="Trust Always" />
			</p>';

		wp_nonce_field('wp-openid-server_trust', '_wpnonce', true);

		echo '
			</form>';

		$html = ob_get_contents();
		ob_end_clean();

		wp_die($html, 'OpenID Trust Request');
	}
}


/**
 * Discovery and cache OpenID services for a user's delegate OpenID.
 *
 * @param int $userid user ID
 * @url string URL to discover.  If not provided, user's current delegate will be used
 * @return bool true if successful
 */
function openid_server_update_delegation_info($userid, $url = null) {
	if (empty($url)) $url = get_usermeta($userid, 'openid_delegate');
	if (empty($url)) return false;

	$fetcher = Auth_Yadis_Yadis::getHTTPFetcher();
	$discoveryResult = Auth_Yadis_Yadis::discover($url, $fetcher);

	$endpoints = Auth_OpenID_ServiceEndpoint::fromDiscoveryResult($discoveryResult);

	$services = array();
	foreach ($endpoints as $endpoint) {
		$services[] = array(
			'types' => $endpoint->type_uris,
			'server' => $endpoint->server_url,
			'local' => $endpoint->local_id,
		);
	}

	if (empty($services)) return false;

	update_usermeta($userid, 'openid_delegate_services', $services);
	return true;
}

?>
