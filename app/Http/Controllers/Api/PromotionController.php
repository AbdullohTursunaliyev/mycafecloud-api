<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PromotionController extends Controller
{
    // GET /api/promotions
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $q = Promotion::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('is_active')
            ->orderByDesc('priority')
            ->orderByDesc('id');

        if ($request->filled('active')) {
            $q->where('is_active', filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json([
            'data' => $q->paginate(min((int)($request->input('per_page', 20)), 50)),
        ]);
    }

    // POST /api/promotions
    public function store(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $data = $this->validatePromotion($request);

        $promo = Promotion::create(array_merge($data, [
            'tenant_id' => $tenantId,
        ]));

        return response()->json(['data' => $promo], 201);
    }

    // PATCH /api/promotions/{id}
    public function update(Request $request, int $id)
    {
        $tenantId = $request->user()->tenant_id;

        $promo = Promotion::where('tenant_id', $tenantId)->findOrFail($id);

        $data = $this->validatePromotion($request, true);

        $promo->update($data);

        return response()->json(['data' => $promo]);
    }

    // POST /api/promotions/{id}/toggle
    public function toggle(Request $request, int $id)
    {
        $tenantId = $request->user()->tenant_id;

        $promo = Promotion::where('tenant_id', $tenantId)->findOrFail($id);

        $promo->update(['is_active' => !$promo->is_active]);

        return response()->json(['data' => $promo]);
    }

    private function validatePromotion(Request $request, bool $isUpdate = false): array
    {
        $rules = [
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'min:3', 'max:120'],
            'type' => [$isUpdate ? 'sometimes' : 'required', 'string', Rule::in(['double_topup'])],
            'applies_payment_method' => [$isUpdate ? 'sometimes' : 'required', 'string', Rule::in(['cash'])],
            'priority' => [$isUpdate ? 'sometimes' : 'nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => [$isUpdate ? 'sometimes' : 'nullable', 'boolean'],

            'days_of_week' => [$isUpdate ? 'sometimes' : 'nullable', 'array'],
            'days_of_week.*' => ['integer', Rule::in([0,1,2,3,4,5,6])],

            'time_from' => [$isUpdate ? 'sometimes' : 'nullable', 'nullable', 'date_format:H:i'],
            'time_to' => [$isUpdate ? 'sometimes' : 'nullable', 'nullable', 'date_format:H:i'],

            'starts_at' => [$isUpdate ? 'sometimes' : 'nullable', 'nullable', 'date'],
            'ends_at' => [$isUpdate ? 'sometimes' : 'nullable', 'nullable', 'date'],
        ];

        $data = $request->validate($rules);

        // normalize empty schedule fields
        if (array_key_exists('days_of_week', $data) && empty($data['days_of_week'])) {
            $data['days_of_week'] = null;
        }
        if (array_key_exists('priority', $data) && $data['priority'] === null) {
            $data['priority'] = 100;
        }

        // optional: ensure starts_at <= ends_at
        if (!empty($data['starts_at']) && !empty($data['ends_at'])) {
            $a = Carbon::parse($data['starts_at']);
            $b = Carbon::parse($data['ends_at']);
            if ($a->gt($b)) {
                abort(response()->json([
                    'message' => 'Дата начала не может быть больше даты окончания',
                    'errors' => ['starts_at' => ['Неверный период']]
                ], 422));
            }
        }

        return $data;
    }

    public function activeForTopup(Request $request)
    {
        $paymentMethod = $request->query('payment_method', 'cash');

        // Agar tenant ishlatsangiz — shu yerda tenant filter bo‘lishi kerak
        $tenantId = auth('operator')->user()->tenant_id;

        $now = Carbon::now(); // server timezone bo‘yicha

        // Postgres: extract(dow) => 0=Sunday..6=Saturday
        $dow = (int)$now->dayOfWeek; // Carbon dayOfWeek ham 0..6 (0=Sunday)

        $time = $now->format('H:i:s');

        $q = Promotion::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('applies_payment_method', $paymentMethod)

            // starts_at / ends_at (agar null bo‘lsa cheklanmaydi)
            ->where(function ($qq) use ($now) {
                $qq->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($qq) use ($now) {
                $qq->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })

            // days_of_week: agar bo‘sh bo‘lsa — har kuni; bo‘sh bo‘lmasa — ichida dow bo‘lishi shart
            ->where(function ($qq) use ($dow) {
                $qq->whereNull('days_of_week')
                    ->orWhereJsonLength('days_of_week', 0)
                    ->orWhereJsonContains('days_of_week', $dow);
            })

            // time_from/time_to:
            // null bo‘lsa — kun bo‘yi
            // agar time_to < time_from bo‘lsa — tunda kesib o‘tadi (masalan 22:00–06:00)
            ->where(function ($qq) use ($time) {
                $qq->whereNull('time_from')->whereNull('time_to')
                    ->orWhere(function ($q2) use ($time) {
                        $q2->whereNotNull('time_from')->whereNotNull('time_to')
                            ->whereRaw('time_from <= time_to')
                            ->whereRaw('?::time between time_from and time_to', [$time]);
                    })
                    ->orWhere(function ($q2) use ($time) {
                        $q2->whereNotNull('time_from')->whereNotNull('time_to')
                            ->whereRaw('time_from > time_to')
                            ->where(function ($q3) use ($time) {
                                $q3->whereRaw('?::time >= time_from', [$time])
                                    ->orWhereRaw('?::time <= time_to', [$time]);
                            });
                    });
            })

            ->orderBy('priority', 'asc')   // 1 eng kuchli bo‘lsa
            ->orderBy('id', 'desc');

        $promo = $q->first();

        // Frontga tushunarli format
        return response()->json([
            'data' => $promo ? [
                'id' => $promo->id,
                'name' => $promo->name,
                'type' => $promo->type,
                'applies_payment_method' => $promo->applies_payment_method,
                'days_of_week' => $promo->days_of_week,
                'time_from' => $promo->time_from,
                'time_to' => $promo->time_to,
                'starts_at' => $promo->starts_at,
                'ends_at' => $promo->ends_at,
                'priority' => $promo->priority,
                'is_active' => (bool)$promo->is_active,
            ] : null,
            'meta' => [
                'server_now' => $now->toDateTimeString(),
                'server_dow' => $dow,
                'server_time' => $time,
                'payment_method' => $paymentMethod,
            ]
        ]);
    }
}
