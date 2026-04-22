<?php

namespace App\Services;

use Carbon\Carbon;
use ZipArchive;

class ShiftHistoryExportService
{
    public function __construct(
        private readonly ShiftHistoryService $history,
    ) {
    }

    public function exportXmlResponse(int $tenantId, ?Carbon $from, ?Carbon $to, string $lang, int $limit)
    {
        $view = $this->buildView($tenantId, $from, $to, $lang, $limit);
        $labels = $view['labels'];

        $xml = [];
        $xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml[] = '<?mso-application progid="Excel.Sheet"?>';
        $xml[] = '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
        $xml[] = '<Styles>';
        $xml[] = '<Style ss:ID="Default"><Font ss:FontName="Calibri" ss:Size="10"/></Style>';
        $xml[] = '<Style ss:ID="Header"><Font ss:Bold="1"/></Style>';
        $xml[] = '<Style ss:ID="Currency"><NumberFormat ss:Format="#,##0"/></Style>';
        $xml[] = '</Styles>';

        $xml[] = '<Worksheet ss:Name="' . $this->xmlEsc($labels['dashboard']) . '"><Table>';
        foreach ($this->buildDashboardRows($view) as $row) {
            $xml[] = $this->xmlRow($row);
        }
        $xml[] = '</Table></Worksheet>';

        $xml[] = '<Worksheet ss:Name="' . $this->xmlEsc($labels['sheetShifts']) . '"><Table>';
        foreach ($this->buildShiftRows($view) as $row) {
            $xml[] = $this->xmlRow($row);
        }
        $xml[] = '</Table></Worksheet>';

        $xml[] = '<Worksheet ss:Name="' . $this->xmlEsc($labels['sheetPeaks']) . '"><Table>';
        foreach ($this->buildPeakRows($view) as $row) {
            $xml[] = $this->xmlRow($row);
        }
        $xml[] = '</Table></Worksheet>';

        $xml[] = '</Workbook>';

        return response(implode('', $xml), 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="shift-history-' . now()->format('Ymd-His') . '.xls"',
            'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    public function exportXlsxResponse(int $tenantId, ?Carbon $from, ?Carbon $to, string $lang, int $limit)
    {
        $view = $this->buildView($tenantId, $from, $to, $lang, $limit);
        $labels = $view['labels'];

        $tmp = tempnam(sys_get_temp_dir(), 'shift-history-xlsx-');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml($labels));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->xlsxSheetXml($this->buildDashboardRows($view)));
        $zip->addFromString('xl/worksheets/sheet2.xml', $this->xlsxSheetXml($this->buildShiftRows($view)));
        $zip->addFromString('xl/worksheets/sheet3.xml', $this->xlsxSheetXml($this->buildPeakRows($view)));
        $zip->close();

        $content = (string) file_get_contents($tmp);
        @unlink($tmp);

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="shift-history-dashboard-' . now()->format('Ymd-His') . '.xlsx"',
            'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    private function buildView(int $tenantId, ?Carbon $from, ?Carbon $to, string $lang, int $limit): array
    {
        $dataset = $this->history->exportDataset($tenantId, $from, $to, $limit);
        $labels = $this->labels($lang);
        $rows = array_map(function (array $row) use ($lang, $labels) {
            return [
                ...$row,
                'status_label' => $labels[$row['status']] ?? $row['status'],
                'signals_text' => implode('; ', $this->signals($row['finance'], $lang)),
            ];
        }, $dataset['rows']);

        $summary = (array) ($dataset['summary'] ?? []);
        $shiftsCount = max(1, (int) ($summary['shifts_count'] ?? count($rows)));
        $reconciledCount = (int) ($summary['reconciled_count'] ?? 0);
        $reconciledPct = round(($reconciledCount / $shiftsCount) * 100, 1);
        $cashIn = (int) ($summary['cash_in'] ?? 0);
        $returnsCash = (int) ($summary['returns_cash'] ?? 0);
        $expenses = (int) ($summary['expenses_total'] ?? 0);

        $insights = [];
        if ((int) ($summary['diff_shortage_sum'] ?? 0) > 0) {
            $insights[] = $this->text($lang, 'insightShortage', [
                'value' => number_format((int) ($summary['diff_shortage_sum'] ?? 0), 0, '.', ','),
            ]);
        }
        if ($reconciledPct < 80) {
            $insights[] = $this->text($lang, 'insightReconLow', ['value' => $reconciledPct]);
        }
        if ($cashIn > 0) {
            $expensePressure = round(($expenses / $cashIn) * 100, 1);
            $returnPressure = round(($returnsCash / $cashIn) * 100, 1);

            if ($expensePressure >= 35) {
                $insights[] = $this->text($lang, 'insightExpenseHigh', ['value' => $expensePressure]);
            }
            if ($returnPressure >= 20) {
                $insights[] = $this->text($lang, 'insightReturnsHigh', ['value' => $returnPressure]);
            }
        }
        if ($insights === []) {
            $insights[] = $this->text($lang, 'insightStable');
        }

        $topShortages = collect($rows)
            ->filter(fn(array $row) => (int) ($row['finance']['effective_diff'] ?? 0) < 0)
            ->sortBy(fn(array $row) => (int) ($row['finance']['effective_diff'] ?? 0))
            ->take(10)
            ->values()
            ->all();

        return [
            'labels' => $labels,
            'lang' => $lang,
            'period_label' => $from && $to ? ($from->toDateString() . ' -> ' . $to->toDateString()) : 'all',
            'generated_at' => now()->format('Y-m-d H:i:s'),
            'rows' => $rows,
            'summary' => $summary,
            'reconciled_pct' => $reconciledPct,
            'insights' => $insights,
            'top_shortages' => $topShortages,
            'peak_expenses' => $dataset['peak_expenses'] ?? [],
            'peak_returns' => $dataset['peak_returns'] ?? [],
        ];
    }

    private function buildDashboardRows(array $view): array
    {
        $labels = $view['labels'];
        $summary = $view['summary'];

        $rows = [
            [$labels['title']],
            [$labels['period'], $view['period_label']],
            [$labels['generated'], $view['generated_at']],
            [],
            [$labels['kpi']],
            [$labels['kpiShifts'], (int) count($view['rows'])],
            [$labels['kpiCashIn'], (int) ($summary['cash_in'] ?? 0)],
            [$labels['kpiCardIn'], (int) ($summary['card_in'] ?? 0)],
            [$labels['kpiReturns'], (int) ($summary['returns_total'] ?? 0)],
            [$labels['kpiExpenses'], (int) ($summary['expenses_total'] ?? 0)],
            [$labels['kpiExpected'], (int) ($summary['expected_sum'] ?? 0)],
            [$labels['kpiClosing'], (int) ($summary['closing_sum'] ?? 0)],
            [$labels['kpiNet'], (int) ($summary['net_cash'] ?? 0)],
            [$labels['kpiOver'], (int) ($summary['diff_overage_sum'] ?? 0)],
            [$labels['kpiShort'], (int) ($summary['diff_shortage_sum'] ?? 0)],
            [$labels['kpiRecon'], (float) $view['reconciled_pct']],
            [],
            [$labels['insights']],
        ];

        foreach ($view['insights'] as $insight) {
            $rows[] = [$insight];
        }

        $rows[] = [];
        $rows[] = [$labels['topShortage']];
        $rows[] = [
            $labels['id'],
            $labels['closedAt'],
            $labels['operator'],
            $labels['expected'],
            $labels['closing'],
            $labels['diff'],
            $labels['status'],
            $labels['signals'],
        ];

        foreach ($view['top_shortages'] as $row) {
            $shift = $row['shift'];
            $finance = $row['finance'];

            $rows[] = [
                (int) $shift->id,
                (string) $shift->closed_at,
                (string) ($shift->closedBy->name ?? $shift->closedBy->login ?? '-'),
                (int) ($finance['expected_cash'] ?? 0),
                (int) ($finance['closing_cash'] ?? 0),
                (int) ($finance['effective_diff'] ?? $finance['diff'] ?? 0),
                (string) ($row['status_label'] ?? $row['status']),
                (string) ($row['signals_text'] ?? ''),
            ];
        }

        return $rows;
    }

    private function buildShiftRows(array $view): array
    {
        $labels = $view['labels'];
        $rows = [[
            $labels['sShiftId'],
            $labels['sOpenedAt'],
            $labels['sClosedAt'],
            $labels['sOpenedBy'],
            $labels['sClosedBy'],
            $labels['sOpeningCash'],
            $labels['sCashIn'],
            $labels['sCardIn'],
            $labels['sGrossIn'],
            $labels['sReturnsCash'],
            $labels['sReturnsTotal'],
            $labels['sExpenses'],
            $labels['sExpected'],
            $labels['sClosing'],
            $labels['sDiff'],
            $labels['sNet'],
            $labels['sStatus'],
            $labels['sSignal'],
        ]];

        foreach ($view['rows'] as $row) {
            $shift = $row['shift'];
            $finance = $row['finance'];

            $rows[] = [
                (int) $shift->id,
                (string) $shift->opened_at,
                (string) $shift->closed_at,
                (string) ($shift->openedBy->name ?? $shift->openedBy->login ?? '-'),
                (string) ($shift->closedBy->name ?? $shift->closedBy->login ?? '-'),
                (int) ($finance['opening_cash'] ?? 0),
                (int) ($finance['cash_in'] ?? 0),
                (int) ($finance['card_in'] ?? 0),
                (int) ($finance['gross_in'] ?? 0),
                (int) ($finance['returns_cash'] ?? 0),
                (int) ($finance['returns_total'] ?? 0),
                (int) ($finance['expenses_cash'] ?? 0),
                (int) ($finance['expected_cash'] ?? 0),
                (int) ($finance['closing_cash'] ?? 0),
                (int) ($finance['effective_diff'] ?? $finance['diff'] ?? 0),
                (int) ($finance['net_cash'] ?? 0),
                (string) ($row['status_label'] ?? $row['status']),
                (string) ($row['signals_text'] ?? ''),
            ];
        }

        return $rows;
    }

    private function buildPeakRows(array $view): array
    {
        $labels = $view['labels'];
        $rows = [[
            $labels['pType'],
            $labels['pShiftId'],
            $labels['pWhen'],
            $labels['pName'],
            $labels['pCategory'],
            $labels['pClientId'],
            $labels['pAmount'],
        ]];

        foreach ($view['peak_expenses'] as $expense) {
            $rows[] = [
                'expense',
                (int) ($expense['shift_id'] ?? 0),
                (string) ($expense['at'] ?? ''),
                (string) ($expense['name'] ?? ''),
                (string) ($expense['category'] ?? ''),
                '',
                (int) ($expense['amount'] ?? 0),
            ];
        }

        foreach ($view['peak_returns'] as $return) {
            $rows[] = [
                'cash_return',
                (int) ($return['shift_id'] ?? 0),
                (string) ($return['at'] ?? ''),
                '',
                '',
                (int) ($return['client_id'] ?? 0),
                (int) ($return['amount'] ?? 0),
            ];
        }

        return $rows;
    }

    private function signals(array $finance, string $lang): array
    {
        $signals = [];
        $cashIn = max(1, (int) ($finance['cash_in'] ?? 0));
        $expenseRatio = (int) round(((int) ($finance['expenses_cash'] ?? 0) / $cashIn) * 100);
        $returnsRatio = (int) round(((int) ($finance['returns_cash'] ?? 0) / $cashIn) * 100);
        $diff = (int) ($finance['effective_diff'] ?? ($finance['diff'] ?? 0));

        if ($diff < 0) {
            $signals[] = $this->text($lang, 'shiftSignalsShort', ['amount' => number_format(abs($diff), 0, '.', ',')]);
        } elseif ($diff > 0) {
            $signals[] = $this->text($lang, 'shiftSignalsOver', ['amount' => number_format(abs($diff), 0, '.', ',')]);
        }
        if ($expenseRatio >= 35) {
            $signals[] = $this->text($lang, 'shiftSignalsExpense', ['ratio' => $expenseRatio]);
        }
        if ($returnsRatio >= 20) {
            $signals[] = $this->text($lang, 'shiftSignalsReturns', ['ratio' => $returnsRatio]);
        }
        if ((int) ($finance['tx_count'] ?? 0) >= 80) {
            $signals[] = $this->text($lang, 'shiftSignalsOps', ['count' => (int) ($finance['tx_count'] ?? 0)]);
        }

        return $signals !== [] ? $signals : [$this->text($lang, 'shiftSignalsOk')];
    }

    private function labels(string $lang): array
    {
        $dict = [
            'uz' => [
                'dashboard' => 'Dashboard',
                'sheetShifts' => 'Smena Tarixi',
                'sheetPeaks' => 'Top Choqqilar',
                'title' => 'Smena tarixi eksporti',
                'period' => 'Davr',
                'generated' => 'Yaratilgan',
                'kpi' => 'Asosiy KPI',
                'insights' => 'AI uslubidagi tahliliy signal',
                'topShortage' => 'Eng katta kamomadli smenalar',
                'id' => 'ID',
                'closedAt' => 'Yopilgan',
                'operator' => 'Operator',
                'expected' => 'Expected',
                'closing' => 'Closing',
                'diff' => 'Diff',
                'status' => 'Status',
                'signals' => 'Signal',
                'kpiShifts' => 'Smenalar soni',
                'kpiCashIn' => 'Naqd kirim',
                'kpiCardIn' => 'Karta kirim',
                'kpiReturns' => 'Qaytarishlar jami',
                'kpiExpenses' => 'Xarajatlar jami',
                'kpiExpected' => 'Expected summa',
                'kpiClosing' => 'Closing summa',
                'kpiNet' => 'Net cash',
                'kpiOver' => 'Overage summa',
                'kpiShort' => 'Shortage summa',
                'kpiRecon' => 'Reconciled ulushi',
                'matched' => 'Mos',
                'carried' => 'Otkazilgan',
                'partial' => 'Qisman otkazilgan',
                'overage' => 'Ortiqcha',
                'shortage' => 'Kamomad',
                'shiftSignalsShort' => 'Kutilgandan kam: {amount}',
                'shiftSignalsOver' => 'Kutilgandan kop: {amount}',
                'shiftSignalsExpense' => 'Xarajat bosimi: {ratio}%',
                'shiftSignalsReturns' => 'Naqd qaytarish ulushi: {ratio}%',
                'shiftSignalsOps' => 'Naqd tranzaksiya soni yuqori: {count}',
                'shiftSignalsOk' => 'Kuchli risk signali topilmadi',
                'insightShortage' => 'Umumiy kamomad: {value}. Kassa intizomini tekshirish tavsiya etiladi.',
                'insightReconLow' => 'Reconciled ulushi past: {value}%. Shift yopish jarayonini qayta koring.',
                'insightExpenseHigh' => 'Xarajat bosimi yuqori: {value}% (naqd kirimga nisbatan).',
                'insightReturnsHigh' => 'Naqd qaytarish ulushi yuqori: {value}% (naqd kirimga nisbatan).',
                'insightStable' => 'Korsatkichlar barqaror: keskin risk aniqlanmadi.',
                'sShiftId' => 'Shift ID',
                'sOpenedAt' => 'Opened at',
                'sClosedAt' => 'Closed at',
                'sOpenedBy' => 'Opened by',
                'sClosedBy' => 'Closed by',
                'sOpeningCash' => 'Opening cash',
                'sCashIn' => 'Cash in',
                'sCardIn' => 'Card in',
                'sGrossIn' => 'Gross in',
                'sReturnsCash' => 'Returns cash',
                'sReturnsTotal' => 'Returns total',
                'sExpenses' => 'Expenses',
                'sExpected' => 'Expected',
                'sClosing' => 'Closing',
                'sDiff' => 'Diff',
                'sNet' => 'Net',
                'sStatus' => 'Status',
                'sSignal' => 'Signal',
                'pType' => 'Turi',
                'pShiftId' => 'Shift ID',
                'pWhen' => 'Vaqt',
                'pName' => 'Nomi',
                'pCategory' => 'Kategoriya',
                'pClientId' => 'Client ID',
                'pAmount' => 'Summa',
            ],
            'ru' => [
                'dashboard' => 'Dashboard',
                'sheetShifts' => 'Istoriya Smen',
                'sheetPeaks' => 'Top Piki',
                'title' => 'Export istorii smen',
                'period' => 'Period',
                'generated' => 'Sformirovano',
                'kpi' => 'Klyuchevye KPI',
                'insights' => 'Analiticheskie signaly',
                'topShortage' => 'Smeny s maksimalnoy nedostachey',
                'id' => 'ID',
                'closedAt' => 'Zakryta',
                'operator' => 'Operator',
                'expected' => 'Expected',
                'closing' => 'Closing',
                'diff' => 'Diff',
                'status' => 'Status',
                'signals' => 'Signal',
                'kpiShifts' => 'Kolichestvo smen',
                'kpiCashIn' => 'Nalichnyy prihod',
                'kpiCardIn' => 'Kartochniy prihod',
                'kpiReturns' => 'Vozvraty itogo',
                'kpiExpenses' => 'Rashody itogo',
                'kpiExpected' => 'Expected summa',
                'kpiClosing' => 'Closing summa',
                'kpiNet' => 'Net cash',
                'kpiOver' => 'Overage summa',
                'kpiShort' => 'Shortage summa',
                'kpiRecon' => 'Dolya reconciled',
                'matched' => 'Skhoditsya',
                'carried' => 'Pereneseno',
                'partial' => 'Chastichno pereneseno',
                'overage' => 'Izlishek',
                'shortage' => 'Nedostacha',
                'shiftSignalsShort' => 'Nizhe ozhidaemogo: {amount}',
                'shiftSignalsOver' => 'Vyshe ozhidaemogo: {amount}',
                'shiftSignalsExpense' => 'Davlenie rashodov: {ratio}%',
                'shiftSignalsReturns' => 'Dolya cash-vozvratov: {ratio}%',
                'shiftSignalsOps' => 'Mnogo nalichnyh operaciy: {count}',
                'shiftSignalsOk' => 'Silnyh risk-signalov net',
                'insightShortage' => 'Obshchaya nedostacha: {value}. Rekomenduetsya proverit kasovuyu disciplinu.',
                'insightReconLow' => 'Nizkaya dolya reconciled: {value}%. Pereproverte process zakrytiya smeny.',
                'insightExpenseHigh' => 'Vysokoe davlenie rashodov: {value}% ot cash in.',
                'insightReturnsHigh' => 'Vysokaya dolya cash-vozvratov: {value}% ot cash in.',
                'insightStable' => 'Pokazateli stabilny, rezkih riskov ne naydeno.',
                'sShiftId' => 'Shift ID',
                'sOpenedAt' => 'Opened at',
                'sClosedAt' => 'Closed at',
                'sOpenedBy' => 'Opened by',
                'sClosedBy' => 'Closed by',
                'sOpeningCash' => 'Opening cash',
                'sCashIn' => 'Cash in',
                'sCardIn' => 'Card in',
                'sGrossIn' => 'Gross in',
                'sReturnsCash' => 'Returns cash',
                'sReturnsTotal' => 'Returns total',
                'sExpenses' => 'Expenses',
                'sExpected' => 'Expected',
                'sClosing' => 'Closing',
                'sDiff' => 'Diff',
                'sNet' => 'Net',
                'sStatus' => 'Status',
                'sSignal' => 'Signal',
                'pType' => 'Tip',
                'pShiftId' => 'Shift ID',
                'pWhen' => 'Vremya',
                'pName' => 'Nazvanie',
                'pCategory' => 'Kategoriya',
                'pClientId' => 'Client ID',
                'pAmount' => 'Summa',
            ],
            'en' => [
                'dashboard' => 'Dashboard',
                'sheetShifts' => 'Shift History',
                'sheetPeaks' => 'Top Peaks',
                'title' => 'Shift History Export',
                'period' => 'Period',
                'generated' => 'Generated at',
                'kpi' => 'Core KPI',
                'insights' => 'AI-style analytical signals',
                'topShortage' => 'Highest-shortage shifts',
                'id' => 'ID',
                'closedAt' => 'Closed at',
                'operator' => 'Operator',
                'expected' => 'Expected',
                'closing' => 'Closing',
                'diff' => 'Diff',
                'status' => 'Status',
                'signals' => 'Signal',
                'kpiShifts' => 'Shifts count',
                'kpiCashIn' => 'Cash in',
                'kpiCardIn' => 'Card in',
                'kpiReturns' => 'Returns total',
                'kpiExpenses' => 'Expenses total',
                'kpiExpected' => 'Expected sum',
                'kpiClosing' => 'Closing sum',
                'kpiNet' => 'Net cash',
                'kpiOver' => 'Overage sum',
                'kpiShort' => 'Shortage sum',
                'kpiRecon' => 'Reconciled ratio',
                'matched' => 'Matched',
                'carried' => 'Carried',
                'partial' => 'Partially carried',
                'overage' => 'Overage',
                'shortage' => 'Shortage',
                'shiftSignalsShort' => 'Below expected: {amount}',
                'shiftSignalsOver' => 'Above expected: {amount}',
                'shiftSignalsExpense' => 'Expense pressure: {ratio}%',
                'shiftSignalsReturns' => 'Cash return share: {ratio}%',
                'shiftSignalsOps' => 'High cash ops count: {count}',
                'shiftSignalsOk' => 'No strong risk signals',
                'insightShortage' => 'Total shortage: {value}. Cash discipline review is recommended.',
                'insightReconLow' => 'Low reconciled ratio: {value}%. Review shift-closing workflow.',
                'insightExpenseHigh' => 'High expense pressure: {value}% of cash in.',
                'insightReturnsHigh' => 'High cash-return share: {value}% of cash in.',
                'insightStable' => 'Metrics look stable, no sharp risk signal detected.',
                'sShiftId' => 'Shift ID',
                'sOpenedAt' => 'Opened at',
                'sClosedAt' => 'Closed at',
                'sOpenedBy' => 'Opened by',
                'sClosedBy' => 'Closed by',
                'sOpeningCash' => 'Opening cash',
                'sCashIn' => 'Cash in',
                'sCardIn' => 'Card in',
                'sGrossIn' => 'Gross in',
                'sReturnsCash' => 'Returns cash',
                'sReturnsTotal' => 'Returns total',
                'sExpenses' => 'Expenses',
                'sExpected' => 'Expected',
                'sClosing' => 'Closing',
                'sDiff' => 'Diff',
                'sNet' => 'Net',
                'sStatus' => 'Status',
                'sSignal' => 'Signal',
                'pType' => 'Type',
                'pShiftId' => 'Shift ID',
                'pWhen' => 'When',
                'pName' => 'Name',
                'pCategory' => 'Category',
                'pClientId' => 'Client ID',
                'pAmount' => 'Amount',
            ],
        ];

        return $dict[$lang] ?? $dict['uz'];
    }

    private function text(string $lang, string $key, array $params = []): string
    {
        $text = $this->labels($lang)[$key] ?? $this->labels('uz')[$key] ?? $key;

        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }

        return $text;
    }

    private function xmlRow(array $cells): string
    {
        $payload = array_map(function ($value) {
            $style = is_numeric($value) ? ' ss:StyleID="Currency"' : '';
            $type = is_numeric($value) ? 'Number' : 'String';
            $content = is_numeric($value) ? (string) (0 + $value) : $this->xmlEsc((string) $value);

            if ($value === '' || $value === null) {
                return '<Cell/>';
            }

            return '<Cell' . $style . '><Data ss:Type="' . $type . '">' . $content . '</Data></Cell>';
        }, $cells);

        return '<Row>' . implode('', $payload) . '</Row>';
    }

    private function xmlEsc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function xlsxSheetXml(array $rows): string
    {
        $xml = [];
        $xml[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml[] = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $xml[] = '<sheetData>';

        foreach ($rows as $rowIndex => $row) {
            $xml[] = '<row r="' . ($rowIndex + 1) . '">';
            foreach ($row as $colIndex => $value) {
                $ref = $this->xlsxColName($colIndex + 1) . ($rowIndex + 1);
                if ($value === '' || $value === null) {
                    $xml[] = '<c r="' . $ref . '"/>';
                    continue;
                }

                if (is_numeric($value)) {
                    $xml[] = '<c r="' . $ref . '"><v>' . (0 + $value) . '</v></c>';
                    continue;
                }

                $xml[] = '<c r="' . $ref . '" t="inlineStr"><is><t>'
                    . $this->xmlEsc((string) $value)
                    . '</t></is></c>';
            }
            $xml[] = '</row>';
        }

        $xml[] = '</sheetData></worksheet>';

        return implode('', $xml);
    }

    private function xlsxColName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $name = chr(65 + $mod) . $name;
            $index = (int) (($index - 1) / 26);
        }

        return $name ?: 'A';
    }

    private function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbookXml(array $labels): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>'
            . '<sheet name="' . $this->xmlEsc($labels['dashboard']) . '" sheetId="1" r:id="rId1"/>'
            . '<sheet name="' . $this->xmlEsc($labels['sheetShifts']) . '" sheetId="2" r:id="rId2"/>'
            . '<sheet name="' . $this->xmlEsc($labels['sheetPeaks']) . '" sheetId="3" r:id="rId3"/>'
            . '</sheets>'
            . '</workbook>';
    }

    private function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>'
            . '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="10"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
}
