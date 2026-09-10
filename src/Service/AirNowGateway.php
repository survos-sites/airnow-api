<?php

declare(strict_types=1);

namespace App\Service;

use Survos\AirNowBundle\Service\AirNowClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class AirNowGateway
{
    public function __construct(
        private readonly AirNowClient $client,
        private readonly CacheInterface $cache,
        #[Autowire(service: 'limiter.upstream')] private readonly RateLimiterFactoryInterface $upstream,
        #[Autowire('%app.endpoints%')] public readonly array $endpoints,
        #[Autowire('%env(AIRNOW_API_KEY)%'), \SensitiveParameter] private readonly string $apiKey,
    ) {}

    public function endpoint(string $path): array
    {
        return $this->endpoints[rtrim($path, '/')] ?? throw new NotFoundHttpException();
    }

    public function fetch(string $path, string $zip): array
    {
        $endpoint = $this->endpoint($path);
        if (trim($this->apiKey) === '') {
            throw new HttpException(503, 'The server API key is not configured.');
        }
        $key = 'airnow.v1.'.hash('sha256', rtrim($path, '/').'|'.$zip);
        return $this->cache->get($key, function (ItemInterface $item) use ($endpoint, $zip): array {
            // Shared across visitors; cache hits consume no upstream budget.
            $limit = $this->upstream->create($endpoint['method'])->consume();
            if (!$limit->isAccepted()) {
                throw new HttpException(503, 'Upstream request budget exhausted.', headers: [
                    'Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()),
                ]);
            }
            $data = $this->client->{$endpoint['method']}($zip);
            $item->expiresAfter($endpoint['ttl']);
            return ['data' => $data, 'meta' => [
                'zipCode' => $zip, 'fetchedAt' => gmdate(DATE_ATOM),
                'expiresAt' => gmdate(DATE_ATOM, time() + $endpoint['ttl']),
                'preliminary' => true, 'source' => 'EPA AirNow and reporting agencies',
            ]];
        }, beta: 0);
    }
}
