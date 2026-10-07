<?php

declare(strict_types=1);

namespace BWC\Visa;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Sends a single passport image to the Claude vision API and returns the
 * structured fields read from the Machine Readable Zone (MRZ) plus the visual
 * "place of birth" / issue date.
 *
 * A forced tool call guarantees well-formed JSON output.
 */
final class PassportAnalyzer implements PassportReaderInterface
{
    private Client $http;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $apiVersion,
        private readonly int $maxTokens,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $extractionLogger,
    ) {
        $this->http = new Client([
            'base_uri' => 'https://api.anthropic.com',
            'timeout'  => 120,
        ]);
    }

    private const TOOL = [
        'name' => 'report_passport',
        'description' => 'Report the data read from a passport photo page. Read the MRZ (the two/three monospaced lines at the bottom) character by character; do not guess. Use null for anything not clearly visible.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'passport_detected' => ['type' => 'boolean', 'description' => 'True only if a passport data page with an MRZ is visible.'],
                'mrz' => [
                    'type' => 'object',
                    'properties' => [
                        'surname'         => ['type' => ['string', 'null'], 'description' => 'Surname from MRZ (before the << separator).'],
                        'given_names'     => ['type' => ['string', 'null'], 'description' => 'Given names from MRZ.'],
                        'document_number' => ['type' => ['string', 'null'], 'description' => 'Passport number from MRZ.'],
                        'nationality'     => ['type' => ['string', 'null'], 'description' => 'ISO 3166-1 alpha-3 nationality code from MRZ, e.g. CHE, ITA, FRA, CHN, KAZ.'],
                        'date_of_birth'   => ['type' => ['string', 'null'], 'description' => 'Date of birth as YYYY-MM-DD (interpret the YYMMDD MRZ field).'],
                        'sex'             => ['type' => ['string', 'null'], 'description' => 'M, F or X from MRZ.'],
                        'expiry_date'     => ['type' => ['string', 'null'], 'description' => 'Expiry date as YYYY-MM-DD (from the YYMMDD MRZ field).'],
                    ],
                    'required' => ['surname', 'given_names', 'document_number', 'nationality', 'date_of_birth', 'sex', 'expiry_date'],
                ],
                'visual' => [
                    'type' => 'object',
                    'properties' => [
                        'place_of_birth' => ['type' => ['string', 'null'], 'description' => 'Place of birth as printed in the visual zone (not in the MRZ).'],
                        'issue_date'     => ['type' => ['string', 'null'], 'description' => 'Date of issue as YYYY-MM-DD if printed.'],
                    ],
                    'required' => ['place_of_birth', 'issue_date'],
                ],
                'raw_mrz_lines' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'The raw MRZ lines exactly as read.',
                ],
            ],
            'required' => ['passport_detected', 'mrz', 'visual', 'raw_mrz_lines'],
        ],
    ];

    /**
     * @param array<int,array{media_type:string,data:string,source:string,page:int}> $images
     * @return array<int,array<string,mixed>>
     */
    public function analyzeMany(array $images): array
    {
        $this->diagnostics = [];
        $out = [];
        foreach ($images as $img) {
            try {
                $r = $this->analyze($img);
            } catch (\Throwable) {
                $this->diagnostics[] = ['source' => $img['source'], 'page' => $img['page'], 'status' => 'error',
                    'message' => 'Analysis service call failed'];
                continue;
            }
            if ($r !== null) {
                $out[] = $r;
            } else {
                $this->diagnostics[] = ['source' => $img['source'], 'page' => $img['page'], 'status' => 'no_passport',
                    'message' => 'No passport / MRZ detected in this file'];
            }
        }
        return $out;
    }

    /** @var array<int,array{source:string,page:int,status:string,message:string}> */
    private array $diagnostics = [];

    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * @param array{media_type:string,data:string,source:string,page:int} $image
     * @return array<string,mixed>|null  null when no passport is detected.
     */
    public function analyze(array $image): ?array
    {
        $payload = [
            'model'       => $this->model,
            'max_tokens'  => $this->maxTokens,
            'tools'       => [self::TOOL],
            'tool_choice' => ['type' => 'tool', 'name' => 'report_passport'],
            'messages'    => [[
                'role' => 'user',
                'content' => [
                    [
                        'type'   => 'image',
                        'source' => [
                            'type'       => 'base64',
                            'media_type' => $image['media_type'],
                            'data'       => $image['data'],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => 'This image is a scan/photo of a passport page. Extract the passport data and call report_passport. '
                            . 'Read the MRZ characters precisely (O vs 0, < as filler). If there is no passport MRZ visible, set passport_detected=false.',
                    ],
                ],
            ]],
        ];

        try {
            $resp = $this->http->post('/v1/messages', [
                'headers' => [
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => $this->apiVersion,
                    'content-type'      => 'application/json',
                ],
                'body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (GuzzleException $e) {
            $this->logger->error('Vision API call failed', ['source' => $image['source'], 'error' => $e->getMessage()]);
            throw new \RuntimeException('Vision API error for ' . $image['source'] . ': ' . $e->getMessage(), 0, $e);
        }

        $body = json_decode((string) $resp->getBody(), true);
        $toolInput = null;
        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'report_passport') {
                $toolInput = $block['input'] ?? null;
                break;
            }
        }

        if (!is_array($toolInput) || empty($toolInput['passport_detected'])) {
            $this->extractionLogger->info(
                ExtractionLog::format('vision', $image['source'], $image['page'], null, null)
            );
            return null;
        }

        $toolInput = MrzVerifier::apply($toolInput, (int) date('y'));
        $toolInput['_source'] = $image['source'];
        $toolInput['_page']   = $image['page'];

        $this->extractionLogger->info(
            ExtractionLog::format('vision', $image['source'], $image['page'], null, $toolInput)
        );

        return $toolInput;
    }
}
