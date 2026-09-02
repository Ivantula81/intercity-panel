<?php

declare(strict_types=1);

require_once __DIR__ . '/SalesParser.php';
require_once __DIR__ . '/SalesClassifier.php';

/** Read-only IMAP importer for sales/refund notification emails. */
final class SalesInboxIngestor
{
    private const SOURCE = 'gmail_sales';

    public function __construct(private PDO $pdo, private array $config)
    {
    }

    /** @return array{checked:int,imported:int,ignored:int,errors:int,last_uid:int,dry_run:bool} */
    public function run(bool $dryRun = false, int $limit = 250): array
    {
        $this->assertReady();
        $mailbox = $this->mailbox();
        $result = ['checked' => 0, 'imported' => 0, 'ignored' => 0, 'errors' => 0,
            'last_uid' => 0, 'dry_run' => $dryRun];

        if (!$dryRun) $this->markStarted($mailbox);
        $imap = @imap_open($mailbox, $this->config['user'], $this->config['password'], OP_READONLY, 1,
            ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
        if ($imap === false) {
            $error = $this->safeImapError();
            if (!$dryRun) $this->markFailed($error);
            throw new RuntimeException($error);
        }

        try {
            $status = @imap_status($imap, $mailbox, SA_UIDVALIDITY | SA_UIDNEXT | SA_MESSAGES);
            if (!$status) throw new RuntimeException('Не удалось получить состояние IMAP-папки.');
            $uidValidity = (int) ($status->uidvalidity ?? 0);
            $state = $this->state();
            $classifier = new SalesClassifier($this->pdo);
            $lastUid = (int) ($state['last_uid'] ?? 0);
            if ((int) ($state['uid_validity'] ?? 0) !== 0 && (int) $state['uid_validity'] !== $uidValidity) {
                // UID сбросились на стороне ящика. Перечитывание безопасно: запись
                // дополнительно защищена Message-ID и бизнес-ключом события.
                $lastUid = 0;
            }

            if ($lastUid === 0) {
                // Первый запуск не должен начинать обход многолетнего ящика с
                // UID 1. Берём только согласованное окно свежих писем, затем
                // продолжаем строго по UID из сохранённого курсора.
                $lookbackDays = max(1, min(3650, (int) ($this->config['lookback_days'] ?? 30)));
                $criteria = 'SINCE "' . date('d-M-Y', strtotime('-' . $lookbackDays . ' days')) . '"';
                $uids = @imap_search($imap, $criteria, SE_UID) ?: [];
                $uids = array_values(array_filter(array_map('intval', $uids), static fn(int $uid): bool => $uid > 0));
                sort($uids, SORT_NUMERIC);
                if (count($uids) > $limit) $uids = array_slice($uids, 0, $limit);
            } else {
                // libc-client, используемый PHP IMAP, не поддерживает критерий
                // поиска `UID n:*`. Берём ограниченный UID-диапазон через
                // overview и продвигаем курсор до конца проверенного диапазона,
                // включая возможные разрывы от удалённых писем.
                $uidNext = max($lastUid + 1, (int) ($status->uidnext ?? ($lastUid + 1)));
                $rangeEnd = min($uidNext - 1, $lastUid + $limit);
                $uids = [];
                if ($rangeEnd >= $lastUid + 1) {
                    $overview = @imap_fetch_overview($imap, ($lastUid + 1) . ':' . $rangeEnd, FT_UID);
                    if ($overview === false) throw new RuntimeException('Не удалось получить следующий диапазон UID.');
                    foreach ($overview as $item) {
                        $uid = (int) ($item->uid ?? 0);
                        if ($uid > $lastUid && $uid <= $rangeEnd) $uids[] = $uid;
                    }
                    $result['last_uid'] = $rangeEnd;
                }
                sort($uids, SORT_NUMERIC);
            }

            foreach ($uids as $uid) {
                $message = null;
                $result['checked']++;
                $result['last_uid'] = max($result['last_uid'], $uid);
                try {
                    $message = $this->readMessage($imap, $uid, $uidValidity);
                    $row = SalesParser::parse($message['sender'], $message['subject'], $message['body'],
                        $message['occurred_at'], $message['message_hash']);
                    $row['source_event_id'] = $message['source_event_id'];
                    $row['sender_email'] = $message['sender'];
                    $row['recipient_email'] = $message['recipient'];
                    $row['recipient_header'] = $message['recipient_header'];
                    $row += $classifier->classify(
                        $message['sender'], $message['recipient'], $message['subject']
                    );
                    if (!SalesParser::relevant($row)) {
                        $result['ignored']++;
                        if (!$dryRun) $this->recordIssue($message, $row['channel'] === 'other' ? 'unknown_sender' : 'unsupported_event',
                            $row['channel'] === 'other' ? 'Отправитель не относится к известным каналам продаж.' : 'Тип события не распознан.');
                        continue;
                    }
                    if ($dryRun) {
                        $result['imported']++;
                    } elseif ($this->insert($row)) {
                        $result['imported']++;
                    } else {
                        $result['ignored']++;
                    }
                } catch (Throwable $e) {
                    $result['errors']++;
                    if (!$dryRun) {
                        $fallback = is_array($message) ? $message : $this->fallbackMessage($uid, $uidValidity);
                        $this->recordIssue($fallback, 'parse_error', mb_substr($e->getMessage(), 0, 500));
                    }
                }
            }

            $effectiveLastUid = max($lastUid, $result['last_uid']);
            if (!$dryRun) $this->markFinished($mailbox, $uidValidity, $effectiveLastUid, $result);
            $result['last_uid'] = $effectiveLastUid;
            return $result;
        } catch (Throwable $e) {
            if (!$dryRun) $this->markFailed(mb_substr($e->getMessage(), 0, 500));
            throw $e;
        } finally {
            imap_close($imap);
        }
    }

    private function assertReady(): void
    {
        if (!function_exists('imap_open')) {
            throw new RuntimeException('PHP IMAP extension не установлено; нужен пакет php8.3-imap.');
        }
        foreach (['host', 'user', 'password'] as $key) {
            if (trim((string) ($this->config[$key] ?? '')) === '') {
                throw new RuntimeException('Не настроен SALES_IMAP_' . strtoupper($key) . '.');
            }
        }
        if (!filter_var($this->config['user'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('SALES_IMAP_USER должен быть адресом почты.');
        }
    }

    private function mailbox(): string
    {
        $host = preg_replace('/[^a-z0-9.\-]/i', '', (string) $this->config['host']);
        $port = max(1, min(65535, (int) ($this->config['port'] ?? 993)));
        $folder = trim((string) ($this->config['folder'] ?? 'INBOX')) ?: 'INBOX';
        if (str_contains($folder, '}') || str_contains($folder, "\r") || str_contains($folder, "\n")) {
            throw new RuntimeException('Некорректная IMAP-папка.');
        }
        return sprintf('{%s:%d/imap/ssl}%s', $host, $port, $folder);
    }

    /** @return array{sender:string,recipient:string,recipient_header:string,subject:string,body:string,occurred_at:string,source_event_id:string,message_hash:string,snippet:string} */
    private function readMessage($imap, int $uid, int $uidValidity): array
    {
        $msgNo = imap_msgno($imap, $uid);
        if ($msgNo < 1) throw new RuntimeException('Письмо не найдено по UID.');
        $header = imap_headerinfo($imap, $msgNo);
        if (!$header) throw new RuntimeException('Не удалось прочитать заголовки письма.');
        $from = $header->from[0] ?? null;
        $sender = SalesClassifier::normalizeEmail($from ? (string) ($from->mailbox ?? '') . '@' . (string) ($from->host ?? '') : '');
        [$recipient, $recipientHeader] = $this->recipient($header, (string) imap_fetchheader($imap, $uid, FT_UID));
        $subject = $this->decodeHeader((string) ($header->subject ?? ''));
        $messageId = trim((string) ($header->message_id ?? ''), "<> \t\r\n");
        $sourceEventId = mb_substr($messageId !== '' ? $messageId : self::SOURCE . ':' . $uidValidity . ':' . $uid, 0, 191);
        $messageHash = sha1(mb_strtolower($sourceEventId, 'UTF-8'));
        $occurredAt = date('Y-m-d H:i:s', (int) ($header->udate ?? time()));
        $structure = imap_fetchstructure($imap, $uid, FT_UID);
        if (!$structure) throw new RuntimeException('Не удалось прочитать структуру письма.');
        $body = $this->messageBody($imap, $uid, $structure);
        if ($body === '') throw new RuntimeException('Письмо не содержит доступного текстового тела.');
        return ['sender' => mb_substr($sender, 0, 255), 'recipient' => $recipient,
            'recipient_header' => $recipientHeader, 'subject' => mb_substr($subject, 0, 255),
            'body' => $body, 'occurred_at' => $occurredAt, 'source_event_id' => $sourceEventId,
            'message_hash' => $messageHash, 'snippet' => mb_substr($body, 0, 512)];
    }

    /** @return array{string,string} */
    private function recipient(object $header, string $rawHeader): array
    {
        foreach (['X-Original-To', 'Original-Recipient'] as $name) {
            if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.+(?:\r?\n[ \t].+)*)/mi', $rawHeader, $m)) {
                $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', $m[1]) ?? $m[1];
                foreach (imap_rfc822_parse_adrlist($unfolded, '') ?: [] as $address) {
                    $email = SalesClassifier::normalizeEmail((string) ($address->mailbox ?? '') . '@' . (string) ($address->host ?? ''));
                    if ($email !== '') return [$email, $name];
                }
                $email = SalesClassifier::normalizeEmail($unfolded);
                if ($email !== '') return [$email, $name];
                if (preg_match('/[a-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-z0-9.-]+/i', $unfolded, $emailMatch)) {
                    $email = SalesClassifier::normalizeEmail($emailMatch[0]);
                    if ($email !== '') return [$email, $name];
                }
            }
        }
        foreach (($header->to ?? []) as $address) {
            $email = SalesClassifier::normalizeEmail((string) ($address->mailbox ?? '') . '@' . (string) ($address->host ?? ''));
            if ($email !== '') return [$email, 'To'];
        }
        // Envelope/Delivered-To часто указывают уже общий Gmail-пул. Используем
        // их только если исходный To действительно отсутствует.
        foreach (['Envelope-To', 'X-Forwarded-To', 'Delivered-To'] as $name) {
            if (!preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/mi', $rawHeader, $m)) continue;
            if (preg_match('/[a-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-z0-9.-]+/i', $m[1], $emailMatch)) {
                $email = SalesClassifier::normalizeEmail($emailMatch[0]);
                if ($email !== '') return [$email, $name];
            }
        }
        return ['', ''];
    }

    private function decodeHeader(string $value): string
    {
        $parts = imap_mime_header_decode($value);
        $out = '';
        foreach ($parts ?: [] as $part) {
            $text = (string) ($part->text ?? '');
            $charset = strtoupper((string) ($part->charset ?? 'UTF-8'));
            if ($charset !== 'DEFAULT' && $charset !== 'UTF-8' && $text !== '') {
                $text = mb_convert_encoding($text, 'UTF-8', $charset);
            }
            $out .= $text;
        }
        return trim($out !== '' ? $out : $value);
    }

    private function messageBody($imap, int $uid, object $structure): string
    {
        $plain = $this->findTextPart($imap, $uid, $structure, '', 'PLAIN');
        if ($plain !== '') return $this->normalizeText($plain, false);
        $html = $this->findTextPart($imap, $uid, $structure, '', 'HTML');
        return $this->normalizeText($html, true);
    }

    private function findTextPart($imap, int $uid, object $part, string $number, string $wantedSubtype): string
    {
        $type = (int) ($part->type ?? -1);
        $subtype = strtoupper((string) ($part->subtype ?? ''));
        if ($type === 0 && $subtype === $wantedSubtype) {
            $raw = $number === '' ? imap_body($imap, $uid, FT_UID | FT_PEEK)
                : imap_fetchbody($imap, $uid, $number, FT_UID | FT_PEEK);
            return $this->decodePart((string) $raw, $part);
        }
        foreach (($part->parts ?? []) as $index => $child) {
            $childNumber = $number === '' ? (string) ($index + 1) : $number . '.' . ($index + 1);
            $found = $this->findTextPart($imap, $uid, $child, $childNumber, $wantedSubtype);
            if ($found !== '') return $found;
        }
        return '';
    }

    private function decodePart(string $raw, object $part): string
    {
        $encoding = (int) ($part->encoding ?? 0);
        if ($encoding === 3) $raw = (string) base64_decode($raw, true);
        elseif ($encoding === 4) $raw = quoted_printable_decode($raw);
        $charset = 'UTF-8';
        foreach (array_merge($part->parameters ?? [], $part->dparameters ?? []) as $param) {
            if (strtoupper((string) ($param->attribute ?? '')) === 'CHARSET') $charset = (string) $param->value;
        }
        if ($raw !== '' && strtoupper($charset) !== 'UTF-8') {
            $raw = mb_convert_encoding($raw, 'UTF-8', $charset ?: 'UTF-8');
        }
        return $raw;
    }

    private function normalizeText(string $body, bool $html): string
    {
        if ($html) $body = strip_tags(preg_replace('/<br\s*\/?\s*>/i', "\n", $body) ?? $body);
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = preg_replace('/[ \t]+/u', ' ', $body) ?? $body;
        $body = preg_replace('/\R{3,}/u', "\n\n", $body) ?? $body;
        return trim(mb_substr($body, 0, 100000));
    }

    private function insert(array $r): bool
    {
        // Старые строки schema11 ещё не имеют event_key. Перед вставкой сверяем
        // бизнес-ID напрямую, чтобы первый live-импорт не продублировал seed.
        if ($r['ticket_no'] !== '') {
            $existing = $this->pdo->prepare('SELECT id FROM sales WHERE channel=? AND kind=? AND ticket_no=? LIMIT 1');
            $existing->execute([$r['channel'], $r['kind'], $r['ticket_no']]);
            if ($id = (int) $existing->fetchColumn()) { $this->updateClassification($id, $r); return false; }
        } elseif ($r['order_no'] !== '') {
            $existing = $this->pdo->prepare('SELECT id FROM sales WHERE channel=? AND kind=? AND order_no=? LIMIT 1');
            $existing->execute([$r['channel'], $r['kind'], $r['order_no']]);
            if ($id = (int) $existing->fetchColumn()) { $this->updateClassification($id, $r); return false; }
        }
        $sql = 'INSERT INTO sales
            (source,email_id,source_event_id,sender_email,recipient_email,recipient_header,channel,
             agent_rule_id,report_agent_id,agent_tag,owner_side,carrier_id,classified_at,
             kind,ticket_no,order_no,quantity,event_key,parse_version,
             route,segment,depart_at,amount,passenger,occurred_at,subject,snippet)
            VALUES (:source,:email_id,:source_event_id,:sender_email,:recipient_email,:recipient_header,:channel,
             :agent_rule_id,:report_agent_id,:agent_tag,:owner_side,:carrier_id,:classified_at,
             :kind,:ticket_no,:order_no,:quantity,:event_key,:parse_version,
             :route,:segment,:depart_at,:amount,:passenger,:occurred_at,:subject,:snippet)
            ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),sender_email=VALUES(sender_email),
             recipient_email=VALUES(recipient_email),recipient_header=VALUES(recipient_header),
             agent_rule_id=VALUES(agent_rule_id),report_agent_id=VALUES(report_agent_id),agent_tag=VALUES(agent_tag),
             owner_side=VALUES(owner_side),carrier_id=VALUES(carrier_id),classified_at=VALUES(classified_at)';
        $st = $this->pdo->prepare($sql);
        $st->execute($r);
        return $st->rowCount() === 1;
    }

    private function updateClassification(int $id, array $r): void
    {
        $this->pdo->prepare('UPDATE sales SET sender_email=?,recipient_email=?,recipient_header=?,
            agent_rule_id=?,report_agent_id=?,agent_tag=?,owner_side=?,carrier_id=?,classified_at=? WHERE id=?')
            ->execute([$r['sender_email'], $r['recipient_email'], $r['recipient_header'], $r['agent_rule_id'],
                $r['report_agent_id'], $r['agent_tag'], $r['owner_side'], $r['carrier_id'], $r['classified_at'], $id]);
    }

    private function state(): array
    {
        $st = $this->pdo->prepare('SELECT * FROM sales_sync_state WHERE source=?');
        $st->execute([$this->stateSource()]);
        return $st->fetch() ?: [];
    }

    private function markStarted(string $mailbox): void
    {
        $this->pdo->prepare("INSERT INTO sales_sync_state (source,mailbox,status,last_started_at)
            VALUES (?,?,'running',NOW()) ON DUPLICATE KEY UPDATE mailbox=VALUES(mailbox),status='running',
            last_started_at=NOW(),last_error=''")->execute([$this->stateSource(), $mailbox]);
    }

    private function markFinished(string $mailbox, int $uidValidity, int $lastUid, array $r): void
    {
        $status = $r['errors'] > 0 ? 'warning' : 'ok';
        $this->pdo->prepare('UPDATE sales_sync_state SET mailbox=?,uid_validity=?,last_uid=?,status=?,
            last_finished_at=NOW(),last_success_at=NOW(),imported_count=imported_count+?,
            ignored_count=ignored_count+?,error_count=error_count+?,last_error=? WHERE source=?')
            ->execute([$mailbox, $uidValidity, $lastUid, $status, $r['imported'], $r['ignored'], $r['errors'],
                $r['errors'] ? 'Часть писем не обработана; смотрите журнал импорта.' : '', $this->stateSource()]);
    }

    private function markFailed(string $error): void
    {
        try {
            $this->pdo->prepare("INSERT INTO sales_sync_state (source,status,last_started_at,last_finished_at,last_error,error_count)
                VALUES (?,'failed',NOW(),NOW(),?,1) ON DUPLICATE KEY UPDATE status='failed',last_finished_at=NOW(),
                last_error=VALUES(last_error),error_count=error_count+1")->execute([$this->stateSource(), mb_substr($error, 0, 500)]);
        } catch (Throwable $ignored) {
        }
    }

    private function recordIssue(array $m, string $code, string $text): void
    {
        $this->pdo->prepare('INSERT INTO sales_ingest_errors
            (source,source_event_id,message_hash,sender,subject,occurred_at,error_code,error_text,snippet)
            VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE error_code=VALUES(error_code),
            error_text=VALUES(error_text),created_at=NOW()')->execute([self::SOURCE,
                mb_substr((string) ($m['source_event_id'] ?? ''), 0, 191), (string) ($m['message_hash'] ?? sha1(json_encode($m))),
                mb_substr((string) ($m['sender'] ?? ''), 0, 255), mb_substr((string) ($m['subject'] ?? ''), 0, 255),
                $m['occurred_at'] ?? null, $code, mb_substr($text, 0, 500), mb_substr((string) ($m['snippet'] ?? ''), 0, 512)]);
    }

    private function fallbackMessage(int $uid, int $uidValidity): array
    {
        $id = self::SOURCE . ':' . $uidValidity . ':' . $uid;
        return ['source_event_id' => $id, 'message_hash' => sha1($id), 'sender' => '', 'subject' => '',
            'occurred_at' => null, 'snippet' => ''];
    }

    private function safeImapError(): string
    {
        $error = trim((string) imap_last_error());
        // IMAP может включить логин/URL в ошибку; пароль в лог никогда не пишем.
        $password = (string) ($this->config['password'] ?? '');
        if ($password !== '') $error = str_replace($password, '[скрыто]', $error);
        return 'Не удалось открыть Gmail IMAP' . ($error !== '' ? ': ' . mb_substr($error, 0, 400) : '.');
    }

    private function stateSource(): string
    {
        $value = preg_replace('/[^a-z0-9_-]/i', '', (string) ($this->config['state_source'] ?? self::SOURCE));
        return mb_substr($value !== '' ? $value : self::SOURCE, 0, 64);
    }
}
