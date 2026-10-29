<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/ClientPortal.php';

use BWC\Visa\ClientPortal;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

$tmp = sys_get_temp_dir() . '/cp_' . bin2hex(random_bytes(3));
mkdir($tmp);
$write = function (string $name, string $php) use ($tmp): string {
    file_put_contents("$tmp/$name", $php);
    return "$tmp/$name";
};

echo "Config file (outside .env)\n";
$hash = password_hash('s3cret', PASSWORD_DEFAULT);
$ok = $write('ok.php', "<?php return ['api_url' => 'https://visa.example.com/', 'api_key' => 'KEY-123', 'users' => ['anna' => " . var_export($hash, true) . "], 'defaults' => ['country' => 'Switzerland'], 'timeout' => 5];");
$cfg = ClientPortal::load($ok);
$conn = ClientPortal::connection($cfg, 'default');
t_eq('single connection (api_url/api_key at top level) is loaded, trailing slash removed', [$conn['api_url'], $conn['api_key'], $cfg['defaults']['country'], $cfg['max_images'], $conn['verify_tls']], ['https://visa.example.com', 'KEY-123', 'Switzerland', 24, true]);
t_eq('... as the connection "default"', array_keys($cfg['connections']), ['default']);
foreach ([
    'missing file'        => $tmp . '/nope.php',
    'api_url without http' => $write('u.php', "<?php return ['api_url' => 'ftp://x', 'api_key' => 'k'];"),
    'api_key placeholder'  => $write('k.php', "<?php return ['api_url' => 'https://x.ch', 'api_key' => 'change-me'];"),
    'not an array'         => $write('n.php', "<?php return 'x';"),
] as $label => $path) {
    try { ClientPortal::load($path); t_eq("$label -> rejected", 'loaded', 'rejected'); }
    catch (\RuntimeException $e) { t_eq("$label -> rejected, message does not leak the path", str_contains($e->getMessage(), $tmp), false); }
}
putenv('BWC_CLIENT_CONFIG=' . $ok);
t_eq('BWC_CLIENT_CONFIG overrides the location', ClientPortal::configPath(), $ok);
putenv('BWC_CLIENT_CONFIG');
t_eq('default location is config/client.php next to src/', str_ends_with(ClientPortal::configPath(), '/config/client.php'), true);

echo "\nHTTP Basic access protection\n";
$basic = fn (string $u, string $p) => 'Basic ' . base64_encode("$u:$p");
t_eq('right user + password', ClientPortal::authenticate($cfg, $basic('anna', 's3cret')), 'ok');
t_eq('wrong password', ClientPortal::authenticate($cfg, $basic('anna', 'nope')), 'denied');
t_eq('unknown user', ClientPortal::authenticate($cfg, $basic('mallory', 's3cret')), 'denied');
t_eq('no header', ClientPortal::authenticate($cfg, ''), 'denied');
t_eq('Bearer instead of Basic', ClientPortal::authenticate($cfg, 'Bearer abc'), 'denied');
t_eq('garbage base64', ClientPortal::authenticate($cfg, 'Basic !!!'), 'denied');
t_eq('password containing a colon', ClientPortal::authenticate(['users' => ['b' => password_hash('a:b', PASSWORD_DEFAULT)]], $basic('b', 'a:b')), 'ok');
t_eq('no users configured -> locked (fail-closed)', ClientPortal::authenticate(['users' => []], ''), 'unconfigured');
t_eq('no users but allow_anonymous (localhost tests)', ClientPortal::authenticate(['users' => [], 'allow_anonymous' => true], ''), 'ok');

echo "\nCSRF\n";
$_SESSION = [];
$t = ClientPortal::csrfToken();
t_eq('token is 64 hex chars and stable within a session', [strlen($t), ClientPortal::csrfToken() === $t], [64, true]);
t_eq('right token accepted', ClientPortal::csrfValid($t), true);
t_eq('wrong / empty token rejected', [ClientPortal::csrfValid('x'), ClientPortal::csrfValid('')], [false, false]);

echo "\nForwarding to the service\n";
$f1 = "$tmp/a.xlsx"; file_put_contents($f1, 'XLSX');
$f2 = "$tmp/p.jpg"; file_put_contents($f2, 'JPG');
$seen = null;
$spy = function (Psr\Http\Message\RequestInterface $req, array $o) use (&$seen) {
    $seen = ['url' => (string) $req->getUri(), 'headers' => $req->getHeaders(), 'body' => (string) $req->getBody(), 'opts' => $o];
    return GuzzleHttp\Promise\Create::promiseFor(new Response(200, [], json_encode(['ok' => true, 'summary' => ['successful' => 1]])));
};
$files = [['field' => 'excel', 'path' => $f1, 'name' => 'list.xlsx'], ['field' => 'images[]', 'path' => $f2, 'name' => 'pass 1.jpg']];
$res = ClientPortal::forward($conn, ['name' => 'Lang', 'firstname' => 'Daniel', 'send_email' => '1'], $files, $spy);
t_eq('upstream answer is passed through', [$res['status'], json_decode($res['body'], true)['ok']], [200, true]);
t_eq('calls <api_url>/api/visa-request with Bearer key', [$seen['url'], $seen['headers']['Authorization'][0]], ['https://visa.example.com/api/visa-request', 'Bearer KEY-123']);
t_eq('text fields and both file kinds are in the multipart body', [str_contains($seen['body'], 'name="name"'), str_contains($seen['body'], 'name="excel"; filename="list.xlsx"'), str_contains($seen['body'], 'name="images[]"; filename="pass 1.jpg"'), str_contains($seen['body'], 'send_email')], [true, true, true, true]);
t_eq('redirects are not followed (key must not be replayed)', $seen['opts']['allow_redirects'], false);
t_eq('no HMAC headers without hmac_secret', isset($seen['headers']['X-Signature']), false);

$res = ClientPortal::forward($conn, ['name' => 'x'], [], $spy);
$connH = $conn; $connH['hmac_secret'] = 'sec';
ClientPortal::forward($connH, ['name' => 'x'], [], $spy);
$ts = $seen['headers']['X-Timestamp'][0];
t_eq('HMAC headers: signature = hmac_sha256(timestamp, secret)', hash_equals(hash_hmac('sha256', $ts, 'sec'), $seen['headers']['X-Signature'][0]), true);

$down = new MockHandler([new ConnectException('secret detail https://internal/host', new Request('POST', '/x'))]);
$res = ClientPortal::forward($conn, ['name' => 'x'], [], $down);
t_eq('service unreachable -> 502 without internal details', [$res['status'], str_contains($res['body'], 'internal'), str_contains($res['body'], 'KEY-123')], [502, false, false]);

foreach ([401, 403] as $code) {
    $res = ClientPortal::forward($conn, ['name' => 'x'], [], new MockHandler([new Response($code, [], '{"ok":false,"error":"Invalid API key."}')]));
    t_eq("upstream $code -> shown as configuration error (502), not as a login prompt", [$res['status'], str_contains($res['body'], 'api_key')], [502, true]);
}
$res = ClientPortal::forward($conn, ['name' => 'x'], [], new MockHandler([new Response(500, [], '<html>boom</html>')]));
t_eq('non-JSON upstream answer -> clean JSON error', [$res['status'], json_decode($res['body'], true)['ok']], [502, false]);
$res = ClientPortal::forward($conn, ['name' => 'x'], [], new MockHandler([new Response(400, [], '{"ok":false,"error":"Missing required text field(s): zip"}')]));
t_eq('upstream 400 with its message is passed on', [$res['status'], json_decode($res['body'], true)['error']], [400, 'Missing required text field(s): zip']);

echo "\nSeveral connections (one API key per client of the service)\n";
$hA = password_hash('pwA', PASSWORD_DEFAULT);
$hB = password_hash('pwB', PASSWORD_DEFAULT);
$multi = ClientPortal::load($write('multi.php', '<?php return ' . var_export([
    'connections' => [
        'bwc'   => ['label' => 'Berne World Cup', 'api_url' => 'https://visa.example.com/', 'api_key' => 'KEY-BWC'],
        'other' => ['api_url' => 'https://visa.example.com', 'api_key' => 'KEY-OTHER', 'hmac_secret' => 'sec-other'],
    ],
    'users' => [
        'office' => $hA,                                                         // all connections
        'swiss'  => ['password_hash' => $hB, 'connections' => ['bwc']],          // restricted
    ],
    'timeout' => 15,
], true) . ';'));
t_eq('connections are loaded; label defaults to the id', [array_keys($multi['connections']), $multi['connections']['bwc']['label'], $multi['connections']['other']['label']], [['bwc', 'other'], 'Berne World Cup', 'other']);
t_eq('unrestricted user sees every connection', array_keys(ClientPortal::allowedConnections($multi, 'office')), ['bwc', 'other']);
t_eq('restricted user sees only the assigned connection', array_keys(ClientPortal::allowedConnections($multi, 'swiss')), ['bwc']);
t_eq('login works for both user shapes and returns the user', [ClientPortal::identify($multi, $basic('office', 'pwA')), ClientPortal::identify($multi, $basic('swiss', 'pwB'))],
    [['status' => 'ok', 'user' => 'office'], ['status' => 'ok', 'user' => 'swiss']]);
t_eq('wrong password for the restricted user', ClientPortal::identify($multi, $basic('swiss', 'pwA'))['status'], 'denied');

$seen = null;
ClientPortal::forward(ClientPortal::connection($multi, 'bwc'), ['name' => 'x'], [], $spy);
t_eq('connection bwc -> its own URL and API key', [$seen['url'], $seen['headers']['Authorization'][0]], ['https://visa.example.com/api/visa-request', 'Bearer KEY-BWC']);
ClientPortal::forward(ClientPortal::connection($multi, 'other'), ['name' => 'x'], [], $spy);
t_eq('connection other -> a different API key (and its own HMAC secret)', [$seen['headers']['Authorization'][0], isset($seen['headers']['X-Signature'])], ['Bearer KEY-OTHER', true]);
t_eq('timeout / TLS settings are merged into the connection', [ClientPortal::connection($multi, 'bwc')['timeout'], ClientPortal::connection($multi, 'bwc')['verify_tls']], [15, true]);
try { ClientPortal::connection($multi, 'nope'); t_eq('unknown connection -> error', 'found', 'error'); }
catch (\OutOfBoundsException) { t_eq('unknown connection -> error', true, true); }

foreach ([
    'user assigned to an unknown connection' => ['connections' => ['bwc' => ['api_url' => 'https://x.ch', 'api_key' => 'k']], 'users' => ['u' => ['password_hash' => 'h', 'connections' => ['ghost']]]],
    'connection id with spaces/capitals'      => ['connections' => ['My Client' => ['api_url' => 'https://x.ch', 'api_key' => 'k']]],
    'connection without api_key'              => ['connections' => ['bwc' => ['api_url' => 'https://x.ch']]],
    'connection with a non-http url'          => ['connections' => ['bwc' => ['api_url' => 'ftp://x.ch', 'api_key' => 'k']]],
] as $label => $c) {
    try { ClientPortal::load($write('bad_' . md5($label) . '.php', '<?php return ' . var_export($c, true) . ';')); t_eq("$label -> rejected", 'loaded', 'rejected'); }
    catch (\RuntimeException $e) { t_eq("$label -> rejected, key not in the message", str_contains($e->getMessage(), 'KEY-'), false); }
}

t_done();
