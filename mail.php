<?php
// Sends one plain-text mail over SMTP with implicit TLS (port 465). The server certificate is verified.
declare(strict_types=1);

function send_mail(array $account, string $to, string $subject, string $text): void
{
    $s = stream_socket_client($account['smtp'] ?? 'ssl://smtp.ionos.de:465', $no, $err, 20);
    if (!$s) throw new RuntimeException("mail server unreachable: $err");
    stream_set_timeout($s, 20);
    $expect = function (string $code) use ($s) {
        $o = '';
        while (($l = fgets($s, 1000)) !== false) { $o .= $l; if (($l[3] ?? '') === ' ') break; }
        if (strncmp($o, $code, 3) !== 0) throw new RuntimeException('mail server: ' . trim($o));
    };
    $cmd = function (string $line, string $code) use ($s, $expect) { fwrite($s, "$line\r\n"); $expect($code); };

    $from = $account['user'];
    $domain = substr(strrchr($from, '@'), 1);
    $head = 'From: ' . ($account['name'] ?? 'Smarthome') . " <$from>\r\nTo: <$to>\r\n"
          . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
          . 'Date: ' . date('r') . "\r\nMessage-ID: <" . bin2hex(random_bytes(12)) . "@$domain>\r\n"
          . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";

    $expect('220');
    $cmd("EHLO $domain", '250');
    $cmd('AUTH LOGIN', '334');
    $cmd(base64_encode($from), '334');
    $cmd(base64_encode($account['pass']), '235');
    $cmd("MAIL FROM:<$from>", '250');
    $cmd("RCPT TO:<$to>", '250');
    $cmd('DATA', '354');
    $cmd($head . "\r\n" . chunk_split(base64_encode($text)) . '.', '250');
    fwrite($s, "QUIT\r\n");
    fclose($s);
}
