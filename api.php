<?php
// GET: current state of all plugs plus monthly usage, as JSON.
// POST {"name": "pc", "on": false}: switches one plug.
require __DIR__ . '/auth.php';
require __DIR__ . '/tuya.php';
require __DIR__ . '/miio.php';
header('Content-Type: application/json');
if (!$loggedIn) { http_response_code(401); exit('{"error":"login"}'); }
session_write_close();

// Plugs handle parallel connections badly, so several open tabs share one reading.
$cache = sys_get_temp_dir() . '/smarthome-status.json';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A custom header cannot be sent cross-site without a CORS preflight, which this page never allows.
    if (($_SERVER['HTTP_X_SMARTHOME'] ?? '') !== '1') { http_response_code(403); exit('{"error":"forbidden"}'); }
    $in = json_decode((string) file_get_contents('php://input'), true);
    $plug = current(array_filter(PLUGS, fn($p) => $p['name'] === ($in['name'] ?? null)));
    if (!$plug || ($plug['switch'] ?? true) === false || !is_bool($in['on'] ?? null)) { http_response_code(400); exit('{"error":"bad request"}'); }
    try {
        tuya_switch($plug['ip'], $plug['id'], $plug['key'], $in['on']);
    } catch (Throwable $e) {
        http_response_code(502); exit(json_encode(['error' => $e->getMessage()]));
    }
    @unlink($cache); // next reading shows the new state
    exit('{"ok":true}');
}

if (is_file($cache) && time() - filemtime($cache) < 4) { readfile($cache); exit; }

$out = ['time' => time(), 'plugs' => []];
foreach (PLUGS as $p) {
    try {
        $out['plugs'][] = ['name' => $p['name'], 'switch' => $p['switch'] ?? true] + plug_values(tuya_status($p['ip'], $p['id'], $p['key']));
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
