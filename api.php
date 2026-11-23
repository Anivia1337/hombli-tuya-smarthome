<?php
// Current state of all plugs plus monthly usage, as JSON. Read-only.
require __DIR__ . '/auth.php';
require __DIR__ . '/tuya.php';
require __DIR__ . '/miio.php';
header('Content-Type: application/json');
if (!$loggedIn) { http_response_code(401); exit('{"error":"login"}'); }
session_write_close();

// Plugs handle parallel connections badly, so several open tabs share one reading.
$cache = sys_get_temp_dir() . '/smarthome-status.json';
if (is_file($cache) && time() - filemtime($cache) < 4) { readfile($cache); exit; }

$out = ['time' => time(), 'plugs' => []];
foreach (PLUGS as $p) {
    try {
        $out['plugs'][] = ['name' => $p['name']] + plug_values(tuya_status($p['ip'], $p['id'], $p['key']));
    } catch (Throwable $e) {
        $out['plugs'][] = ['name' => $p['name'], 'error' => $e->getMessage()];
    }
}
if (defined('AIR_PURIFIER')) {
    $a = AIR_PURIFIER;
    try {
        $out['air'] = ['name' => $a['name']] + purifier_values($a['ip'], $a['token']);
    } catch (Throwable $e) {
        $out['air'] = ['name' => $a['name'], 'error' => $e->getMessage()];
    }
}
$usage = @json_decode((string) @file_get_contents(__DIR__ . '/data/usage.json'), true);
$out['months'] = (object) array_slice($usage['months'] ?? [], -13, null, true);
$json = json_encode($out);
file_put_contents($cache, $json, LOCK_EX);
echo $json;
