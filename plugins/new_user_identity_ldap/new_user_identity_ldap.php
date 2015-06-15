<?php
/**
 * New user LDAP identity
 *
 * Populates a new user's default identity from LDAP on their first visit.
 *
 * This plugin is based on new_user_identity plugin by Kris Steinhoff
 *
 * @version @package_version@
 * @author Adrian Gruntkowski
 * @license GNU GPLv3+
 *
 */
class new_user_identity_ldap extends rcube_plugin
{
    public $task = 'login';

    private $ldap;

    private $ldap_config;
    private $match;
    private $name_attribute;
    private $email_attribute;

    function init()
    {
        $this->add_hook('user_create', array($this, 'lookup_user_name'));

        $rcmail = rcmail::get_instance();
		$this->load_config();

        $this->ldap_config = $rcmail->config->get('new_user_identity_ldap_db');
        $this->name_attribute = $rcmail->config->get('new_user_identity_ldap_name_attr', 'name');
        $this->email_attribute = $rcmail->config->get('new_user_identity_ldap_email_attr', 'email');
    }

    function lookup_user_name($args)
    {
        $ldap = $this->_get_ldap($this->ldap_config);
        
        $result = $this->ldap->search($args['user']);

        if (!empty($result[$this->name_attribute])) {
            $args['user_name'] = $result[$this->name_attribute][0];
        }
        
        if (!empty($result[$this->email_attribute])) {
            $user_email = $result[$this->email_attribute][0];

            if (strpos($user_email, '@')) {
                $args['user_email'] = rcube_utils::idn_to_ascii($user_email);
            }
        }

        return $args;
    }

    private function _get_ldap($ldap_config)
    {
        if ($this->ldap) {
            return $this->ldap;
        }

        $this->ldap = new new_user_identity_ldap_backend($ldap_config);

        return $this->ldap;
    }
}

class new_user_identity_ldap_backend
{
    private $scope_to_function = array(
        'sub' => 'ldap_search',
        'onelevel' => 'ldap_list',
        'base' => 'ldap_find'
    );

    private $db;
    private $uri;
    private $protocol_version;
    private $use_tls;
    private $bind_dn;
    private $password;
    private $base_dn;
    private $filter_expr;

    function __construct($db_config)
    {
        $this->uri = $db_config['uri'];
        $this->protocol_version = $db_config['protocol_version'];
        $this->use_tls = $db_config['use_tls'];
        $this->bind_dn = $db_config['bind_dn'];
        $this->password = $db_config['password'];
        $this->base_dn = $db_config['base_dn'];
        $this->scope = $db_config['scope'];
        $this->filter_expr = $db_config['filter_expr'];
    }

    function search($user) {
        $this->_db_connect();

        $search_function = $this->scope_to_function[$this->scope];
        
        $result = $search_function(
            $this->db,
            $this->base_dn,
            preg_replace('/__USERNAME__/', $user, $this->filter_expr)
        );
        

        $attributes = null;

        if ($result !== false) {
            $entries = ldap_get_entries($this->db, $result);
            $attributes = $this->_normalize_attributes($entries[0]);
        }

        return $attributes;
    }

    private function _normalize_attributes($attributes) {
        $output = array();

        foreach ($attributes as $name => $values) {
            $new_values = (array) $values;
            unset($new_values['count']);
            $output[$name] = $new_values;
        }
        return $output;
    }


    private function _db_connect() {
        if ($this->db) {
            return;
        }

        $ldap_connection = ldap_connect($this->uri);

        ldap_set_option($ldap_connection, LDAP_OPT_PROTOCOL_VERSION, $this->protocol_version);

        $ldap_bind = false;
        if ($ldap_connection) {
            if (!$this->use_tls || ($this->use_tls && ldap_start_tls($ldap_connection))) {
                $ldap_bind = ldap_bind($ldap_connection, $this->bind_dn, $this->password);
            }
        }

        if (!$ldap_connection || !$ldap_bind) {
            if ($ldap_connection) {
                ldap_unbind($ldap_connection);
            }
            rcube::raise_error(array(
                'code' => 100, 'type' => 'ldap',
                'file' => __FILE__, 'line' => __LINE__,
                'message' => "Could not connect to LDAP server"
            ),
            false, true);
        }

        $this->db = $ldap_connection;
    }
}
