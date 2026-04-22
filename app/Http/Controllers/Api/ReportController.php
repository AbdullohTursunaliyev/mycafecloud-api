<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AiInsightsReportRequest;
use App\Http\Requests\Api\ApplyAutopilotRequest;
use App\Http\Requests\Api\BranchCompareReportRequest;
use App\Http\Requests\Api\ExchangeConfigRequest;
use App\Http\Requests\Api\ReportRangeRequest;
use App\Http\Resources\Report\AbCompareReportResource;
use App\Http\Resources\Report\AiInsightsReportResource;
use App\Http\Resources\Report\AutopilotApplyResource;
use App\Http\Resources\Report\AutopilotPlanResource;
use App\Http\Resources\Report\BranchCompareReportResource;
use App\Http\Resources\Report\CashReportResource;
use App\Http\Resources\Report\ExchangeConfigResource;
use App\Http\Resources\Report\ExchangeReportResource;
use App\Http\Resources\Report\LostRevenueReportResource;
use App\Http\Resources\Report\MonthlyPdfReportResource;
use App\Http\Resources\Report\OverviewReportResource;
use App\Http\Resources\Report\SessionsReportResource;
use App\Http\Resources\Report\TopClientsReportResource;
use App\Http\Resources\Report\ZoneProfitabilityReportResource;
use App\Services\ExchangeNetworkService;
use App\Services\MonthlySummaryPdfService;
use App\Services\ReportAutopilotService;
use App\Services\ReportDashboardService;
use App\Services\TenantReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public function __construct(
        private readonly TenantReportService $reports,
        private readonly ReportDashboardService $dashboard,
        private readonly MonthlySummaryPdfService $monthlyPdfs,
        private readonly ReportAutopilotService $autopilot,
        private readonly ExchangeNetworkService $exchangeNetwork,
    ) {}

    public function cash(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'from' => ['required','date'],
            'to' => ['required','date'],
        ]);

        $from = Carbon::parse($data['from'])->startOfDay();
        $to   = Carbon::parse($data['to'])->endOfDay();

        return new CashReportResource(
            $this->reports->buildCashBreakdown((int) $tenantId, $from, $to)
        );
    }

    public function sessions(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'from' => ['required','date'],
            'to' => ['required','date'],
        ]);

        $from = Carbon::parse($data['from'])->startOfDay();
        $to   = Carbon::parse($data['to'])->endOfDay();

        return new SessionsReportResource(
            $this->reports->buildSessionsSummary((int) $tenantId, $from, $to)
        );
    }

    public function topClients(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'from' => ['required','date'],
            'to' => ['required','date'],
        ]);

        $from = Carbon::parse($data['from'])->startOfDay();
        $to   = Carbon::parse($data['to'])->endOfDay();

        return new TopClientsReportResource(
            $this->reports->buildTopClients((int) $tenantId, $from, $to)
        );
    }

    public function overview(ReportRangeRequest $request)
    {
        $operator = $request->user('operator') ?: $request->user();
        [$from, $to] = $request->resolvedRange();

        return new OverviewReportResource(
            $this->dashboard->buildOverview((int) $operator->tenant_id, $from, $to)
        );
    }

    public function aiInsights(AiInsightsReportRequest $request)
    {
        $operator = $request->user('operator') ?: $request->user();
        $tenantId = (int) $operator->tenant_id;
        if ($request->query('range') === 'all') {
            $from = $this->reports->resolveAllTimeStart($tenantId);
            $to = now()->endOfDay();
        } else {
            [$from, $to] = $request->resolvedRange();
        }

        return new AiInsightsReportResource(
            $this->dashboard->buildAiInsights($tenantId, $from, $to)
        );
    }

    public function lostRevenue(ReportRangeRequest $request)
    {
        $operator = $request->user('operator') ?: $request->user();
        [$from, $to] = $request->resolvedRange();

        return new LostRevenueReportResource(
            $this->reports->buildLostRevenue((int) $operator->tenant_id, $from, $to)
        );
    }

    public function zoneProfitability(ReportRangeRequest $request)
    {
        $operator = $request->user('operator') ?: $request->user();
        [$from, $to] = $request->resolvedRange();

        return new ZoneProfitabilityReportResource(
            $this->reports->buildZoneProfitability((int) $operator->tenant_id, $from, $to)
        );
    }

    public function abCompare(Request $request)
    {
        $operator = $request->user('operator') ?: $request->user();

        $payload = $request->validate([
            'from_a' => ['required', 'date'],
            'to_a' => ['required', 'date', 'after_or_equal:from_a'],
            'from_b' => ['required', 'date'],
            'to_b' => ['required', 'date', 'after_or_equal:from_b'],
        ]);

        return new AbCompareReportResource(
            $this->reports->buildAbComparison(
                (int) $operator->tenant_id,
                Carbon::parse($payload['from_a'])->startOfDay(),
                Carbon::parse($payload['to_a'])->endOfDay(),
                Carbon::parse($payload['from_b'])->startOfDay(),
                Carbon::parse($payload['to_b'])->endOfDay(),
            )
        );
    }

    public function monthlyPdf(Request $request)
    {
        $operator = $request->user('operator') ?: $request->user();
        $month = (string) $request->query('month', now()->format('Y-m'));

        try {
            $monthDate = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'month' => 'Month must be in YYYY-MM format',
            ]);
        }

        return new MonthlyPdfReportResource(
            $this->monthlyPdfs->build((int) $operator->tenant_id, $monthDate)
        );
    }

    public function branchCompare(BranchCompareReportRequest $request)
    {
        $operator = $request->user('operator') ?: $request->user();
        [$from, $to] = $request->resolvedRange();

        return new BranchCompareReportResource(
            $this->reports->buildBranchComparison(
                (int) $operator->tenant_id,
                (string) $request->validated('license_key'),
                $from,
                $to,
            )
        );
    }

    public function autopilot(ReportRangeRequest $request)
    {
        $operator = $request->user('operator') ?: $request->user();
        [$from, $to] = $request->resolvedRange();

        return new AutopilotPlanResource(
            $this->autopilot->buildPlan(
                (int) $operator->tenant_id,
                $from,
                $to,
                (string) $request->query('strategy', 'balanced'),
            )
        );
    }

    public function autopilotApply(ApplyAutopilotRequest $request)
    {
        $operator = $request->user('operator') ?: $request->user();
        $payload = $request->validated();
        [$from, $to] = $request->resolvedRange();

        return new AutopilotApplyResource(
            $this->autopilot->applyPlan(
                (int) $operator->tenant_id,
                (int) $operator->id,
                $from,
                $to,
                (string) ($payload['strategy'] ?? 'balanced'),
                array_key_exists('apply_zone_prices', $payload) ? (bool) $payload['apply_zone_prices'] : true,
                array_key_exists('apply_promotion', $payload) ? (bool) $payload['apply_promotion'] : true,
                array_key_exists('enable_beast_mode', $payload) ? (bool) $payload['enable_beast_mode'] : true,
                (bool) ($payload['dry_run'] ?? false),
            )
        );
    }

    public function exchange(ReportRangeRequest $request)
    {
        $operator = $request->user('operator') ?: $request->user();
        [$from, $to] = $request->resolvedRange();

        return new ExchangeReportResource(
            $this->exchangeNetwork->buildDashboard((int) $operator->tenant_id, $from, $to)
        );
    }

    public function exchangeConfig(ExchangeConfigRequest $request)
    {
        $operator = $request->user('operator') ?: $request->user();

        return new ExchangeConfigResource(
            $this->exchangeNetwork->saveConfig(
                (int) $operator->tenant_id,
                (int) $operator->id,
                $request->validated(),
            )
        );
    }

}
