<?php

namespace App\Services;

use App\Models\Pc;
use App\Models\PcCell;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;

class LayoutAdminService
{
    public function __construct(
        private readonly TenantSettingService $settings,
    ) {
    }

    public function index(int $tenantId): array
    {
        return [
            'grid' => $this->gridForTenant($tenantId),
            'data' => PcCell::query()
                ->where('tenant_id', $tenantId)
                ->with([
                    'pc:id,tenant_id,code,status,zone_id',
                    'zone:id,tenant_id,name,price_per_hour',
                ])
                ->get()
                ->all(),
        ];
    }

    public function updateGrid(int $tenantId, array $grid): array
    {
        $normalized = [
            'rows' => max(1, min(50, (int) ($grid['rows'] ?? 8))),
            'cols' => max(1, min(50, (int) ($grid['cols'] ?? 12))),
        ];

        $this->settings->set($tenantId, 'layout.grid', $normalized);

        return $normalized;
    }

    public function batchUpdate(int $tenantId, array $items): void
    {
        DB::transaction(function () use ($tenantId, $items): void {
            foreach ($items as $item) {
                $row = (int) $item['row'];
                $col = (int) $item['col'];

                $cell = null;
                if (!empty($item['id'])) {
                    $cell = PcCell::query()
                        ->where('tenant_id', $tenantId)
                        ->where('id', (int) $item['id'])
                        ->first();
                }

                if (!$cell) {
                    $cell = PcCell::query()->firstOrNew([
                        'tenant_id' => $tenantId,
                        'row' => $row,
                        'col' => $col,
                    ]);
                }

                $pcId = $item['pc_id'] ?? null;
                $zoneName = null;

                if ($pcId) {
                    PcCell::query()
                        ->where('tenant_id', $tenantId)
                        ->where('pc_id', $pcId)
                        ->where('id', '!=', $cell->id ?? 0)
                        ->update(['pc_id' => null]);
                }

                $cell->row = $row;
                $cell->col = $col;

                if (array_key_exists('zone_id', $item)) {
                    $cell->zone_id = $item['zone_id'];
                    if ($cell->zone_id) {
                        $zoneName = Zone::query()
                            ->where('tenant_id', $tenantId)
                            ->where('id', $cell->zone_id)
                            ->value('name');
                    }
                }

                if (array_key_exists('pc_id', $item)) {
                    $cell->pc_id = $pcId;
                }
                if (array_key_exists('label', $item)) {
                    $cell->label = $item['label'];
                }
                if (array_key_exists('is_active', $item)) {
                    $cell->is_active = (bool) $item['is_active'];
                }
                if (array_key_exists('notes', $item)) {
                    $cell->notes = $item['notes'];
                }

                $cell->save();

                if (array_key_exists('is_active', $item) && !$cell->is_active && $cell->pc_id) {
                    $cell->pc_id = null;
                    $cell->save();
                }

                if (array_key_exists('zone_id', $item) && $cell->pc_id) {
                    Pc::query()
                        ->where('tenant_id', $tenantId)
                        ->where('id', $cell->pc_id)
                        ->update([
                            'zone_id' => $cell->zone_id,
                            'zone' => $zoneName,
                        ]);
                }
            }
        });
    }

    private function gridForTenant(int $tenantId): array
    {
        $grid = $this->settings->get($tenantId, 'layout.grid', ['rows' => 8, 'cols' => 12]);
        if (!is_array($grid)) {
            $grid = ['rows' => 8, 'cols' => 12];
        }

        return [
            'rows' => max(1, min(50, (int) ($grid['rows'] ?? 8))),
            'cols' => max(1, min(50, (int) ($grid['cols'] ?? 12))),
        ];
    }
}
