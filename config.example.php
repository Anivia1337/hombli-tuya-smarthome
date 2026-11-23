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

// Monthly report (report.php). MAIL_ACCOUNT is a PHP file outside the web root that returns
//   ['user' => 'sender@example.com', 'pass' => '...', 'smtp' => 'ssl://smtp.example.com:465']
// 'smtp' is optional and defaults to IONOS (ssl://smtp.ionos.de:465).
const MAIL_ACCOUNT = '/etc/smarthome/mail.php';
const MAIL_TO = 'you@example.com';
const DASHBOARD_URL = 'https://example.com/smarthome';   // optional, shown in the mail

// Optional: Xiaomi Smart Air Purifier 4 Pro (zhimi.airp.vb4), shown as an extra card.
// The token comes from Xiaomi-cloud-tokens-extractor (see README).
// const AIR_PURIFIER = ['name' => 'Air purifier', 'ip' => '192.168.1.60', 'token' => '0123456789abcdef0123456789abcdef'];
