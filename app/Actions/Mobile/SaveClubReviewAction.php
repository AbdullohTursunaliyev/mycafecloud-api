<?php

namespace App\Actions\Mobile;

use App\Models\ClubReview;
use Illuminate\Http\Exceptions\HttpResponseException;

class SaveClubReviewAction
{
    public function execute(int $tenantId, int $clientId, array $attributes): array
    {
        $fallback = isset($attributes['rating']) ? (int) $attributes['rating'] : null;
        $atmosphere = isset($attributes['atmosphere_rating']) ? (int) $attributes['atmosphere_rating'] : $fallback;
        $cleanliness = isset($attributes['cleanliness_rating']) ? (int) $attributes['cleanliness_rating'] : $fallback;
        $technical = isset($attributes['technical_rating']) ? (int) $attributes['technical_rating'] : $fallback;
        $peripherals = isset($attributes['peripherals_rating']) ? (int) $attributes['peripherals_rating'] : $fallback;

        if (!$atmosphere || !$cleanliness || !$technical || !$peripherals) {
            throw new HttpResponseException(response()->json([
                'message' => 'All 4 ratings are required',
                'errors' => [
                    'atmosphere_rating' => ['Atmosphere rating is required'],
                    'cleanliness_rating' => ['Cleanliness rating is required'],
                    'technical_rating' => ['Technical rating is required'],
                    'peripherals_rating' => ['Peripherals rating is required'],
                ],
            ], 422));
        }

        $comment = trim((string) ($attributes['comment'] ?? ''));
        if ($comment === '') {
            $comment = null;
        }

        $lastReview = ClubReview::query()
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->latest('created_at')
            ->first();

        $lastReferenceAt = $lastReview?->updated_at ?? $lastReview?->created_at;
        if ($lastReferenceAt && $lastReferenceAt->gt(now()->subMonth())) {
            $nextReviewAt = $lastReferenceAt->copy()->addMonth();

            throw new HttpResponseException(response()->json([
                'message' => 'You can leave a review once per month.',
                'next_review_at' => $nextReviewAt->toIso8601String(),
            ], 422));
        }

        $overall = (int) round(($atmosphere + $cleanliness + $technical + $peripherals) / 4, 0);
        $review = ClubReview::query()->create([
            'tenant_id' => $tenantId,
            'client_id' => $clientId,
            'rating' => $overall,
            'atmosphere_rating' => $atmosphere,
            'cleanliness_rating' => $cleanliness,
            'technical_rating' => $technical,
            'peripherals_rating' => $peripherals,
            'comment' => $comment,
        ]);

        return [
            'ok' => true,
            'review' => [
                'rating' => (int) $review->rating,
                'atmosphere_rating' => (int) $review->atmosphere_rating,
                'cleanliness_rating' => (int) $review->cleanliness_rating,
                'technical_rating' => (int) $review->technical_rating,
                'peripherals_rating' => (int) $review->peripherals_rating,
                'comment' => (string) ($review->comment ?? ''),
                'updated_at' => optional($review->updated_at)->toIso8601String(),
                'created_at' => optional($review->created_at)->toIso8601String(),
            ],
        ];
    }
}
