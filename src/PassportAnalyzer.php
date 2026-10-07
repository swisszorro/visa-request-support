<?php

declare(strict_types=1);

namespace BWC\Visa;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Passport reader backed by the Anthropic Claude Messages API (vision + native PDF).
 * Same behaviour as GeminiPassportReader: concurrent calls, retry with backoff,
 * several passports per file, per-file diagnostics, and every read goes through
 * the fail-closed MrzVerifier (check digits decide, not the model).
 *
 * Structured outputs (JSON schema) return schema-conformant JSON.
 */
final class PassportAnalyzer implements PassportReaderInterface
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
        private readonly int $maxTokens,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $extractionLogger,
        private readonly int $concurrency = 4,
        private readonly int $pivotYear = 30,
        ?callable $handler = null,
        private readonly bool $backoff = true,
    ) {
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::retry(
            static function (int $retries, $req, $resp = null, $err = null): bool {
                if ($retries >= self::MAX_RETRIES) {
                    return false;
                }
                if ($err instanceof ConnectException) {
                    return true;
                }
                $code = $resp ? $resp->getStatusCode() : 0;
                return $code === 429 || $code === 529 || $code >= 500; // 529 = overloaded
            },
            fn (int $retries): int => $this->backoff ? (int) (1000 * (2 ** $retries) + random_int(0, 500)) : 0
        ));
        $this->http = new Client([
            'base_uri' => 'https://api.anthropic.com',
            'timeout'  => 120,
            'handler'  => $stack,
            'headers'  => [
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => $this->apiVersion,
            ],
        ]);
    }

    /**
     * JSON schema for Anthropic "structured outputs" (output_config.format). Forced tool use
     * (tool_choice tool/any) is rejected by Sonnet 5.5 / Opus 5.5 (HTTP 400), structured outputs
     * work on all current models and guarantee schema-valid JSON. Limits of that feature: every
     * object needs additionalProperties:false, no minLength/maxLength/minimum/maximum.
     */
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'passports' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'mrz' => [
                            'type' => 'object',
                            'properties' => [
                                'surname'         => ['type' => ['string', 'null']],
                                'given_names'     => ['type' => ['string', 'null']],
                                'document_number' => ['type' => ['string', 'null']],
                                'nationality'     => ['type' => ['string', 'null']],
                                'date_of_birth'   => ['type' => ['string', 'null']],
                                'sex'             => ['enum' => ['M', 'F', 'X', null]], // no 'type' next to enum: the API rejects ['string','null'] + enum
                                'expiry_date'     => ['type' => ['string', 'null']],
                            ],
                            'required' => ['surname', 'given_names', 'document_number', 'nationality', 'date_of_birth', 'sex', 'expiry_date'],
                            'additionalProperties' => false,
                        ],
                        'visual' => [
                            'type' => 'object',
                            'properties' => [
                                'place_of_birth' => ['type' => ['string', 'null']],
                                'issue_date'     => ['type' => ['string', 'null']],
                            ],
                            'required' => ['place_of_birth', 'issue_date'],
                            'additionalProperties' => false,
                        ],
                        'raw_mrz_lines' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['mrz', 'visual', 'raw_mrz_lines'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['passports'],
        'additionalProperties' => false,
    ];

    /** Per-file problems of the last analyzeMany() run. */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * @param array<int,array{media_type:string,data:string,source:string,page:int}> $images
     * @return array<int,array<string,mixed>>
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
                yield $i => new Request('POST', '/v1/messages', ['content-type' => 'application/json'], $this->payload($img));
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
                $this->logger->error('Claude API call failed', ['source' => $images[$i]['source'], 'error' => $msg]);
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

    /** First passport of the image only; use analyzeMany() for all. */
    public function analyze(array $image): ?array
    {
        return $this->analyzeMany([$image])[0] ?? null;
    }

    private function diag(array $image, string $status, string $message): void
    {
        $this->diagnostics[] = ['source' => $image['source'], 'page' => $image['page'], 'status' => $status, 'message' => $message];
    }

    /** @param array{media_type:string,data:string,source:string,page:int} $image */
    private function payload(array $image): string
    {
        $isPdf = $image['media_type'] === 'application/pdf';
        $block = [
            'type'   => $isPdf ? 'document' : 'image',
            'source' => ['type' => 'base64', 'media_type' => $image['media_type'], 'data' => $image['data']],
        ];
        return (string) json_encode([
            'model'         => $this->model,
            'max_tokens'    => $this->maxTokens,
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => self::SCHEMA]],
            'messages'      => [[
                'role'    => 'user',
                'content' => [
                    $block,
                    ['type' => 'text', 'text' => PassportPrompt::TEXT . ' Format: the JSON object with the key `passports`.'],
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array{media_type:string,data:string,source:string,page:int} $image
     * @return array<int,array<string,mixed>>|null passports found (empty = none), null = technical failure
     */
    private function interpret(string $responseBody, array $image): ?array
    {
        $body = json_decode($responseBody, true);
        $stop = (string) ($body['stop_reason'] ?? '');
        if (!is_array($body) || in_array($stop, ['max_tokens', 'refusal'], true)) {
            $this->logger->warning('Claude returned no usable answer', ['source' => $image['source'], 'stop_reason' => $stop]);
            $this->diag($image, 'error', 'Analysis service gave no usable answer (' . ($stop ?: 'invalid response') . ')');
            return null;
        }
        $text = '';
        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }
        $input = json_decode($text, true);
        if (!is_array($input) || !is_array($input['passports'] ?? null)) {
            $this->diag($image, 'error', 'Analysis service answer had no passport report');
            return null;
        }

        $passports = array_values(array_filter($input['passports'], 'is_array'));
        if ($passports === []) {
            $this->diag($image, 'no_passport', 'No passport / MRZ detected in this file');
            $this->extractionLogger->info(ExtractionLog::format('claude', $image['source'], $image['page'], null, null));
            return [];
        }

        $found = [];
        foreach ($passports as $idx => $data) {
            $data['passport_detected'] = true;
            $data = MrzVerifier::apply($data, $this->pivotYear);
            if ($data['review'] !== []) {
                $this->logger->warning('Passport read needs manual review', ['source' => $image['source'], 'reasons' => $data['review']]);
            }
            $data['_source'] = $image['source'];
            $data['_page']   = $image['page'];
            $data['_index']  = $idx + 1;
            $this->extractionLogger->info(
                ExtractionLog::format('claude (' . $this->model . ')', $image['source'], $image['page'], null, $data)
            );
            $found[] = $data;
        }
        return $found;
    }
}
