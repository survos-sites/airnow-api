<?php

declare(strict_types=1);

namespace App\Tests;

use Survos\FetchBundle\Retry\ExponentialBackoffRetry;
use Survos\FetchBundle\Service\PersistentFetcher;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class ApiTest extends WebTestCase
{
    private function client(array $responses): array
    {
        $client = self::createClient();
        $client->disableReboot();
        $cache = new FilesystemAdapter('test'.bin2hex(random_bytes(6)), directory: self::getContainer()->getParameter('kernel.cache_dir').'/gateway-test');
        self::getContainer()->set('cache.app', $cache);
        $http = new MockHttpClient($responses);
        self::getContainer()->set('survos_airnow.fetcher', new PersistentFetcher($http, new ArrayAdapter(), new ExponentialBackoffRetry()));
        return [$client, $http];
    }

    public function testCatchallNormalizesAndCachesBothEndpoints(): void
    {
        [$client, $http] = $this->client([
            new MockResponse(file_get_contents(__DIR__.'/fixtures/observations.json')),
            new MockResponse(file_get_contents(__DIR__.'/fixtures/forecast.json')),
        ]);
        $url = '/aq/observation/current/ziplatlong/?zipcode=20002';
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $result = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(3, $result['data']);
        self::assertSame(34, $result['data'][0]['aqi']);
        self::assertTrue($result['meta']['preliminary']);
        self::assertSame('20002', $result['meta']['zipCode']);
        self::assertStringNotContainsString('test-server-key', $client->getResponse()->getContent());
        $etag = $client->getResponse()->headers->get('ETag');
        $client->request('GET', $url, server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(304);
        self::assertSame(1, $http->getRequestsCount());
        $client->request('GET', '/aq/forecast/current/?zipCode=20008&format=application/json');
        self::assertResponseIsSuccessful();
        $forecast = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertNotEmpty($forecast['data']);
        self::assertArrayHasKey('dateValid', $forecast['data'][0]);
        self::assertSame(86400, strtotime($forecast['meta']['expiresAt']) - strtotime($forecast['meta']['fetchedAt']));
        self::assertSame(2, $http->getRequestsCount());
    }

    public function testRejectsUnknownPathsAndParametersWithoutCallingUpstream(): void
    {
        [$client, $http] = $this->client([]);
        foreach ([
            ['/aq/anything/?zipcode=20002', 404],
            ['/aq/observation/current/ziplatlong/', 400],
            ['/aq/observation/current/ziplatlong/?zipcode=abcde', 400],
            ['/aq/observation/current/ziplatlong/?zipcode[]=20002', 400],
            ['/aq/observation/current/ziplatlong/?zipcode=20002&API_KEY=client-key', 400],
            ['/aq/observation/current/ziplatlong/?zipcode=20002&force=1', 400],
            ['/aq/observation/current/ziplatlong/?zipcode=20002&url=https://example.com', 400],
            ['/aq/forecast/current/?zipCode=20008&format=application/xml', 400],
        ] as [$url, $status]) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame($status);
            self::assertResponseHeaderSame('Content-Type', 'application/json');
        }
        $client->request('POST', '/aq/forecast/current/?zipCode=20008');
        self::assertResponseStatusCodeSame(405);
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testUpstreamErrorsAreSanitizedAndNotCached(): void
    {
        [$client, $http] = $this->client([
            new MockResponse('upstream secret details', ['http_code' => 401]),
            new MockResponse('[]'),
        ]);
        $url = '/aq/observation/current/ziplatlong/?zipcode=20002';
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(502);
        self::assertStringNotContainsString('secret', $client->getResponse()->getContent());
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode($client->getResponse()->getContent(), true)['data']);
        self::assertSame(2, $http->getRequestsCount());
    }

    public function testClientRateLimit(): void
    {
        [$client, $http] = $this->client([new MockResponse('[]')]);
        self::getContainer()->set('limiter.clients', new RateLimiterFactory([
            'id' => 'test-client', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute',
        ], new InMemoryStorage()));
        $url = '/aq/observation/current/ziplatlong/?zipcode=20002';
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(429);
        self::assertResponseHasHeader('Retry-After');
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testSharedBudgetAppliesOnlyToCacheMisses(): void
    {
        [$client, $http] = $this->client([new MockResponse('[]')]);
        self::getContainer()->set('limiter.upstream', new RateLimiterFactory([
            'id' => 'test-upstream', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour',
        ], new InMemoryStorage()));
        $client->request('GET', '/aq/observation/current/ziplatlong/?zipcode=20002');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/aq/observation/current/ziplatlong/?zipcode=20002');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/aq/observation/current/ziplatlong/?zipcode=10001');
        self::assertResponseStatusCodeSame(503);
        self::assertResponseHasHeader('Retry-After');
        self::assertSame(1, $http->getRequestsCount());
    }
}
