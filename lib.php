<?php
// Readings and switching, shared by api.php and the intranet (intranet/werkzeuge/smarthome.php).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tuya.php';
require_once __DIR__ . '/miio.php';

// Plugs handle parallel connections badly, so every caller shares one reading.
function smarthome_cache(): string { return sys_get_temp_dir() . '/smarthome-status.json'; }

// Plugs, air purifier and monthly usage as JSON, cached 4 s.
function smarthome_status(): string
{
    $cache = smarthome_cache();
    if (is_file($cache) && time() - filemtime($cache) < 4) return (string) file_get_contents($cache);

    $out = ['time' => time(), 'plugs' => []];
    foreach (PLUGS as $p) {
        try {
            $out['plugs'][] = ['name' => $p['name'], 'switch' => $p['switch'] ?? true] + plug_values(tuya_status($p['ip'], $p['id'], $p['key']));
        } catch (Throwable $e) {
            $out['plugs'][] = ['name' => $p['name'], 'error' => $e->getMessage()];
        }
    }
    if (defined('AIR_PURIFIER')) {
        // A purifier that is off or gone would cost its whole timeout on every reading
        // (the cache only holds 4 s). After a miss it is skipped for a minute instead.
        $a = AIR_PURIFIER;
        $miss = sys_get_temp_dir() . '/smarthome-air-miss.txt';
        if (is_file($miss) && time() - filemtime($miss) < 60) {
            $out['air'] = ['name' => $a['name'], 'error' => (string) file_get_contents($miss)];
        } else {
            try {
                $out['air'] = ['name' => $a['name']] + purifier_values($a['ip'], $a['token'], 1.0); // LAN answers in ms
                @unlink($miss);
            } catch (Throwable $e) {
                $out['air'] = ['name' => $a['name'], 'error' => $e->getMessage()];
                @file_put_contents($miss, $e->getMessage());
            }
        }
    }
    $usage = @json_decode((string) @file_get_contents(__DIR__ . '/data/usage.json'), true);
    $out['months'] = (object) array_slice($usage['months'] ?? [], -13, null, true);
    $json = json_encode($out);
    file_put_contents($cache, $json, LOCK_EX);
    return $json;
}

// The SNMP tab (NAS, printer) as JSON, cached 10 s.
function smarthome_snmp(): string
{
    require_once __DIR__ . '/snmp.php';
    $cache = sys_get_temp_dir() . '/smarthome-snmp.json';
    if (is_file($cache) && time() - filemtime($cache) < 10) return (string) file_get_contents($cache);
    $json = json_encode(['time' => time(), 'devices' => array_map('snmp_device', defined('SNMP_DEVICES') ? SNMP_DEVICES : [])], JSON_INVALID_UTF8_SUBSTITUTE);
    file_put_contents($cache, $json, LOCK_EX);
    return $json;
}

// Switches one plug. InvalidArgumentException: unknown plug, plug without switch, or $on not a bool.
function smarthome_schalten(mixed $name, mixed $on): void
{
    $plug = current(array_filter(PLUGS, fn($p) => $p['name'] === $name));
    if (!$plug || ($plug['switch'] ?? true) === false || !is_bool($on)) throw new InvalidArgumentException('bad request');
    tuya_switch($plug['ip'], $plug['id'], $plug['key'], $on);
    @unlink(smarthome_cache()); // next reading shows the new state
}
