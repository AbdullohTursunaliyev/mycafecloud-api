<?php

namespace App\Http\Resources\Shift;

use App\Http\Resources\BaseJsonResource;
use App\Models\ShiftExpense;

class ShiftExpenseResource extends BaseJsonResource
{
    /**
     * @var ShiftExpense
     */
    public $resource;

    public function toArray($request): array
    {
        return [
            'id' => (int) $this->resource->id,
            'shift_id' => (int) $this->resource->shift_id,
            'operator_id' => (int) $this->resource->operator_id,
            'amount' => (int) $this->resource->amount,
            'title' => (string) $this->resource->title,
            'category' => $this->resource->category,
            'note' => $this->resource->note,
            'spent_at' => optional($this->resource->spent_at)->toIso8601String(),
            'created_at' => optional($this->resource->created_at)->toIso8601String(),
            'operator' => $this->resource->operator ? [
                'id' => (int) $this->resource->operator->id,
                'login' => (string) $this->resource->operator->login,
                'name' => (string) $this->resource->operator->name,
                'role' => (string) $this->resource->operator->role,
            ] : null,
        ];
    }
}
