<?php

namespace App\Actions\Mobile;

use App\Models\ClubReview;

class ListClubReviewsAction
{
    public function execute(int $tenantId, int $clientId): array
    {
        $rows = ClubReview::query()
            ->where('tenant_id', $tenantId)
            ->with('client:id,login')
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();

        $mine = ClubReview::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->latest('created_at')
            ->first();

        $nextReviewAt = null;
        $canSubmit = true;
        $mineReferenceAt = $mine?->updated_at ?? $mine?->created_at;
        if ($mineReferenceAt && $mineReferenceAt->gt(now()->subMonth())) {
            $canSubmit = false;
            $nextReviewAt = $mineReferenceAt->copy()->addMonth();
        }

        return [
            'reviews' => $rows->map(fn(ClubReview $review) => $this->mapReview($review))->values()->all(),
            'mine' => $mine ? $this->mapReview($mine, false) : null,
            'can_submit' => $canSubmit,
            'next_review_at' => optional($nextReviewAt)->toIso8601String(),
        ];
    }

    private function mapReview(ClubReview $review, bool $withClientMeta = true): array
    {
        $payload = [
            'rating' => (int) $review->rating,
            'atmosphere_rating' => (int) ($review->atmosphere_rating ?? $review->rating),
            'cleanliness_rating' => (int) ($review->cleanliness_rating ?? $review->rating),
            'technical_rating' => (int) ($review->technical_rating ?? $review->rating),
            'peripherals_rating' => (int) ($review->peripherals_rating ?? $review->rating),
            'comment' => (string) ($review->comment ?? ''),
            'updated_at' => optional($review->updated_at)->toIso8601String(),
            'created_at' => optional($review->created_at)->toIso8601String(),
        ];

        if (!$withClientMeta) {
            return $payload;
        }

        return [
            'id' => (int) $review->id,
            'client_id' => (int) $review->client_id,
            'client_login' => (string) ($review->client?->login ?? ('#' . $review->client_id)),
            ...$payload,
        ];
    }
}
