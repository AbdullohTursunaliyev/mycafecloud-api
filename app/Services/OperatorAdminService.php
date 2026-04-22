<?php

namespace App\Services;

use App\Models\Operator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

class OperatorAdminService
{
    public function list(int $tenantId): Collection
    {
        return Operator::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->get(['id', 'name', 'login', 'role', 'is_active', 'created_at', 'updated_at']);
    }

    public function create(int $tenantId, array $payload): Operator
    {
        return Operator::query()->create([
            'tenant_id' => $tenantId,
            'name' => $payload['name'],
            'login' => $payload['login'],
            'password' => Hash::make($payload['password']),
            'role' => $payload['role'],
            'is_active' => true,
        ]);
    }

    public function update(int $tenantId, int $operatorId, array $payload): Operator
    {
        $operator = Operator::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($operatorId);

        if (array_key_exists('password', $payload)) {
            $payload['password'] = Hash::make($payload['password']);
        }

        $operator->fill($payload);
        $operator->save();

        return $operator;
    }
}
