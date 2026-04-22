<?php

namespace App\Services;

use App\Models\Tenant;
use Carbon\Carbon;

class MonthlySummaryPdfService
{
    public function __construct(
        private readonly TenantReportService $reports,
    ) {}

    public function build(int $tenantId, Carbon $monthDate): array
    {
        $from = $monthDate->copy()->startOfMonth();
        $to = $monthDate->copy()->endOfMonth();
        $report = $this->reports->build($tenantId, $from, $to);
        $summary = (array) ($report['summary'] ?? []);
        $payments = (array) ($report['payments'] ?? []);
        $topZone = $report['zones']['top_zone'] ?? null;

        $tenant = Tenant::query()->find($tenantId, ['id', 'name']);
        $clubName = $tenant?->name ?? ('Club #' . $tenantId);

        $lines = [
            'MyCafe Owner Monthly Summary',
            'Club: ' . $clubName,
            'Period: ' . $from->toDateString() . ' -> ' . $to->toDateString(),
            'Net sales: ' . (int) ($summary['net_sales'] ?? 0) . ' UZS',
            'Gross sales: ' . (int) ($summary['gross_sales'] ?? 0) . ' UZS',
            'Sessions: ' . (int) ($summary['sessions_count'] ?? 0),
            'Avg session: ' . (float) ($summary['avg_session_minutes'] ?? 0) . ' min',
            'Utilization: ' . (float) ($summary['utilization_pct'] ?? 0) . ' %',
            'Returns: ' . (int) ($summary['returns_total'] ?? 0) . ' UZS',
            'Expenses: ' . (int) ($summary['expenses_total'] ?? 0) . ' UZS',
            'Cash sales: ' . (int) ($payments['cash_sales_total'] ?? 0) . ' UZS',
            'Card sales: ' . (int) ($payments['card_sales_total'] ?? 0) . ' UZS',
            'Balance sales: ' . (int) ($payments['balance_sales_total'] ?? 0) . ' UZS',
        ];

        if (is_array($topZone) && !empty($topZone)) {
            $lines[] = 'Top zone: ' . ($topZone['zone'] ?? '-') . ' | ' . (int) ($topZone['revenue'] ?? 0) . ' UZS';
        }

        return [
            'month' => $monthDate->format('Y-m'),
            'filename' => 'monthly-summary-' . $monthDate->format('Y-m') . '.pdf',
            'pdf_base64' => base64_encode($this->buildSimplePdf($lines)),
        ];
    }

    private function buildSimplePdf(array $lines): string
    {
        $escape = static function (string $text): string {
            return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        };

        $y = 780;
        $content = "BT\n/F1 12 Tf\n";
        foreach ($lines as $line) {
            $content .= "1 0 0 1 50 {$y} Tm (" . $escape((string) $line) . ") Tj\n";
            $y -= 16;
            if ($y < 40) {
                break;
            }
        }
        $content .= "ET";

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 5 0 R /Resources << /Font << /F1 4 0 R >> >> >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[5] = "<< /Length " . strlen($content) . " >>\nstream\n{$content}\nendstream";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[$index] = strlen($pdf);
            $pdf .= "{$index} 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($index = 1; $index <= count($objects); $index++) {
            $pdf .= str_pad((string) $offsets[$index], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xref}\n%%EOF";

        return $pdf;
    }
}
