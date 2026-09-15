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

// Wrapped SOL.
$price = $client->price->getPrice('So11111111111111111111111111111111111111112');
printf("SOL price: \$%.4f (updated %s)\n", $price['value'], $price['updateHumanTime']);
