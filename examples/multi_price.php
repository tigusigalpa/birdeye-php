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

$addresses = [
    'So11111111111111111111111111111111111111112', // wrapped SOL
    'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v', // USDC
];

$prices = $client->price->getMultiPrice($addresses);
foreach ($prices as $address => $price) {
    printf("%s: \$%.4f\n", $address, $price['value']);
}
