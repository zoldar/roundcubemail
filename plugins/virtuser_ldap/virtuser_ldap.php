<?php

/**
 * DB based User-to-Email and Email-to-User lookup
 *
 * Add it to the plugins list in config.inc.php and set
 * SQL queries to resolve usernames, e-mail addresses and hostnames from the database
 * %u will be replaced with the current username for login.
 * %m will be replaced with the current e-mail address for login.
 *
 * Queries should select the user's e-mail address, username or the imap hostname as first column
 * The email query could optionally select identity data columns in specified order:
 *    name, organization, reply-to, bcc, signature, html_signature
 *
 * $config['virtuser_query'] = array('email' => '', 'user' => '', 'host' => '', 'alias' => '');
 *
 * The email query can return more than one record to create more identities.
 * This requires identities_level option to be set to value less than 2.
 *
 * By default Roundcube database is used. To use different database (or host)
 * you can specify DSN string in $config['virtuser_query_dsn'] option.
 *
 * @version @package_version@
 * @author Aleksander Machniak <alec@alec.pl>
 * @author Steffen Vogel
 * @author Tim Gerundt
 * @license GNU GPLv3+
 */
class virtuser_ldap extends rcube_plugin
{
    private $ldap_config;
    private $email_attribute;
    
    private $app;
    private $ldap;

    function init()
    {
        $this->app = rcmail::get_instance();

        $this->load_config();

        $this->ldap_config = $this->app->config->get('virtuser_ldap_db');
        $this->email_attribute = $this->app->config->get('virtuser_ldap_email_attr');
        $this->name_attribute = $this->app->config->get('virtuser_ldap_name_attr');

        $this->add_hook('user2email', array($this, 'user2email'));
        $this->add_hook('authenticate', array($this, 'authuser2email'));
    }

    /**
     * User > Email
     */
    function user2email($args)
    {
        $ldap = $this->_get_ldap($this->ldap_config);
        
        $result = $this->ldap->search($args['user']);

        if (!empty($result[$this->email_attribute])) {
            $emails = array(
                array(
                    'email' => rcube_utils::idn_to_ascii($result[$this->email_attribute][0]),
                    'name' => !empty($result[$this->name_attribute])?$result[$this->name_attribute]:'',
                )
            );
            $args['email'] = $emails;
        }

        return $args;
    }

    /**
     * Auth User > Email
     */
    function authuser2email($args)
    {
        $ldap = $this->_get_ldap($this->ldap_config);
        
        $result = $this->ldap->search($args['user']);

        if (!empty($result[$this->email_attribute])) {
            $args['user'] = $result[$this->email_attribute][0];
        }

        return $args;
    }


    private function _get_ldap($ldap_config)
    {
        if ($this->ldap) {
            return $this->ldap;
        }

        $this->ldap = new virtuser_ldap_backend($ldap_config);

        return $this->ldap;
    }
}

class virtuser_ldap_backend
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
