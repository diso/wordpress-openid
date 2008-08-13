<?php
/**
 * store.php
 *
 * Database Connector for wp-openid
 * Dual Licence: GPL & Modified BSD
 */
require_once 'Auth/OpenID/DatabaseConnection.php';
require_once 'Auth/OpenID/SQLStore.php';
require_once 'Auth/OpenID/MySQLStore.php';
require_once 'Auth/OpenID/Association.php';

if (class_exists( 'Auth_OpenID_MySQLStore' ) && !class_exists('WordPressOpenID_Store')):
class WordPressOpenID_Store extends Auth_OpenID_MySQLStore {
	var $associations_table_name;
	var $nonces_table_name;
	var $identity_table_name;
	var $comments_table_name;
	var $usermeta_table_name;

	function WordPressOpenID_Store()
	{
		global $wpdb;

		$this->associations_table_name = openid_associations_table();
		$this->nonces_table_name = openid_nonces_table();
		$this->identity_table_name =  openid_identity_table();
		$this->comments_table_name =  openid_comments_table();
		$this->usermeta_table_name =  openid_usermeta_table();

		$conn = new WordPressOpenID_Connection( $wpdb );
		parent::Auth_OpenID_MySQLStore(
			$conn,
			$this->associations_table_name,
			$this->nonces_table_name
		);
	}

	function isError($value)
	{
		return $value === false;
	}

	function blobEncode($blob)
	{
		return $blob;
	}

	function blobDecode($blob)
	{
		return $blob;
	}

	/**
	 * Set SQL for database calls.
	 * 
	 * @see Auth_OpenID_SQLStore::setSQL
	 */
	function setSQL()
	{
		$this->sql['nonce_table'] =
				"CREATE TABLE %s (
					server_url varchar(255) CHARACTER SET latin1,
					timestamp int(11),
					salt char(40) CHARACTER SET latin1,
					UNIQUE KEY server_url (server_url(255),timestamp,salt)
				)";

		$this->sql['assoc_table'] =
				"CREATE TABLE %s (
					server_url varchar(255) CHARACTER SET latin1,
					handle varchar(255) CHARACTER SET latin1,
					secret blob,
					issued int(11),
					lifetime int(11),
					assoc_type varchar(64),
					PRIMARY KEY  (server_url(235),handle)
				)";

		$this->sql['set_assoc'] =
				"REPLACE INTO %s VALUES (%%s, %%s, %%s, %%d, %%d, %%s)";

		$this->sql['get_assocs'] =
				"SELECT handle, secret, issued, lifetime, assoc_type FROM %s ".
				"WHERE server_url = %%s";

		$this->sql['get_assoc'] =
				"SELECT handle, secret, issued, lifetime, assoc_type FROM %s ".
				"WHERE server_url = %%s AND handle = %%s";

		$this->sql['remove_assoc'] =
				"DELETE FROM %s WHERE server_url = %%s AND handle = %%s";

		$this->sql['add_nonce'] =
				"REPLACE INTO %s (server_url, timestamp, salt) VALUES (%%s, %%d, %%s)";

		$this->sql['get_expired'] =
				"SELECT server_url FROM %s WHERE issued + lifetime < %%s";

		$this->sql['clean_nonce'] =
				"DELETE FROM %s WHERE timestamp < %%s";

		$this->sql['clean_assoc'] =
				"DELETE FROM %s WHERE issued + lifetime < %%s";
	}

}
endif;


/**
 * WordPressOpenID_Connection class implements a PEAR-style database connection using the WordPress WPDB object.
 * Written by Josh Hoyt
 * Modified to support setFetchMode() by Alan J Castonguay, 2006-06-16
 */
if (class_exists('Auth_OpenID_DatabaseConnection') && !class_exists('WordPressOpenID_Connection')):
class WordPressOpenID_Connection extends Auth_OpenID_DatabaseConnection {
	var $fetchmode = ARRAY_A;  // to fix PHP Fatal error:  Cannot use object of type stdClass as array in /usr/local/php5/lib/php/Auth/OpenID/SQLStore.php on line 495

	function WordPressOpenID_Connection(&$wpdb) {
		$this->wpdb =& $wpdb;
	}
	function _fmt($sql, $args) {
		$interp = new MySQLInterpolater($this->wpdb->dbh);
		return $interp->interpolate($sql, $args);
	}
	function query($sql, $args) {
		return $this->wpdb->query($this->_fmt($sql, $args));
	}
	function getOne($sql, $args=null) {
		if($args==null) $args = array();
		return $this->wpdb->get_var($this->_fmt($sql, $args));
	}
	function getRow($sql, $args) {
		return $this->wpdb->get_row($this->_fmt($sql, $args), $this->fetchmode);
	}
	function getAll($sql, $args) {
		return $this->wpdb->get_results($this->_fmt($sql, $args), $this->fetchmode);
	}

	/* This function translates fetch mode constants PEAR=>WPDB
	 * DB_FETCHMODE_ASSOC   => ARRAY_A
	 * DB_FETCHMODE_ORDERED => ARRAY_N
	 * DB_FETCHMODE_OBJECT  => OBJECT  (default)
	 */
	function setFetchMode( $mode ) {
		if( DB_FETCHMODE_ASSOC == $mode ) $this->fetchmode = ARRAY_A;
		if( DB_FETCHMODE_ORDERED == $mode ) $this->fetchmode = ARRAY_N;
		if( DB_FETCHMODE_OBJECT == $mode ) $this->fetchmode = OBJECT;
	}

	function affectedRows() { 
		return mysql_affected_rows($this->wpdb->dbh);
	}

	function commit() {
		return @mysql_query('COMMIT', $this->wpdb->dbh);
	}
}
endif;



/**
 * Object for doing SQL substitution
 *
 * The internal state should be consistent across calls, so feel free
 * to re-use this object for more than one formatting operation.
 *
 * Allowed formats:
 *  %s -> string substitution (binary allowed)
 *  %d -> integer substitution
 */
if (!class_exists('Interpolater')):
class Interpolater {

	/**
	 * The pattern to use for substitution
	 */
	var $pattern = '/%([sd])/';

	/**
	 * Constructor
	 *
	 * Just sets the initial state to empty
	 */
	function Interpolater() {
		$this->values = false;
	}

	/**
	 * Escape a string for an SQL engine.
	 *
	 * Override this function to customize string escaping.
	 *
	 * @param string $s The string to escape
	 * @return string $escaped The escaped string
	 */
	function escapeString($s) {
		return addslashes($s);
	}

	/**
	 * Perform one replacement on a value
	 *
	 * Dispatch to the approprate format function
	 *
	 * @param array $matches The matches from this object's pattern
	 *	 with preg_match
	 * @return string $escaped An appropriately escaped value
	 * @access private
	 */
	function interpolate1($matches) {
		if (!$this->values) {
			error_log('Not enough values for format string');
		}
		$value = array_shift($this->values);
		if (is_null($value)) {
			return 'NULL';
		}
		return call_user_func(array($this, 'format_' . $matches[1]), $value);
	}

	/**
	 * Format and quote a string for use in an SQL query
	 *
	 * @param string $value The string to escape. It may contain any
	 *	 characters.
	 * @return string $escaped The escaped string
	 * @access private
	 */

	function format_s($value) {
		if (get_magic_quotes_gpc()) {
			$value = stripslashes($value);
		}
		$val_esc = $this->escapeString($value);
		return "'$val_esc'";
	}

	/**
	 * Format an integer for use in an SQL query
	 *
	 * @param integer $value The number to use in the query
	 * @return string $escaped The number formatted as a string
	 * @access private
	 */
	function format_d($value) {
		$val_int = (integer)$value;
		return (string)$val_int;
	}

	/**
	 * Create an escaped query given this format string and these
	 * values to substitute
	 *
	 * @param string $format_string A string to match
	 * @param array $values The values to substitute into the format string
	 */
	function interpolate($format_string, $values) {
		$matches = array();
		$this->values = $values;
		$callback = array(&$this, 'interpolate1');
		$s = preg_replace_callback($this->pattern, $callback, $format_string);
		if ($this->values) {
			error_log('Too many values for format string: ' . $format_string . " => " . implode(', ', $this->values));
		}
		$this->values = false;
		return $s;
	}
}
endif;

/**
 * Interpolate MySQL queries
 */
if (class_exists('Interpolater') && !class_exists('MySQLInterpolater')):
class MySQLInterpolater extends Interpolater {
	function MySQLInterpolater($dbconn=false) {
		$this->dbconn = $dbconn;
		$this->values = false;
	}

	function escapeString($s) {
		if ($this->dbconn === false) {
			return mysql_real_escape_string($s);
		} else {
			return mysql_real_escape_string($s, $this->dbconn);
		}
	}
}
endif;


if (!class_exists('WordPress_OpenID_OptionStore')):
class WordPress_OpenID_OptionStore extends Auth_OpenID_OpenIDStore {
	var $KEY_LEN = 20;
	var $MAX_NONCE_AGE = 21600; // 6 * 60 * 60
	function WordPress_OpenID_SerializedStore() {
		;
	}
	function storeAssociation($server_url, $association) {
		$key = $this->_getAssociationKey($server_url, $association->handle);
		$association_s = $association->serialize();
		// prevent the likelihood of a race condition - don't rely on cache
		wp_cache_delete('openid_associations', 'options');
		$associations = get_option('openid_associations');
		if ($associations == null) {
			$associations = array();
		}
		$associations[$key] = $association_s;
		update_option('openid_associations', $associations);
	}
	function getAssociation($server_url, $handle = null) {
		//wp_cache_delete('openid_associations', 'options');
		if ($handle === null) {
			$handle = '';
		}
		$key = $this->_getAssociationKey($server_url, $handle);
		$associations = get_option('openid_associations');
		if ($handle && array_key_exists($key, $associations)) {
			return Auth_OpenID_Association::deserialize(
				'Auth_OpenID_Association', $associations[$key]
			);
		} else {
			// Return the most recently issued association
			$matching_keys = array();
			foreach (array_keys($associations) as $assoc_key) {
				if (strpos($assoc_key, $key) === 0) {
					$matching_keys[] = $assoc_key;
				}
			}
			$matching_associations = array();
			// sort by time issued
			foreach ($matching_keys as $assoc_key) {
				if (array_key_exists($assoc_key, $associations)) {
					$association = Auth_OpenID_Association::deserialize(
						'Auth_OpenID_Association', $associations[$assoc_key]
					);
				}
				if ($association !== null) {
					$matching_associations[] = array(
						$association->issued, $association
					);
				}
			}
			$issued = array();
			$assocs = array();
			foreach ($matching_associations as $assoc_key => $assoc) {
				$issued[$assoc_key] = $assoc[0];
				$assocs[$assoc_key] = $assoc[1];
			}
			array_multisort($issued, SORT_DESC, $assocs, SORT_DESC,
							$matching_associations);

			// return the most recently issued one.
			if ($matching_associations) {
				list($issued, $assoc) = $matching_associations[0];
				return $assoc;
			} else {
				return null;
			}
		}
	}
	function _getAssociationKey($server_url, $handle) {
		if (strpos($server_url, '://') === false) {
			trigger_error(sprintf("Bad server URL: %s", $server_url),
						  E_USER_WARNING);
			return null;
		}
		list($proto, $rest) = explode('://', $server_url, 2);
		$parts = explode('/', $rest);
		$domain = $parts[0];
		$url_hash = base64_encode($server_url);
		if ($handle) {
			$handle_hash = base64_encode($handle);
		} else {
			$handle_hash = '';
		}
		return sprintf('%s-%s-%s-%s',
			$proto, $domain, $url_hash, $handle_hash);
	}

	function removeAssociation($server_url, $handle) {
		// Remove the matching association if it's found, and
		// returns whether the association was removed or not.
		$key = $this->_getAssociationKey($server_url, $handle);
		$assoc = $this->getAssociation($server_url, $handle);
		if ($assoc === null) {
			return false;
		} else {
			$associations = get_option('openid_associations');
			if (isset($associations[$key])) {
				unset($associations[$key]);
				update_option('openid_associations', $associations);
				return true;
			} else {
				return false;
			}
		}		
	}
	function useNonce($server_url, $timestamp, $salt) {
		global $Auth_OpenID_SKEW;

		if ( abs($timestamp - time()) > $Auth_OpenID_SKEW ) {
			return false;
		}

		$key = $this->_getNonceKey($server_url, $timestamp, $salt);

		// prevent the likelihood of a race condition - don't rely on cache
		wp_cache_delete('openid_nonces', 'options');
		$nonces = get_option('openid_nonces');
		if ($nonces == null) {
			$nonces = array();
		}

		if (array_key_exists($key, $nonces)) {
			return false;
		} else {
			$nonces[$key] = $timestamp;
			update_option('openid_nonces', $nonces);
			return true;
		}
	}

	function _getNonceKey($server_url, $timestamp, $salt) {
		if ($server_url) {
			list($proto, $rest) = explode('://', $server_url, 2);
		} else {
			$proto = '';
			$rest = '';
		}

		$parts = explode('/', $rest, 2);
		$domain = $parts[0];
		$url_hash = base64_encode($server_url);
		$salt_hash = base64_encode($salt);

		return sprintf('%08x-%s-%s-%s-%s', $timestamp, $proto, 
			$domain, $url_hash, $salt_hash);
	}

	function cleanupNonces() { 
		global $Auth_OpenID_SKEW;

		$nonces = get_option('openid_nonces');

		foreach ($nonces as $nonce => $time) {
			if ( abs($time - time()) > $Auth_OpenID_SKEW ) {
				unset($nonces[$nonce]);
			}
		}

		update_option('openid_nonces', $nonces);
	
	}

	function cleanupAssociations() { 
		$associations = get_option('openid_associations');

		foreach ($associations as $key => $assoc_s) {
			$assoc = Auth_OpenID_Association::deserialize('Auth_OpenID_Association', $assoc_s);

			if ( $assoc->getExpiresIn() == 0) {
				unset($associations[$key]);
			}
		}

		update_option('openid_associations', $associations);
	}

	function reset() { 
		update_option('openid_nonces', array());
		update_option('openid_associations', array());
	}
}
endif;


/**
 * Check to see whether the nonce, association, and identity tables exist.
 *
 * @param bool $retry if true, tables will try to be recreated if they are not okay
 * @return bool if tables are okay
 */
function openid_check_tables($retry=true) {
	global $wpdb;

	$ok = true;
	$message = array();
	$tables = array(
		openid_associations_table(),
		openid_nonces_table(),
		openid_identity_table(),
	);
	foreach( $tables as $t ) {
		if( $wpdb->get_var("SHOW TABLES LIKE '$t'") != $t ) {
			$ok = false;
			$message[] = "Table $t doesn't exist.";
		} else {
			$message[] = "Table $t exists.";
		}
	}
		
	if( $retry and !$ok) {
		openid_create_tables();
		$ok = openid_check_tables( false );
	}
	return $ok;
}

/**
 * Create OpenID related tables in the WordPress database.
 */
function openid_create_tables()
{
	global $wp_version, $wpdb;

	$store = openid_getStore();

	if ($wp_version >= '2.3') {
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
	} else {
		require_once(ABSPATH . 'wp-admin/admin-db.php');
		require_once(ABSPATH . 'wp-admin/upgrade-functions.php');
	}

	// Create the SQL and call the WP schema upgrade function
	$statements = array(
		$store->sql['nonce_table'],
		$store->sql['assoc_table'],

		"CREATE TABLE ".openid_identity_table()." (
		uurl_id bigint(20) NOT NULL auto_increment,
		user_id bigint(20) NOT NULL default '0',
		url text,
		hash char(32),
		PRIMARY KEY  (uurl_id),
		UNIQUE KEY uurl (hash),
		KEY url (url(30)),
		KEY user_id (user_id)
		)",
	);

	$sql = implode(';', $statements);
	dbDelta($sql);

	// add column to comments table
	$result = maybe_add_column(openid_comments_table(), 'openid',
			"ALTER TABLE ".openid_comments_table()." ADD `openid` TINYINT(1) NOT NULL DEFAULT '0'");

	if (!$result) {
		error_log('unable to add column `openid` to comments table.');
	}

	// update old style of marking openid comments and users
	$wpdb->query("update ".openid_comments_table()." set `comment_type`='', `openid`=1 where `comment_type`='openid'");
	$wpdb->query("update ".openid_usermeta_table()." set `meta_key`='has_openid' where `meta_key`='registered_with_openid'");
}


/**
 * Remove database tables which hold only transient data - associations and nonces.  Any non-transient data, such
 * as linkages between OpenIDs and WordPress user accounts are maintained.
 */
function openid_destroy_tables() {
	global $wpdb;

	$sql = 'drop table ' . openid_associations_table();
	$wpdb->query($sql);
	$sql = 'drop table ' . openid_nonces_table();
	$wpdb->query($sql);

	// just in case they've upgraded from an old version
	$settings_table_name = (isset($wpdb->base_prefix) ? $wpdb->base_prefix : $wpdb->prefix ).'openid_settings';
	$sql = "drop table if exists $settings_table_name";
	$wpdb->query($sql);
}

?>
