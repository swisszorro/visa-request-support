<?php

declare(strict_types=1);

namespace BWC\Visa;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Passport reader backed by Google Gemini Flash (Generative Language API).
 * Reads the MRZ + the visual place of birth / issue date and returns the same
 * structure as the other readers. A response JSON schema forces well-formed
 * output. No ICAO check digits are produced (vision model), so 'check_digits'
 * is omitted.
 */
final class GeminiPassportReader implements PassportReaderInterface
{
    private Client $http;

    /** @var array<int,array{source:string,page:int,status:string,message:string}> */
    private array $diagnostics = [];

    private const MAX_RETRIES = 3;

    /**
     * @param callable|null $handler optional Guzzle handler (tests inject a MockHandler)
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $apiVersion,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $extractionLogger,
        private readonly int $concurrency = 8,
        private readonly int $pivotYear = 30,
        ?callable $handler = null,
        private readonly bool $backoff = true,
    ) {
        $stack = HandlerStack::create($handler);
        // Retry transient failures (network, 429, 5xx) with exponential backoff + jitter.
        $stack->push(Middleware::retry(
            static function (int $retries, $req, $resp = null, $err = null): bool {
                if ($retries >= self::MAX_RETRIES) {
                    return false;
                }
                if ($err instanceof ConnectException) {
                    return true;
                }
                $code = $resp ? $resp->getStatusCode() : 0;
                return $code === 429 || $code >= 500;
            },
            fn (int $retries): int => $this->backoff ? (int) (1000 * (2 ** $retries) + random_int(0, 500)) : 0
        ));
        $this->http = new Client([
            'base_uri'    => 'https://generativelanguage.googleapis.com',
            'timeout'     => 120,
            'handler'     => $stack,
            // the key travels in a header, never in the URL (URLs end up in logs / exception texts)
            'headers'     => ['x-goog-api-key' => $this->apiKey],
        ]);
    }

    /** Per-file problems of the last analyzeMany() run (API errors, no passport found). */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /** One passport data page. */
    private const ITEM = [
        'type' => 'OBJECT',
        'properties' => [
            'mrz' => [
                'type' => 'OBJECT',
                'properties' => [
                    'surname'         => ['type' => 'STRING', 'nullable' => true],
                    'given_names'     => ['type' => 'STRING', 'nullable' => true],
                    'document_number' => ['type' => 'STRING', 'nullable' => true],
                    'nationality'     => ['type' => 'STRING', 'nullable' => true],
                    'date_of_birth'   => ['type' => 'STRING', 'nullable' => true],
                    'sex'             => ['type' => 'STRING', 'nullable' => true, 'enum' => ['M', 'F', 'X']],
                    'expiry_date'     => ['type' => 'STRING', 'nullable' => true],
                ],
            ],
            'visual' => [
                'type' => 'OBJECT',
                'properties' => [
                    'place_of_birth' => ['type' => 'STRING', 'nullable' => true, 'maxLength' => 60],
                    'issue_date'     => ['type' => 'STRING', 'nullable' => true, 'maxLength' => 10],
                ],
            ],
            'raw_mrz_lines' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
        ],
        'required' => ['mrz', 'visual', 'raw_mrz_lines'],
    ];

    /** A file may contain several passports (multi-page PDF, two passports on one photo). */
    private const SCHEMA = [
        'type' => 'OBJECT',
        'properties' => ['passports' => ['type' => 'ARRAY', 'items' => self::ITEM]],
        'required' => ['passports'],
    ];

    private const PROMPT = PassportPrompt::TEXT;

    /**
     * Analyse many images CONCURRENTLY (Guzzle pool).
     *
     * @param array<int,array{media_type:string,data:string,source:string,page:int}> $images
     * @return array<int,array<string,mixed>> detected passports; failures go to diagnostics()
     */
    public function analyzeMany(array $images): array
    {
        $this->diagnostics = [];
        if ($images === []) {
            return [];
        }

        $results = [];
        $requests = function () use ($images) {
            foreach ($images as $i => $img) {
                yield $i => new Request(
                    'POST',
                    $this->endpoint(),
                    ['content-type' => 'application/json'],
                    $this->payload($img)
                );
            }
        };

        $pool = new Pool($this->http, $requests(), [
            'concurrency' => max(1, $this->concurrency),
            'fulfilled'   => function (ResponseInterface $resp, int $i) use (&$results, $images): void {
                $results[$i] = $this->interpret((string) $resp->getBody(), $images[$i]);
            },
            'rejected'    => function ($reason, int $i) use (&$results, $images): void {
                $msg = $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason;
                if ($reason instanceof \GuzzleHttp\Exception\RequestException && $reason->hasResponse()) {
                    // the exception text is cut off after ~120 chars; the full API error explains the cause
                    $msg = 'HTTP ' . $reason->getResponse()->getStatusCode() . ': ' . substr((string) $reason->getResponse()->getBody(), 0, 500);
                }
                $this->logger->error('Gemini API call failed', ['source' => $images[$i]['source'], 'error' => $msg]);
                $this->diag($images[$i], 'error', 'Analysis service call failed after retries');
                $results[$i] = null;
            },
        ]);
        $pool->promise()->wait();

        ksort($results);
        $flat = [];
        foreach ($results as $list) {
            foreach ($list ?? [] as $p) {
                $flat[] = $p;
            }
        }
        return $flat;
    }

    /**
     * @param array{media_type:string,data:string,source:string,page:int} $image
     * @return array<string,mixed>|null
     */
    public function analyze(array $image): ?array
    {
        $out = $this->analyzeMany([$image]);
        return $out[0] ?? null; // first passport only; use analyzeMany() for all
    }

    private function diag(array $image, string $status, string $message): void
    {
        $this->diagnostics[] = ['source' => $image['source'], 'page' => $image['page'], 'status' => $status, 'message' => $message];
    }

    private function endpoint(): string
    {
        return sprintf('/%s/models/%s:generateContent', $this->apiVersion, $this->model);
    }

    /** @param array{media_type:string,data:string,source:string,page:int} $image */
    private function payload(array $image): string
    {
        return (string) json_encode([
            'contents' => [[
                'parts' => [
                    ['inline_data' => ['mime_type' => $image['media_type'], 'data' => $image['data']]],
                    ['text' => self::PROMPT],
                ],
            ]],
            'generationConfig' => [
                'temperature'      => 0,
                'responseMimeType' => 'application/json',
                'responseSchema'   => self::SCHEMA,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Parse a Gemini response body for one image into the shared record shape.
     * @param array{media_type:string,data:string,source:string,page:int} $image
     * @return array<int,array<string,mixed>>|null passports found (empty = none), null = technical failure
     */
    private function interpret(string $responseBody, array $image): ?array
    {
        $body = json_decode($responseBody, true);
        $cand = $body['candidates'][0] ?? null;
        $finish = (string) ($cand['finishReason'] ?? '');
        if (!is_array($cand) || ($finish !== '' && $finish !== 'STOP')) {
            $why = $finish !== '' ? $finish : (string) ($body['promptFeedback']['blockReason'] ?? 'no candidate');
            $this->logger->warning('Gemini returned no usable answer', ['source' => $image['source'], 'reason' => $why]);
            $this->diag($image, 'error', 'Analysis service gave no usable answer (' . $why . ')');
            return null;
        }
        $text = null;
        foreach ($cand['content']['parts'] ?? [] as $part) {
            if (empty($part['thought']) && isset($part['text'])) {
                $text = $part['text'];
            }
        }
        $data = is_string($text) ? json_decode($text, true) : null;
        if (!is_array($data)) {
            $this->diag($image, 'error', 'Analysis service answer was not valid JSON');
            return null;
        }

        $passports = array_values(array_filter((array) ($data['passports'] ?? []), 'is_array'));
        if ($passports === []) {
            $this->diag($image, 'no_passport', 'No passport / MRZ detected in this file');
            $this->extractionLogger->info(
                ExtractionLog::format('gemini', $image['source'], $image['page'], null, null)
            );
            return [];
        }

        $found = [];
        foreach ($passports as $idx => $data) {
            $data['passport_detected'] = true;
            // Fail-closed: re-derive the fields from the raw MRZ lines, validate the
            // ICAO check digits and cross-check against the structured fields.
            $data = MrzVerifier::apply($data, $this->pivotYear);
            if ($data['review'] !== []) {
                $this->logger->warning('Passport read needs manual review', [
                    'source' => $image['source'], 'reasons' => $data['review'],
                ]);
            }

            $data['_source'] = $image['source'];
            $data['_page']   = $image['page'];
            $data['_index']  = $idx + 1;

            $this->extractionLogger->info(
                ExtractionLog::format('gemini (' . $this->model . ')', $image['source'], $image['page'], null, $data)
            );
            $found[] = $data;
        }

        return $found;
    }
}
