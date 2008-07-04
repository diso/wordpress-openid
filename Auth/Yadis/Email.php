<?php

/**
 * Implementation of Email Address to URL Transform protocol
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
 * XRDS type for EAUT Template.
 */
define('Auth_Yadis_EAUT_Template_Type', 'http://specs.eaut.org/1.0/template');

/**
 * XRDS type for EAUT Mapping Service.
 */
define('Auth_Yadis_EAUT_Mapper_Type', 'http://specs.eaut.org/1.0/mapping');

/**
 * EAUT Wildcard for username
 */
define('Auth_Yadis_EAUT_Wildcard_Username', '%7Busername%7D');

/**
 * Default service for email mapping.
 */
if (!defined('Auth_Yadis_Default_Email_Mapper')) {
    define('Auth_Yadis_Default_Email_Mapper', 'http://emailtoid.net/');
}

function Auth_Yadis_Email_getEmailTypeURIs() 
{
    return array(Auth_Yadis_EAUT_Template_Type, Auth_Yadis_EAUT_Mapper_Type,);
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
                case Auth_Yadis_EAUT_Template_Type:
                    // TODO verify valid EAUT Template
                    $id =  preg_replace('/'.preg_quote(Auth_Yadis_EAUT_Wildcard_Username).'/', $user, $uris[0]);
                    break;

                case Auth_Yadis_EAUT_Mapper_Type:
                    $url_parts = parse_url($uris[0]);

                    if (empty($url_parts['query'])) {
                        $id = $uris[0] . '?email=' . $email;
                    } else {
                        $id =  $uris[0] . '&email=' . $email;
                    }
                    
                    if ($site_name) {
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
