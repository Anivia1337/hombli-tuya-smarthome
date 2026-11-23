<?php
// Minimal Xiaomi miIO/MIoT client (UDP 54321). get_properties only: this project never changes a device.
declare(strict_types=1);

/** Reads MIoT properties. $props: ['name' => [siid, piid], ...]. Returns ['name' => value, ...]. */
function miio_props(string $ip, string $token, array $props, float $timeout = 3.0): array
{
    $tok = hex2bin($token);
    if ($tok === false || strlen($tok) !== 16) throw new RuntimeException('token must be 32 hex characters');
    $key = md5($tok, true);
    $iv = md5($key . $tok, true);

    $s = @stream_socket_client("udp://$ip:54321", $errno, $errstr, $timeout);
    if (!$s) throw new RuntimeException("unreachable ($errstr)");
    stream_set_timeout($s, (int) ceil($timeout));
    try {
        // handshake: device answers with its id and a timestamp
        fwrite($s, hex2bin('21310020' . str_repeat('ff', 28)));
        $hello = fread($s, 1024);
        if (strlen((string) $hello) < 32) throw new RuntimeException('no answer');
        $did = substr($hello, 8, 4);
        $stamp = unpack('N', substr($hello, 12, 4))[1];

        $params = [];
        foreach ($props as [$siid, $piid]) $params[] = ['did' => (string) unpack('N', $did)[1], 'siid' => $siid, 'piid' => $piid];
        $ct = openssl_encrypt(json_encode(['id' => random_int(1, 9999), 'method' => 'get_properties', 'params' => $params]),
            'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
        $head = pack('nnN', 0x2131, 32 + strlen($ct), 0) . $did . pack('N', $stamp + 1);
        fwrite($s, $head . md5($head . $tok . $ct, true) . $ct);

        $r = fread($s, 4096);
        if (strlen((string) $r) <= 32) throw new RuntimeException('no data (wrong token?)');
        $plain = openssl_decrypt(substr($r, 32), 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
        $j = $plain === false ? null : json_decode(rtrim($plain, "\0"), true);
        if (!isset($j['result'])) throw new RuntimeException('no data (wrong token?)');
    } finally {
        fclose($s);
    }

    $out = [];
    $names = array_keys($props);
    foreach ($j['result'] as $i => $p) {
        if (($p['code'] ?? -1) === 0) $out[$names[$i]] = $p['value'];
    }
    return $out;
}

/** Xiaomi Smart Air Purifier 4 Pro (zhimi.airp.vb4). Ids from miot-spec.org. */
function purifier_values(string $ip, string $token): array
{
    $v = miio_props($ip, $token, [
        'on' => [2, 1], 'fault' => [2, 2], 'mode' => [2, 4], 'humidity' => [3, 1],
        'pm25' => [3, 4], 'temp' => [3, 7], 'filter' => [4, 1], 'rpm' => [9, 1],
    ]);
    $faults = [1 => 'PM sensor error', 2 => 'Temperature sensor error', 3 => 'Humidity sensor error', 4 => 'No filter'];
    $modes = ['Auto', 'Sleep', 'Favorite', 'Manual'];
    return [
        'on' => (bool) ($v['on'] ?? false),
        'pm25' => $v['pm25'] ?? null,
        'humidity' => $v['humidity'] ?? null,
        'temp' => $v['temp'] ?? null,
        'filter' => $v['filter'] ?? null,
        'rpm' => $v['rpm'] ?? null,
        'mode' => $modes[$v['mode'] ?? -1] ?? null,
        'fault' => $faults[$v['fault'] ?? 0] ?? null,
    ];
}
