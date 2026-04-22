<?php

namespace App\Actions\Booking;

use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListBookingsAction
{
    public function execute(int $tenantId, array $filters): LengthAwarePaginator
    {
        $query = Booking::query()
            ->where('tenant_id', $tenantId)
            ->with([
                'pc:id,tenant_id,code,status',
                'client:id,tenant_id,account_id,login,phone',
                'creator:id,tenant_id,login,name',
            ])
            ->orderByDesc('start_at');

        if (!empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (!empty($filters['pc_id'])) {
            $query->where('pc_id', (int) $filters['pc_id']);
        }

        if (!empty($filters['client_id'])) {
            $query->where('client_id', (int) $filters['client_id']);
        }

        if (!empty($filters['from'])) {
            $query->where('start_at', '>=', Carbon::parse((string) $filters['from'])->startOfDay());
        }

        if (!empty($filters['to'])) {
            $query->where('start_at', '<=', Carbon::parse((string) $filters['to'])->endOfDay());
        }

        return $query->paginate((int) ($filters['per_page'] ?? 20));
    }
}
