<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LogController extends Controller
{
    public function index(Request $request)
    {
        $operator = $request->user('operator') ?: $request->user();
        $tenantId = (int)$operator->tenant_id;

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'type' => ['nullable', 'string', 'max:40'],
            'source' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'string', 'max:40'],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $to = isset($data['to'])
            ? Carbon::parse($data['to'])->endOfDay()
            : now()->endOfDay();

        $from = isset($data['from'])
            ? Carbon::parse($data['from'])->startOfDay()
            : $to->copy()->subDays(6)->startOfDay();

        $transactions = DB::table('client_transactions as ct')
            ->leftJoin('operators as o', 'o.id', '=', 'ct.operator_id')
            ->leftJoin('clients as c', 'c.id', '=', 'ct.client_id')
            ->where('ct.tenant_id', $tenantId)
            ->whereBetween('ct.created_at', [$from, $to])
            ->selectRaw("
                ct.id::bigint as row_id,
                ct.created_at as happened_at,
                'transaction'::text as type,
                ct.type::text as action,
                'operator'::text as source,
                NULL::text as status,
                ct.amount::bigint as amount,
                ct.operator_id::bigint as operator_id,
                COALESCE(o.login, o.name)::text as operator_name,
                NULL::bigint as pc_id,
                NULL::text as pc_code,
                ct.client_id::bigint as client_id,
                COALESCE(c.login, c.account_id, c.phone)::text as client_label,
                ct.payment_method::text as note
            ");

        $returns = DB::table('returns as r')
            ->leftJoin('operators as o', 'o.id', '=', 'r.operator_id')
            ->leftJoin('clients as c', 'c.id', '=', 'r.client_id')
            ->where('r.tenant_id', $tenantId)
            ->whereBetween('r.created_at', [$from, $to])
            ->selectRaw("
                r.id::bigint as row_id,
                r.created_at as happened_at,
                'return'::text as type,
                r.type::text as action,
                'operator'::text as source,
                NULL::text as status,
                r.amount::bigint as amount,
                r.operator_id::bigint as operator_id,
                COALESCE(o.login, o.name)::text as operator_name,
                NULL::bigint as pc_id,
                NULL::text as pc_code,
                r.client_id::bigint as client_id,
                COALESCE(c.login, c.account_id, c.phone)::text as client_label,
                r.payment_method::text as note
            ");

        $transfers = DB::table('client_transfers as t')
            ->leftJoin('operators as o', 'o.id', '=', 't.operator_id')
            ->leftJoin('clients as fc', 'fc.id', '=', 't.from_client_id')
            ->leftJoin('clients as tc', 'tc.id', '=', 't.to_client_id')
            ->where('t.tenant_id', $tenantId)
            ->whereBetween('t.created_at', [$from, $to])
            ->selectRaw("
                t.id::bigint as row_id,
                t.created_at as happened_at,
                'transfer'::text as type,
                'balance_transfer'::text as action,
                'operator'::text as source,
                NULL::text as status,
                t.amount::bigint as amount,
                t.operator_id::bigint as operator_id,
                COALESCE(o.login, o.name)::text as operator_name,
                NULL::bigint as pc_id,
                NULL::text as pc_code,
                t.from_client_id::bigint as client_id,
                COALESCE(fc.login, fc.account_id, fc.phone)::text as client_label,
                COALESCE(tc.login, tc.account_id, tc.phone)::text as note
            ");

        $commands = DB::table('pc_commands as pcmd')
            ->leftJoin('pcs as p', 'p.id', '=', 'pcmd.pc_id')
            ->where('pcmd.tenant_id', $tenantId)
            ->whereBetween('pcmd.created_at', [$from, $to])
            ->selectRaw("
                pcmd.id::bigint as row_id,
                pcmd.created_at as happened_at,
                'pc_command'::text as type,
                pcmd.type::text as action,
                'operator'::text as source,
                pcmd.status::text as status,
                NULL::bigint as amount,
                NULL::bigint as operator_id,
                NULL::text as operator_name,
                pcmd.pc_id::bigint as pc_id,
                COALESCE(p.code, CONCAT('PC #', pcmd.pc_id))::text as pc_code,
                NULL::bigint as client_id,
                NULL::text as client_label,
                COALESCE(pcmd.error, '')::text as note
            ");

        $sessionsStarted = DB::table('sessions as s')
            ->leftJoin('operators as o', 'o.id', '=', 's.operator_id')
            ->leftJoin('clients as c', 'c.id', '=', 's.client_id')
            ->leftJoin('pcs as p', 'p.id', '=', 's.pc_id')
            ->where('s.tenant_id', $tenantId)
            ->whereBetween('s.started_at', [$from, $to])
            ->selectRaw("
                s.id::bigint as row_id,
                s.started_at as happened_at,
                'session'::text as type,
                'started'::text as action,
                'operator'::text as source,
                s.status::text as status,
                NULL::bigint as amount,
                s.operator_id::bigint as operator_id,
                COALESCE(o.login, o.name)::text as operator_name,
                s.pc_id::bigint as pc_id,
                COALESCE(p.code, CONCAT('PC #', s.pc_id))::text as pc_code,
                s.client_id::bigint as client_id,
                COALESCE(c.login, c.account_id, c.phone)::text as client_label,
                NULL::text as note
            ");

        $sessionsEnded = DB::table('sessions as s')
            ->leftJoin('operators as o', 'o.id', '=', 's.operator_id')
            ->leftJoin('clients as c', 'c.id', '=', 's.client_id')
            ->leftJoin('pcs as p', 'p.id', '=', 's.pc_id')
            ->where('s.tenant_id', $tenantId)
            ->whereNotNull('s.ended_at')
            ->whereBetween('s.ended_at', [$from, $to])
            ->selectRaw("
                s.id::bigint as row_id,
                s.ended_at as happened_at,
                'session'::text as type,
                'ended'::text as action,
                'operator'::text as source,
                s.status::text as status,
                s.price_total::bigint as amount,
                s.operator_id::bigint as operator_id,
                COALESCE(o.login, o.name)::text as operator_name,
                s.pc_id::bigint as pc_id,
                COALESCE(p.code, CONCAT('PC #', s.pc_id))::text as pc_code,
                s.client_id::bigint as client_id,
                COALESCE(c.login, c.account_id, c.phone)::text as client_label,
                NULL::text as note
            ");

        $union = $transactions
            ->unionAll($returns)
            ->unionAll($transfers)
            ->unionAll($commands)
            ->unionAll($sessionsStarted)
            ->unionAll($sessionsEnded);

        $query = DB::query()->fromSub($union, 'logs');

        if (!empty($data['type'])) {
            $query->where('type', $data['type']);
        }
        if (!empty($data['source'])) {
            $query->where('source', $data['source']);
        }
        if (!empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (!empty($data['search'])) {
            $term = '%' . mb_strtolower($data['search']) . '%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw("LOWER(COALESCE(operator_name, '')) LIKE ?", [$term])
                    ->orWhereRaw("LOWER(COALESCE(client_label, '')) LIKE ?", [$term])
                    ->orWhereRaw("LOWER(COALESCE(pc_code, '')) LIKE ?", [$term])
                    ->orWhereRaw("LOWER(COALESCE(action, '')) LIKE ?", [$term])
                    ->orWhereRaw("LOWER(COALESCE(note, '')) LIKE ?", [$term]);
            });
        }

        $page = (int)($data['page'] ?? 1);
        $perPage = (int)($data['per_page'] ?? 50);
        $offset = ($page - 1) * $perPage;
        $total = (clone $query)->count();

        $rows = (clone $query)
            ->orderByDesc('happened_at')
            ->offset($offset)
            ->limit($perPage)
            ->get()
            ->map(function ($row) {
                return [
                    'id' => $row->type . ':' . $row->row_id . ':' . $row->happened_at,
                    'happened_at' => $row->happened_at,
                    'type' => (string)$row->type,
                    'action' => (string)$row->action,
                    'source' => (string)$row->source,
                    'status' => $row->status,
                    'amount' => $row->amount === null ? null : (int)$row->amount,
                    'operator' => [
                        'id' => $row->operator_id === null ? null : (int)$row->operator_id,
                        'name' => $row->operator_name,
                    ],
                    'pc' => [
                        'id' => $row->pc_id === null ? null : (int)$row->pc_id,
                        'code' => $row->pc_code,
                    ],
                    'client' => [
                        'id' => $row->client_id === null ? null : (int)$row->client_id,
                        'label' => $row->client_label,
                    ],
                    'note' => $row->note,
                ];
            })
            ->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => (int)$total,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
        ]);
    }
}
