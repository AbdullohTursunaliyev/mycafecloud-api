<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Models\Client;
use App\Models\ClientTransaction;
use App\Models\Session;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TenantReportSummaryService
{
    public function buildCashBreakdown(int $tenantId, Carbon $from, Carbon $to): array
    {
        $cash = PaymentMethod::Cash->value;
        $card = PaymentMethod::Card->value;

        $topups = ClientTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'topup')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("
                SUM(CASE WHEN payment_method = ? THEN amount ELSE 0 END) as cash,
                SUM(CASE WHEN payment_method = ? THEN amount ELSE 0 END) as card
            ", [$cash, $card])
            ->first();

        $packages = ClientTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'package')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("
                SUM(CASE WHEN payment_method = ? THEN amount ELSE 0 END) as cash,
                SUM(CASE WHEN payment_method = ? THEN amount ELSE 0 END) as card
            ", [$cash, $card])
            ->first();

        $subscriptions = ClientTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'subscription')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("
                SUM(CASE WHEN payment_method = ? THEN amount ELSE 0 END) as cash,
                SUM(CASE WHEN payment_method = ? THEN amount ELSE 0 END) as card
            ", [$cash, $card])
            ->first();

        return [
            'topups' => [
                'cash' => (int) ($topups->cash ?? 0),
                'card' => (int) ($topups->card ?? 0),
            ],
            'packages' => [
                'cash' => (int) ($packages->cash ?? 0),
                'card' => (int) ($packages->card ?? 0),
            ],
            'subscriptions' => [
                'cash' => (int) ($subscriptions->cash ?? 0),
                'card' => (int) ($subscriptions->card ?? 0),
            ],
            'total_cash' =>
                (int) ($topups->cash ?? 0) +
                (int) ($packages->cash ?? 0) +
                (int) ($subscriptions->cash ?? 0),
            'total_card' =>
                (int) ($topups->card ?? 0) +
                (int) ($packages->card ?? 0) +
                (int) ($subscriptions->card ?? 0),
        ];
    }

    public function buildSessionsSummary(int $tenantId, Carbon $from, Carbon $to): array
    {
        $rows = Session::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('started_at', [$from, $to])
            ->selectRaw("
                COUNT(*) as sessions_count,
                SUM(price_total) as revenue,
                AVG(EXTRACT(EPOCH FROM (COALESCE(ended_at, NOW()) - started_at)) / 60) as avg_minutes
            ")
            ->first();

        return [
            'sessions_count' => (int) ($rows->sessions_count ?? 0),
            'revenue' => (int) ($rows->revenue ?? 0),
            'avg_minutes' => round((float) ($rows->avg_minutes ?? 0), 1),
        ];
    }

    public function buildTopClients(int $tenantId, Carbon $from, Carbon $to, int $limit = 10): array
    {
        $clients = Session::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('started_at', [$from, $to])
            ->whereNotNull('client_id')
            ->select(
                'client_id',
                DB::raw('SUM(price_total) as total_spent'),
                DB::raw('COUNT(*) as sessions_count'),
            )
            ->groupBy('client_id')
            ->orderByDesc('total_spent')
            ->limit($limit)
            ->get();

        $clientIds = $clients->pluck('client_id')->all();
        $clientsMap = Client::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $clientIds)
            ->get(['id', 'account_id', 'login', 'phone'])
            ->keyBy('id');

        return $clients->map(function ($row) use ($clientsMap) {
            $client = $clientsMap->get((int) $row->client_id);

            return [
                'client_id' => (int) $row->client_id,
                'total_spent' => (int) ($row->total_spent ?? 0),
                'sessions_count' => (int) ($row->sessions_count ?? 0),
                'client' => $client ? [
                    'id' => (int) $client->id,
                    'account_id' => $client->account_id,
                    'login' => $client->login,
                    'phone' => $client->phone,
                ] : null,
            ];
        })->values()->all();
    }
}
