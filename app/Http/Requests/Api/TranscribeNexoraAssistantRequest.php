<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class TranscribeNexoraAssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'audio' => [
                'required',
                'file',
                'max:25600',
                'mimetypes:audio/mpeg,audio/mp4,audio/x-m4a,audio/aac,audio/wav,audio/webm,video/webm',
            ],
            'locale' => ['nullable', 'string', Rule::in(['uz', 'ru', 'en'])],
        ];
    }

    public function audioFile(): UploadedFile
    {
        /** @var UploadedFile $audio */
        $audio = $this->file('audio');

        return $audio;
    }

    public function localeCode(): string
    {
        $locale = $this->validated()['locale'] ?? 'uz';

        return is_string($locale) && $locale !== '' ? $locale : 'uz';
    }
}
