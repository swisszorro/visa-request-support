<?php

declare(strict_types=1);

namespace BWC\Visa;

/**
 * Fills the invitation-letter table (10 columns) with the verified applicants
 * and injects the requesting national federation contact block above the table.
 *
 * Works directly on word/document.xml via DOMDocument so all original styling,
 * headers, footers and media inside the .docx are preserved.
 */
final class WordFiller
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * @param array<int,array<string,mixed>> $applicants  verified applicants (verify() output, status=success)
     * @param array{name:string,firstname:string,address:string,zip:string,city:string,country:string,federation:string} $federation
     * @param string $watermark optional watermark text ('' = none)
     * @param string $watermarkColor hex without '#'
     */
    public function fill(string $templateDocx, string $outDocx, array $applicants, array $federation, string $watermark = '', string $watermarkColor = 'C0C0C0'): void
    {
        if (!copy($templateDocx, $outDocx)) {
            throw new \RuntimeException('Could not copy the Word template.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($outDocx) !== true) {
            throw new \RuntimeException('Could not open the Word template as a zip archive.');
        }
        $xml = $zip->getFromName('word/document.xml');
        if ($xml === false) {
            $zip->close();
            throw new \RuntimeException('word/document.xml missing from the template.');
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = true;
        $dom->loadXML($xml);
        $dom->xmlStandalone = true;

        $this->replacePlaceholders($dom, $federation);
        $this->fillTable($dom, $applicants);

        $zip->addFromString('word/document.xml', $dom->saveXML());

        if (trim($watermark) !== '') {
            $this->applyWatermark($zip, trim($watermark), $watermarkColor);
        }

        $zip->close();
    }

    /**
     * Inject a Word text watermark (VML) into every header part so LibreOffice
     * (soffice / Gotenberg) and Word render it diagonally on all pages.
     */
    private function applyWatermark(\ZipArchive $zip, string $text, string $color): void
    {
        $color = preg_replace('/[^0-9A-Fa-f]/', '', $color) ?: 'C0C0C0';
        $wmPara = $this->watermarkParagraph($text, $color);

        for ($i = 1; $i <= 6; $i++) {
            $part = "word/header{$i}.xml";
            $hdr = $zip->getFromName($part);
            if ($hdr === false) {
                continue;
            }
            // ensure the VML namespaces exist on the <w:hdr> root
            $hdr = $this->ensureVmlNamespaces($hdr);
            // insert the watermark paragraph right before </w:hdr>
            $hdr = str_replace('</w:hdr>', $wmPara . '</w:hdr>', $hdr);
            $zip->addFromString($part, $hdr);
        }
    }

    private function ensureVmlNamespaces(string $hdr): string
    {
        $ns = [
            'xmlns:v'   => 'urn:schemas-microsoft-com:vml',
            'xmlns:o'   => 'urn:schemas-microsoft-com:office:office',
            'xmlns:w10' => 'urn:schemas-microsoft-com:office:word',
        ];
        $add = '';
        foreach ($ns as $attr => $uri) {
            if (!str_contains($hdr, $attr . '=')) {
                $add .= ' ' . $attr . '="' . $uri . '"';
            }
        }
        if ($add === '') {
            return $hdr;
        }
        // append the missing namespace declarations to the opening <w:hdr ...> tag
        return preg_replace('/<w:hdr\b/', '<w:hdr' . $add, $hdr, 1) ?? $hdr;
    }

    private function watermarkParagraph(string $text, string $colorHex): string
    {
        $esc = htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        return '<w:p><w:r><w:rPr><w:noProof/></w:rPr><w:pict>'
            . '<v:shapetype id="_x0000_t136" coordsize="21600,21600" o:spt="136" adj="10800" '
            . 'path="m@7,0l@8,0m@5,21600l@6,21600e">'
            . '<v:formulas><v:f eqn="sum #0 0 10800"/><v:f eqn="prod #0 2 1"/>'
            . '<v:f eqn="sum 21600 0 @1"/><v:f eqn="sum 0 0 @2"/><v:f eqn="sum 21600 0 @3"/>'
            . '<v:f eqn="if @0 @3 0"/><v:f eqn="if @0 21600 @1"/><v:f eqn="if @0 0 @2"/>'
            . '<v:f eqn="if @0 @4 21600"/><v:f eqn="mid @5 @6"/><v:f eqn="mid @8 @5"/>'
            . '<v:f eqn="mid @7 @8"/><v:f eqn="mid @6 @7"/><v:f eqn="sum @6 0 @5"/></v:formulas>'
            . '<v:path textpathok="t" o:connecttype="custom" '
            . 'o:connectlocs="@9,0;@10,10800;@11,21600;@12,10800" o:connectangles="270,180,90,0"/>'
            . '<v:textpath on="t" fitshape="t"/><v:handles><v:h position="#0,bottomRight" '
            . 'xrange="6629,14971"/></v:handles><o:lock v:ext="edit" text="t" shapetype="t"/>'
            . '</v:shapetype>'
            . '<v:shape id="BWCWatermark" type="#_x0000_t136" '
            . 'style="position:absolute;margin-left:0;margin-top:0;width:468pt;height:117pt;'
            . 'rotation:315;z-index:-251654144;mso-position-horizontal:center;'
            . 'mso-position-horizontal-relative:margin;mso-position-vertical:center;'
            . 'mso-position-vertical-relative:margin" fillcolor="#' . $colorHex . '" stroked="f">'
            . '<v:textpath style="font-family:&quot;Arial&quot;;font-size:1pt" string="' . $esc . '"/>'
            . '</v:shape></w:pict></w:r></w:p>';
    }

    private function fillTable(\DOMDocument $dom, array $applicants): void
    {
        $tables = $dom->getElementsByTagNameNS(self::W, 'tbl');
        if ($tables->length === 0) {
            throw new \RuntimeException('No table found in the invitation letter.');
        }
        /** @var \DOMElement $table */
        $table = $tables->item(0);

        // direct child rows only
        $rows = [];
        foreach ($table->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'tr') {
                $rows[] = $child;
            }
        }
        if (count($rows) < 2) {
            throw new \RuntimeException('Invitation table has no data template row.');
        }

        $headerRow = $rows[0];
        $templateRow = $rows[1];

        // build the new filled rows from the template row
        $newRows = [];
        $seq = 0;
        foreach ($applicants as $app) {
            $seq++;
            $v = $app['verified'] ?? [];
            $values = [
                (string) $seq,
                (string) ($v['full_name'] ?? ''),
                (string) ($v['gender'] ?? ''),
                (string) ($v['nationality'] ?? ''),
                $this->fmtDate((string) ($v['date_of_birth'] ?? '')),
                (string) ($v['place_of_birth'] ?? ''),
                (string) ($v['passport_number'] ?? ''),
                $this->fmtDate((string) ($v['passport_issue_date'] ?? '')) . ' / ' . $this->fmtDate((string) ($v['passport_expiry_date'] ?? '')),
                (string) ($v['role'] ?? ''),
                $this->fmtDate((string) ($v['date_of_arrival'] ?? '')) . ' / ' . $this->fmtDate((string) ($v['date_of_departure'] ?? '')),
            ];

            $clone = $templateRow->cloneNode(true);
            $this->setRowValues($dom, $clone, $values);
            $newRows[] = $clone;
        }

        // remove every existing data row (keep the header)
        foreach ($rows as $i => $r) {
            if ($i === 0) {
                continue;
            }
            $table->removeChild($r);
        }

        // append filled rows after the header
        foreach ($newRows as $r) {
            $table->appendChild($r);
        }

        // keep the original empty-template look when no applicant matched:
        // re-add one blank row so the table never collapses to header-only.
        if ($newRows === []) {
            $blank = $templateRow->cloneNode(true);
            $table->appendChild($blank);
        }
        unset($headerRow);
    }

    /** @param string[] $values one per cell (in column order) */
    private function setRowValues(\DOMDocument $dom, \DOMElement $row, array $values): void
    {
        $cells = [];
        foreach ($row->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'tc') {
                $cells[] = $child;
            }
        }
        foreach ($cells as $i => $cell) {
            $value = $values[$i] ?? '';
            $this->setCellText($dom, $cell, $value);
        }
    }

    private function setCellText(\DOMDocument $dom, \DOMElement $cell, string $text): void
    {
        // find first paragraph in the cell, else create one
        $para = null;
        foreach ($cell->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'p') {
                $para = $child;
                break;
            }
        }
        if ($para === null) {
            $para = $dom->createElementNS(self::W, 'w:p');
            $cell->appendChild($para);
        }

        // remove existing runs (keep paragraph properties w:pPr), but preserve
        // the first run's properties (w:rPr) so template run-level formatting
        // (font, size) is kept exactly as in the template.
        $rpr = null;
        $toRemove = [];
        foreach ($para->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'r') {
                if ($rpr === null) {
                    foreach ($child->childNodes as $rc) {
                        if ($rc instanceof \DOMElement && $rc->localName === 'rPr') {
                            $rpr = $rc;
                            break;
                        }
                    }
                }
                $toRemove[] = $child;
            }
        }
        foreach ($toRemove as $r) {
            $para->removeChild($r);
        }

        if ($text === '') {
            return;
        }

        // No explicit run font: the run inherits the cell's paragraph style
        // (e.g. BodyText) so it renders exactly as the template defines.
        $run = $dom->createElementNS(self::W, 'w:r');
        if ($rpr !== null) {
            $run->appendChild($rpr->cloneNode(true));
        }
        $t = $dom->createElementNS(self::W, 'w:t');
        $t->setAttribute('xml:space', 'preserve');
        $t->appendChild($dom->createTextNode($text));
        $run->appendChild($t);
        $para->appendChild($run);
    }

    /**
     * Fill the requesting-organisation placeholders the template provides
     * (no inserting of paragraphs — the block already sits below the title):
     *   <National Federation> <Address> <Country> <Name> <Firstname> <E-Mail>
     * The PLZ/Ort is appended to the address (no own placeholder).
     */
    private function replacePlaceholders(\DOMDocument $dom, array $fed): void
    {
        $addr = trim((string) ($fed['address'] ?? ''));
        $zipCity = trim(trim((string) ($fed['zip'] ?? '')) . ' ' . trim((string) ($fed['city'] ?? '')));
        $address = trim($addr . ($addr !== '' && $zipCity !== '' ? ', ' : '') . $zipCity);

        $map = [
            '<National Federation>' => (string) ($fed['federation'] ?? ''),
            '<Address>'             => $address,
            '<Country>'             => (string) ($fed['country'] ?? ''),
            '<Name>'                => (string) ($fed['name'] ?? ''),
            '<Firstname>'           => (string) ($fed['firstname'] ?? ''),
            '<E-Mail>'              => (string) ($fed['email'] ?? ''),
        ];

        // Placeholders like "<National Federation>" are usually split across
        // several <w:t> runs by Word. Replace per PARAGRAPH across its value
        // runs: join the text from the first run containing '<' to the end,
        // substitute, write the result into that first run and clear the rest.
        // Label runs and alignment tabs (before the first '<') stay untouched.
        foreach ($dom->getElementsByTagNameNS(self::W, 'p') as $p) {
            $tNodes = [];
            foreach ($p->getElementsByTagNameNS(self::W, 't') as $t) {
                $tNodes[] = $t;
            }
            if ($tNodes === []) {
                continue;
            }

            $firstIdx = null;
            foreach ($tNodes as $i => $t) {
                if (str_contains($t->textContent, '<')) {
                    $firstIdx = $i;
                    break;
                }
            }
            if ($firstIdx === null) {
                continue;
            }

            $region = array_slice($tNodes, $firstIdx);
            $joined = '';
            foreach ($region as $t) {
                $joined .= $t->textContent;
            }
            $replaced = strtr($joined, $map);
            if ($replaced === $joined) {
                continue;
            }
            $region[0]->textContent = $replaced;
            for ($k = 1, $n = count($region); $k < $n; $k++) {
                $region[$k]->textContent = '';
            }
        }
    }

    private function fmtDate(string $iso): string
    {
        if ($iso === '') {
            return '';
        }
        $dt = \DateTime::createFromFormat('Y-m-d', $iso);
        return $dt !== false ? $dt->format('d.m.Y') : $iso;
    }
}
