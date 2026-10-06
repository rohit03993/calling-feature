<?php

return [

    'provider' => env('CALL_AI_PROVIDER', 'openai'),

    'openai_key' => env('OPENAI_API_KEY'),

    'openai_base_url' => rtrim((string) env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'),

    'gemini_key' => env('GEMINI_API_KEY'),

    'gemini_model' => env('GEMINI_MODEL', 'gemini-flash-latest'),

    'gemini_fallbacks' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('GEMINI_FALLBACK_MODELS', 'gemini-2.5-flash,gemini-2.5-flash-lite,gemini-2.0-flash,gemini-2.0-flash-lite')),
    ))),

    'gemini_base_url' => rtrim((string) env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'), '/'),

    'transcription_model' => env('CALL_AI_TRANSCRIBE_MODEL', 'gpt-4o-mini-transcribe'),

    'transcription_format' => env('CALL_AI_TRANSCRIBE_FORMAT', 'json'),

    'transcription_language' => env('CALL_AI_TRANSCRIBE_LANGUAGE', 'hi'),

    'analysis_model' => env('CALL_AI_ANALYSIS_MODEL', 'gpt-4o-mini'),

    'cleanup_model' => env('CALL_AI_CLEANUP_MODEL', 'gpt-4o-mini'),

    'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),

    'ffprobe_binary' => env('FFPROBE_BINARY', 'ffprobe'),

    'max_duration_seconds' => (int) env('CALL_AI_MAX_DURATION', 3600),

    'max_upload_kilobytes' => (int) env('CALL_AI_MAX_UPLOAD_KB', 102400),

    'allow_test_routes' => filter_var(env('CALL_AI_TEST_ROUTES', env('APP_ENV') === 'local'), FILTER_VALIDATE_BOOLEAN),

    'skip_ffmpeg' => filter_var(env('CALL_AI_SKIP_FFMPEG', false), FILTER_VALIDATE_BOOLEAN),

    'school_code' => env('CALL_AI_SCHOOL_CODE'),

    'school_secret' => env('CALL_AI_SCHOOL_SECRET'),

    'school_callback_url' => env('CALL_AI_SCHOOL_CALLBACK'),

    'school_callback_secret' => env('CALL_AI_SCHOOL_CALLBACK_SECRET'),

];
