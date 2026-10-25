<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Ultra-premium A4 landscape certificate PDF matching the BTI mockup.
 * Cream parchment, gold double border, real logo seal, navy/gold typography.
 */
class certificate_pdf_helper {
    /** Navy #0c1f3d */
    private const NAVY = [12, 31, 61];
    /** Gold foil #c9a227 */
    private const GOLD = [201, 162, 39];
    /** Soft gold #d4af37 */
    private const GOLD_LIGHT = [212, 175, 55];
    /** Warm cream paper #f7f3ea */
    private const PAPER = [247, 243, 234];

    /**
     * Build print-ready A4 landscape certificate PDF bytes.
     *
     * @param array{
     *   studentname:string,
     *   coursename:string,
     *   coursecode:string,
     *   certid:string,
     *   completedat:int,
     *   verifyurl?:string
     * } $data
     */
    public static function render(array $data): string {
        global $CFG;

        require_once($CFG->libdir . '/pdflib.php');

        $student = (string)$data['studentname'];
        $course = (string)$data['coursename'];
        $code = (string)$data['coursecode'];
        $certid = (string)$data['certid'];
        $completedat = (int)$data['completedat'];
        $verifyurl = (string)($data['verifyurl'] ?? ('BTI-CERT:' . $certid));
        $issued = userdate($completedat, '%B %d, %Y');

        $pdf = new \pdf('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetMargins(0, 0, 0);
        $pdf->AddPage('L', 'A4');

        $w = 297.0;
        $h = 210.0;

        // Cream parchment fill.
        $pdf->SetFillColor(...self::PAPER);
        $pdf->Rect(0, 0, $w, $h, 'F');

        // Subtle paper grain.
        $pdf->SetDrawColor(230, 222, 205);
        $pdf->SetLineWidth(0.04);
        for ($i = -210; $i < 300; $i += 7) {
            $pdf->Line($i, 0, $i + 210, 210);
        }

        // Soft watermark logo.
        self::draw_logo_watermark($pdf, $w / 2, $h / 2 + 4, 52);

        // Gold double border.
        $pdf->SetDrawColor(...self::GOLD);
        $pdf->SetLineWidth(1.6);
        $pdf->Rect(9, 9, $w - 18, $h - 18);
        $pdf->SetLineWidth(0.4);
        $pdf->Rect(11.5, 11.5, $w - 23, $h - 23);

        self::draw_corner_flourishes($pdf, $w, $h);

        // Gold circular seal with real logo.
        self::draw_logo_seal($pdf, $w / 2, 32, 13);

        $pdf->SetTextColor(...self::NAVY);
        $pdf->SetFont('times', 'B', 13);
        $pdf->SetXY(20, 46);
        $pdf->Cell($w - 40, 6, 'bti LMS', 0, 1, 'C');

        $pdf->SetFont('helvetica', 'B', 6.5);
        $pdf->SetTextColor(...self::NAVY);
        $pdf->SetXY(20, 51.5);
        $pdf->Cell($w - 40, 4, 'LEARNING PORTAL', 0, 1, 'C');

        // Title.
        $pdf->SetTextColor(...self::NAVY);
        $pdf->SetFont('times', 'B', 26);
        $pdf->SetXY(24, 58);
        $pdf->Cell($w - 48, 11, 'Certificate of Completion', 0, 1, 'C');

        // Gold rule + diamond under title.
        $mid = $w / 2;
        self::draw_diamond_rule($pdf, $mid, 71, 48);

        $pdf->SetTextColor(...self::NAVY);
        $pdf->SetFont('times', 'I', 11);
        $pdf->SetXY(24, 75);
        $pdf->Cell($w - 48, 6, 'This is to certify that', 0, 1, 'C');

        // Recipient name (script-like italic).
        $pdf->SetTextColor(...self::NAVY);
        $pdf->SetFont('times', 'BI', 28);
        $pdf->SetXY(24, 84);
        $pdf->Cell($w - 48, 13, $student, 0, 1, 'C');

        self::draw_diamond_rule($pdf, $mid, 99, 55);

        $pdf->SetTextColor(...self::NAVY);
        $pdf->SetFont('times', 'I', 11);
        $pdf->SetXY(24, 103);
        $pdf->Cell($w - 48, 5, 'has successfully completed the course', 0, 1, 'C');

        $pdf->SetTextColor(...self::NAVY);
        $pdf->SetFont('times', 'B', 15);
        $pdf->SetXY(30, 111);
        $pdf->MultiCell($w - 60, 7, $course, 0, 'C');

        if ($code !== '') {
            $pdf->SetTextColor(80, 90, 105);
            $pdf->SetFont('helvetica', 'B', 8);
            $y = $pdf->GetY() + 0.5;
            $pdf->SetXY(24, $y);
            $pdf->Cell($w - 48, 4, strtoupper($code), 0, 1, 'C');
        }

        $pdf->SetTextColor(...self::NAVY);
        $pdf->SetFont('times', '', 9);
        $pdf->SetXY(24, 136);
        $pdf->Cell($w - 48, 4, 'Date of Completion: ' . $issued, 0, 1, 'C');
        $pdf->SetXY(24, 141);
        $pdf->Cell($w - 48, 4, 'Certificate ID: ' . $certid, 0, 1, 'C');

        // Signatures with vertical gold divider.
        $sigy = 162;
        $pdf->SetDrawColor(...self::GOLD);
        $pdf->SetLineWidth(0.35);
        $pdf->Line($mid, 152, $mid, 175);

        // Left signature.
        $pdf->SetTextColor(...self::NAVY);
        $pdf->SetFont('times', 'I', 13);
        $pdf->SetXY(40, $sigy - 9);
        $pdf->Cell(70, 7, 'James Whitaker', 0, 0, 'C');
        $pdf->SetDrawColor(...self::GOLD);
        $pdf->SetLineWidth(0.4);
        $pdf->Line(48, $sigy, 102, $sigy);
        // Diamond ends.
        self::draw_diamond($pdf, 48, $sigy, 1.1);
        self::draw_diamond($pdf, 102, $sigy, 1.1);
        $pdf->SetFont('helvetica', 'B', 6);
        $pdf->SetTextColor(...self::NAVY);
        $pdf->SetXY(40, $sigy + 1.5);
        $pdf->Cell(70, 4, 'DEAN OF ACADEMICS', 0, 0, 'C');

        // Right signature.
        $pdf->SetTextColor(...self::NAVY);
        $pdf->SetFont('times', 'I', 13);
        $pdf->SetXY(187, $sigy - 9);
        $pdf->Cell(70, 7, 'Sophia Martinez', 0, 0, 'C');
        $pdf->SetDrawColor(...self::GOLD);
        $pdf->Line(195, $sigy, 249, $sigy);
        self::draw_diamond($pdf, 195, $sigy, 1.1);
        self::draw_diamond($pdf, 249, $sigy, 1.1);
        $pdf->SetFont('helvetica', 'B', 6);
        $pdf->SetTextColor(...self::NAVY);
        $pdf->SetXY(187, $sigy + 1.5);
        $pdf->Cell(70, 4, 'REGISTRAR', 0, 0, 'C');

        // Footer certificate ID (left).
        $pdf->SetFont('helvetica', '', 6.5);
        $pdf->SetTextColor(90, 95, 105);
        $pdf->SetXY(18, 188);
        $pdf->Cell(90, 3.5, 'Certificate ID', 0, 0, 'L');
        $pdf->SetTextColor(...self::NAVY);
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->SetXY(18, 192);
        $pdf->Cell(90, 3.5, $certid, 0, 0, 'L');
        $pdf->SetDrawColor(...self::GOLD);
        $pdf->SetLineWidth(0.35);
        $pdf->Line(18, 196.5, 72, 196.5);

        // Gold QR + VERIFY pill (bottom right).
        try {
            $style = [
                'border' => 0,
                'vpadding' => 0,
                'hpadding' => 0,
                'fgcolor' => self::GOLD,
                'bgcolor' => self::PAPER,
                'module_width' => 1,
                'module_height' => 1,
            ];
            $pdf->write2DBarcode($verifyurl, 'QRCODE,M', 255, 170, 24, 24, $style, 'N');
            // VERIFY pill.
            $pdf->SetFillColor(...self::GOLD);
            $pdf->RoundedRect(257, 196, 20, 5.5, 2.2, '1111', 'F');
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('helvetica', 'B', 5.5);
            $pdf->SetXY(257, 196.8);
            $pdf->Cell(20, 4, 'VERIFY', 0, 0, 'C');
        } catch (\Throwable $e) {
            debugging('certificate QR failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return $pdf->Output('', 'S');
    }

    private static function logo_path(): ?string {
        $path = __DIR__ . '/../pix/ouk-logo.png';
        return is_readable($path) ? $path : null;
    }

    private static function draw_logo_watermark(\pdf $pdf, float $cx, float $cy, float $size): void {
        $logo = self::logo_path();
        if (!$logo) {
            return;
        }
        if (method_exists($pdf, 'SetAlpha')) {
            $pdf->SetAlpha(0.06);
        }
        $pdf->Image($logo, $cx - $size / 2, $cy - $size / 2, $size, $size, 'PNG', '', '', false, 300);
        if (method_exists($pdf, 'SetAlpha')) {
            $pdf->SetAlpha(1);
        }
    }

    private static function draw_logo_seal(\pdf $pdf, float $cx, float $cy, float $r): void {
        // Gold outer disc.
        $pdf->SetFillColor(...self::GOLD);
        $pdf->SetDrawColor(...self::GOLD);
        $pdf->Circle($cx, $cy, $r, 0, 360, 'DF');
        // Inner cream disc.
        $pdf->SetFillColor(...self::PAPER);
        $pdf->Circle($cx, $cy, $r - 1.8, 0, 360, 'F');
        $pdf->SetDrawColor(...self::NAVY);
        $pdf->SetLineWidth(0.25);
        $pdf->Circle($cx, $cy, $r - 1.8, 0, 360, 'D');

        $logo = self::logo_path();
        if ($logo) {
            $s = $r * 1.35;
            $pdf->Image($logo, $cx - $s / 2, $cy - $s / 2, $s, $s, 'PNG', '', '', false, 300);
        } else {
            $pdf->SetTextColor(...self::NAVY);
            $pdf->SetFont('times', 'B', 9);
            $pdf->SetXY($cx - $r, $cy - 3);
            $pdf->Cell($r * 2, 6, 'BTI', 0, 0, 'C');
        }
    }

    private static function draw_diamond_rule(\pdf $pdf, float $mid, float $y, float $half): void {
        $pdf->SetDrawColor(...self::GOLD);
        $pdf->SetLineWidth(0.45);
        $pdf->Line($mid - $half, $y, $mid - 2.5, $y);
        $pdf->Line($mid + 2.5, $y, $mid + $half, $y);
        self::draw_diamond($pdf, $mid, $y, 1.4);
    }

    private static function draw_diamond(\pdf $pdf, float $x, float $y, float $s): void {
        $pdf->SetFillColor(...self::GOLD);
        $pdf->SetDrawColor(...self::GOLD);
        $pdf->Polygon([
            $x, $y - $s,
            $x + $s, $y,
            $x, $y + $s,
            $x - $s, $y,
        ], 'F');
    }

    private static function draw_corner_flourishes(\pdf $pdf, float $w, float $h): void {
        $pdf->SetDrawColor(...self::GOLD);
        $pdf->SetLineWidth(0.55);
        $len = 16;
        $o = 15;

        // Top-left.
        $pdf->Line($o, $o, $o + $len, $o);
        $pdf->Line($o, $o, $o, $o + $len);
        $pdf->Line($o + 3, $o + 3, $o + 10, $o + 3);
        $pdf->Line($o + 3, $o + 3, $o + 3, $o + 10);
        $pdf->Circle($o + 6, $o + 6, 0.7, 0, 360, 'F');

        // Top-right.
        $pdf->Line($w - $o, $o, $w - $o - $len, $o);
        $pdf->Line($w - $o, $o, $w - $o, $o + $len);
        $pdf->Line($w - $o - 3, $o + 3, $w - $o - 10, $o + 3);
        $pdf->Line($w - $o - 3, $o + 3, $w - $o - 3, $o + 10);
        $pdf->Circle($w - $o - 6, $o + 6, 0.7, 0, 360, 'F');

        // Bottom-left.
        $pdf->Line($o, $h - $o, $o + $len, $h - $o);
        $pdf->Line($o, $h - $o, $o, $h - $o - $len);
        $pdf->Line($o + 3, $h - $o - 3, $o + 10, $h - $o - 3);
        $pdf->Line($o + 3, $h - $o - 3, $o + 3, $h - $o - 10);
        $pdf->Circle($o + 6, $h - $o - 6, 0.7, 0, 360, 'F');

        // Bottom-right.
        $pdf->Line($w - $o, $h - $o, $w - $o - $len, $h - $o);
        $pdf->Line($w - $o, $h - $o, $w - $o, $h - $o - $len);
        $pdf->Line($w - $o - 3, $h - $o - 3, $w - $o - 10, $h - $o - 3);
        $pdf->Line($w - $o - 3, $h - $o - 3, $w - $o - 3, $h - $o - 10);
        $pdf->Circle($w - $o - 6, $h - $o - 6, 0.7, 0, 360, 'F');
    }
}
