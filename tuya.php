<?php
// Minimal Tuya local protocol 3.3 client. DP_QUERY only: this project never switches a plug.
declare(strict_types=1);

/** Returns the data points of one device, e.g. ['1' => true, '19' => 2922, ...]. */
function tuya_status(string $ip, string $id, string $key, float $timeout = 3.0): array
{
    $json = json_encode(['gwId' => $id, 'devId' => $id, 'uid' => $id, 't' => (string) time()]);
    $payload = openssl_encrypt($json, 'AES-128-ECB', $key, OPENSSL_RAW_DATA);
    $msg = pack('NNNN', 0x000055AA, 1, 0x0a, strlen($payload) + 8) . $payload;
    $msg .= pack('NN', crc32($msg), 0x0000AA55);

    $s = @stream_socket_client("tcp://$ip:6668", $errno, $errstr, $timeout);
    if (!$s) throw new RuntimeException("unreachable ($errstr)");
    stream_set_timeout($s, (int) ceil($timeout));
    fwrite($s, $msg);
    try {
        for ($i = 0; $i < 3; $i++) {                         // the device may send other frames first
            $h = unpack('Nprefix/Nseq/Ncmd/Nlen', read_exact($s, 16));
            if ($h['prefix'] !== 0x000055AA) throw new RuntimeException('invalid response');
            $data = substr(read_exact($s, $h['len']), 4, -8); // drop return code, crc and suffix
            if (str_starts_with($data, '3.3')) $data = substr($data, 15);
            if ($h['cmd'] !== 0x0a && $h['cmd'] !== 0x08 || $data === '') continue;
            $plain = openssl_decrypt($data, 'AES-128-ECB', $key, OPENSSL_RAW_DATA);
            $j = $plain === false ? null : json_decode($plain, true);
            if (isset($j['dps'])) return $j['dps'];
        }
        throw new RuntimeException('no data (wrong local key?)');
    } finally {
        fclose($s);
    }
}

function read_exact($s, int $n): string
{
    $buf = '';
    while (strlen($buf) < $n) {
        $chunk = fread($s, $n - strlen($buf));
        if ($chunk === '' || $chunk === false) throw new RuntimeException('timeout');
        $buf .= $chunk;
    }
    return $buf;
}

/** Maps the usual Tuya plug data points to readable values. */
function plug_values(array $dps): array
{
    return ['on' => (bool) ($dps['1'] ?? false), 'w' => ($dps['19'] ?? 0) / 10,
            'v' => ($dps['20'] ?? 0) / 10, 'ma' => $dps['18'] ?? 0];
}
