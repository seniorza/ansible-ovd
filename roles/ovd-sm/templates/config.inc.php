<?php
// {{ ansible_managed }}

define('SESSIONMANAGER_SPOOL', '/var/spool/ovd/session-manager');
define('SESSIONMANAGER_LOGS', '/var/log/ovd/session-manager');
define('SESSIONMANAGER_CONFFILE_SERIALIZED', SESSIONMANAGER_SPOOL.'/config');

/**
 * Admin access
 *
 * the password has to be a sha512 of the real password
 */
define('SESSIONMANAGER_ADMIN_LOGIN', '{{ ovd_sm_admin_login }}');
define('SESSIONMANAGER_ADMIN_PASSWORD', '{{ ovd_sm_admin_password | password_hash("sha512") }}');

/* Admin debug mode */
//define('SESSIONMANAGER_ADMIN_DEBUG', true);
