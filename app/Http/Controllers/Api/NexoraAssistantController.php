<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ExecuteNexoraAssistantRequest;
use App\Http\Requests\Api\PlanNexoraAssistantRequest;
use App\Http\Requests\Api\SpeakNexoraAssistantRequest;
use App\Http\Requests\Api\TranscribeNexoraAssistantRequest;
use App\Http\Requests\Api\UpdateNexoraAutopilotRequest;
use Illuminate\Http\Request;
use App\Services\NexoraAssistantService;
use App\Services\NexoraAssistantVoiceService;

class NexoraAssistantController extends Controller
{
    public function __construct(
        private readonly NexoraAssistantService $assistant,
        private readonly NexoraAssistantVoiceService $voice,
    ) {
    }

    public function plan(PlanNexoraAssistantRequest $request)
    {
        $operator = $request->user('operator') ?: $request->user();

        return response()->json([
            'data' => $this->assistant->plan(
                (int) $operator->tenant_id,
                (int) $operator->id,
                $request->message(),
                $request->localeCode(),
            ),
        ]);
    }

    public function overview(Request $request)
    {
        $operator = $request->user('operator') ?: $request->user();
        $locale = (string) $request->query('locale', 'uz');
        if (!in_array($locale, ['uz', 'ru', 'en'], true)) {
            $locale = 'uz';
        }

        return response()->json([
            'data' => $this->assistant->overview(
                (int) $operator->tenant_id,
                $locale,
                in_array((string) ($operator->role ?? ''), ['owner', 'admin'], true),
            ),
        ]);
    }

    public function execute(ExecuteNexoraAssistantRequest $request)
    {
        $operator = $request->user('operator') ?: $request->user();

        return response()->json([
            'data' => $this->assistant->execute(
                (int) $operator->tenant_id,
                (int) $operator->id,
                $request->planId(),
                $request->confirmed(),
                $request->localeCode(),
            ),
        ]);
    }

    public function speak(SpeakNexoraAssistantRequest $request)
    {
        $audio = $this->voice->synthesize($request->text(), $request->localeCode());

        return response($audio['content'], 200, [
            'Content-Type' => $audio['content_type'],
            'Cache-Control' => 'no-store, private',
            'X-Nexora-Voice' => $audio['voice'],
        ]);
    }

    public function transcribe(TranscribeNexoraAssistantRequest $request)
    {
        return response()->json([
            'data' => $this->voice->transcribe($request->audioFile(), $request->localeCode()),
        ]);
    }

    public function updateAutopilot(UpdateNexoraAutopilotRequest $request)
    {
        $operator = $request->user('operator') ?: $request->user();

        return response()->json([
            'data' => $this->assistant->updateAutopilot(
                (int) $operator->tenant_id,
                (int) $operator->id,
                (string) ($operator->role ?? ''),
                $request->settings(),
                $request->localeCode(),
            ),
        ]);
    }
}
