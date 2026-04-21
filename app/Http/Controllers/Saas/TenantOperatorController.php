<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Models\Operator;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class TenantOperatorController extends Controller
{
    public function index($tenantId)
    {
        $tenant = Tenant::findOrFail($tenantId);

        return Operator::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('id', 'desc')
            ->get(['id','tenant_id','login','name','role','is_active','created_at']);
    }

    public function store(Request $request, $tenantId)
    {
        $tenant = Tenant::findOrFail($tenantId);

        $data = $request->validate([
            'login' => ['required','string','max:50', Rule::unique('operators','login')->where('tenant_id', $tenant->id)],
            'name' => ['nullable','string','max:100'],
            'password' => ['required','string','min:6'],
            'role' => ['required', Rule::in(['owner','admin','operator'])],
            'is_active' => ['boolean'],
        ]);

        $op = Operator::create([
            'tenant_id' => $tenant->id,
            'login' => $data['login'],
            'name' => $data['name'] ?? null,
            'role' => $data['role'],
            'is_active' => $data['is_active'] ?? true,
            'password' => Hash::make($data['password']),
        ]);

        return response()->json($op, 201);
    }

    public function update(Request $request, $id)
    {
        $op = Operator::findOrFail($id);

        $data = $request->validate([
            'name' => ['nullable','string','max:100'],
            'role' => ['sometimes', Rule::in(['owner','admin','operator'])],
            'is_active' => ['sometimes','boolean'],
            'password' => ['sometimes','string','min:6'],
        ]);

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $op->update($data);

        return $op->fresh(['id','tenant_id','login','name','role','is_active','created_at']);
    }
}
