<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/bootstrap.php';

use BWC\Visa\GeminiPassportReader;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;

$lines = make_td3('TESTER', 'ANNA MARIE', 'AB1234567', 'CHE', '850312', 'F', '320630');

function gem_body(array $record, string $finish = 'STOP'): Response
{
    $payload = ['passports' => (isset($record['passports'])) ? $record['passports'] : (($record['passport_detected'] ?? true) ? [$record] : [])];
    return new Response(200, [], json_encode(['candidates' => [[
        'finishReason' => $finish,
        'content' => ['parts' => [['text' => json_encode($payload)]]],
    ]]]));
}

function reader(array $queue, int $conc = 1, array &$history = null): GeminiPassportReader
{
    $mock = new MockHandler($queue);
    $h = \GuzzleHttp\HandlerStack::create($mock);
    return new GeminiPassportReader('SECRET-KEY-123', 'gemini-test', 'v1beta', new NullLogger(), new NullLogger(), $conc, 26, $mock, false);
}

$img = fn (string $n) => ['media_type' => 'image/png', 'data' => base64_encode('x'), 'source' => $n, 'page' => 1];

echo "GeminiPassportReader (mocked HTTP)\n";

$r = reader([gem_body(llm_record($lines))]);
$out = $r->analyzeMany([$img('a.png')]);
t_eq('D1 valid answer -> one verified passport', [count($out), $out[0]['review'] ?? 'x', $r->diagnostics()], [1, [], []]);

$r = reader([new Response(200, [], json_encode(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => 'not json']]]]]]))]);
$out = $r->analyzeMany([$img('a.png')]);
t_eq('D2 non-JSON answer -> error diagnostic, no crash', [$out, $r->diagnostics()[0]['status']], [[], 'error']);

$r = reader([new Response(200, [], json_encode(['candidates' => []]))]);
$r->analyzeMany([$img('a.png')]);
t_eq('D2 empty candidates -> error diagnostic', $r->diagnostics()[0]['status'], 'error');

$r = reader([gem_body(llm_record($lines), 'SAFETY')]);
$out = $r->analyzeMany([$img('a.png')]);
t_eq('D3 finishReason SAFETY -> error diagnostic', [$out, $r->diagnostics()[0]['status']], [[], 'error']);

$r = reader([new Response(429), new Response(503), gem_body(llm_record($lines))]);
$out = $r->analyzeMany([$img('a.png')]);
t_eq('D4 429 then 503 then ok -> retried, passport returned', [count($out), $r->diagnostics()], [1, []]);

$r = reader([new Response(500), new Response(500), new Response(500), new Response(500)]);
$out = $r->analyzeMany([$img('a.png')]);
$d = $r->diagnostics();
t_eq('D4 permanent 500 -> error diagnostic without secrets', [$out, $d[0]['status'], str_contains(json_encode($d), 'SECRET')], [[], 'error', false]);

$r = reader([gem_body(llm_record($lines)), new Response(500), new Response(500), new Response(500), new Response(500), gem_body(['passport_detected' => false, 'mrz' => [], 'visual' => []])]);
$out = $r->analyzeMany([$img('ok.png'), $img('down.png'), $img('cat.png')]);
$st = array_column($r->diagnostics(), 'status', 'source');
t_eq('D5 mixed batch: 1 passport, 1 error, 1 no_passport are told apart', [count($out), $st['down.png'] ?? null, $st['cat.png'] ?? null], [1, 'error', 'no_passport']);

$rec = llm_record($lines); unset($rec['raw_mrz_lines']);
$r = reader([gem_body($rec)]);
$out = $r->analyzeMany([$img('a.png')]);
t_eq('D6 missing raw_mrz_lines -> passport flagged for review', count($out[0]['review']) > 0, true);

$req = new Request('POST', '/x');
$r = reader([gem_body(llm_record($lines, ['date_of_birth' => '2085-03-12']))]);
$out = $r->analyzeMany([$img('a.png')]);
t_eq('D7 wrong century from LLM corrected', $out[0]['mrz']['date_of_birth'], '1985-03-12');

$two = make_td3('SECOND', 'PERSON', 'CD7654321', 'ITA', '900101', 'M', '300101');
$r = reader([gem_body(['passports' => [llm_record($lines), llm_record($two, ['surname' => 'SECOND', 'given_names' => 'PERSON', 'document_number' => 'CD7654321', 'nationality' => 'ITA', 'sex' => 'M'])]])]);
$out = $r->analyzeMany([$img('multi.pdf')]);
t_eq('D8 two passports in one file -> both returned', array_column(array_column($out, 'mrz'), 'surname'), ['TESTER', 'SECOND']);
t_eq('D8 second passport verified too', $out[1]['review'], []);

$r = reader([gem_body(['passports' => []])]);
$out = $r->analyzeMany([$img('cat.png')]);
t_eq('D9 empty passports array -> no_passport', [$out, $r->diagnostics()[0]['status']], [[], 'no_passport']);

t_done();
