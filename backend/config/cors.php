<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| Consumed by Illuminate\Http\Middleware\HandleCors, which is Laravel's own
| first-party implementation of exactly what backend/app/Http/Middleware/
| CorsMiddleware.php hand-rolled. Using it instead of porting that class is a
| pure win: HandleCors already reflects a matched Origin literally (never "*"
| once credentials are on), emits `Vary: Origin`, and short-circuits the
| OPTIONS preflight with a 204 — the three behaviours the old middleware
| implemented by hand.
|
| Values below are copied from backend/config/cors.php and must stay in sync
| with what frontend/src/services/api.ts actually sends:
|   withCredentials: true             -> allow_credentials + literal origin
|   Accept: application/vnd.sanad.v1+json
|   API-Version: v1
|   X-CSRF-Token on every mutating request
|
| `X-CSRF-Token` in allowed_headers is load-bearing, not cosmetic: it is a
| non-safelisted request header, so a browser will refuse the preflight and
| the real request never leaves the tab if it is missing here.
|
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173'))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept', 'API-Version', 'X-CSRF-Token', 'X-Requested-With'],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 0,

    'supports_credentials' => filter_var(
        env('CORS_ALLOW_CREDENTIALS', true),
        FILTER_VALIDATE_BOOLEAN
    ),

];
