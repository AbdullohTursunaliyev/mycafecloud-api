<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function index(Request $request)
    {
        $q = Tenant::query()->orderByDesc('id');

        if ($request->filled('status')) $q->where('status', $request->string('status'));
        if ($request->filled('search')) {
            $s = $request->string('search');
            $q->where('name','ILIKE',"%{$s}%");
        }

        return response()->json(['data' => $q->paginate(20)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required','string','max:120'],
            'status' => ['nullable','in:active,suspended'],
        ]);

        $tenant = Tenant::create([
            'name' => $data['name'],
            'status' => $data['status'] ?? 'active',
        ]);

        return response()->json(['data'=>$tenant], 201);
    }

    public function show(Request $request, int $id)
    {
        $tenant = Tenant::withCount(['licenseKeys'])->findOrFail($id);
        return response()->json(['data'=>$tenant]);
    }

    public function update(Request $request, int $id)
    {
        $tenant = Tenant::findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes','string','max:120'],
            'status' => ['sometimes','in:active,suspended'],
        ]);

        $tenant->fill($data)->save();

        return response()->json(['data'=>$tenant]);
    }
}

