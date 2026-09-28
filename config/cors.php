<?php

/*
|--------------------------------------------------------------------------
| Cross-origin requests
|--------------------------------------------------------------------------
|
| Laravel's global CORS handling is off: tills are not browsers, and the only browser-facing API, the public trial
| form (/api/v1/public/*, module 1.10), has its own allow-list in App\Http\Middleware\PublicFormCors
| (`sspos.public_form_origins`, env PUBLIC_FORM_ORIGINS). Without this file the framework default would allow any
| origin on api/*.
|
*/

return [

    'paths' => [],

    'allowed_methods' => [],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
