<?php declare(strict_types=1);

namespace ApiClients\Middleware\Cache\Strategy;

use ApiClients\Middleware\Cache\StrategyInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class Always implements StrategyInterface
{
    const ALWAYS_TTL = 300;

    public function decide(RequestInterface $request, ResponseInterface $response): bool
    {
        return true;
    }

    public function determineTtl(
        RequestInterface $request,
        ResponseInterface $response,
        int $default = self::ALWAYS_TTL
    ): int {
        return $default;
    }
}
