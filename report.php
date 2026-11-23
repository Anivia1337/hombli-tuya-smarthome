<?php
// Monthly usage report by mail. Run on the 1st of every month, it reports the month before.
// Cron:  0 7 1 * * php /path/to/smarthome/report.php
// Test:  php report.php --dry-run [--month=2026-11] [--html]   (prints instead of sending)
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/config.php';
require __DIR__ . '/mail.php';

$opt = getopt('', ['dry-run', 'month:', 'html']);
$month = $opt['month'] ?? date('Y-m', strtotime('first day of last month'));
$prev = date('Y-m', strtotime("$month-01 -1 month"));
$usage = json_decode((string) @file_get_contents(__DIR__ . '/data/usage.json'), true)['months'] ?? [];

// ---- numbers -------------------------------------------------------------
$rows = [];
foreach (array_column(PLUGS, 'name') as $n) {
    $rows[] = ['name' => $n, 'now' => $usage[$month][$n] ?? 0.0, 'old' => $usage[$prev][$n] ?? null];
}
usort($rows, fn($a, $b) => $b['now'] <=> $a['now']);
$total = array_sum(array_column($rows, 'now'));
$totalOld = isset($usage[$prev]) ? array_sum($usage[$prev]) : null;
$days = (int) date('t', strtotime("$month-01"));
$hasData = isset($usage[$month]);

$monthLabel = date('F Y', strtotime("$month-01"));
$prevLabel = date('F', strtotime("$prev-01"));
$kwh = fn(float $v) => number_format($v, $v >= 100 ? 1 : 2) . ' kWh';
$change = fn(float $now, ?float $old) => $old ? round(($now - $old) / $old * 100) : null;
$pct = fn(?int $p) => $p === null ? '' : ($p > 0 ? '+' : ($p < 0 ? "\u{2212}" : '±')) . abs($p) . ' %';

$subject = "Energy report $monthLabel: " . $kwh($total);

// ---- plain text ----------------------------------------------------------
$w = max(array_map(fn($r) => strlen($r['name']), $rows)) + 2;
$text = "Energy report $monthLabel\n\nTotal: " . $kwh($total);
if ($totalOld) $text .= " ({$pct($change($total, $totalOld))} vs $prevLabel, " . $kwh($totalOld) . ')';
$text .= "\nAverage: " . number_format($total / $days, 2) . ' kWh per day, ' . round($total / ($days * 24) * 1000) . " W\n\n";
foreach ($rows as $r) {
    $text .= str_pad($r['name'], $w) . str_pad($kwh($r['now']), 12, ' ', STR_PAD_LEFT)
           . ($r['old'] ? '   ' . $pct($change($r['now'], $r['old'])) : '') . "\n";
}
if (!$hasData) $text .= "\nNo readings for this month. Is the counter cron job (counter.php) running?\n";
if (defined('DASHBOARD_URL')) $text .= "\nDashboard: " . DASHBOARD_URL . "\n";

// ---- HTML (table layout with inline styles, so Outlook and Gmail render it the same) ----
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$font = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
$max = max(array_column($rows, 'now')) ?: 1;

$delta = '';
if ($totalOld && $hasData) {
    $p = $change($total, $totalOld);
    [$fg, $bg] = $p > 0 ? ['#b42318', '#fef3f2'] : ['#067647', '#ecfdf3'];
    $delta = "<span style=\"display:inline-block;padding:3px 10px;border-radius:999px;background:$bg;color:$fg;font-size:13px;font-weight:600;\">"
           . $e($pct($p)) . "</span><span style=\"color:#71717a;font-size:13px;\">&nbsp; vs $prevLabel &middot; " . $e($kwh($totalOld)) . '</span>';
}

$stat = fn(string $label, string $value, string $border = '') => "<td width=\"50%\" style=\"padding:14px 16px;$border\">"
    . "<div style=\"font-size:12px;color:#71717a;\">$label</div><div style=\"font-size:17px;font-weight:600;color:#18181b;margin-top:2px;\">$value</div></td>";

$rowsHtml = '';
$bar8 = 'height:8px;font-size:1px;line-height:8px;mso-line-height-rule:exactly;';   // Outlook ignores height without it
foreach ($rows as $r) {
    $bar = max(1, round($r['now'] / $max * 100));
    $p = $change($r['now'], $r['old']);
    $rowsHtml .= '<tr>'
        . '<td style="padding:12px 0;border-top:1px solid #ececef;font-size:14px;color:#18181b;font-weight:600;" width="28%">' . $e($r['name']) . '</td>'
        . '<td style="padding:12px 12px;border-top:1px solid #ececef;">'
        .   '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        .   "<td width=\"$bar%\" height=\"8\" bgcolor=\"#0f6cbd\" style=\"background:#0f6cbd;border-radius:4px;$bar8\">&nbsp;</td>"
        .   ($bar < 100 ? "<td height=\"8\" bgcolor=\"#eef0f3\" style=\"background:#eef0f3;border-radius:4px;$bar8\">&nbsp;</td>" : '')
        .   '</tr></table></td>'
        . '<td align="right" style="padding:12px 0;border-top:1px solid #ececef;font-size:14px;color:#18181b;white-space:nowrap;" width="22%">' . $e($kwh($r['now']))
        . ($p === null ? '' : '<div style="font-size:12px;color:#71717a;">' . $e($pct($p)) . '</div>') . '</td>'
        . '</tr>';
}

$notice = $hasData ? '' : '<p style="margin:20px 0 0;padding:12px 14px;background:#fffaeb;border:1px solid #fedf89;border-radius:8px;font-size:13px;color:#93370d;">'
    . 'No readings for this month. Check that the counter cron job (counter.php) is running.</p>';
$button = defined('DASHBOARD_URL')
    // the padding sits on the table cell: Outlook ignores padding on links
    ? '<table role="presentation" cellpadding="0" cellspacing="0"><tr><td bgcolor="#0f6cbd" style="background:#0f6cbd;border-radius:6px;padding:10px 18px;">'
      . '<a href="' . $e(DASHBOARD_URL) . '" style="color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;display:block;">Open dashboard</a></td></tr></table>'
    : '';

$html = <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light only"><title>{$e($subject)}</title></head>
<body bgcolor="#f4f4f5" style="margin:0;padding:0;background:#f4f4f5;">
<div style="display:none;max-height:0;overflow:hidden;">{$e($kwh($total))} used in {$e($monthLabel)}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#f4f4f5" style="background:#f4f4f5;$font">
<tr><td align="center" style="padding:32px 16px;">
  <!-- width attribute for Outlook, max-width for everyone else -->
  <table role="presentation" width="560" align="center" cellpadding="0" cellspacing="0" bgcolor="#ffffff" style="width:100%;max-width:560px;background:#ffffff;border:1px solid #e4e4e7;border-radius:12px;">
  <tr><td style="padding:28px 32px 8px;">
    <div style="font-size:12px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:#0f6cbd;">Smarthome &middot; Monthly report</div>
    <h1 style="margin:8px 0 0;font-size:22px;line-height:1.3;font-weight:600;color:#18181b;">Energy usage in {$e($monthLabel)}</h1>
  </td></tr>
  <tr><td style="padding:20px 32px 4px;">
    <div style="font-size:13px;color:#71717a;">Total</div>
    <div style="font-size:40px;line-height:1.15;font-weight:600;color:#18181b;letter-spacing:-.02em;">{$e(number_format($total, $total >= 100 ? 1 : 2))} <span style="font-size:18px;font-weight:500;color:#71717a;">kWh</span></div>
    <div style="margin-top:8px;">$delta</div>
  </td></tr>
  <tr><td style="padding:20px 32px 8px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#fafafa" style="background:#fafafa;border:1px solid #ececef;border-radius:8px;"><tr>
      {$stat('Per day', $e(number_format($total / $days, 2)) . ' kWh')}
      {$stat('Average power', $e(round($total / ($days * 24) * 1000)) . ' W', 'border-left:1px solid #ececef;')}
    </tr></table>
  </td></tr>
  <tr><td style="padding:20px 32px 8px;">
    <div style="font-size:15px;font-weight:600;color:#18181b;margin-bottom:6px;">By plug</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">$rowsHtml</table>
    $notice
  </td></tr>
  <tr><td style="padding:16px 32px 28px;">$button</td></tr>
  </table>
  <table role="presentation" width="560" align="center" cellpadding="0" cellspacing="0" style="width:100%;max-width:560px;"><tr>
    <td style="padding:16px 4px 0;font-size:12px;line-height:1.5;color:#a1a1aa;">Calculated from the plugs' power, read once a minute on the local network. Values are estimates.</td>
  </tr></table>
</td></tr>
</table>
</body></html>
HTML;

if (isset($opt['dry-run'])) { echo isset($opt['html']) ? $html : "To: " . MAIL_TO . "\nSubject: $subject\n\n$text"; exit; }
send_mail(require MAIL_ACCOUNT, MAIL_TO, $subject, $text, $html);
