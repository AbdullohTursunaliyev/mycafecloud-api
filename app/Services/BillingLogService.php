<?php

namespace App\Services;

use App\Models\SessionBillingLog;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BillingLogService
{
    public function paginate(int $tenantId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->baseQuery($tenantId, $filters);

        return $query
            ->orderByDesc('session_billing_logs.id')
            ->paginate($perPage);
    }

    public function summary(int $tenantId, array $filters): Collection
    {
        $query = $this->summaryQuery($tenantId, $filters);

        return $query
            ->selectRaw('date(session_billing_logs.created_at) as day')
            ->selectRaw('sum(session_billing_logs.amount) as amount_sum')
            ->selectRaw('sum(session_billing_logs.minutes) as minutes_sum')
            ->selectRaw('count(*) as cnt')
            ->groupBy(DB::raw('date(session_billing_logs.created_at)'))
            ->orderBy(DB::raw('date(session_billing_logs.created_at)'))
            ->get();
    }

    private function baseQuery(int $tenantId, array $filters)
    {
        $query = SessionBillingLog::query()
            ->where('session_billing_logs.tenant_id', $tenantId)
            ->leftJoin('clients as c', 'c.id', '=', 'session_billing_logs.client_id')
            ->leftJoin('pcs as p', 'p.id', '=', 'session_billing_logs.pc_id')
            ->select([
                'session_billing_logs.*',
                'c.login as client_login',
                'p.code as pc_code',
            ]);

        return $this->applyFilters($query, $filters, true);
    }

    private function summaryQuery(int $tenantId, array $filters)
    {
        $query = SessionBillingLog::query()
            ->where('session_billing_logs.tenant_id', $tenantId);

        return $this->applyFilters($query, $filters, !empty($filters['search']));
    }

    private function applyFilters($query, array $filters, bool $withJoinsForSearch)
    {
        if (!empty($filters['from']) && $filters['from'] instanceof Carbon) {
            $query->where('session_billing_logs.created_at', '>=', $filters['from']);
        }
        if (!empty($filters['to']) && $filters['to'] instanceof Carbon) {
            $query->where('session_billing_logs.created_at', '<=', $filters['to']);
        }
        if (!empty($filters['session_id'])) {
            $query->where('session_billing_logs.session_id', (int) $filters['session_id']);
        }
        if (!empty($filters['client_id'])) {
            $query->where('session_billing_logs.client_id', (int) $filters['client_id']);
        }
        if (!empty($filters['pc_id'])) {
            $query->where('session_billing_logs.pc_id', (int) $filters['pc_id']);
        }
        if (!empty($filters['mode'])) {
            $query->where('session_billing_logs.mode', (string) $filters['mode']);
        }
        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            if ($withJoinsForSearch) {
                $query->leftJoin('clients as c', 'c.id', '=', 'session_billing_logs.client_id')
                    ->leftJoin('pcs as p', 'p.id', '=', 'session_billing_logs.pc_id');
            }
            $query->where(function ($builder) use ($search) {
                $builder->where('c.login', 'ilike', '%' . $search . '%')
                    ->orWhere('p.code', 'ilike', '%' . $search . '%');
                if (ctype_digit($search)) {
                    $builder->orWhere('session_billing_logs.session_id', (int) $search);
                }
            });
        }

        return $query;
    }
}
