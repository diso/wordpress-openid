<?php

require_once 'Auth/OpenID/SReg.php';

add_action('openid_server_post_auth', 'openid_server_sreg_post_auth');

/**
 * See if the OpenID authentication request includes SReg and add additional hooks if so.
 */
function openid_server_sreg_post_auth($request) {
	$sreg_request = Auth_OpenID_SRegRequest::fromOpenIDRequest($request);
	if ($sreg_request) {
		$GLOBALS['openid_server_sreg_request'] = $sreg_request;
		add_action('openid_server_trust_form', 'openid_server_sreg_trust_form');
		add_action('openid_server_trust_submit', 'openid_server_sreg_trust_submit', 10, 2);
		add_action('openid_server_auth_response', 'openid_server_sreg_auth_response' );
	}
}


/**
 * Add SReg input fields to the OpenID Trust Form
 */
function openid_server_sreg_trust_form() {
	$sreg_request = $GLOBALS['openid_server_sreg_request'];
	$sreg_fields = $sreg_request->allRequestedFields();
	if (!empty($sreg_fields)) {
		echo '
			<p>The following profile data will be included:</p>
			<table class="form-table">';
		foreach ($sreg_fields as $field) {
			$name = $GLOBALS['Auth_OpenID_sreg_data_fields'][$field];
			echo '
				<tr>
					<th><label for="sreg_'.$field.'">'.$name.':</label></th>
					<td><input type="text id="sreg_'.$field.'" name="sreg['.$field.']" value="'.openid_server_sreg_from_profile($field).'" /></td>
				</tr>';
		}
		echo '</table>';
	}
}

/**
 * Based on input from the OpenID trust form, prep data to be included in the authentication response
 */
function openid_server_sreg_trust_submit($trust, $input) {
	if ($trust) {
		$GLOBALS['openid_server_sreg_input'] = $input['sreg'];
	} else {
		$GLOBALS['openid_server_sreg_input'] = array();
	}
}


/**
 * Attach SReg response to authentication response.
 */
function openid_server_sreg_auth_response($response) {
	if (isset($GLOBALS['openid_server_sreg_input'])) {
		$sreg_data = $GLOBALS['openid_server_sreg_input'];
	} else {
		foreach ($GLOBALS['Auth_OpenID_sreg_data_fields'] as $field => $name) {
			$sreg_data[$field] = openid_server_sreg_from_profile($field);
		}
	}

	$sreg_response = Auth_OpenID_SRegResponse::extractResponse($GLOBALS['openid_server_sreg_request'], $sreg_data);
	$response->addExtension($sreg_response);

	return $response;
}


/**
 * Try to pre-populate SReg data from user's profile.  Some of this require the diso-profile plugin.
 */
function openid_server_sreg_from_profile($field) {
	$user = wp_get_current_user();
	switch($field) {
		case 'nickname': // wp-core
			return get_usermeta($user->ID, 'nickname');

		case 'email': // wp-core
			return $user->user_email;

		case 'fullname': // wp-core
			return get_usermeta($user->ID, 'display_name');
		
		case 'dob': // ?
			return;

		case 'gender': // ?
			return;

		case 'postcode': // diso-profile
			return get_usermeta($user->ID, 'postalcode');

		case 'country': // diso-profile
			return get_usermeta($user->ID, 'countryname');

		case 'language': // ?
			return;

		case 'timezone': // wp-core (use's blog timezone)
			if (!function_exists('timezone_name_from_abbr')) return; // added in PHP 5.1.0
			return timezone_name_from_abbr('', (get_option('gmt_offset') * 3600), 0);
	}
}


?>
