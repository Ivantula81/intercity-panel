<?php

require dirname(__DIR__) . '/app/conversations.php';

$cases = [
    'greenapi' => 'max',
    'MAX основной' => 'max',
    'greenapi_tg' => 'telegram',
    'telegram-support' => 'telegram',
    'intercity' => 'whatsapp',
    '' => 'whatsapp',
];
foreach ($cases as $instance => $expected) {
    $actual = conversation_channel_for_inbox($instance);
    if ($actual !== $expected) {
        fwrite(STDERR,"$instance: ожидалось $expected, получено $actual\n");
        exit(1);
    }
}
echo "Conversations mapping: OK\n";
