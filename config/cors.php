<?php

return [
    'paths'                => ['*'],
    'allowed_methods'      => ['GET'],
    'allowed_origins'      => ['*'], // 실제 운영시에는 허용할 도메인만 지정
    'allowed_headers'      => ['*'],
    'exposed_headers'      => [],
    'max_age'              => 0,
    'supports_credentials' => false,
];