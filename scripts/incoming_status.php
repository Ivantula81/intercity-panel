<?php
// Operator-only CLI: aggregate states, no message text, phone numbers, tokens or retries.
require dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$rows=db()->query('SELECT reply_state,COUNT(*) total,MIN(created_at) oldest_at FROM incoming_receipts GROUP BY reply_state ORDER BY reply_state')->fetchAll();
echo json_encode(['reply_attempts'=>$rows],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
