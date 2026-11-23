<?php
// Energy counter: reads the power of every plug once a minute and adds up kWh per month
// in data/usage.json. The plugs have no kWh counter of their own.
// Cron: * * * * * php /path/to/smarthome/counter.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/config.php';
require __DIR__ . '/tuya.php';

$file = __DIR__ . '/data/usage.json';
$db = is_file($file) ? json_decode(file_get_contents($file), true) : [];
$db += ['last' => [], 'months' => [], 'hours' => []];   // hours: time actually measured per month
$now = time();
$month = date('Y-m', $now);
$measured = 0;

foreach (PLUGS as $p) {
    try {
        $w = plug_values(tuya_status($p['ip'], $p['id'], $p['key']))['w'];
    } catch (Throwable $e) {
        continue; // reading failed, the next run covers the gap
    }
    $prev = $db['last'][$p['name']] ?? null;
    // ponytail: gaps over 10 min (server or plug down) are not counted, usage there is unknown
    if ($prev && ($dt = $now - $prev['t']) > 0 && $dt <= 600) {
        $kwh = ($prev['w'] + $w) / 2 * $dt / 3600 / 1000;   // trapezoid between two readings
        $db['months'][$month][$p['name']] = ($db['months'][$month][$p['name']] ?? 0) + $kwh;
        $measured = max($measured, $dt);
    }
    $db['last'][$p['name']] = ['t' => $now, 'w' => $w];
}

if ($measured) $db['hours'][$month] = ($db['hours'][$month] ?? 0) + $measured / 3600;
ksort($db['months']);
file_put_contents("$file.tmp", json_encode($db, JSON_PRETTY_PRINT));
rename("$file.tmp", $file);
