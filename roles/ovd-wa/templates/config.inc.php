<?php
// {{ ansible_managed }}

// IP/Host of the SessionManager to link the Web Portal to
define('SESSIONMANAGER_HOST', '{{ ovd_session_manager }}');

// Option session: force mode
// define('OPTION_FORCE_SESSION_MODE', 'desktop');
// define('OPTION_FORCE_SESSION_MODE', 'applications');
// default: do not force any behavior

// Option session language: default value
// define('OPTION_LANGUAGE_DEFAULT', 'fr'); // French for instance
// define('OPTION_LANGUAGE_DEFAULT', 'es'); // Spanish for instance
// default: 'en-us'

// Option session language: autodetect language frow browser settings
// define('OPTION_LANGUAGE_AUTO_DETECT', true);
// define('OPTION_LANGUAGE_AUTO_DETECT', false);
// default: true

// Option session language: force the option
// define('OPTION_FORCE_LANGUAGE', true);
// define('OPTION_FORCE_LANGUAGE', false);
// default: false (do not force any behavior)


// Option session language: default value
// define('OPTION_KEYMAP_DEFAULT', 'fr'); // French for instance
// define('OPTION_KEYMAP_DEFAULT', 'es'); // Spanish for instance
// default: 'en-us'

// Option session language: autodetect keyboard layout from client environment or language
// define('OPTION_KEYMAP_AUTO_DETECT', true);
// define('OPTION_KEYMAP_AUTO_DETECT', false);
// default: true

// Option session language: force the option
// define('OPTION_FORCE_KEYMAP', true);
// define('OPTION_FORCE_KEYMAP', false);
// default: false (do not force any behavior)

// Option force SSO: do not let the user enter a login and password. The login is set to REMOTE_USER if possible
// define('OPTION_FORCE_SSO', true);
// define('OPTION_FORCE_SSO', false);
// default is false

// Option force SAML2: do not let the user enter a login and password the user is redirected th the Identity Provider.
// define('OPTION_FORCE_SAML2', true);
// define('OPTION_FORCE_SAML2', false);
// default is false

// Option SAML2 Recirection Uri: Set the address of the Assertion Consumer Service (Acs).
// define(SAML2_REDIRECT_URI, 'https://www.example.com');
// default is automatic detection

// Enable/disable debug mode
//  define('DEBUG_MODE', true);
//  define('DEBUG_MODE', false);
// default: false

// Select RDP input method
// define('RDP_INPUT_METHOD', 'scancode'); // alternative method
// define('RDP_INPUT_METHOD', 'unicode');  // default
// define('RDP_INPUT_METHOD', 'unicode_local_ime');  // alternative method with client integration

// RDP input method : show option
// define('OPTION_SHOW_INPUT_METHOD', false); // default
// define('OPTION_SHOW_INPUT_METHOD', true);

// RDP input method : force option
// Must be used in conjunction of RDP_INPUT_METHOD
// define('OPTION_FORCE_INPUT_METHOD', false); // default
// define('OPTION_FORCE_INPUT_METHOD', true);

// CONFIRM LOGOUT
// define('OPTION_CONFIRM_LOGOUT', 'always');
// define('OPTION_CONFIRM_LOGOUT', 'apps_only');
// define('OPTION_CONFIRM_LOGOUT', 'never');
// default = never

// Option direct SM communication (with proxy.php)
// define('OPTION_USE_PROXY', true);
// define('OPTION_USE_PROXY', false);
// default: false

// Web Portal session cookie name
// define('SESSION_COOKIE_NAME', 'OVDWebPortal');
