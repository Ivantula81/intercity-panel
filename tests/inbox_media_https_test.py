#!/usr/bin/env python3
"""Explicit Linux isolated-network test. Run as root with unshare --net; never uses application config/DB."""
import base64
import http.server
import json
import os
from pathlib import Path
import shutil
import socket
import ssl
import subprocess
import tempfile
import threading

if not Path('/proc/self/ns/net').exists() or os.readlink('/proc/self/ns/net') == os.readlink('/proc/1/ns/net'):
    raise SystemExit('Requires a separate Linux network namespace')
if [name for _, name in socket.if_nameindex()] != ['lo']:
    raise SystemExit('Only loopback may be present')
ROOT = Path(__file__).resolve().parents[1]
subprocess.run(['ip', 'link', 'set', 'lo', 'up'], check=True)
# A routable-form address is assigned only within this isolated loopback network.
subprocess.run(['ip', 'addr', 'add', '93.184.216.34/32', 'dev', 'lo'], check=True)
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a3ioAAAAASUVORK5CYII=')
requests = {}

class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        requests[self.path] = requests.get(self.path, 0) + 1
        status, data, length = 200, PNG, True
        if self.path.startswith('/retry/') and requests[self.path] == 1 or self.path == '/failure':
            status, data = 403, b'<Error>synthetic failure</Error>'
        elif self.path.startswith('/redirect-'):
            self.send_response(302)
            self.send_header('Location', '/failure' if self.path == '/redirect-failure' else '/ok')
            self.send_header('Content-Length', '0')
            self.end_headers()
            return
        elif self.path == '/partial':
            status = 206
        elif self.path == '/empty':
            data = b''
        elif self.path.startswith('/oversize'):
            data = b'x' * (20 * 1024 * 1024 + 1)
        if self.path in ['/unknown-length', '/oversize-unknown']:
            length = False
        self.send_response(status)
        self.send_header('Content-Type', 'image/png' if status == 200 else 'application/xml')
        if length:
            self.send_header('Content-Length', str(len(data) + (10 if self.path == '/truncated' else 0)))
        self.end_headers()
        try:
            self.wfile.write(data)
        except (BrokenPipeError, ConnectionResetError, ssl.SSLError):
            pass  # Oversize rejection intentionally closes the connection early.

    def log_message(self, *args):
        pass

with tempfile.TemporaryDirectory(prefix='panel-media-https-') as temporary:
    directory = Path(temporary)
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1',
                    '-keyout',str(directory/'key.pem'),'-out',str(directory/'cert.pem'),
                    '-subj','/CN=Synthetic media test','-addext','subjectAltName=IP:93.184.216.34'],
                   check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    server = http.server.HTTPServer(('93.184.216.34',18443),Handler)
    tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    tls.load_cert_chain(directory/'cert.pem',directory/'key.pem')
    server.socket = tls.wrap_socket(server.socket,server_side=True)
    thread = threading.Thread(target=server.serve_forever,daemon=True)
    thread.start()
    probe = directory/'probe.php'
    probe.write_text('''<?php
require $argv[1]; putenv('INBOX_MEDIA_DIR='.$argv[2]);
$mode=$argv[3];$base='https://93.184.216.34:18443';$checks=[];
function verify($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks[]=$label;}
verify(function_exists('curl_init')===($mode==='curl'),'selected transport');
foreach(['/failure','/redirect-failure','/partial','/empty','/truncated','/oversize','/oversize-unknown'] as $path){
 verify(inbox_media_fetch($base.$path)==='','reject '.$path);
}
$expected=base64_decode($argv[4]);
foreach(['/ok','/redirect-ok','/unknown-length'] as $path){
 verify(inbox_media_fetch($base.$path)===$expected,'complete body '.$path);
}
$key=hash('sha256','synthetic-'.$mode);$url=$base.'/retry/'.$mode;
verify(inbox_save_media($url,'image/png','fixture.png',$key)===['',''],'failure not saved');
verify(!is_file($argv[2].'/'.$key.'.bin'),'no failure cache');
$saved=inbox_save_media($url,'image/png','fixture.png',$key);
verify($saved[0]!=='' && file_get_contents($argv[2].'/'.$key.'.bin')===$expected,'successful retry');
verify(inbox_save_media($url,'image/png','fixture.png',$key)===$saved,'cached successful replay');
echo json_encode($checks);
''')
    result = {'network_isolated': True, 'transports': {}}
    try:
        for mode in ['curl','stream']:
            media = directory/mode
            media.mkdir()
            command = [shutil.which('php'),'-d','curl.cainfo='+str(directory/'cert.pem'),
                       '-d','openssl.cafile='+str(directory/'cert.pem')]
            if mode == 'stream':
                command += ['-d','disable_functions=curl_init']
            environment = {key:value for key,value in os.environ.items() if 'proxy' not in key.lower()}
            completed = subprocess.run(command+[str(probe),str(ROOT/'lib/inbox_media.php'),str(media),mode,
                                       base64.b64encode(PNG).decode()],capture_output=True,text=True,
                                       check=True,timeout=60,env=environment)
            checks = json.loads(completed.stdout)
            assert requests['/retry/'+mode] == 2
            checks.append('successful cache avoids third request')
            result['transports'][mode] = checks
        result['assertions'] = sum(map(len,result['transports'].values()))
        print(json.dumps(result,indent=2))
    finally:
        server.shutdown()
        thread.join()
        server.server_close()
