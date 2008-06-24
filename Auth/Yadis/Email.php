<?php

/**
 * Implementation of OpenID Email Address Transform Extension
 *
 * PHP versions 4 and 5
 *
 * @author Will Norris <will@willnorris.com>
 * @copyright 2008 Will Norris.
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache
 */

require_once 'Auth/Yadis/Yadis.php';
require_once 'Auth/OpenID.php';

/**
 * XRDS type for Email Address Transformation Template.
 */
define('Auth_Yadis_ETT_Type', 'http://specs.openid.net/oeat/1.0/ett');

/**
 * XRDS type for Email Address to ID mapper.
 */
define('Auth_Yadis_EATOID_Type', 'http://specs.openid.net/oeat/1.0/eatoid');

/**
 * Alternate XRDS type for Email Address to ID mapper.
 */
define('Auth_Yadis_EmailToUrl_Type', 'http://schemas.net/2008/email-to-url/');

/**
 * ETT Wildcard for username
 */
define('Auth_Yadis_ETT_Wildcard_Username', '[username]');

/**
 * Default service for email mapping.
 */
if (!defined('Auth_Yadis_Default_Email_Mapper')) {
	define('Auth_Yadis_Default_Email_Mapper', 'http://emailtoid.net/');
}

function Auth_Yadis_Email_getEmailTypeURIs() 
{
	return array(Auth_Yadis_ETT_Type,
		         Auth_Yadis_EATOID_Type,
				 Auth_Yadis_EmailToUrl_Type);
}

function filter_MatchesAnyEmailType(&$service) 
{
	$uris = $service->getTypes();

    foreach ($uris as $uri) {
        if (in_array($uri, Auth_Yadis_Email_getEmailTypeURIs())) {
            return true;
        }   
    }   

    return false;
}


/**
 * Function for performaing email addresss to ID translation.
 */
function Auth_Yadis_Email_getID($email, $site_name = '') {
	list($user, $domain) = split('@', $email, 2);

	$fetcher = Auth_Yadis_Yadis::getHTTPFetcher();

	$services = Auth_Yadis_Email_getServices($domain, $fetcher);
	if (empty($services)) {
		$services = Auth_Yadis_Email_getServices(Auth_Yadis_Default_Email_Mapper, $fetcher);
	}

	foreach ($services as $s) {
		$types = $s->getTypes();
		$uris = $s->getURIs();

		if (empty($types) || empty($uris)) {
			continue;
		}

		foreach ($types as $t) {
			switch ("$t") {
				case Auth_Yadis_ETT_Type:
					$id = Auth_Yadis_Email_translateETT($uris[0], $user);
					break;
				case Auth_Yadis_EATOID_Type:
					$id = Auth_Yadis_Email_translateEATOID($uris[0], $email);
					break;
				case Auth_Yadis_EmailToUrl_Type:
					$id = Auth_Yadis_Email_translateEmailToUrl($uris[0], $email, $site_name);
					break;
			}

			if ($id) {
				return $id;
			}
		}

	}

}

function Auth_Yadis_Email_getServices($uri, $fetcher) {
	$uri = Auth_OpenID::normalizeUrl($uri);

	$response = Auth_Yadis_Yadis::discover($uri, $fetcher);
	if ($response->isXRDS()) {
		$xrds =& Auth_Yadis_XRDS::parseXRDS($response->response_text);
		if ($xrds) {
			return $xrds->services(array('filter_MatchesAnyEmailType'));
		}
	}
}

function Auth_Yadis_Email_translateETT($ett, $user) {
	//TODO verify valid ETT
	return preg_replace('/'.preg_quote(Auth_Yadis_ETT_Wildcard_Username).'/', $user, $ett);
}

function Auth_Yadis_Email_translateEATOID($uri, $email) {
	$url_parts = parse_url($uri);

	if (empty($url_parts['query'])) {
		return $uri . '?email=' . $email;
	} else {
		return $uri . '&email=' . $email;
	}
}

function Auth_Yadis_Email_translateEmailToUrl($uri, $email, $site_name) {
	return Auth_Yadis_Email_translateEATOID($uri, $email) 
		. ($site_name ? "&site_name=$site_name" : '');
}


?>
