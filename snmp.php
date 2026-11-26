<?php
// Minimal SNMP v2c client (GET and WALK over UDP), no PHP extension needed.
declare(strict_types=1);

function ber(int $tag, string $v): string
{
    $n = strlen($v);
    return chr($tag) . ($n < 128 ? chr($n) : ($n < 256 ? "\x81" . chr($n) : "\x82" . pack('n', $n))) . $v;
}

function ber_int(int $i): string // non-negative only, enough for requests
{
    $s = '';
    do { $s = chr($i & 0xff) . $s; $i >>= 8; } while ($i > 0);
    return ber(0x02, (ord($s[0]) & 0x80 ? "\x00" : '') . $s);
}

function ber_oid(string $oid): string
{
    $a = array_map('intval', explode('.', trim($oid, '.')));
    $s = chr(40 * $a[0] + $a[1]);
    foreach (array_slice($a, 2) as $n) {
        $b = chr($n & 0x7f);
        while ($n >>= 7) $b = chr(0x80 | ($n & 0x7f)) . $b;
        $s .= $b;
    }
    return ber(0x06, $s);
}

/** Reads one TLV at $p and moves $p past it. */
function ber_read(string $d, int &$p): array
{
    $tag = ord($d[$p++]);
    $len = ord($d[$p++]);
    if ($len & 0x80) for ($n = $len & 0x7f, $len = 0; $n--;) $len = ($len << 8) | ord($d[$p++]);
    $v = substr($d, $p, $len);
    $p += $len;
    return [$tag, $v];
}

function ber_list(string $v): array
{
    for ($out = [], $p = 0; $p < strlen($v);) $out[] = ber_read($v, $p);
    return $out;
}

function snmp_value(int $tag, string $v): int|string|null
{
    switch ($tag) {
        case 0x02: case 0x41: case 0x42: case 0x43: case 0x46: // Integer, Counter32, Gauge32, TimeTicks, Counter64
            $n = 0;
            foreach (str_split($v) as $c) $n = ($n << 8) | ord($c);
            if ($tag === 0x02 && $v !== '' && ord($v[0]) & 0x80) $n -= 1 << (8 * strlen($v));
            return $n;
        case 0x06:
            $f = ord($v[0]);
            $a = [intdiv($f, 40), $f % 40];
            for ($i = 1, $n = 0; $i < strlen($v); $i++) {
                $n = ($n << 7) | (ord($v[$i]) & 0x7f);
                if (!(ord($v[$i]) & 0x80)) { $a[] = $n; $n = 0; }
            }
            return implode('.', $a);
        case 0x40: return inet_ntop($v) ?: null;
        case 0x80: case 0x81: case 0x82: case 0x05: return null; // noSuchObject, noSuchInstance, endOfMibView, NULL
        default: return $v; // OCTET STRING and anything else as raw bytes
    }
}

/** One request; $pdu 0xA0 = GET, 0xA5 = GETBULK ($bulk = max repetitions). Returns [oid => value]. */
function snmp_request(string $host, string $community, int $pdu, array $oids, int $bulk = 0): array
{
    $vb = '';
    foreach ($oids as $o) $vb .= ber(0x30, ber_oid($o) . "\x05\x00");
    $msg = ber(0x30, ber_int(1) . ber(0x04, $community) . ber($pdu, ber_int(random_int(1, 0x7fffffff)) . ber_int(0) . ber_int($bulk) . ber(0x30, $vb)));

    $fp = @stream_socket_client("udp://$host:161", $errno, $err, 1);
    if (!$fp) throw new RuntimeException("$host: $err");
    stream_set_timeout($fp, 1);
    for ($try = 0, $r = ''; $try < 2 && $r === ''; $try++) {
        fwrite($fp, $msg);
        $r = (string) fread($fp, 65535);
    }
    fclose($fp);
    if ($r === '') throw new RuntimeException('No SNMP answer');

    $p = 0;
    [, $m] = ber_read($r, $p);
    [, , [, $body]] = ber_list($m);
    [, [, $status], , [, $binds]] = ber_list($body);
    if ($e = snmp_value(0x02, $status)) throw new RuntimeException("SNMP error $e");
    $out = [];
    foreach (ber_list($binds) as [, $b]) {
        [[, $o], [$t, $v]] = ber_list($b);
        $out[snmp_value(0x06, $o)] = snmp_value($t, $v);
    }
    return $out;
}

function snmp_get(string $host, string $community, array $oids): array
{
    return snmp_request($host, $community, 0xA0, $oids);
}

/** A MIB table as [row index => [column => value]]. */
function snmp_table(string $host, string $community, string $root): array
{
    $t = [];
    foreach (snmp_walk($host, $community, $root) as $k => $v) {
        [$col, $idx] = explode('.', (string) $k, 2);
        $t[$idx][(int) $col] = is_string($v) ? trim($v, " \0") : $v;
    }
    return $t;
}

/** All values below $root, keyed by the OID suffix after $root (e.g. "6.1.2"). */
function snmp_walk(string $host, string $community, string $root): array
{
    $out = [];
    for ($oid = $root, $guard = 0; $guard < 40; $guard++) {
        $r = snmp_request($host, $community, 0xA5, [$oid], 25);
        foreach ($r as $o => $v) {
            if ($v === null || !str_starts_with($o, "$root.")) return $out;
            $out[substr($o, strlen($root) + 1)] = $v;
            $oid = $o;
        }
        if (!$r) break;
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Devices for the SNMP tab. Each returns the same shape for app.js:
// status, warn, rows [[label, value]], bars [{label, pct, text, warn, note?}], table {head, rows}

function snmp_device(array $d): array
{
    try {
        $info = match ($d['type']) {
            'synology' => synology_info($d['ip'], $d['community']),
            'printer' => printer_info($d['ip'], $d['community']),
        };
        return ['name' => $d['name']] + $info;
    } catch (Throwable $e) {
        return ['name' => $d['name'], 'error' => $e->getMessage()];
    }
}

function snmp_uptime(?int $ticks): string
{
    if ($ticks === null) return '–';
    $s = intdiv($ticks, 100);
    return intdiv($s, 86400) . ' d ' . intdiv($s % 86400, 3600) . ' h';
}

function snmp_bytes(int|float $b): string
{
    $u = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    for ($i = 0; $i < 5 && $b >= 1024 ** ($i + 1); $i++);
    return round($b / 1024 ** $i, 1) . ' ' . $u[$i];
}

/** Printer-MIB (RFC 3805) + Host Resources, works for most network printers. */
function printer_info(string $ip, string $c): array
{
    [$model, $dev, $prt, $pages, $display, $up] = array_values(snmp_get($ip, $c, [
        '1.3.6.1.2.1.25.3.2.1.3.1',    // hrDeviceDescr
        '1.3.6.1.2.1.25.3.2.1.5.1',    // hrDeviceStatus: 2 running, 3 warning, 5 down
        '1.3.6.1.2.1.25.3.5.1.1.1',    // hrPrinterStatus: 3 idle, 4 printing, 5 warming up
        '1.3.6.1.2.1.43.10.2.1.4.1.1', // prtMarkerLifeCount
        '1.3.6.1.2.1.43.16.5.1.2.1.1', // prtConsoleDisplayBufferText
        '1.3.6.1.2.1.1.3.0',           // sysUpTime
    ]));
    $bars = [];
    foreach (snmp_table($ip, $c, '1.3.6.1.2.1.43.11.1.1') as $s) { // prtMarkerSuppliesTable
        [$max, $level] = [$s[8] ?? 0, $s[9] ?? -2];
        $pct = $max > 0 && $level >= 0 ? (int) round(100 * $level / $max) : null;
        $bars[] = [
            'label' => ucfirst(preg_replace('/ (ink |toner )?cartridge.*$/i', '', (string) ($s[6] ?? 'Supply'))),
            'pct' => $pct,
            'text' => $pct !== null ? "$pct %" : ($level === -3 ? 'Some left' : 'Unknown'),
            'warn' => $pct !== null && $pct <= 15,
        ];
    }
    return [
        'status' => match (true) { $dev === 5 => 'Down', $dev === 3 => 'Warning', default => [3 => 'Idle', 4 => 'Printing', 5 => 'Warming up'][$prt] ?? 'Unknown' },
        'warn' => $dev === 3 || $dev === 5,
        'rows' => [
            ['Model', trim((string) $model) ?: '–'],
            ['Pages printed', $pages === null ? '–' : number_format($pages, 0, '.', "'")],
            ['Display', trim((string) $display, " \0") ?: '–'],
            ['Uptime', snmp_uptime($up)],
        ],
        'bars' => $bars,
    ];
}

/** Synology DSM: SYNOLOGY-*-MIB (1.3.6.1.4.1.6574) plus UCD-SNMP for CPU and memory. */
function synology_info(string $ip, string $c): array
{
    [$sys, $temp, $power, $fan, $cpuFan, $model, $dsm, $upgrade, $up, $idle, $memTotal, $memFree, $memBuf, $memCache, $load] = array_values(snmp_get($ip, $c, [
        '1.3.6.1.4.1.6574.1.1.0', '1.3.6.1.4.1.6574.1.2.0', '1.3.6.1.4.1.6574.1.3.0', // status, temperature, power: 1 normal
        '1.3.6.1.4.1.6574.1.4.1.0', '1.3.6.1.4.1.6574.1.4.2.0',                     // system fan, CPU fan: 1 normal
        '1.3.6.1.4.1.6574.1.5.1.0', '1.3.6.1.4.1.6574.1.5.3.0', '1.3.6.1.4.1.6574.1.5.4.0', // model, DSM version, upgrade: 1 available
        '1.3.6.1.2.1.25.1.1.0',                                                       // hrSystemUptime
        '1.3.6.1.4.1.2021.11.11.0',                                                   // ssCpuIdle %
        '1.3.6.1.4.1.2021.4.5.0', '1.3.6.1.4.1.2021.4.6.0', '1.3.6.1.4.1.2021.4.14.0', '1.3.6.1.4.1.2021.4.15.0', // memory kB: total, free, buffers, cached
        '1.3.6.1.4.1.2021.10.1.3.1',                                                  // 1-minute load
    ]));
    $memUsed = $memTotal ? ($memTotal - $memFree - $memBuf - $memCache) / $memTotal : null;
    $failed = fn($v) => $v !== null && $v !== 1;

    $raidStatus = [1 => 'Normal', 2 => 'Repairing', 3 => 'Migrating', 4 => 'Expanding', 5 => 'Deleting', 6 => 'Creating', 7 => 'Syncing',
        8 => 'Parity check', 9 => 'Assembling', 10 => 'Canceling', 11 => 'Degraded', 12 => 'Crashed', 13 => 'Data scrubbing'];
    // Read/write speed: the byte counters only grow, so compare with the previous sample (one every ~10 s via the API cache).
    // ponytail: counter "dm-N" is matched to "Volume N" (verified on this NAS with a test write); breaks if DSM numbers them differently.
    $io = [];
    foreach (snmp_table($ip, $c, '1.3.6.1.4.1.6574.102.1.1') as $s) { // spaceIOTable: 2 device, 12 bytes read, 13 bytes written (64-bit)
        if (preg_match('/^dm-(\d+)$/', (string) ($s[2] ?? ''), $m)) $io['Volume ' . $m[1]] = [$s[12] ?? 0, $s[13] ?? 0];
    }
    $ioFile = sys_get_temp_dir() . "/smarthome-io-$ip.json";
    $prev = @json_decode((string) @file_get_contents($ioFile), true);
    $now = microtime(true);
    file_put_contents($ioFile, json_encode(['t' => $now, 'io' => $io]), LOCK_EX);
    $dt = $now - ($prev['t'] ?? 0);
    $speed = function (string $name) use ($io, $prev, $dt): ?string {
        if (!isset($io[$name])) return null;
        if (!isset($prev['io'][$name]) || $dt <= 0 || $dt > 300) return 'Read – · Write –';
        $rate = fn($i) => snmp_bytes(max(0, $io[$name][$i] - $prev['io'][$name][$i]) / $dt) . '/s'; // max: counters reset on reboot
        return 'Read ' . $rate(0) . ' · Write ' . $rate(1);
    };

    $bars = [];
    $raids = snmp_table($ip, $c, '1.3.6.1.4.1.6574.3.1.1'); // raidTable: 2 name, 3 status, 4 free, 5 total (bytes)
    usort($raids, fn($a, $b) => strnatcmp((string) ($a[2] ?? ''), (string) ($b[2] ?? '')));
    foreach ($raids as $r) {
        // A pool is always "full" (its volume takes all of it), so show it only when its RAID is not normal.
        if (str_starts_with((string) ($r[2] ?? ''), 'Storage Pool') && ($r[3] ?? 1) === 1) continue;
        $total = $r[5] ?? 0;
        $pct = $total ? (int) round(100 * ($total - ($r[4] ?? 0)) / $total) : 0;
        $bars[] = [
            'label' => $r[2] ?? 'Volume',
            'pct' => $pct,
            'text' => snmp_bytes($total - ($r[4] ?? 0)) . ' of ' . snmp_bytes($total) . (($r[3] ?? 1) !== 1 ? ' · ' . ($raidStatus[$r[3]] ?? 'Status ' . $r[3]) : ''),
            'warn' => $pct >= 90 || !in_array($r[3] ?? 1, [1, 13], true),
            'note' => $speed((string) ($r[2] ?? '')),
        ];
    }

    $diskStatus = [1 => 'Normal', 2 => 'Initialized', 3 => 'Not initialized', 4 => 'System partition failed', 5 => 'Crashed'];
    $health = [1 => 'Normal', 2 => 'Warning', 3 => 'Critical', 4 => 'Failing'];
    $disks = [];
    foreach (snmp_table($ip, $c, '1.3.6.1.4.1.6574.2.1.1') as $d) { // diskTable: 2 id, 3 model, 5 status, 6 temp, 13 health (DSM 7.1+)
        $disks[] = [$d[2] ?? '–', $d[3] ?? '–', isset($d[6]) ? $d[6] . ' °C' : '–',
            isset($d[13]) ? ($health[$d[13]] ?? '–') : ($diskStatus[$d[5] ?? 0] ?? '–')];
    }

    $warn = $failed($sys) || $failed($power) || $failed($fan) || $failed($cpuFan);
    return [
        'status' => $warn ? 'Problem' : 'Normal',
        'warn' => $warn,
        'rows' => [
            ['Model', $model ?: '–'],
            ['DSM', ($dsm ?: '–') . ($upgrade === 1 ? ' (update available)' : '')],
            ['Uptime', snmp_uptime($up)],
            ['CPU', $idle === null ? '–' : (100 - $idle) . ' %' . ($load !== null ? " · load $load" : '')],
            ['Memory', $memUsed === null ? '–' : round(100 * $memUsed) . ' % of ' . snmp_bytes($memTotal * 1024)],
            ['Temperature', $temp === null ? '–' : "$temp °C"],
            ['Fans', $failed($fan) || $failed($cpuFan) ? 'Failed' : 'Normal'],
        ],
        'bars' => $bars,
        'table' => $disks ? ['head' => ['Disk', 'Model', 'Temp', 'Health'], 'rows' => $disks] : null,
    ];
}
