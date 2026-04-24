<?php

namespace App\Services;

use App\Models\LandingLead;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LandingLeadService
{
    public function create(array $payload, Request $request): LandingLead
    {
        return LandingLead::query()->create([
            ...$payload,
            'status' => 'new',
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = LandingLead::query()->orderByDesc('id');

        if (!empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (!empty($filters['plan_code'])) {
            $query->where('plan_code', (string) $filters['plan_code']);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $operator = DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
            $query->where(function ($builder) use ($operator, $search): void {
                $builder
                    ->where('club_name', $operator, '%' . $search . '%')
                    ->orWhere('city', $operator, '%' . $search . '%')
                    ->orWhere('contact', $operator, '%' . $search . '%');
            });
        }

        return $query->paginate(20);
    }

    public function updateStatus(int $id, string $status): LandingLead
    {
        $lead = LandingLead::query()->findOrFail($id);
        $lead->forceFill(['status' => $status])->save();

        return $lead->fresh();
    }
}
