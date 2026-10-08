<?php
// Each run uses a fresh PHP process with synthetic transport functions: never makes a network call.
if (!in_array('--synthetic-transport', $argv, true)) {
    $disabled = 'curl_init,curl_setopt_array,curl_exec,curl_getinfo,curl_close';
    $process = proc_open([PHP_BINARY, '-d', 'disable_functions=' . $disabled, __FILE__, '--synthetic-transport'],
        [0=>['pipe','r'], 1=>STDOUT, 2=>STDERR], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start media test');
    fclose($pipes[0]);
    exit(proc_close($process));
} else {
    if (function_exists('curl_init')) throw new RuntimeException('Synthetic transport must replace curl');
    function curl_init($url) { return new stdClass(); }
    function curl_setopt_array($handle, $options) { return true; }
    function curl_exec($handle) { global $body, $requests; $requests++; return $body; }
    function curl_getinfo($handle, $option) { global $status; return $status; }
    function curl_close($handle) {}
}
foreach (['CURLOPT_RETURNTRANSFER','CURLOPT_FOLLOWLOCATION','CURLOPT_MAXREDIRS','CURLOPT_PROTOCOLS',
    'CURLOPT_REDIR_PROTOCOLS','CURLOPT_TIMEOUT','CURLOPT_CONNECTTIMEOUT','CURLOPT_SSL_VERIFYPEER',
    'CURLOPT_SSL_VERIFYHOST','CURLOPT_MAXFILESIZE','CURLPROTO_HTTPS','CURLINFO_RESPONSE_CODE'] as $i=>$name) {
    if (!defined($name)) define($name, $i+1);
}
require dirname(__DIR__) . '/lib/inbox_media.php';
$checks=0; $requests=0; $status=200; $body='synthetic complete file';
function media_check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
}
$url='https://93.184.216.34/synthetic'; // Numeric address is only validated; curl is replaced above.
foreach ([302,403,404,500,206] as $status) {
    media_check(inbox_media_fetch($url)==='', 'unsuccessful or partial response rejected');
}
$status=200; $body=false;
media_check(inbox_media_fetch($url)==='', 'transport failure rejected');
$body=str_repeat('x',20*1024*1024+1);
media_check(inbox_media_fetch($url)==='', 'oversized response rejected');
$body='synthetic complete file';
media_check(inbox_media_fetch($url)===$body, 'successful response preserved');
$dir=sys_get_temp_dir().'/media-fetch-test-'.bin2hex(random_bytes(8));mkdir($dir,0700);putenv('INBOX_MEDIA_DIR='.$dir);
$key=hash('sha256','synthetic media retry');
try {
    $requests=0;$status=403;$body='synthetic nonempty failure body';
    media_check(inbox_save_media($url,'text/plain','fixture.txt',$key)===['',''], 'failure not reported as saved');
    media_check(glob($dir.'/*')===[], 'failure leaves no permanent cache');
    $status=200;$body='synthetic complete file';
    $saved=inbox_save_media($url,'text/plain','fixture.txt',$key);
    media_check($saved[0]!=='' && file_get_contents($dir.'/'.$key.'.bin')===$body, 'successful retry publishes complete file');
    media_check(inbox_save_media($url,'text/plain','fixture.txt',$key)===$saved && $requests===2, 'accepted file reused without another download');
} finally {
    foreach(glob($dir.'/*') as $file) unlink($file);
    rmdir($dir);putenv('INBOX_MEDIA_DIR');
}
echo "Incoming media transport tests: $checks passed\n";
