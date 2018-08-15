<?php
// {{ ansible_managed }}

define('SESSIONMANAGER_SPOOL', '/var/spool/ovd/session-manager');
define('SESSIONMANAGER_LOGS', '/var/log/ovd/session-manager');
define('SESSIONMANAGER_CONFFILE_SERIALIZED', SESSIONMANAGER_SPOOL.'/config');

{% set hash_algo = 'sha512' if ovd_version_detected is version('2.6', '>=') else 'md5' -%}
/**
 * Admin access
 *
 * the password has to be a {{ hash_algo }} of the real password
 */
define('SESSIONMANAGER_ADMIN_LOGIN', '{{ ovd_sm_admin_login }}');
{% if hash_algo == 'md5' -%}
define('SESSIONMANAGER_ADMIN_PASSWORD', '{{ ovd_sm_admin_password | hash(hash_algo) }}');
{% else -%}
define('SESSIONMANAGER_ADMIN_PASSWORD', '{{ ovd_sm_admin_password | password_hash(hash_algo) }}');
{% endif %}

/* Admin debug mode */
//define('SESSIONMANAGER_ADMIN_DEBUG', true);
