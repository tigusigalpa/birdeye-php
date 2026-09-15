<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Tigusigalpa\Birdeye\Client;

$apiKey = getenv('BIRDEYE_API_KEY');
if ($apiKey === false || $apiKey === '') {
    fwrite(STDERR, "Set BIRDEYE_API_KEY in your environment first.\n");
    exit(1);
}

$factory = new HttpFactory();
$client = new Client(
    httpClient: new GuzzleClient(),
    requestFactory: $factory,
    streamFactory: $factory,
    apiKey: $apiKey,
    defaultChain: 'solana',
);

$now = time();
$page = $client->price->getOhlcvV3([
    'address' => 'So11111111111111111111111111111111111111112',
    'type' => '1H',
    'time_from' => $now - 24 * 3600,
    'time_to' => $now,
]);

foreach ($page['items'] as $candle) {
    printf(
        "%d  o=%.4f h=%.4f l=%.4f c=%.4f v=%.2f\n",
        $candle['unix_time'],
        $candle['o'],
        $candle['h'],
        $candle['l'],
        $candle['c'],
        $candle['v'],
    );
}
