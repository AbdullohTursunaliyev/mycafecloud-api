<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PackageController extends Controller
{
    private function tenantId(): int
    {
        $op = auth('operator')->user();
        return (int)($op->tenant_id ?? 0);
    }

    public function index(Request $request)
    {
        $tenantId = $this->tenantId();

        $q = trim((string)$request->query('q', ''));
        $active = $request->query('active', null); // "true" | "false" | null
        $perPage = (int)($request->query('per_page', 20));
        $perPage = max(1, min($perPage, 100));

        $query = Package::query()
            ->where('tenant_id', $tenantId);

        if ($q !== '') {
            // PostgreSQL bo'lsa ILIKE, bo'lmasa LIKE
            $driver = \DB::getDriverName();
            if ($driver === 'pgsql') {
                $query->where('name', 'ILIKE', '%' . $q . '%');
            } else {
                $query->where('name', 'LIKE', '%' . $q . '%');
            }
        }

        if ($active !== null && $active !== '') {
            $bool = filter_var($active, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($bool !== null) {
                $query->where('is_active', $bool);
            }
        }

        $query->orderByDesc('is_active')->orderByDesc('id');

        // paginate frontga qulay
        $pag = $query->paginate($perPage);

        return response()->json([
            'data' => $pag,
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = $this->tenantId();

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'duration_min' => ['required', 'integer', 'min:1', 'max:1000000'],
            'price' => ['required', 'integer', 'min:0', 'max:2000000000'],
            'zone' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $pkg = Package::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'duration_min' => (int)$data['duration_min'],
            'price' => (int)$data['price'],
            'zone' => $data['zone'] ?? null,
            'is_active' => array_key_exists('is_active', $data) ? (bool)$data['is_active'] : true,
        ]);

        return response()->json([
            'data' => $pkg,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $tenantId = $this->tenantId();

        $pkg = Package::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'duration_min' => ['sometimes', 'required', 'integer', 'min:1', 'max:1000000'],
            'price' => ['sometimes', 'required', 'integer', 'min:0', 'max:2000000000'],
            'zone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $pkg->fill($data);
        $pkg->save();

        return response()->json([
            'data' => $pkg,
        ]);
    }

    public function toggle($id)
    {
        $tenantId = $this->tenantId();

        $pkg = Package::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $pkg->is_active = !$pkg->is_active;
        $pkg->save();

        return response()->json([
            'data' => $pkg,
        ]);
    }
}
