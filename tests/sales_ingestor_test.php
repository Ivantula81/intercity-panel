<?php

require dirname(__DIR__) . '/lib/SalesInboxIngestor.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SalesInboxIngestor metadata-only: SKIP (pdo_sqlite unavailable)\n";
    exit(0);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE sales (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_event_id TEXT NOT NULL DEFAULT "",
    email_id TEXT NOT NULL DEFAULT "",
    event_key TEXT NULL,
    channel TEXT NOT NULL DEFAULT "",
    kind TEXT NOT NULL DEFAULT "",
    ticket_no TEXT NOT NULL DEFAULT "",
    order_no TEXT NOT NULL DEFAULT "",
    sender_email TEXT NOT NULL DEFAULT "",
    recipient_email TEXT NOT NULL DEFAULT "",
    recipient_header TEXT NOT NULL DEFAULT "",
    agent_rule_id INTEGER NULL,
    report_agent_id INTEGER NULL,
    agent_tag TEXT NOT NULL DEFAULT "",
    owner_side TEXT NOT NULL DEFAULT "unassigned",
    carrier_id INTEGER NULL,
    classified_at TEXT NULL
)');

$ingestor = new SalesInboxIngestor($pdo, ['metadata_only' => true]);
$insert = new ReflectionMethod($ingestor, 'insert');
$row = [
    'source_event_id' => 'message-1', 'email_id' => 'hash-1', 'event_key' => null,
    'channel' => 'rosbilet', 'kind' => 'sale', 'ticket_no' => '', 'order_no' => '',
    'sender_email' => 'agent@example.ru', 'recipient_email' => 'owner@example.ru',
    'recipient_header' => 'To', 'agent_rule_id' => 3, 'report_agent_id' => 4,
    'agent_tag' => 'Агент', 'owner_side' => 'carrier', 'carrier_id' => 5,
    'classified_at' => '2026-09-02 15:00:00',
];

if ($insert->invoke($ingestor, $row) !== false || (int) $pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn() !== 0) {
    fwrite(STDERR, "Metadata-only backfill inserted a missing sale.\n");
    exit(1);
}

$pdo->prepare('INSERT INTO sales (source_event_id,email_id,channel,kind) VALUES (?,?,?,?)')
    ->execute(['message-1', 'hash-1', 'rosbilet', 'sale']);
$insert->invoke($ingestor, $row);
$saved = $pdo->query('SELECT sender_email,recipient_email,agent_tag,owner_side,carrier_id FROM sales')->fetch(PDO::FETCH_ASSOC);
if ($saved !== [
    'sender_email' => 'agent@example.ru', 'recipient_email' => 'owner@example.ru',
    'agent_tag' => 'Агент', 'owner_side' => 'carrier', 'carrier_id' => 5,
]) {
    fwrite(STDERR, 'Existing sale metadata was not updated: ' . json_encode($saved, JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}

echo "SalesInboxIngestor metadata-only: OK\n";
