<?php

// Отправка email через SMTP smtp.bz (STARTTLS + AUTH LOGIN). HTTP-API подвисает с нашего IP, SMTP надёжен.

class SmtpMailer
{
    private $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = array_merge([
            'host' => 'connect.smtp.bz', 'port' => 2525,
            'user' => '', 'pass' => '',
            'from' => '', 'from_name' => 'Интерсити Тур', 'reply' => '',
        ], $cfg);
    }

    public function isConfigured(): bool
    {
        return $this->cfg['user'] !== '' && $this->cfg['pass'] !== ''
            && filter_var($this->cfg['from'], FILTER_VALIDATE_EMAIL) !== false;
    }

    // $attachments: [['path'=>, 'name'=>, 'mime'=>], ...]
    public function send(string $to, string $subject, string $html, array $attachments = [], string $text = ''): array
    {
        if (!$this->isConfigured()) return ['ok' => false, 'error' => 'SMTP не настроен'];
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Некорректный email: ' . $to];

        $fp = @stream_socket_client('tcp://' . $this->cfg['host'] . ':' . (int) $this->cfg['port'], $en, $es, 20);
        if (!$fp) return ['ok' => false, 'error' => "SMTP connect: $es"];

        try {
            $this->expect($fp, '220');
            $this->cmd($fp, 'EHLO panel', '250');
            $this->cmd($fp, 'STARTTLS', '220');
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS failed');
            }
            $this->cmd($fp, 'EHLO panel', '250');
            $this->cmd($fp, 'AUTH LOGIN', '334');
            $this->cmd($fp, base64_encode($this->cfg['user']), '334');
            $this->cmd($fp, base64_encode($this->cfg['pass']), '235');
            $this->cmd($fp, 'MAIL FROM:<' . $this->cfg['from'] . '>', '250');
            $this->cmd($fp, 'RCPT TO:<' . $to . '>', '250');
            $this->cmd($fp, 'DATA', '354');

            fputs($fp, $this->buildMessage($to, $subject, $html, $text, $attachments) . "\r\n.\r\n");
            $resp = $this->read($fp);
            if (substr($resp, 0, 3) !== '250') {
                throw new RuntimeException('Письмо не принято: ' . trim($resp));
            }
            $id = preg_match('/queued as (\S+)/', $resp, $m) ? $m[1] : '';
            @fputs($fp, "QUIT\r\n");
            fclose($fp);
            return ['ok' => true, 'id' => $id];
        } catch (Exception $e) {
            @fclose($fp);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function buildMessage(string $to, string $subject, string $html, string $text, array $attachments): string
    {
        $b = '=_b_' . bin2hex(random_bytes(8));
        $a = '=_a_' . bin2hex(random_bytes(8));
        $enc = fn($s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
        if ($text === '') $text = trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $html)), ENT_QUOTES, 'UTF-8'));

        $h = 'From: ' . $enc($this->cfg['from_name']) . ' <' . $this->cfg['from'] . ">\r\n";
        $h .= 'To: ' . $to . "\r\n";
        $h .= 'Subject: ' . $enc($subject) . "\r\n";
        if ($this->cfg['reply'] !== '') $h .= 'Reply-To: ' . $this->cfg['reply'] . "\r\n";
        $h .= "MIME-Version: 1.0\r\n";

        $altBody = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text)) . "\r\n";
        $altBody .= "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n--$b--\r\n";

        if (empty($attachments)) {
            return $h . "Content-Type: multipart/alternative; boundary=\"$b\"\r\n\r\n" . $altBody;
        }

        $out = $h . "Content-Type: multipart/mixed; boundary=\"$a\"\r\n\r\n";
        $out .= "--$a\r\nContent-Type: multipart/alternative; boundary=\"$b\"\r\n\r\n" . $altBody;
        foreach ($attachments as $att) {
            if (empty($att['path']) || !is_file($att['path'])) continue;
            $mime = $att['mime'] ?? 'application/octet-stream';
            $name = $enc($att['name'] ?? basename($att['path']));
            $out .= "--$a\r\nContent-Type: $mime\r\nContent-Transfer-Encoding: base64\r\n";
            $out .= "Content-Disposition: attachment; filename=\"$name\"\r\n\r\n";
            $out .= chunk_split(base64_encode(file_get_contents($att['path']))) . "\r\n";
        }
        $out .= "--$a--\r\n";
        return $out;
    }

    private function cmd($fp, string $c, string $expect): string
    {
        fputs($fp, $c . "\r\n");
        return $this->expect($fp, $expect);
    }

    private function expect($fp, string $code): string
    {
        $r = $this->read($fp);
        if (substr($r, 0, 3) !== $code) {
            throw new RuntimeException('SMTP ожидал ' . $code . ', получил: ' . trim($r));
        }
        return $r;
    }

    private function read($fp): string
    {
        $d = '';
        while ($l = fgets($fp, 515)) {
            $d .= $l;
            if (isset($l[3]) && $l[3] === ' ') break;
        }
        return $d;
    }
}
