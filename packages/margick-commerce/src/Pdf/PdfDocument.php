<?php
/**
 * PdfDocument — a minimal, ZERO-DEPENDENCY PDF writer.
 * ====================================================
 * Enough to lay out a one-page A4 business document (tax invoice / receipt):
 * text in the standard PDF core fonts (no font embedding), straight lines and
 * filled rectangles. PURE PHP — no dompdf/mPDF/wkhtmltopdf, so the module stays
 * standalone (PSR-4, no vendor) and works on every install.
 *
 * Coordinate system: TOP-LEFT origin in points (1pt = 1/72"), y grows downward
 * (natural for documents); internally flipped to PDF's bottom-left space.
 * A4 = 595.28 x 841.89 pt. Single page, WinAnsi/Latin-1 text (SG invoices are
 * English; non-Latin-1 -> '?'), no images. Output is a valid, openable PDF.
 */

declare(strict_types=1);

namespace Margick\Commerce\Pdf;

final class PdfDocument
{
    public const A4_W = 595.28;
    public const A4_H = 841.89;

    private float $pageW;
    private float $pageH;
    /** @var list<string> */
    private array $ops = [];

    private const FONTS = ['F1' => 'Helvetica', 'F2' => 'Helvetica-Bold'];

    public function __construct(float $pageW = self::A4_W, float $pageH = self::A4_H)
    {
        $this->pageW = $pageW;
        $this->pageH = $pageH;
    }

    public function width(): float { return $this->pageW; }
    public function height(): float { return $this->pageH; }

    /** Text at (x,y) from TOP-LEFT, points. $bold -> Helvetica-Bold. */
    public function text(float $x, float $y, string $s, float $size = 10.0, bool $bold = false): void
    {
        $font = $bold ? 'F2' : 'F1';
        $py   = $this->pageH - $y - $size;
        $this->ops[] = 'BT /' . $font . ' ' . self::num($size) . ' Tf '
            . self::num($x) . ' ' . self::num($py) . ' Td ('
            . self::escape($s) . ') Tj ET';
    }

    /** Right-align so text ENDS at $xRight. */
    public function textRight(float $xRight, float $y, string $s, float $size = 10.0, bool $bold = false): void
    {
        $this->text($xRight - self::textWidth($s, $size, $bold), $y, $s, $size, $bold);
    }

    /** Straight line, top-left coords. */
    public function line(float $x1, float $y1, float $x2, float $y2, float $wpt = 0.6, float $gray = 0.0): void
    {
        $this->ops[] = self::num($gray) . ' G ' . self::num($wpt) . ' w '
            . self::num($x1) . ' ' . self::num($this->pageH - $y1) . ' m '
            . self::num($x2) . ' ' . self::num($this->pageH - $y2) . ' l S';
    }

    /** Filled rect (top-left origin), gray 0=black..1=white. */
    public function fillRect(float $x, float $y, float $w, float $h, float $gray = 0.9): void
    {
        $this->ops[] = self::num($gray) . ' g '
            . self::num($x) . ' ' . self::num($this->pageH - $y - $h) . ' '
            . self::num($w) . ' ' . self::num($h) . ' re f 0 g';
    }

    /** Approx width using built-in Helvetica AFM widths (per 1000 em). */
    public static function textWidth(string $s, float $size, bool $bold = false): float
    {
        $w = $bold ? self::HELV_BOLD_W : self::HELV_W;
        $total = 0;
        $bytes = self::toLatin1($s);
        for ($i = 0, $len = strlen($bytes); $i < $len; $i++) {
            $total += $w[ord($bytes[$i])] ?? 556;
        }
        return $total / 1000.0 * $size;
    }

    /** Assemble the whole file as a byte string. */
    public function output(): string
    {
        $content = implode("\n", $this->ops);
        $objs = [];
        $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objs[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
        $objs[3] = "<< /Type /Page /Parent 2 0 R "
            . "/MediaBox [0 0 " . self::num($this->pageW) . ' ' . self::num($this->pageH) . "] "
            . "/Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>";
        $objs[4] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        $objs[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /" . self::FONTS['F1'] . " /Encoding /WinAnsiEncoding >>";
        $objs[6] = "<< /Type /Font /Subtype /Type1 /BaseFont /" . self::FONTS['F2'] . " /Encoding /WinAnsiEncoding >>";

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        for ($i = 1; $i <= 6; $i++) {
            $offsets[$i] = strlen($pdf);
            $pdf .= $i . " 0 obj\n" . $objs[$i] . "\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 7\n0000000000 65535 f \n";
        for ($i = 1; $i <= 6; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size 7 /Root 1 0 R >>\nstartxref\n" . $xrefPos . "\n%%EOF";
        return $pdf;
    }

    private static function num(float $n): string
    {
        $s = rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
        return ($s === '' || $s === '-0') ? '0' : $s;
    }

    private static function escape(string $s): string
    {
        return strtr(self::toLatin1($s), [
            '\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ',
        ]);
    }

    private static function toLatin1(string $s): string
    {
        if (function_exists('mb_convert_encoding')) {
            $out = @mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
            if ($out !== false && $out !== '') { return $out; }
        }
        return preg_replace('/[^\x20-\x7E]/', '?', $s) ?? '';
    }

    private const HELV_W = [
        32=>278,33=>278,34=>355,35=>556,36=>556,37=>889,38=>667,39=>191,40=>333,41=>333,42=>389,43=>584,
        44=>278,45=>333,46=>278,47=>278,48=>556,49=>556,50=>556,51=>556,52=>556,53=>556,54=>556,55=>556,
        56=>556,57=>556,58=>278,59=>278,60=>584,61=>584,62=>584,63=>556,64=>1015,65=>667,66=>667,67=>722,
        68=>722,69=>667,70=>611,71=>778,72=>722,73=>278,74=>500,75=>667,76=>556,77=>833,78=>722,79=>778,
        80=>667,81=>778,82=>722,83=>667,84=>611,85=>722,86=>667,87=>944,88=>667,89=>667,90=>611,91=>278,
        92=>278,93=>278,94=>469,95=>556,96=>333,97=>556,98=>556,99=>500,100=>556,101=>556,102=>278,103=>556,
        104=>556,105=>222,106=>222,107=>500,108=>222,109=>833,110=>556,111=>556,112=>556,113=>556,114=>333,
        115=>500,116=>278,117=>556,118=>500,119=>722,120=>500,121=>500,122=>500,123=>334,124=>260,125=>334,126=>584,
    ];
    private const HELV_BOLD_W = [
        32=>278,33=>333,34=>474,35=>556,36=>556,37=>889,38=>722,39=>238,40=>333,41=>333,42=>389,43=>584,
        44=>278,45=>333,46=>278,47=>278,48=>556,49=>556,50=>556,51=>556,52=>556,53=>556,54=>556,55=>556,
        56=>556,57=>556,58=>333,59=>333,60=>584,61=>584,62=>584,63=>611,64=>975,65=>722,66=>722,67=>722,
        68=>722,69=>667,70=>611,71=>778,72=>722,73=>278,74=>556,75=>722,76=>611,77=>833,78=>722,79=>778,
        80=>667,81=>778,82=>722,83=>667,84=>611,85=>722,86=>667,87=>944,88=>667,89=>667,90=>611,91=>333,
        92=>278,93=>333,94=>584,95=>556,96=>333,97=>556,98=>611,99=>556,100=>611,101=>556,102=>333,103=>611,
        104=>611,105=>278,106=>278,107=>556,108=>278,109=>889,110=>611,111=>611,112=>611,113=>611,114=>389,
        115=>556,116=>333,117=>611,118=>556,119=>778,120=>556,121=>556,122=>500,123=>389,124=>280,125=>389,126=>584,
    ];
}
