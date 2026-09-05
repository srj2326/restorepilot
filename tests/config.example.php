<?php
/**
 * Copy to tests/config.local.php and edit. That file is untracked.
 *
 * Every value can also be given as an environment variable of the same name,
 * which takes precedence. The site named here must contain a file called
 * .restorepilot-disposable, or the tests refuse to run against it -- they
 * restore databases, delete users and run Master Reset.
 */
return [
    // A disposable WordPress installation (the directory holding wp-load.php).
    'RP_TEST_SITE' => '/path/to/disposable/wordpress',

    // The plugin working copy under test. Defaults to the plugin this
    // tests/ directory belongs to, which is usually what you want.
    // 'RP_PLUGIN_DIR' => '/path/to/restorepilot-backup-migration',

    // Only needed when the CLI php is not the one that can reach the database
    // -- with Local, for instance, that is the bundled interpreter.
    // 'RP_TEST_PHP'          => '/path/to/php',
    // 'RP_TEST_MYSQL_SOCKET' => '/path/to/mysqld.sock',
];
