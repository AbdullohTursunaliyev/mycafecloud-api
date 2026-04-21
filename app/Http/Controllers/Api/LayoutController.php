<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pc;
use App\Models\PcCell;
use App\Models\Zone;
use App\Service\SettingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LayoutController extends Controller
{
    private function gridForTenant(int $tenantId): array
    {
        $grid = SettingService::get($tenantId, 'layout.grid', ['rows' => 8, 'cols' => 12]);
        if (!is_array($grid)) $grid = ['rows' => 8, 'cols' => 12];

        $rows = (int)($grid['rows'] ?? 8);
        $cols = (int)($grid['cols'] ?? 12);

        $rows = max(1, min(50, $rows));
        $cols = max(1, min(50, $cols));

        return ['rows' => $rows, 'cols' => $cols];
    }

    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $grid = $this->gridForTenant($tenantId);

        $cells = PcCell::where('tenant_id', $tenantId)
            ->with([
                'pc:id,tenant_id,code,status,zone_id',
                'zone:id,tenant_id,name,price_per_hour',
            ])
            ->get()
            ->map(fn(PcCell $c) => [
                'id' => $c->id,
                'row' => (int)$c->row,
                'col' => (int)$c->col,
                'zone_id' => $c->zone_id,
                'zone' => $c->zone?->name,
                'pc_id' => $c->pc_id,
                'pc' => $c->pc ? [
                    'id' => $c->pc->id,
                    'code' => $c->pc->code,
                    'status' => $c->pc->status,
                    'zone_id' => $c->pc->zone_id,
                ] : null,
                'label' => $c->label,
                'is_active' => (bool)$c->is_active,
                'notes' => $c->notes,
                'updated_at' => optional($c->updated_at)?->toIso8601String(),
            ]);

        return response()->json([
            'grid' => $grid,
            'data' => $cells,
        ]);
    }

    public function updateGrid(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'rows' => ['required','integer','min:1','max:50'],
            'cols' => ['required','integer','min:1','max:50'],
        ]);

        $grid = ['rows' => (int)$data['rows'], 'cols' => (int)$data['cols']];
        SettingService::set($tenantId, 'layout.grid', $grid);

        return response()->json(['ok' => true, 'grid' => $grid]);
    }

    public function batchUpdate(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'items' => ['required','array','max:500'],
            'items.*.id' => ['nullable','integer'],
            'items.*.row' => ['required','integer','min:1','max:500'],
            'items.*.col' => ['required','integer','min:1','max:500'],
            'items.*.zone_id' => ['nullable','integer', Rule::exists('zones','id')->where(fn($q) => $q->where('tenant_id',$tenantId))],
            'items.*.pc_id' => ['nullable','integer', Rule::exists('pcs','id')->where(fn($q) => $q->where('tenant_id',$tenantId))],
            'items.*.label' => ['nullable','string','max:40'],
            'items.*.is_active' => ['nullable','boolean'],
            'items.*.notes' => ['nullable','string','max:255'],
        ]);

        foreach ($data['items'] as $item) {
            $row = (int)$item['row'];
            $col = (int)$item['col'];

            $cell = null;
            if (!empty($item['id'])) {
                $cell = PcCell::where('tenant_id', $tenantId)->where('id', (int)$item['id'])->first();
            }
            if (!$cell) {
                $cell = PcCell::firstOrNew(['tenant_id' => $tenantId, 'row' => $row, 'col' => $col]);
            }

            $pcId = $item['pc_id'] ?? null;
            $zoneName = null;
            if ($pcId) {
                PcCell::where('tenant_id', $tenantId)
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
            if (array_key_exists('pc_id', $item)) $cell->pc_id = $pcId;
            if (array_key_exists('label', $item)) $cell->label = $item['label'];
            if (array_key_exists('is_active', $item)) $cell->is_active = (bool)$item['is_active'];
            if (array_key_exists('notes', $item)) $cell->notes = $item['notes'];

            $cell->save();

            if (array_key_exists('is_active', $item) && !$cell->is_active && $cell->pc_id) {
                $cell->pc_id = null;
                $cell->save();
            }

            if (array_key_exists('zone_id', $item) && $cell->pc_id) {
                Pc::where('tenant_id', $tenantId)
                    ->where('id', $cell->pc_id)
                    ->update([
                        'zone_id' => $cell->zone_id,
                        'zone' => $zoneName,
                    ]);
            }
        }

        return response()->json(['ok' => true]);
    }
}
