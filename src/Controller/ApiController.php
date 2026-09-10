<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ZipQuery;
use App\Service\AirNowGateway;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

final class ApiController extends AbstractController
{
    public function __construct(
        private readonly AirNowGateway $gateway,
        #[Autowire(service: 'limiter.clients')] private readonly RateLimiterFactoryInterface $clients,
    ) {}

    #[Route('/', name: 'index', methods: ['GET'], format: 'json')]
    public function index(): JsonResponse
    {
        return $this->json(['service' => 'AirNow API', 'version' => 1,
            'endpoints' => array_map(static fn (string $path): string => '/aq/'.$path.'/', array_keys($this->gateway->endpoints)),
            'dataUse' => 'https://docs.airnowapi.org/docs/DataUseGuidelines.pdf',
        ]);
    }

    #[Route('/health', name: 'health', methods: ['GET'], format: 'json')]
    public function health(): JsonResponse
    {
        return $this->json(['status' => 'ok']);
    }

    #[Route('/aq/{endpoint}', name: 'proxy', requirements: ['endpoint' => '.+'], methods: ['GET'], format: 'json')]
    public function proxy(Request $request, string $endpoint, #[MapQueryString(validationFailedStatusCode: 400)] ZipQuery $query = new ZipQuery()): JsonResponse
    {
        $definition = $this->gateway->endpoint($endpoint);
        $parameter = $definition['zipParameter'];
        if (array_diff(array_keys($request->query->all()), [$parameter, 'format']) || !$query->$parameter) {
            throw new BadRequestHttpException();
        }
        $limit = $this->clients->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }
        $result = $this->gateway->fetch($endpoint, $query->$parameter);
        $response = $this->json($result);
        $response->setPublic()->setMaxAge(max(0, strtotime($result['meta']['expiresAt']) - time()));
        $response->setEtag(hash('sha256', $response->getContent()));
        $response->isNotModified($request);
        return $response;
    }
}
