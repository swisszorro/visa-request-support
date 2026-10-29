<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/bootstrap.php';

use BWC\Visa\Auth;
use BWC\Visa\ClientProfile;
use BWC\Visa\ClientRegistry;
use Slim\Psr7\Factory\ServerRequestFactory;

$tmp = sys_get_temp_dir() . '/cl_' . bin2hex(random_bytes(3));
mkdir($tmp);
$write = function (string $name, string $php) use ($tmp): string {
    file_put_contents("$tmp/$name", $php);
    return "$tmp/$name";
};
$docx = function (string $name, bool $valid = true) use ($tmp): string {
    $z = new ZipArchive();
    $z->open("$tmp/$name", ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString($valid ? 'word/document.xml' : 'readme.txt', '<x/>');
    $z->close();
    return "$tmp/$name";
};
$set = function (array $env): void {
    foreach ($env as $k => $v) {
        putenv("$k=$v");
        $_ENV[$k] = $v;
    }
};
$set(['SERVICE_API_KEYS' => 'env-key-1,env-key-2', 'SMTP_HOST' => 'smtp.env.example', 'SMTP_PORT' => '2525', 'SMTP_USER' => 'env-user',
    'SMTP_PASS' => 'env-pass', 'SMTP_SECURE' => 'ssl', 'MAIL_ENABLED' => 'true', 'MAIL_FROM' => 'env@example.ch', 'MAIL_FROM_NAME' => 'Env Sender',
    'MAIL_DEFAULT_TO' => 'env-to@example.ch', 'MAIL_SUBJECT' => 'Env subject', 'MAIL_HTML' => 'true', 'MAIL_BODY' => 'env body',
    'EVENT_START' => '2026-11-12', 'EVENT_END' => '2026-11-14', 'SERVICE_HMAC_SECRET' => '']);

$tpl = $docx('a.docx');
$tplB = $docx('b.docx');
$full = fn (string $key, string $tplPath): array => [
    'name' => 'Event B', 'api_keys' => [$key], 'template' => $tplPath,
    'event' => ['start' => '2027-05-01', 'end' => '2027-05-03'],
    'smtp' => ['host' => 'smtp.b.example', 'port' => 465, 'secure' => 'ssl', 'user' => 'b-user', 'pass' => 'b-pass'],
    'mail' => ['enabled' => true, 'default_to' => 'b-to@example.ch', 'subject' => 'B subject', 'from' => 'b@example.ch', 'from_name' => 'B Sender', 'html' => false, 'body' => 'B body'],
];

echo "Profile: settings are the client's own\n";
$b = ClientProfile::fromArray('event-b', $full('key-b-0123456789abcdef', $tplB));
t_eq('SMTP of the profile (host/port/secure/user/pass)', $b->smtp(), ['host' => 'smtp.b.example', 'port' => 465, 'secure' => 'ssl', 'user' => 'b-user', 'pass' => 'b-pass']);
t_eq('mail part of the profile (all 7 settings)', $b->mail(), ['enabled' => true, 'default_to' => 'b-to@example.ch', 'subject' => 'B subject', 'from' => 'b@example.ch', 'from_name' => 'B Sender', 'html' => false, 'body' => 'B body']);
t_eq('event window of the profile', [$b->eventStart(), $b->eventEnd()], ['2027-05-01', '2027-05-03']);
t_eq('template of the profile', $b->templatePath(), $tplB);

$default = ClientProfile::legacy();
t_eq('default client reads SMTP from .env', $default->smtp(), ['host' => 'smtp.env.example', 'port' => 2525, 'secure' => 'ssl', 'user' => 'env-user', 'pass' => 'env-pass']);
t_eq('default client reads MAIL_* from .env', [$default->mail()['enabled'], $default->mail()['from'], $default->mail()['from_name'], $default->mail()['default_to'], $default->mail()['subject'], $default->mail()['html'], $default->mail()['body']],
    [true, 'env@example.ch', 'Env Sender', 'env-to@example.ch', 'Env subject', true, 'env body']);
t_eq('default client reads EVENT_START/END from .env', [$default->eventStart(), $default->eventEnd()], ['2026-11-12', '2026-11-14']);
$set(['LETTER_TEMPLATE' => '']);
t_eq('default template is the BWC letter unless LETTER_TEMPLATE says otherwise', ClientProfile::legacy()->templatePath() === dirname(__DIR__) . '/resources/BWC Visa Invitation Letter.docx', true);
$set(['LETTER_TEMPLATE' => $tpl]);
t_eq('LETTER_TEMPLATE configures the template of the default client (absolute path)', ClientProfile::legacy()->templatePath(), $tpl);
$set(['LETTER_TEMPLATE' => 'tests/../resources/BWC Visa Invitation Letter.docx']);
t_eq('... or relative to the project root', is_file(ClientProfile::legacy()->templatePath()), true);
$set(['LETTER_TEMPLATE' => '']);

echo "\nIsolation: a named client inherits nothing from .env\n";
$bare = ClientProfile::fromArray('bare', ['api_key' => 'k', 'template' => $tpl, 'mail' => ['subject' => 'Only a subject']]);
t_eq('no SMTP section -> empty host/user/pass (NOT the .env SMTP credentials)', [$bare->smtp()['host'], $bare->smtp()['user'], $bare->smtp()['pass']], ['', '', '']);
t_eq('partial mail section -> other settings are off/empty, not the .env sender', [$bare->mail()['enabled'], $bare->mail()['from'], $bare->mail()['default_to'], $bare->mail()['subject']], [false, '', '', 'Only a subject']);
t_eq('no event section -> no event window (not the .env one)', [$bare->eventStart(), $bare->eventEnd()], [null, null]);

echo "\nValidation of config/clients.php\n";
$bad = function (string $label, array $profile, string $needle) {
    try { ClientProfile::fromArray('x', $profile); t_eq("$label -> rejected", 'accepted', 'rejected'); }
    catch (\RuntimeException $e) { t_eq("$label -> rejected ({$needle})", str_contains($e->getMessage(), $needle), true); }
};
$bad('typo in a section name', ['api_key' => 'k', 'template' => 'a.docx', 'smpt' => []], 'smpt');
$bad('typo inside smtp', ['api_key' => 'k', 'template' => 'a.docx', 'smtp' => ['hots' => 'x']], 'hots');
$bad('typo inside mail', ['api_key' => 'k', 'template' => 'a.docx', 'mail' => ['frm' => 'x']], 'frm');
$bad('template missing', ['api_key' => 'k'], 'template');
$bad('invalid event date', ['api_key' => 'k', 'template' => 'a.docx', 'event' => ['start' => '12.11.2026']], 'event.start');
$bad('event start after end', ['api_key' => 'k', 'template' => 'a.docx', 'event' => ['start' => '2026-11-14', 'end' => '2026-11-12']], 'after');
$bad('unknown smtp.secure', ['api_key' => 'k', 'template' => 'a.docx', 'smtp' => ['secure' => 'starttls']], 'secure');
$bad('non-numeric port', ['api_key' => 'k', 'template' => 'a.docx', 'smtp' => ['port' => 'abc']], 'port');
try { ClientProfile::fromArray('Bad Id!', ['api_key' => 'k', 'template' => 'a.docx']); t_eq('invalid id -> rejected', 'accepted', 'rejected'); }
catch (\RuntimeException) { t_eq('invalid id -> rejected', true, true); }

echo "\nTemplate check\n";
$missing = ClientProfile::fromArray('m', ['api_key' => 'k', 'template' => 'resources/does-not-exist.docx']);
try { $missing->templatePath(); t_eq('missing template -> error', 'found', 'error'); }
catch (\RuntimeException $e) { t_eq('missing template -> error that names the client', str_contains($e->getMessage(), "'m'"), true); }
$notDocx = ClientProfile::fromArray('n', ['api_key' => 'k', 'template' => $docx('bad.docx', false)]);
try { $notDocx->templatePath(); t_eq('zip without word/document.xml -> error', 'accepted', 'error'); }
catch (\RuntimeException $e) { t_eq('zip without word/document.xml -> not a valid .docx', str_contains($e->getMessage(), 'not a valid .docx'), true); }
file_put_contents("$tmp/plain.docx", 'text');
$plain = ClientProfile::fromArray('p', ['api_key' => 'k', 'template' => "$tmp/plain.docx"]);
try { $plain->templatePath(); t_eq('text file named .docx -> error', 'accepted', 'error'); }
catch (\RuntimeException) { t_eq('text file named .docx -> error', true, true); }

echo "\nRegistry: which key belongs to which client\n";
$hashKey = 'hashed-key-0123456789abcdef';
$cfgFile = $write('clients.php', '<?php return ' . var_export([
    'bwc'     => $full('key-bwc-0123456789abcdef', $tpl) + [],
    'event-b' => ['api_keys' => ['key-b-1', 'key-b-2-rotated'], 'template' => $tplB] + [],
    'hashed'  => ['api_key_hashes' => [hash('sha256', $hashKey)], 'template' => $tpl],
], true) . ';');
$reg = ClientRegistry::load($cfgFile);
t_eq('keys of three profiles + env keys are known', $reg->hasKeys(), true);
t_eq('plain key -> its client', $reg->match('key-bwc-0123456789abcdef')?->id, 'bwc');
t_eq('second key of a client (rotation) -> same client', [$reg->match('key-b-1')?->id, $reg->match('key-b-2-rotated')?->id], ['event-b', 'event-b']);
t_eq('sha256-hashed key -> its client', $reg->match($hashKey)?->id, 'hashed');
t_eq('.env key -> client "default"', [$reg->match('env-key-1')?->id, $reg->match('env-key-2')?->id], ['default', 'default']);
t_eq('unknown / empty / hash itself is not a key', [$reg->match('nope'), $reg->match(''), $reg->match(hash('sha256', $hashKey))], [null, null, null]);
t_eq('two clients -> two different SMTP servers', [$reg->match('key-bwc-0123456789abcdef')?->smtp()['host'], $reg->match('env-key-1')?->smtp()['host']], ['smtp.b.example', 'smtp.env.example']);

try { ClientRegistry::load($write('dup.php', '<?php return ' . var_export(['a' => ['api_key' => 'same', 'template' => $tpl], 'b' => ['api_key' => 'same', 'template' => $tpl]], true) . ';')); t_eq('same key for two clients -> rejected', 'loaded', 'rejected'); }
catch (\RuntimeException $e) { t_eq('same key for two clients -> rejected', str_contains($e->getMessage(), 'same API key'), true); }
try { ClientRegistry::load($write('nokey.php', '<?php return ' . var_export(['a' => ['template' => $tpl]], true) . ';')); t_eq('client without key -> rejected', 'loaded', 'rejected'); }
catch (\RuntimeException $e) { t_eq('client without key -> rejected', str_contains($e->getMessage(), 'no API key'), true); }
try { ClientRegistry::load($write('def.php', '<?php return ' . var_export(['default' => ['api_key' => 'k', 'template' => $tpl]], true) . ';')); t_eq("id 'default' -> reserved", 'loaded', 'rejected'); }
catch (\RuntimeException) { t_eq("id 'default' is reserved for the .env keys", true, true); }
t_eq('no file -> only the .env keys', array_keys(ClientRegistry::load("$tmp/none.php")->profiles()), []);

echo "\nAuth: the key selects the client\n";
$req = fn (array $h) => array_reduce(array_keys($h), fn ($r, $k) => $r->withHeader($k, $h[$k]), (new ServerRequestFactory())->createServerRequest('POST', '/api/visa-request'));
$r = Auth::check($req(['Authorization' => 'Bearer key-b-1']), $reg);
t_eq('Bearer key of client event-b', [$r['ok'], $r['client']->id ?? null], [true, 'event-b']);
$r = Auth::check($req(['X-API-Key' => 'env-key-2']), $reg);
t_eq('X-API-Key of the .env list -> default', [$r['ok'], $r['client']->id ?? null], [true, 'default']);
$r = Auth::check($req(['Authorization' => 'Bearer wrong']), $reg);
t_eq('wrong key -> 401', [$r['ok'], $r['status']], [false, 401]);
$r = Auth::check($req([]), $reg);
t_eq('no key -> 401', [$r['ok'], $r['status']], [false, 401]);

$hm = ClientRegistry::load($write('hm.php', '<?php return ' . var_export(['sec' => ['api_key' => 'hm-key', 'hmac_secret' => 's3', 'template' => $tpl], 'open' => ['api_key' => 'open-key', 'template' => $tpl]], true) . ';'));
$ts = (string) time();
t_eq('client with hmac_secret: key alone is not enough', Auth::check($req(['Authorization' => 'Bearer hm-key']), $hm)['ok'], false);
t_eq('... valid signature is accepted', Auth::check($req(['Authorization' => 'Bearer hm-key', 'X-Timestamp' => $ts, 'X-Signature' => hash_hmac('sha256', $ts, 's3')]), $hm)['ok'], true);
t_eq('... signature of another secret is rejected', Auth::check($req(['Authorization' => 'Bearer hm-key', 'X-Timestamp' => $ts, 'X-Signature' => hash_hmac('sha256', $ts, 'other')]), $hm)['ok'], false);
t_eq('another client without hmac_secret is unaffected', Auth::check($req(['Authorization' => 'Bearer open-key']), $hm)['ok'], true);

$none = new ClientRegistry([]);
$set(['SERVICE_API_KEYS' => '']); // '' in $_ENV wins over the container's real environment
$r = Auth::check($req(['Authorization' => 'Bearer x']), $none);
t_eq('no keys anywhere -> misconfigured (500), not "invalid key"', [$r['ok'], $r['status']], [false, 500]);

t_done();
