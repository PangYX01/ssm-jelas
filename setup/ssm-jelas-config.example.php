<?php
/**
 * COPY THIS FILE TO A PRIVATE FOLDER OUTSIDE httpdocs:
 * /var/www/vhosts/YOUR-DOMAIN/private/ssm-jelas-config.php
 *
 * Replace every example value. NEVER commit a filled-in config to GitHub.
 */
return [
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_name' => 'your_plesk_database_name',
    'db_user' => 'your_plesk_database_user',
    'db_password' => 'your_plesk_database_password',
    'admin_password_hash' => 'REPLACE_WITH_OUTPUT_FROM_PHP_PASSWORD_HASH_TOOL',
];
