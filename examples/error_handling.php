<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Tigusigalpa\Birdeye\Client;
use Tigusigalpa\Birdeye\Exception\AuthenticationException;
use Tigusigalpa\Birdeye\Exception\BirdeyeException;
use Tigusigalpa\Birdeye\Exception\ForbiddenException;
use Tigusigalpa\Birdeye\Exception\RateLimitException;

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

try {
    // A syntactically invalid address should trigger a 4xx from Birdeye.
    $client->price->getPrice('not-a-real-address');
    echo "unexpectedly succeeded\n";
} catch (RateLimitException) {
    echo "rate limited — back off and retry later\n";
} catch (AuthenticationException) {
    echo "check your BIRDEYE_API_KEY\n";
} catch (ForbiddenException) {
    echo "this endpoint isn't available on your Birdeye plan\n";
} catch (BirdeyeException $e) {
    printf("birdeye error: http=%d message=\"%s\"\n", $e->httpStatus, $e->getMessage());
}
