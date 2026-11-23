<?php
// Monthly usage report by mail. Run on the 1st of every month, it reports the month before.
// Cron:  0 7 1 * * php /path/to/smarthome/report.php
// Test:  php report.php --dry-run [--month=2026-11]   (prints instead of sending)
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/config.php';
require __DIR__ . '/mail.php';

$opt = getopt('', ['dry-run', 'month:']);
$month = $opt['month'] ?? date('Y-m', strtotime('first day of last month'));
$before = date('Y-m', strtotime("$month-01 -1 month"));
$usage = json_decode((string) @file_get_contents(__DIR__ . '/data/usage.json'), true)['months'] ?? [];
$label = fn(string $m) => date('F Y', strtotime("$m-01"));
$kwh = fn(float $v) => number_format($v, 2) . ' kWh';

$names = array_column(PLUGS, 'name');
$width = max(array_map('strlen', [...$names, 'Total']));
$lines = [];
$sum = $sumBefore = 0;
foreach ([...$names, 'Total'] as $n) {
    $now = $n === 'Total' ? $sum : ($usage[$month][$n] ?? 0);
    $old = $n === 'Total' ? $sumBefore : ($usage[$before][$n] ?? null);
    if ($n !== 'Total') { $sum += $now; $sumBefore += $old ?? 0; }
    $cmp = $old ? sprintf('  (%s: %s, %+d %%)', date('M', strtotime("$before-01")), $kwh($old), round(($now - $old) / $old * 100)) : '';
    if ($n === 'Total') $lines[] = str_repeat('-', $width + 14);
    $lines[] = str_pad($n, $width) . '  ' . str_pad($kwh($now), 12, ' ', STR_PAD_LEFT) . $cmp;
}

$subject = 'Energy usage ' . $label($month) . ': ' . $kwh($sum);
$text = "Energy usage for {$label($month)}\n\n" . implode("\n", $lines) . "\n";
if (!isset($usage[$month])) {
    $text .= "\nNo readings for this month. Is the counter cron job (counter.php) running?\n";
}
if (defined('DASHBOARD_URL')) $text .= "\nDashboard: " . DASHBOARD_URL . "\n";

if (isset($opt['dry-run'])) { echo "To: " . MAIL_TO . "\nSubject: $subject\n\n$text"; exit; }
send_mail(require MAIL_ACCOUNT, MAIL_TO, $subject, $text);
