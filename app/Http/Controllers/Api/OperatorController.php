<?php

// app/Http/Controllers/Api/OperatorController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Operator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class OperatorController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $ops = Operator::where('tenant_id',$tenantId)
            ->orderByDesc('id')
            ->get(['id','name','login','role','is_active','created_at']);

        return response()->json(['data'=>$ops]);
    }

    public function store(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'name' => ['required','string','max:64'],
            'login' => ['required','string','max:64', Rule::unique('operators')->where(fn($q)=>$q->where('tenant_id',$tenantId))],
            'password' => ['required','string','min:4'],
            'role' => ['required','string','in:operator,admin,owner'],
        ]);

        $op = Operator::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'login' => $data['login'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'is_active' => true,
        ]);

        return response()->json(['data'=>$op], 201);
    }

    public function update(Request $request, int $id)
    {
        $tenantId = $request->user()->tenant_id;

        $op = Operator::where('tenant_id',$tenantId)->findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes','string','max:64'],
            'login' => ['sometimes','string','max:64', Rule::unique('operators')->ignore($op->id)->where(fn($q)=>$q->where('tenant_id',$tenantId))],
            'password' => ['sometimes','string','min:4'],
            'role' => ['sometimes','string','in:operator,admin,owner'],
            'is_active' => ['sometimes','boolean'],
        ]);

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $op->fill($data)->save();

        return response()->json(['data'=>$op]);
    }
}

