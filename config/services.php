<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'club_visual_model' => env('OPENAI_CLUB_VISUAL_MODEL', 'gpt-4.1-mini'),
        'transcription_model' => env('OPENAI_TRANSCRIPTION_MODEL', 'gpt-4o-mini-transcribe'),
        'nexora_tts_model' => env('OPENAI_NEXORA_TTS_MODEL', 'gpt-4o-mini-tts'),
        'nexora_tts_voice' => env('OPENAI_NEXORA_TTS_VOICE', 'marin'),
        'nexora_tts_voice_uz' => env('OPENAI_NEXORA_TTS_VOICE_UZ', env('OPENAI_NEXORA_TTS_VOICE', 'marin')),
        'nexora_tts_voice_ru' => env('OPENAI_NEXORA_TTS_VOICE_RU', env('OPENAI_NEXORA_TTS_VOICE', 'marin')),
        'nexora_tts_voice_en' => env('OPENAI_NEXORA_TTS_VOICE_EN', env('OPENAI_NEXORA_TTS_VOICE', 'marin')),
    ],

];
