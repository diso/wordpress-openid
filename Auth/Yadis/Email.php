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
            switch ($t) {
                case Auth_Yadis_ETT_Type:
                    // TODO verify valid ETT
                    $id =  preg_replace('/'.preg_quote(Auth_Yadis_ETT_Wildcard_Username).'/', $user, $uris[0]);
                    break;

                case Auth_Yadis_EATOID_Type:
                case Auth_Yadis_EmailToUrl_Type:
                    $url_parts = parse_url($uris[0]);

                    if (empty($url_parts['query'])) {
                        $id = $uris[0] . '?email=' . $email;
                    } else {
                        $id =  $uris[0] . '&email=' . $email;
                    }
                    
                    if ($t == Auth_Yadis_EmailToUrl_Type && $site_name) {
                        $id .= "&site_name=$site_name";
                    }

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

?>
