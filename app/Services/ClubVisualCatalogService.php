<?php

namespace App\Services;

use App\Models\ClubVisual;
use Illuminate\Database\Eloquent\Collection;

class ClubVisualCatalogService
{
    public function list(int $tenantId): Collection
    {
        return ClubVisual::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('is_active')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function create(int $tenantId, array $payload): ClubVisual
    {
        return ClubVisual::query()->create([
            'tenant_id' => $tenantId,
            'name' => $payload['name'],
            'headline' => $payload['headline'] ?? null,
            'subheadline' => $payload['subheadline'] ?? null,
            'description_text' => $payload['description_text'] ?? null,
            'prompt_text' => $payload['prompt_text'] ?? null,
            'display_mode' => $payload['display_mode'],
            'screen_mode' => $payload['screen_mode'],
            'accent_color' => $payload['accent_color'] ?? null,
            'image_url' => $payload['image_url'] ?? null,
            'audio_url' => $payload['audio_url'] ?? null,
            'layout_spec' => $payload['layout_spec'] ?? null,
            'visual_spec' => $payload['visual_spec'] ?? null,
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
            'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
        ]);
    }

    public function update(int $tenantId, int $visualId, array $payload): ClubVisual
    {
        $visual = $this->find($tenantId, $visualId);
        $visual->fill($payload);
        $visual->save();

        return $visual->fresh();
    }

    public function toggle(int $tenantId, int $visualId): ClubVisual
    {
        $visual = $this->find($tenantId, $visualId);
        $visual->update([
            'is_active' => !$visual->is_active,
        ]);

        return $visual->fresh();
    }

    public function delete(int $tenantId, int $visualId): void
    {
        $this->find($tenantId, $visualId)->delete();
    }

    public function find(int $tenantId, int $visualId): ClubVisual
    {
        return ClubVisual::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($visualId);
    }
}
