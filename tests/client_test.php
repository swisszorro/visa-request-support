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
t_eq('valid config is loaded, trailing slash of api_url removed', [$cfg['api_url'], $cfg['api_key'], $cfg['defaults']['country'], $cfg['max_images'], $cfg['verify_tls']], ['https://visa.example.com', 'KEY-123', 'Switzerland', 24, true]);
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
$res = ClientPortal::forward($cfg, ['name' => 'Lang', 'firstname' => 'Daniel', 'send_email' => '1'], $files, $spy);
t_eq('upstream answer is passed through', [$res['status'], json_decode($res['body'], true)['ok']], [200, true]);
t_eq('calls <api_url>/api/visa-request with Bearer key', [$seen['url'], $seen['headers']['Authorization'][0]], ['https://visa.example.com/api/visa-request', 'Bearer KEY-123']);
t_eq('text fields and both file kinds are in the multipart body', [str_contains($seen['body'], 'name="name"'), str_contains($seen['body'], 'name="excel"; filename="list.xlsx"'), str_contains($seen['body'], 'name="images[]"; filename="pass 1.jpg"'), str_contains($seen['body'], 'send_email')], [true, true, true, true]);
t_eq('redirects are not followed (key must not be replayed)', $seen['opts']['allow_redirects'], false);
t_eq('no HMAC headers without hmac_secret', isset($seen['headers']['X-Signature']), false);

$res = ClientPortal::forward($cfg + [], ['name' => 'x'], [], $spy);
$cfgH = $cfg; $cfgH['hmac_secret'] = 'sec';
ClientPortal::forward($cfgH, ['name' => 'x'], [], $spy);
$ts = $seen['headers']['X-Timestamp'][0];
t_eq('HMAC headers: signature = hmac_sha256(timestamp, secret)', hash_equals(hash_hmac('sha256', $ts, 'sec'), $seen['headers']['X-Signature'][0]), true);

$down = new MockHandler([new ConnectException('secret detail https://internal/host', new Request('POST', '/x'))]);
$res = ClientPortal::forward($cfg, ['name' => 'x'], [], $down);
t_eq('service unreachable -> 502 without internal details', [$res['status'], str_contains($res['body'], 'internal'), str_contains($res['body'], 'KEY-123')], [502, false, false]);

foreach ([401, 403] as $code) {
    $res = ClientPortal::forward($cfg, ['name' => 'x'], [], new MockHandler([new Response($code, [], '{"ok":false,"error":"Invalid API key."}')]));
    t_eq("upstream $code -> shown as configuration error (502), not as a login prompt", [$res['status'], str_contains($res['body'], 'api_key')], [502, true]);
}
$res = ClientPortal::forward($cfg, ['name' => 'x'], [], new MockHandler([new Response(500, [], '<html>boom</html>')]));
t_eq('non-JSON upstream answer -> clean JSON error', [$res['status'], json_decode($res['body'], true)['ok']], [502, false]);
$res = ClientPortal::forward($cfg, ['name' => 'x'], [], new MockHandler([new Response(400, [], '{"ok":false,"error":"Missing required text field(s): zip"}')]));
t_eq('upstream 400 with its message is passed on', [$res['status'], json_decode($res['body'], true)['error']], [400, 'Missing required text field(s): zip']);

t_done();
