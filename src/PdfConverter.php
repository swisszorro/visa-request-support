<?php

declare(strict_types=1);

namespace BWC\Visa;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Converts a .docx to .pdf. Two backends:
 *   - 'soffice'  : LibreOffice headless (needs the binary; own server / Docker)
 *   - 'gotenberg': HTTP call to a Gotenberg service (no local binary; works on
 *                  shared hosting that only has PHP + cURL). Set GOTENBERG_URL.
 *
 * On shared hosting without either, set OUTPUT_FORMAT=docx to skip conversion.
 */
final class PdfConverter
{
    public function __construct(
        private readonly string $backend = 'soffice',
        private readonly string $sofficeBin = 'soffice',
        private readonly string $gotenbergUrl = '',
        private readonly string $gotenbergUser = '',
        private readonly string $gotenbergPass = '',
    ) {
    }

    public function convert(string $docxPath, string $outDir): string
    {
        if (!is_file($docxPath)) {
            throw new \RuntimeException('DOCX to convert not found.');
        }

        return match ($this->backend) {
            'gotenberg' => $this->viaGotenberg($docxPath, $outDir),
            default     => $this->viaSoffice($docxPath, $outDir),
        };
    }

    private function viaSoffice(string $docxPath, string $outDir): string
    {
        // Each conversion gets an isolated LibreOffice user profile so concurrent
        // requests do not clash on a shared profile lock.
        $profile = $outDir . '/lo_profile_' . bin2hex(random_bytes(4));

        $cmd = sprintf(
            '%s --headless --norestore --nolockcheck -env:UserInstallation=%s --convert-to pdf --outdir %s %s',
            escapeshellcmd($this->sofficeBin),
            escapeshellarg('file://' . $profile),
            escapeshellarg($outDir),
            escapeshellarg($docxPath)
        );

        $r = ProcRunner::run($cmd, 120);
        $code = $r['code'];
        $out = [$r['timed_out'] ? 'timed out after 120 s' : $r['out']];

        $pdfPath = $outDir . '/' . pathinfo($docxPath, PATHINFO_FILENAME) . '.pdf';
        if ($code !== 0 || !is_file($pdfPath)) {
            throw new \RuntimeException('LibreOffice PDF conversion failed: ' . implode("\n", $out));
        }
        return $pdfPath;
    }

    private function viaGotenberg(string $docxPath, string $outDir): string
    {
        if ($this->gotenbergUrl === '') {
            throw new \RuntimeException('GOTENBERG_URL not configured.');
        }
        $endpoint = rtrim($this->gotenbergUrl, '/') . '/forms/libreoffice/convert';

        $opts = [
            'http_errors' => false, // inspect the body ourselves for clear errors
            'multipart' => [[
                'name'     => 'files',
                'contents' => fopen($docxPath, 'r'),
                // explicit .docx filename + MIME so Gotenberg recognises the format
                'filename' => 'invitation.docx',
                'headers'  => ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            ]],
        ];
        if ($this->gotenbergUser !== '') {
            $opts['auth'] = [$this->gotenbergUser, $this->gotenbergPass];
        }

        try {
            $resp = (new Client(['timeout' => 120, 'connect_timeout' => 10]))->post($endpoint, $opts);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Gotenberg not reachable at ' . $endpoint . ': ' . $e->getMessage(), 0, $e);
        }

        $status = $resp->getStatusCode();
        $body = (string) $resp->getBody();
        $ctype = $resp->getHeaderLine('Content-Type');

        if ($status !== 200) {
            throw new \RuntimeException(sprintf(
                'Gotenberg returned HTTP %d (%s): %s',
                $status,
                $ctype ?: 'no content-type',
                substr(trim($body), 0, 300)
            ));
        }
        // A valid PDF starts with "%PDF". Anything else (HTML login page, JSON
        // error, empty body) means the URL/auth/route is wrong — fail loudly.
        if (!str_starts_with($body, '%PDF')) {
            throw new \RuntimeException(sprintf(
                'Gotenberg did not return a PDF (Content-Type: %s). First bytes: %s',
                $ctype ?: 'unknown',
                substr(trim($body), 0, 200)
            ));
        }

        $pdfPath = $outDir . '/' . pathinfo($docxPath, PATHINFO_FILENAME) . '.pdf';
        file_put_contents($pdfPath, $body);
        return $pdfPath;
    }
}
