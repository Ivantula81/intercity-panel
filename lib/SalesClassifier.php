<?php

declare(strict_types=1);

/**
 * Deterministic sales classification.
 *
 * Recipient address defines ownership; sender and optional subject fragment define
 * the sales agent. Unknown or conflicting input is deliberately left unassigned.
 */
final class SalesClassifier
{
    private ?array $ownAddresses = null;
    private ?array $carrierConfig = null;
    private ?array $agentRules = null;

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{agent_rule_id:?int,report_agent_id:?int,agent_tag:string,owner_side:string,carrier_id:?int,classified_at:string} */
    public function classify(string $sender, string $recipient, string $subject): array
    {
        return self::classifyWithConfiguration(
            $sender,
            $recipient,
            $subject,
            $this->ownAddresses ??= self::splitAddresses($this->option('sales_own_recipient_emails')),
            $this->carriers(),
            $this->rules()
        );
    }

    /**
     * @param list<string> $ownAddresses
     * @param list<array{id:int,name:string,addresses:list<string>}> $carriers
     * @param list<array{id:int,tag:string,sender_pattern:string,subject_contains:string,report_agent_id:?int,priority:int,active:bool}> $rules
     * @return array{agent_rule_id:?int,report_agent_id:?int,agent_tag:string,owner_side:string,carrier_id:?int,classified_at:string}
     */
    public static function classifyWithConfiguration(
        string $sender,
        string $recipient,
        string $subject,
        array $ownAddresses,
        array $carriers,
        array $rules
    ): array {
        $sender = self::normalizeEmail($sender);
        $recipient = self::normalizeEmail($recipient);
        $subjectFolded = mb_strtolower(trim($subject), 'UTF-8');

        $ownerSide = 'unassigned';
        $carrierId = null;
        $owners = [];
        foreach ($ownAddresses as $email) {
            if (self::normalizeEmail($email) === $recipient && $recipient !== '') $owners['ours'] = null;
        }
        foreach ($carriers as $carrier) {
            foreach ($carrier['addresses'] ?? [] as $email) {
                if (self::normalizeEmail($email) === $recipient && $recipient !== '') {
                    $owners['carrier:' . (int) $carrier['id']] = (int) $carrier['id'];
                }
            }
        }
        // A duplicate address is a configuration error. Never guess which owner wins.
        if (count($owners) === 1) {
            $key = (string) array_key_first($owners);
            if ($key === 'ours') $ownerSide = 'ours';
            else { $ownerSide = 'carrier'; $carrierId = (int) reset($owners); }
        }

        $matching = [];
        foreach ($rules as $rule) {
            if (empty($rule['active'])) continue;
            $pattern = self::normalizeSenderPattern((string) ($rule['sender_pattern'] ?? ''));
            if (!self::senderMatches($sender, $pattern)) continue;
            $needle = mb_strtolower(trim((string) ($rule['subject_contains'] ?? '')), 'UTF-8');
            if ($needle !== '' && !str_contains($subjectFolded, $needle)) continue;
            $matching[] = $rule + ['_specificity' => ($needle !== '' ? 10000 : 0) + (str_starts_with($pattern, '@') ? 0 : 1000)];
        }
        usort($matching, static fn(array $a, array $b): int =>
            [(int) ($b['_specificity'] ?? 0), (int) ($b['priority'] ?? 0), -(int) ($b['id'] ?? 0)]
            <=> [(int) ($a['_specificity'] ?? 0), (int) ($a['priority'] ?? 0), -(int) ($a['id'] ?? 0)]
        );
        $agent = $matching[0] ?? null;

        return [
            'agent_rule_id' => $agent ? (int) $agent['id'] : null,
            'report_agent_id' => $agent && !empty($agent['report_agent_id']) ? (int) $agent['report_agent_id'] : null,
            'agent_tag' => $agent ? mb_substr(trim((string) $agent['tag']), 0, 100) : '',
            'owner_side' => $ownerSide,
            'carrier_id' => $carrierId,
            'classified_at' => date('Y-m-d H:i:s'),
        ];
    }

    public static function normalizeEmail(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        if (preg_match('/<([^<>\s]+@[^<>\s]+)>/u', $value, $m)) $value = $m[1];
        $value = trim($value, " \t\r\n<>\"'");
        $at = strrpos($value, '@');
        if ($at === false) return '';
        $local = substr($value, 0, $at);
        $domain = substr($value, $at + 1);
        if ($domain !== '' && preg_match('/[^\x20-\x7E]/', $domain) && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') $domain = mb_strtolower($ascii, 'UTF-8');
        }
        $normalized = $local . '@' . $domain;
        $valid = filter_var($normalized, FILTER_VALIDATE_EMAIL)
            || preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/u', $normalized);
        return $valid ? mb_substr($normalized, 0, 255) : '';
    }

    /** @return list<string> */
    public static function splitAddresses(string $value): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/u', $value) ?: [] as $part) {
            $email = self::normalizeEmail($part);
            if ($email !== '') $out[$email] = true;
        }
        return array_keys($out);
    }

    /** @return list<string> */
    public static function invalidAddressTokens(string $value): array
    {
        $invalid = [];
        foreach (preg_split('/[\s,;]+/u', trim($value)) ?: [] as $part) {
            if ($part !== '' && self::normalizeEmail($part) === '') $invalid[] = mb_substr($part, 0, 80);
        }
        return array_values(array_unique($invalid));
    }

    public static function normalizeSenderPattern(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        if (str_starts_with($value, '@')) {
            $domain = substr($value, 1);
            return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain) ? '@' . $domain : '';
        }
        return self::normalizeEmail($value);
    }

    public static function senderMatches(string $sender, string $pattern): bool
    {
        if ($sender === '' || $pattern === '') return false;
        if (str_starts_with($pattern, '@')) return str_ends_with($sender, $pattern);
        return hash_equals($pattern, $sender);
    }

    /** @return array<string,list<string>> address => owners */
    public static function duplicateOwners(array $ownAddresses, array $carriers): array
    {
        $map = [];
        foreach ($ownAddresses as $email) {
            $email = self::normalizeEmail((string) $email);
            if ($email !== '') $map[$email][] = 'ours';
        }
        foreach ($carriers as $carrier) {
            foreach ($carrier['addresses'] ?? [] as $email) {
                $email = self::normalizeEmail((string) $email);
                if ($email !== '') $map[$email][] = 'carrier:' . (int) $carrier['id'];
            }
        }
        return array_filter($map, static fn(array $owners): bool => count(array_unique($owners)) > 1);
    }

    private function option(string $name): string
    {
        $st = $this->pdo->prepare('SELECT value FROM options WHERE name=?');
        $st->execute([$name]);
        $value = $st->fetchColumn();
        return $value === false ? '' : (string) $value;
    }

    /** @return list<array{id:int,name:string,addresses:list<string>}> */
    private function carriers(): array
    {
        if ($this->carrierConfig !== null) return $this->carrierConfig;
        $rows = $this->pdo->query('SELECT id,atp,notification_emails FROM carriers ORDER BY id')->fetchAll();
        return $this->carrierConfig = array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['atp'],
            'addresses' => self::splitAddresses((string) $row['notification_emails']),
        ], $rows);
    }

    /** @return list<array{id:int,tag:string,sender_pattern:string,subject_contains:string,report_agent_id:?int,priority:int,active:bool}> */
    private function rules(): array
    {
        if ($this->agentRules !== null) return $this->agentRules;
        $rows = $this->pdo->query('SELECT id,tag,sender_pattern,subject_contains,report_agent_id,priority,active
            FROM sales_agent_rules WHERE active=1 ORDER BY priority DESC,id')->fetchAll();
        return $this->agentRules = array_map(static fn(array $row): array => [
            'id' => (int) $row['id'], 'tag' => (string) $row['tag'],
            'sender_pattern' => (string) $row['sender_pattern'],
            'subject_contains' => (string) $row['subject_contains'],
            'report_agent_id' => $row['report_agent_id'] === null ? null : (int) $row['report_agent_id'],
            'priority' => (int) $row['priority'], 'active' => (bool) $row['active'],
        ], $rows);
    }
}
