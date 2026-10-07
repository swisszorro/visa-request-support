<?php

declare(strict_types=1);

namespace BWC\Visa;

/**
 * A passport reader takes one prepared image and returns the structured data
 * read from it (MRZ + visual place of birth / issue date), or null when no
 * passport could be detected.
 *
 * Implementations: PassportAnalyzer (Claude Vision) and OcrPassportReader
 * (local Tesseract + MRZ parser). Both return the same shape:
 *
 *   [
 *     'passport_detected' => true,
 *     'mrz' => [surname, given_names, document_number, nationality,
 *               date_of_birth (Y-m-d), sex, expiry_date (Y-m-d)],
 *     'visual' => [place_of_birth, issue_date],
 *     'raw_mrz_lines' => [...],
 *     '_source' => filename, '_page' => int,
 *   ]
 */
interface PassportReaderInterface
{
    /**
     * @param array{media_type:string,data:string,source:string,page:int} $image
     * @return array<string,mixed>|null
     */
    public function analyze(array $image): ?array;

    /**
     * Analyse several prepared images and return the detected passports
     * (nulls filtered out). Implementations may parallelise.
     *
     * @param array<int,array{media_type:string,data:string,source:string,page:int}> $images
     * @return array<int,array<string,mixed>>
     */
    public function analyzeMany(array $images): array;

    /**
     * Per-file problems of the last analyzeMany() run: files that could not be
     * analysed (status "error") or contained no passport ("no_passport").
     *
     * @return array<int,array{source:string,page:int,status:string,message:string}>
     */
    public function diagnostics(): array;
}
