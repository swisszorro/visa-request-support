<?php

declare(strict_types=1);

namespace BWC\Visa;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates the full visa-request pipeline:
 *   text fields + Excel + passport images  ->  verified Word letter as PDF
 *   + success/failure report.
 */
final class VisaController
{
    private const TEXT_FIELDS = ['name', 'firstname', 'address', 'zip', 'city', 'country', 'federation'];
    private const ALLOWED_IMG = ['png', 'jpg', 'jpeg', 'gif', 'pdf'];

    public function __construct(
        private readonly string $storageDir,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $extractionLogger,
    ) {
    }

    public function handle(Request $request, Response $response): Response
    {
        $jobId = bin2hex(random_bytes(6));
        $work = $this->storageDir . '/tmp/job_' . $jobId;
        @mkdir($work, 0775, true);

        try {
            $result = $this->process($request, $work, $jobId);
        } catch (ClientError $e) {
            $this->logger->warning('Request rejected', ['job' => $jobId, 'error' => $e->getMessage()]);
            return $this->json($response, 400, ['ok' => false, 'error' => $e->getMessage(), 'request_id' => $jobId]);
        } catch (UpstreamError $e) {
            $this->logger->error('Upstream failure', ['job' => $jobId, 'error' => $e->getMessage()]);
            return $this->json($response, 502, ['ok' => false, 'error' => $e->getMessage(), 'request_id' => $jobId]);
        } catch (\Throwable $e) {
            // details only in the log, never to the caller (paths, URLs, tool output)
            $this->logger->error('Processing failed', [
                'job' => $jobId, 'error' => $e->getMessage(), 'where' => basename($e->getFile()) . ':' . $e->getLine(),
            ]);
            return $this->json($response, 500, ['ok' => false, 'error' => 'Internal error while processing the request.', 'request_id' => $jobId]);
        } finally {
            // best-effort cleanup of the working directory
            $this->rrmdir($work);
        }

        $filename = 'BWC_Visa_Invitation.' . $result['doc_ext'];
        $format = strtolower((string) ($request->getQueryParams()['format'] ?? 'json'));

        // raw document download (pdf or docx, whatever was produced)
        if (in_array($format, ['pdf', 'docx', 'file', 'raw'], true)) {
            $response->getBody()->write($result['doc_binary']);
            return $response
                ->withHeader('Content-Type', $result['doc_mime'])
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->withStatus(200);
        }

        $sum = $result['summary'];

        // Include the base64 document only when ?document != none AND at least
        // one applicant was verified (no successes → the letter has no content).
        $includeDoc = strtolower((string) ($request->getQueryParams()['document'] ?? 'base64')) !== 'none'
            && ($sum['successful'] ?? 0) > 0;

        $payload = [
            'ok'         => true,
            'summary'    => $sum,
            'successful' => $result['successful'],
            'failed'     => $result['failed'],
            'details'    => $result['details'],
            'document_filename' => $filename,
            'document_format'   => $result['doc_ext'],
            'document_mime'     => $result['doc_mime'],
            'email'             => $result['email'],
            'request_id'        => $jobId,
            'incomplete'        => $result['incomplete'],
            'file_diagnostics'  => $result['file_diagnostics'],
            // flat top-level keys for easy Gravity Flow "Response Body" mapping
            'applicants_total'   => $sum['applicants_total'],
            'passports_detected' => $sum['passports_detected'],
            'successful_count'   => $sum['successful'],
            'failed_count'       => $sum['failed'],
            'email_sent'         => (bool) ($result['email']['sent'] ?? false),
            'email_error'        => (string) ($result['email']['error'] ?? ''),
            'email_to'           => implode(', ', (array) ($result['email']['to'] ?? [])),
            'email_subject'      => (string) ($result['email']['subject'] ?? ''),
            'email_body'         => (string) ($result['email']['body'] ?? ''),
            'email_attachment'   => (string) ($result['email']['attachment']['filename'] ?? ''),
        ];
        if ($includeDoc) {
            $payload['document_base64'] = base64_encode($result['doc_binary']);
        }
        return $this->json($response, 200, $payload);
    }

    /**
     * @return array<string,mixed>
     */
    private function process(Request $request, string $work, string $jobId): array
    {
        // ---- 1. text fields -------------------------------------------------
        $params = (array) $request->getParsedBody();
        // Fallback: some callers (e.g. Gravity Flow) send a JSON body with a
        // content-type Slim doesn't auto-parse → decode the raw body manually.
        if ($params === []) {
            $raw = (string) $request->getBody();
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $params = $decoded;
                }
            }
        }
        $federation = [];
        foreach (self::TEXT_FIELDS as $f) {
            $federation[$f] = trim((string) ($params[$f] ?? ''));
        }
        $missing = array_keys(array_filter($federation, static fn ($v) => $v === ''));
        if ($missing !== []) {
            throw new ClientError('Missing required text field(s): ' . implode(', ', $missing));
        }
        $federation['email'] = trim((string) ($params['email'] ?? '')); // optional

        // ---- 2. inputs (multipart uploads OR URLs, e.g. from Gravity Flow) --
        $files = $request->getUploadedFiles();
        $maxImages = Config::int('MAX_IMAGES', 24);

        // Excel: multipart field "excel" OR "excel_url"
        $excelPath = $work . '/applicants.xlsx';
        $excel = $files['excel'] ?? null;
        $excelUrls = $this->collectUrls($params['excel_url'] ?? null); // GF may send ["https://…"]
        if ($excel instanceof UploadedFileInterface && $excel->getError() === UPLOAD_ERR_OK) {
            $excel->moveTo($excelPath);
        } elseif ($excelUrls !== []) {
            $this->fetchTo($excelUrls[0], $excelPath);
        } else {
            throw new ClientError('Missing Excel: provide multipart field "excel" or "excel_url".');
        }

        // Images: multipart "images[]" AND/OR "images_urls" (array or comma/newline list)
        $imageFiles = []; // list of ['path'=>, 'name'=>]
        $imgUploads = $files['images'] ?? [];
        if ($imgUploads instanceof UploadedFileInterface) {
            $imgUploads = [$imgUploads];
        }
        $fileDiag = []; // files that could not be used (reported, but do not abort the batch)
        foreach ($imgUploads as $up) {
            if (!$up instanceof UploadedFileInterface) {
                continue;
            }
            $name = $up->getClientFilename() ?? 'upload';
            if ($up->getError() !== UPLOAD_ERR_OK) {
                $fileDiag[] = ['source' => $name, 'page' => 1, 'status' => 'error', 'message' => 'Upload failed (error code ' . $up->getError() . ')'];
                continue;
            }
            try {
                $this->assertImageExt($name);
            } catch (ClientError $e) {
                $fileDiag[] = ['source' => $name, 'page' => 1, 'status' => 'error', 'message' => $e->getMessage()];
                continue;
            }
            $dst = $work . '/' . bin2hex(random_bytes(4)) . '.' . strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $up->moveTo($dst);
            $imageFiles[] = ['path' => $dst, 'name' => $name];
        }
        foreach ($this->collectUrls($params['images_urls'] ?? null) as $url) {
            try {
                $imageFiles[] = $this->fetchImage($url, $work);
            } catch (ClientError $e) {
                $fileDiag[] = ['source' => $url, 'page' => 1, 'status' => 'error', 'message' => $e->getMessage()];
            }
        }

        if ($imageFiles === [] && $fileDiag === []) {
            throw new ClientError('No passport images: provide multipart "images[]" or "images_urls".');
        }
        if (count($imageFiles) > $maxImages) {
            throw new ClientError('Too many images: ' . count($imageFiles) . " (max {$maxImages}).");
        }

        // ---- 3. read Excel applicants --------------------------------------
        $excelReader = new ExcelReader();
        $applicants = $excelReader->read($excelPath);
        $skippedRows = $excelReader->skippedRows();
        if ($applicants === [] && $skippedRows === []) {
            throw new ClientError('No applicants found in the "Applicants" table.');
        }

        // ---- 4. prepare images & analyse passports --------------------------
        // gemini/vision can read PDFs natively (no pdftoppm); the local 'ocr'
        // engine needs rasterised pages, so it always uses poppler.
        $engines = $this->engineChain();
        $pdfMode = strtolower(Config::str('PDF_MODE', 'auto'));
        $pdftoppm = Config::str('PDFTOPPM_BIN', 'pdftoppm');
        if (in_array('ocr', $engines, true)) {
            $pdfMode = 'poppler'; // the local OCR needs rasterised pages
        } elseif ($pdfMode === 'auto') {
            // poppler renders every page separately (many passports per PDF, page cap);
            // native sends the whole PDF in one call (works without binaries, shared hosting)
            $pdfMode = ImagePreparer::popplerAvailable($pdftoppm) ? 'poppler' : 'native';
        }
        $preparer = new ImagePreparer(
            $work,
            Config::int('VISION_IMAGE_MAXEDGE', 1600),
            Config::int('MAX_PDF_PAGES', 30),
            $pdftoppm,
            $pdfMode,
        );
        $analyzer = $this->makeReader($work);

        // Prepare every image first, then analyse them together (the reader may
        // run the calls concurrently → stays under the shared-hosting timeout).
        $visionImages = [];
        foreach ($imageFiles as $f) {
            try {
                foreach ($preparer->prepare($f['path'], $f['name']) as $visionImg) {
                    $visionImages[] = $visionImg;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Could not prepare file', ['job' => $jobId, 'file' => $f['name'], 'error' => $e->getMessage()]);
                $fileDiag[] = ['source' => $f['name'], 'page' => 1, 'status' => 'error', 'message' => 'File could not be read as image or PDF'];
            }
        }

        $detected = $analyzer->analyzeMany($visionImages);
        $fileDiag = array_merge($fileDiag, $preparer->warnings(), $analyzer->diagnostics());
        $this->logFiles($jobId, $imageFiles, $visionImages, $detected, $fileDiag);

        // Every file failed technically (analysis service down, all uploads unusable):
        // do not pretend "no passports found" - signal the outage.
        $errCount = count(array_filter($fileDiag, static fn ($d) => $d['status'] === 'error'));
        $noPassportCount = count(array_filter($fileDiag, static fn ($d) => $d['status'] === 'no_passport'));
        if ($detected === [] && $errCount > 0 && $noPassportCount === 0) {
            throw new UpstreamError('No passport file could be analysed (' . $errCount . ' technical error(s)); please retry later. See file_diagnostics / request_id.');
        }

        // ---- 5. match & verify ---------------------------------------------
        $matcher = new Matcher(
            Config::bool('PLACE_OF_BIRTH_WARN_ONLY', true),
            Config::int('PASSPORT_MIN_VALIDITY_MONTHS', 6),
            null,
            Config::str('EVENT_START', '') ?: null,
            Config::str('EVENT_END', '') ?: null,
        );
        $results = $matcher->match($applicants, $detected);
        if ($errCount > 0) {
            foreach ($results as &$res) {
                if (!($res['matched'] ?? true)) {
                    $res['reason'] .= " ({$errCount} file(s) could not be analysed - the passport may be among them; retry or check file_diagnostics)";
                }
            }
            unset($res);
        }

        $successful = [];
        $failed = [];
        foreach ($results as $r) {
            $entry = ['name' => $r['full_name'], 'date_of_birth' => $r['date_of_birth']];
            if ($r['status'] === 'success') {
                $successful[] = $entry;
            } else {
                $failed[] = $entry + [
                    'status'     => $r['status'],
                    'reason'     => $r['reason'],
                    'mismatches' => $r['mismatches'] ?? [],
                ];
            }
        }

        // ---- 6. fill Word letter & convert to PDF --------------------------
        foreach ($skippedRows as $sr) {
            $failed[] = ['name' => 'Excel row ' . $sr['row'], 'date_of_birth' => '', 'status' => 'failed', 'reason' => $sr['reason'], 'mismatches' => []];
        }
        $verifiedSuccess = array_values(array_filter($results, static fn ($r) => $r['status'] === 'success'));
        $template = dirname(__DIR__) . '/resources/BWC Visa Invitation Letter.docx';
        if (!is_file($template)) {
            throw new \RuntimeException('Word template not found at resources/BWC Visa Invitation Letter.docx');
        }
        $outDocx = $work . '/invitation.docx';
        (new WordFiller())->fill(
            $template,
            $outDocx,
            $verifiedSuccess,
            $federation,
            trim((string) ($params['watermark'] ?? Config::str('WATERMARK_TEXT', ''))),
            Config::str('WATERMARK_COLOR', 'C0C0C0'),
        );

        // Output format: 'docx' needs no binaries (shared hosting); 'pdf' uses
        // the configured converter (soffice locally, or a Gotenberg HTTP service).
        $outputFormat = strtolower(Config::str('OUTPUT_FORMAT', 'pdf'));
        if ($outputFormat === 'docx') {
            $docBinary = (string) file_get_contents($outDocx);
            $docMime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
            $docExt = 'docx';
        } else {
            try {
            $pdfPath = (new PdfConverter(
                Config::str('PDF_CONVERTER', 'soffice'),
                Config::str('SOFFICE_BIN', 'soffice'),
                Config::str('GOTENBERG_URL', ''),
                Config::str('GOTENBERG_USER', ''),
                Config::str('GOTENBERG_PASS', ''),
            ))->convert($outDocx, $work);
            } catch (\RuntimeException $e) {
                $this->logger->error('PDF conversion failed', ['job' => $jobId, 'error' => $e->getMessage()]);
                throw new UpstreamError('PDF conversion failed.', 0, $e);
            }
            $docBinary = (string) file_get_contents($pdfPath);
            $docMime = 'application/pdf';
            $docExt = 'pdf';
        }

        $summary = [
            'applicants_total'   => count($applicants) + count($skippedRows),
            'passports_detected' => count($detected),
            'successful'         => count($successful),
            'failed'             => count($failed),
            'needs_review'       => count(array_filter($failed, static fn ($f) => ($f['status'] ?? '') === 'review')),
        ];

        // ---- 7. optional: email the letter as attachment -------------------
        $email = $this->maybeSendEmail(
            $params,
            $federation,
            'BWC_Visa_Invitation.' . $docExt,
            $docBinary,
            $docMime,
            $summary,
            $successful,
            $failed,
        );

        return [
            'summary'    => $summary,
            'successful' => $successful,
            'failed'     => $failed,
            'details'    => $results,
            'doc_binary' => $docBinary,
            'doc_mime'   => $docMime,
            'doc_ext'    => $docExt,
            'email'      => $email,
            'file_diagnostics' => $fileDiag,
            'incomplete' => $errCount > 0,
        ];
    }

    /**
     * Logs EVERY file of the request with its outcome (no-ops when logging is
     * disabled: both loggers then have a NullHandler): analysed pages, passports
     * found (and how many need review), errors, "no passport", truncation warnings
     * and files that were rejected before analysis.
     *
     * @param array<int,array{path:string,name:string}> $imageFiles
     * @param array<int,array<string,mixed>>            $visionImages
     * @param array<int,array<string,mixed>>            $detected
     * @param array<int,array<string,string|int>>       $fileDiag
     */
    private function logFiles(string $jobId, array $imageFiles, array $visionImages, array $detected, array $fileDiag): void
    {
        $files = [];
        foreach ($imageFiles as $f) {
            $files[$f['name']] = ['bytes' => is_file($f['path']) ? (int) filesize($f['path']) : 0];
        }
        foreach ($fileDiag as $d) {
            $files[(string) $d['source']] ??= ['bytes' => 0];
        }

        $rows = [];
        foreach ($files as $name => $meta) {
            $pages = count(array_filter($visionImages, static fn ($v) => $v['source'] === $name));
            $mine = array_filter($detected, static fn ($p) => ($p['_source'] ?? null) === $name);
            $review = count(array_filter($mine, static fn ($p) => !empty($p['review'])));
            $notes = [];
            foreach ($fileDiag as $d) {
                if ((string) $d['source'] === $name) {
                    $notes[] = $d['status'] . ': ' . $d['message'];
                }
            }
            $result = $mine !== []
                ? sprintf('%d passport(s) read, %d need review', count($mine), $review)
                : ($notes === [] ? 'no result' : 'no passport read');
            $rows[] = ['file' => $name, 'bytes' => $meta['bytes'], 'pages_analysed' => $pages, 'result' => $result, 'notes' => $notes];
        }

        $this->logger->info('Files analysed', ['job' => $jobId, 'count' => count($rows), 'files' => $rows]);

        $text = str_repeat('═', 70) . "\nREQUEST " . $jobId . '  ·  ' . count($rows) . " file(s) received\n";
        foreach ($rows as $r) {
            $text .= sprintf("  - %s (%d bytes, %d page(s) analysed): %s\n", $r['file'], $r['bytes'], $r['pages_analysed'], $r['result']);
            foreach ($r['notes'] as $n) {
                $text .= '      ' . $n . "\n";
            }
        }
        $this->extractionLogger->info($text);
    }

    /**
     * Sends the document by email when enabled (MAIL_ENABLED=true or request
     * param send_email truthy). Recipients: request "mail_to" (comma list),
     * else the federation email, else MAIL_DEFAULT_TO. Never aborts the request
     * on mail failure — the outcome is reported in the response.
     *
     * @return array<string,mixed>|null
     */
    private function maybeSendEmail(array $params, array $federation, string $filename, string $binary, string $mime, array $summary, array $successful, array $failed): ?array
    {
        $flag = $params['send_email'] ?? null;
        $requested = Config::bool('MAIL_ENABLED', false)
            || in_array(strtolower((string) $flag), ['1', 'true', 'yes', 'on'], true);
        if (!$requested) {
            return null;
        }

        $subject = Config::str('MAIL_SUBJECT', 'Berne World Cup – Visa Invitation Letter');
        $isHtml = Config::bool('MAIL_HTML', false);
        $body = $this->buildMailBody($isHtml, $federation, $filename, $summary, $failed);
        $from = Config::str('MAIL_FROM');

        // Only attach the letter if at least one applicant was verified — with
        // zero successes the PDF has no content worth sending.
        $attachment = ($summary['successful'] ?? 0) > 0
            ? ['content' => $binary, 'filename' => $filename, 'mime' => $mime]
            : null;

        // The caller always gets back exactly what the recipient got (or would have got).
        $info = [
            'from'       => $from,
            'subject'    => $subject,
            'body'       => $body,
            'html'       => $isHtml,
            'attached'   => $attachment !== null,
            'attachment' => $attachment === null ? null : [
                'filename' => $filename, 'mime' => $mime, 'bytes' => strlen($binary),
            ],
        ];

        // recipients
        $to = $this->collectEmails($params['mail_to'] ?? '');
        if ($to === [] && !empty($federation['email'])) {
            $to = $this->collectEmails($federation['email']);
        }
        if ($to === []) {
            $to = $this->collectEmails(Config::str('MAIL_DEFAULT_TO', ''));
        }
        if ($to === []) {
            return ['sent' => false, 'to' => [], 'error' => 'No recipient (mail_to / federation email / MAIL_DEFAULT_TO all empty).'] + $info;
        }

        try {
            (new Mailer(
                Config::str('SMTP_HOST'),
                Config::int('SMTP_PORT', 587),
                Config::str('SMTP_USER'),
                Config::str('SMTP_PASS'),
                strtolower(Config::str('SMTP_SECURE', 'tls')),
                $from,
                Config::str('MAIL_FROM_NAME', 'Berne World Cup OC'),
                $this->logger,
            ))->send($to, $subject, $body, $attachment, $isHtml);
            return ['sent' => true, 'to' => $to] + $info;
        } catch (\Throwable $e) {
            return ['sent' => false, 'to' => $to, 'error' => $e->getMessage()] + $info;
        }
    }

    /**
     * Build the mail body from the MAIL_BODY template (.env). Supports multi-line
     * (real newlines or "\n" escapes) and HTML tags (MAIL_HTML=true). Placeholders:
     *   {federation} {name} {firstname} {filename}
     *   {applicants_total} {passports_detected} {successful} {failed} {failed_list}
     * Falls back to a built-in text/HTML body when MAIL_BODY is empty.
     */
    private function buildMailBody(bool $isHtml, array $federation, string $filename, array $summary, array $failed): string
    {
        $nl = $isHtml ? '<br>' : "\n";

        $failedList = '';
        if ($failed !== []) {
            $items = [];
            foreach ($failed as $f) {
                $items[] = sprintf('- %s (%s): %s', $f['name'] ?? '', $f['date_of_birth'] ?? '', $f['reason'] ?? '');
            }
            $failedList = implode($nl, $items);
        }

        $repl = [
            '{federation}'         => (string) ($federation['federation'] ?? ''),
            '{verband}'            => (string) ($federation['federation'] ?? ''), // alias used in existing .env files
            '{name}'               => (string) ($federation['name'] ?? ''),
            '{firstname}'          => (string) ($federation['firstname'] ?? ''),
            '{filename}'           => $filename,
            '{applicants_total}'   => (string) $summary['applicants_total'],
            '{passports_detected}' => (string) $summary['passports_detected'],
            '{successful}'         => (string) $summary['successful'],
            '{failed}'             => (string) $summary['failed'],
            '{failed_list}'        => $failedList,
        ];

        $tpl = Config::str('MAIL_BODY', '');
        if ($tpl !== '') {
            // allow literal "\n" / "\r\n" escapes in the .env value as line breaks
            $tpl = str_replace(['\\r\\n', '\\n'], "\n", $tpl);
            return strtr($tpl, $repl);
        }

        // default body
        $lines = [
            'Attached: the visa invitation letter.',
            '',
            sprintf('Applicants: %d · passports detected: %d · verified: %d · failed: %d',
                $summary['applicants_total'], $summary['passports_detected'], $summary['successful'], $summary['failed']),
        ];
        if ($failedList !== '') {
            $lines[] = '';
            $lines[] = 'Not included (verification failed):';
            $lines[] = $failedList;
        }
        return implode($nl, $lines);
    }

    /** @return string[] */
    private function collectEmails(mixed $val): array
    {
        $parts = is_array($val) ? $val : preg_split('/[;,\s]+/', (string) $val);
        $out = [];
        foreach ($parts ?: [] as $e) {
            $e = trim((string) $e);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $out[] = $e;
            }
        }
        return array_values(array_unique($out));
    }

    /** @return string[] engines from PASSPORT_ENGINE, e.g. "vision,ocr" => ['vision','ocr'] (first = primary) */
    private function engineChain(): array
    {
        $list = array_values(array_filter(array_map(
            static fn ($e) => strtolower(trim($e)),
            explode(',', Config::str('PASSPORT_ENGINE', 'gemini'))
        )));
        return $list === [] ? ['gemini'] : $list;
    }

    /** Builds the reader (or reader chain) selected via PASSPORT_ENGINE: gemini | vision (alias claude) | ocr. */
    private function makeReader(string $work): PassportReaderInterface
    {
        $readers = array_map(fn (string $e) => $this->makeSingleReader($e, $work), $this->engineChain());
        return count($readers) === 1 ? $readers[0] : new FallbackPassportReader($readers, $this->logger);
    }

    private function makeSingleReader(string $engine, string $work): PassportReaderInterface
    {
        $pivot = Config::int('MRZ_DOB_PIVOT_YEAR', (int) date('y'));
        if ($engine === 'gemini') {
            return new GeminiPassportReader(
                Config::str('GEMINI_API_KEY'),
                Config::str('GEMINI_MODEL', 'gemini-2.5-flash'),
                Config::str('GEMINI_API_VERSION', 'v1beta'),
                $this->logger,
                $this->extractionLogger,
                Config::int('GEMINI_CONCURRENCY', 4),
                $pivot,
            );
        }
        if ($engine === 'vision' || $engine === 'claude') {
            return new PassportAnalyzer(
                Config::str('ANTHROPIC_API_KEY'),
                Config::str('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
                Config::str('ANTHROPIC_VERSION', '2023-06-01'),
                Config::int('ANTHROPIC_MAX_TOKENS', 4000),
                $this->logger,
                $this->extractionLogger,
                Config::int('ANTHROPIC_CONCURRENCY', 4),
                $pivot,
            );
        }

        return new OcrPassportReader(
            $work,
            $this->logger,
            $this->extractionLogger,
            Config::str('TESSERACT_BIN', 'tesseract'),
            Config::str('TESSERACT_LANG', 'eng'),
            $pivot,
            Config::int('MRZ_PREP_WIDTH', 1600),
            Config::str('TESSERACT_MRZ_LANG', 'mrz'),
        );
    }

    // ---- URL intake (Gravity Flow etc. send file URLs, not multipart) ------

    /** @return string[] normalised list of http(s) URLs from an array or comma/newline string */
    private function collectUrls(mixed $val): array
    {
        if ($val === null || $val === '') {
            return [];
        }
        if (is_string($val)) {
            $trim = trim($val);
            if ($trim !== '' && ($trim[0] === '[' )) {           // JSON array string
                $decoded = json_decode($trim, true);
                $val = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', $trim);
            } else {
                $val = preg_split('/[\s,]+/', $trim) ?: [];       // comma/newline/space list
            }
        }
        $out = [];
        foreach ((array) $val as $u) {
            $u = trim((string) $u);
            if ($u !== '' && preg_match('#^https?://#i', $u)) {
                $out[] = $u;
            }
        }
        return $out;
    }

    private function assertImageExt(string $name): void
    {
        $ext = strtolower(pathinfo(parse_url($name, PHP_URL_PATH) ?: $name, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_IMG, true)) {
            throw new ClientError("Unsupported image type for {$name} (allowed: png, jpg, gif, pdf).");
        }
    }

    private function guardUrl(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        if ($host === '' || !preg_match('#^https?://#i', $url)) {
            throw new ClientError("Invalid URL: {$url}");
        }
        $allow = Config::list('ALLOWED_FETCH_HOSTS'); // optional SSRF allow-list
        if ($allow !== [] && !in_array(strtolower($host), array_map('strtolower', $allow), true)) {
            throw new ClientError("URL host not allowed: {$host}");
        }
    }

    private function httpClient(): Client
    {
        return new Client(['timeout' => 60, 'connect_timeout' => 10]);
    }

    private function fetchTo(string $url, string $dest): void
    {
        $this->guardUrl($url);
        try {
            $this->httpClient()->get($url, ['sink' => $dest]);
        } catch (\Throwable $e) {
            $this->logger->warning('Download failed', ['url' => $url, 'error' => $e->getMessage()]);
            throw new ClientError('Could not download ' . $url, 0, $e);
        }
        if (!is_file($dest) || filesize($dest) === 0) {
            throw new ClientError('Downloaded empty file from ' . $url);
        }
    }

    /**
     * Download an image/PDF from a URL, infer a valid extension and return
     * ['path'=>, 'name'=>].
     * @return array{path:string,name:string}
     */
    private function fetchImage(string $url, string $work): array
    {
        $this->guardUrl($url);
        $name = basename((string) (parse_url($url, PHP_URL_PATH) ?: 'file'));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        try {
            $resp = $this->httpClient()->get($url);
        } catch (\Throwable $e) {
            $this->logger->warning('Download failed', ['url' => $url, 'error' => $e->getMessage()]);
            throw new ClientError('Could not download image ' . $url, 0, $e);
        }
        $bytes = (string) $resp->getBody();
        if ($bytes === '') {
            throw new ClientError('Downloaded empty image from ' . $url);
        }

        // fall back to Content-Type when the URL has no usable extension
        if (!in_array($ext, self::ALLOWED_IMG, true)) {
            $ct = strtolower($resp->getHeaderLine('Content-Type'));
            $ext = match (true) {
                str_contains($ct, 'png')  => 'png',
                str_contains($ct, 'jpeg'), str_contains($ct, 'jpg') => 'jpg',
                str_contains($ct, 'gif')  => 'gif',
                str_contains($ct, 'pdf')  => 'pdf',
                default => '',
            };
            if ($ext === '') {
                throw new ClientError("Cannot determine image type for {$url} (Content-Type: {$ct}).");
            }
            $name .= '.' . $ext;
        }

        $path = $work . '/' . bin2hex(random_bytes(4)) . '.' . $ext;
        file_put_contents($path, $bytes);
        return ['path' => $path, 'name' => $name];
    }

    private function json(Response $response, int $status, array $data): Response
    {
        // Robust flags: substitute invalid UTF-8 (from OCR/Excel/MRZ) and keep
        // partial output instead of returning false → an empty body would make
        // the client fail with "Unexpected end of JSON input".
        $flags = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
        $json = json_encode($data, $flags);
        if ($json === false && ($data['ok'] ?? false) === true) {
            // shrink step by step, but keep ok:true and the mail information (it was already sent)
            foreach ([['document_base64'], ['document_base64', 'details'], ['document_base64', 'details', 'successful', 'failed']] as $drop) {
                $slim = array_diff_key($data, array_flip($drop)) + ['truncated' => $drop];
                $json = json_encode($slim, $flags);
                if ($json !== false) {
                    break;
                }
            }
        }
        if ($json === false) {
            $status = 500;
            $json = json_encode(
                ['ok' => false, 'error' => 'Response encoding failed: ' . json_last_error_msg()],
                JSON_UNESCAPED_UNICODE
            ) ?: '{"ok":false,"error":"Response encoding failed."}';
        }
        $response->getBody()->write($json);
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
