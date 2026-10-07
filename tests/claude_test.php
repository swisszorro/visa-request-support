<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/bootstrap.php';

use BWC\Visa\FallbackPassportReader;
use BWC\Visa\MrzVerifier;
use BWC\Visa\PassportAnalyzer;
use BWC\Visa\PassportReaderInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;

$lines = make_td3('TESTER', 'ANNA MARIE', 'AB1234567', 'CHE', '850312', 'F', '320630');
$two = make_td3('SECOND', 'PERSON', 'CD7654321', 'ITA', '900101', 'M', '300101');

function claude_body(array $passports, string $stop = 'end_turn'): Response
{
    return new Response(200, [], json_encode(['stop_reason' => $stop, 'content' => [
        ['type' => 'tool_use', 'name' => 'report_passports', 'input' => ['passports' => $passports]],
    ]]));
}

function claude(array $queue, ?array &$captured = null): PassportAnalyzer
{
    $mock = new MockHandler($queue);
    return new PassportAnalyzer('SECRET-KEY-123', 'claude-test', '2023-06-01', 4000, new NullLogger(), new NullLogger(), 1, 26, $mock, false);
}
$img = fn (string $n, string $mt = 'image/png') => ['media_type' => $mt, 'data' => base64_encode('x'), 'source' => $n, 'page' => 1];
$rec = fn (array $l, array $o = []) => ['mrz' => $o + ['surname' => 'TESTER', 'given_names' => 'ANNA MARIE', 'document_number' => 'AB1234567', 'nationality' => 'CHE', 'date_of_birth' => '1985-03-12', 'sex' => 'F', 'expiry_date' => '2032-06-30'],
    'visual' => ['place_of_birth' => 'Bern', 'issue_date' => '2022-07-01'], 'raw_mrz_lines' => $l];

echo "PassportAnalyzer (Claude, mocked HTTP)\n";
$r = claude([claude_body([$rec($lines)])]);
$out = $r->analyzeMany([$img('a.png')]);
t_eq('valid answer -> one verified passport', [count($out), $out[0]['review'], $out[0]['_source']], [1, [], 'a.png']);

$r = claude([claude_body([$rec($lines), $rec($two, ['surname' => 'SECOND', 'given_names' => 'PERSON', 'document_number' => 'CD7654321', 'nationality' => 'ITA', 'sex' => 'M', 'date_of_birth' => '1990-01-01', 'expiry_date' => '2030-01-01'])])]);
$out = $r->analyzeMany([$img('multi.pdf', 'application/pdf')]);
t_eq('PDF with two passports -> both returned', array_column(array_column($out, 'mrz'), 'surname'), ['TESTER', 'SECOND']);

$r = claude([claude_body([])]);
$out = $r->analyzeMany([$img('cat.png')]);
t_eq('empty list -> no_passport', [$out, $r->diagnostics()[0]['status']], [[], 'no_passport']);

$r = claude([new Response(529), new Response(429), claude_body([$rec($lines)])]);
t_eq('overloaded (529) / rate limit (429) are retried', count($r->analyzeMany([$img('a.png')])), 1);

$r = claude([new Response(500), new Response(500), new Response(500), new Response(500)]);
$out = $r->analyzeMany([$img('a.png')]);
t_eq('permanent failure -> error diagnostic, no secrets leaked', [$out, $r->diagnostics()[0]['status'], str_contains(json_encode($r->diagnostics()), 'SECRET')], [[], 'error', false]);

$r = claude([claude_body([$rec($lines)], 'max_tokens')]);
t_eq('stop_reason max_tokens -> error, not a silently truncated read', [$r->analyzeMany([$img('a.png')]), $r->diagnostics()[0]['status']], [[], 'error']);

$r = claude([claude_body([$rec($lines, ['date_of_birth' => '2085-03-12'])])]);
t_eq('wrong century from the model corrected by the MRZ', $r->analyzeMany([$img('a.png')])[0]['mrz']['date_of_birth'], '1985-03-12');

$bad = $lines; $bad[1] = substr_replace($bad[1], '3', 18, 1);
$r = claude([claude_body([$rec($bad)])]);
t_eq('altered check digit -> review (model is not trusted)', count($r->analyzeMany([$img('a.png')])[0]['review']) > 0, true);

/** Stub reader returning prepared results per source. */
final class StubReader implements PassportReaderInterface
{
    public array $seen = [];
    public function __construct(private array $byFile, private array $diag = []) {}
    public function analyze(array $image): ?array { return $this->analyzeMany([$image])[0] ?? null; }
    public function diagnostics(): array { return $this->diag; }
    public function analyzeMany(array $images): array
    {
        $out = [];
        foreach ($images as $i) {
            $this->seen[] = $i['source'];
            foreach ($this->byFile[$i['source']] ?? [] as $p) {
                $out[] = $p + ['_source' => $i['source'], '_page' => $i['page']];
            }
        }
        return $out;
    }
}
$pp = fn (string $doc, array $review = []) => ['mrz' => ['document_number' => $doc], 'review' => $review];

echo "\nFallbackPassportReader (engine chain)\n";
$primary = new StubReader(['ok.png' => [$pp('AAA111')], 'weak.png' => [$pp('BBB222', ['check digit failed'])]], [
    ['source' => 'down.png', 'page' => 1, 'status' => 'error', 'message' => 'service down'],
]);
$secondary = new StubReader(['weak.png' => [$pp('BBB222')], 'down.png' => [$pp('CCC333')]]);
$chain = new FallbackPassportReader([$primary, $secondary], new NullLogger());
$img2 = fn (string $n) => ['media_type' => 'image/png', 'data' => 'x', 'source' => $n, 'page' => 1];
$out = $chain->analyzeMany([$img2('ok.png'), $img2('weak.png'), $img2('down.png')]);
t_eq('secondary only sees files the primary could not read safely', $secondary->seen, ['weak.png', 'down.png']);
t_eq('weak read replaced by the clean one, failed file recovered', [count($out), array_map(fn ($p) => [$p['mrz']['document_number'], $p['review']], $out)], [3, [['AAA111', []], ['BBB222', []], ['CCC333', []]]]);
t_eq('obsolete error diagnostic of the recovered file is dropped', $chain->diagnostics(), []);

$primary = new StubReader(['x.png' => [$pp('DDD444', ['a'])]]);
$secondary = new StubReader(['x.png' => [$pp('DDD444', ['a', 'b'])]]);
$out = (new FallbackPassportReader([$primary, $secondary], new NullLogger()))->analyzeMany([$img2('x.png')]);
t_eq('both engines doubtful: the less doubtful read is kept, still flagged for review', [count($out), count($out[0]['review'])], [1, 1]);

$primary = new StubReader(['y.png' => [$pp('EEE555')]]);
$secondary = new StubReader([]);
(new FallbackPassportReader([$primary, $secondary], new NullLogger()))->analyzeMany([$img2('y.png')]);
t_eq('everything read cleanly -> fallback engine is never called (no extra cost)', $secondary->seen, []);

t_done();
