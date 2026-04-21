<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SessionBillingLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillingLogController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'session_id' => ['nullable', 'integer', 'min:1'],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'pc_id' => ['nullable', 'integer', 'min:1'],
            'mode' => ['nullable', 'in:wallet,package'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $listQuery = SessionBillingLog::query()
            ->where('session_billing_logs.tenant_id', $tenantId)
            ->leftJoin('clients as c', 'c.id', '=', 'session_billing_logs.client_id')
            ->leftJoin('pcs as p', 'p.id', '=', 'session_billing_logs.pc_id')
            ->select([
                'session_billing_logs.*',
                'c.login as client_login',
                'p.code as pc_code',
            ]);

        if (!empty($data['from'])) {
            $from = Carbon::parse($data['from'])->startOfDay();
            $listQuery->where('session_billing_logs.created_at', '>=', $from);
        }
        if (!empty($data['to'])) {
            $to = Carbon::parse($data['to'])->endOfDay();
            $listQuery->where('session_billing_logs.created_at', '<=', $to);
        }
        if (!empty($data['session_id'])) {
            $listQuery->where('session_billing_logs.session_id', (int) $data['session_id']);
        }
        if (!empty($data['client_id'])) {
            $listQuery->where('session_billing_logs.client_id', (int) $data['client_id']);
        }
        if (!empty($data['pc_id'])) {
            $listQuery->where('session_billing_logs.pc_id', (int) $data['pc_id']);
        }
        if (!empty($data['mode'])) {
            $listQuery->where('session_billing_logs.mode', $data['mode']);
        }
        if (!empty($data['search'])) {
            $search = trim((string) $data['search']);
            $listQuery->where(function ($w) use ($search) {
                $w->where('c.login', 'ilike', '%' . $search . '%')
                    ->orWhere('p.code', 'ilike', '%' . $search . '%');
                if (ctype_digit($search)) {
                    $w->orWhere('session_billing_logs.session_id', (int) $search);
                }
            });
        }

        if ($request->boolean('summary')) {
            $summary = SessionBillingLog::query()
                ->where('session_billing_logs.tenant_id', $tenantId);

            if (!empty($data['from'])) {
                $summary->where('session_billing_logs.created_at', '>=', Carbon::parse($data['from'])->startOfDay());
            }
            if (!empty($data['to'])) {
                $summary->where('session_billing_logs.created_at', '<=', Carbon::parse($data['to'])->endOfDay());
            }
            if (!empty($data['session_id'])) {
                $summary->where('session_billing_logs.session_id', (int) $data['session_id']);
            }
            if (!empty($data['client_id'])) {
                $summary->where('session_billing_logs.client_id', (int) $data['client_id']);
            }
            if (!empty($data['pc_id'])) {
                $summary->where('session_billing_logs.pc_id', (int) $data['pc_id']);
            }
            if (!empty($data['mode'])) {
                $summary->where('session_billing_logs.mode', $data['mode']);
            }
            if (!empty($data['search'])) {
                $search = trim((string) $data['search']);
                $summary->leftJoin('clients as c', 'c.id', '=', 'session_billing_logs.client_id')
                    ->leftJoin('pcs as p', 'p.id', '=', 'session_billing_logs.pc_id')
                    ->where(function ($w) use ($search) {
                        $w->where('c.login', 'ilike', '%' . $search . '%')
                            ->orWhere('p.code', 'ilike', '%' . $search . '%');
                        if (ctype_digit($search)) {
                            $w->orWhere('session_billing_logs.session_id', (int) $search);
                        }
                    });
            }

            $summary->selectRaw("date(session_billing_logs.created_at) as day")
                ->selectRaw("sum(session_billing_logs.amount) as amount_sum")
                ->selectRaw("sum(session_billing_logs.minutes) as minutes_sum")
                ->selectRaw("count(*) as cnt")
                ->groupBy(DB::raw('date(session_billing_logs.created_at)'))
                ->orderBy(DB::raw('date(session_billing_logs.created_at)'));

            return response()->json([
                'data' => $summary->get(),
            ]);
        }

        $perPage = (int) ($data['per_page'] ?? 50);
        $rows = (clone $listQuery)
            ->orderByDesc('session_billing_logs.id')
            ->paginate($perPage);

        return response()->json($rows);
    }
}
