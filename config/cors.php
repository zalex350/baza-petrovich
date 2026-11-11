<?php

/*
| CORS (HandleCors): выключен для всех адресов. API обмена с 1С (/api/1c/*) вызывает сервер 1С,
| не браузер, — заголовки Access-Control-Allow-* ему не нужны и открыли бы API для скриптов
| с чужих сайтов. По умолчанию Laravel включал CORS для api/* с allowed_origins = *.
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
