<?php
// Copy to config.php and fill in. config.php is git-ignored and blocked by .htaccess.

// Password hash for the login page. Create one with:
//   php -r "echo password_hash('your password', PASSWORD_DEFAULT), PHP_EOL;"
const PAGE_PASSWORD_HASH = '$2y$10$replace.this.with.your.own.hash.............................';

// One entry per plug. id and key come from `python -m tinytuya wizard` (see README).
// Give each plug a fixed DHCP lease so the IP does not change.
const PLUGS = [
    ['name' => 'desk', 'ip' => '192.168.1.50', 'id' => 'bf0123456789abcdefghij', 'key' => '0123456789abcdef'],
    ['name' => 'tv',   'ip' => '192.168.1.51', 'id' => 'bf9876543210zyxwvutsrq', 'key' => 'fedcba9876543210'],
];
