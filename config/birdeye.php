<?php

return [
    'api_key' => env('BIRDEYE_API_KEY', ''),
    'default_chain' => env('BIRDEYE_DEFAULT_CHAIN', ''),
    'base_url' => env('BIRDEYE_BASE_URL', \Tigusigalpa\Birdeye\Client::DEFAULT_BASE_URL),
];
