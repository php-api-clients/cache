<?php declare(strict_types=1);

namespace ApiClients\Tests\Middleware\Cache;

use ApiClients\Middleware\Cache\CacheMiddleware;
use ApiClients\Middleware\Cache\Document;
use ApiClients\Middleware\Cache\Options;
use ApiClients\Middleware\Cache\StrategyInterface;
use ApiClients\Tools\TestUtilities\TestCase;
use function Clue\React\Block\await;
use Prophecy\Argument;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use React\Cache\CacheInterface;
use React\EventLoop\Factory;
use function React\Promise\reject;
use function React\Promise\resolve;
use RingCentral\Psr7\BufferStream;
use RingCentral\Psr7\Request;
use RingCentral\Psr7\Response;

/**
 * @internal
 */
final class CacheMiddlewareTest extends TestCase
{
    public function providerMethod()
    {
        yield ['POST'];
        yield ['PUT'];
        yield ['HEAD'];
        yield ['PATCH'];
        yield ['OPTIONS'];
        yield ['LOLCAT'];
    }

    /**
     * @dataProvider providerMethod
     */
    public function testPreNoGet(string $method): void
    {
        $options = [
            CacheMiddleware::class => [
                Options::CACHE => $this->prophesize(CacheInterface::class)->reveal(),
                Options::STRATEGY => $this->prophesize(StrategyInterface::class)->reveal(),
            ],
        ];

        $request = $this->prophesize(RequestInterface::class);
        $request->getMethod()->shouldBeCalled()->willReturn($method);

        $requestInstance = $request->reveal();
        $middleware = new CacheMiddleware();

        self::assertSame(
            $requestInstance,
            await(
                $middleware->pre($requestInstance, 'abc', $options),
                Factory::create()
            )
        );

        $middleware->post($this->prophesize(ResponseInterface::class)->reveal(), 'abc');
    }

    public function testPreGetCache(): void
    {
        $documentString = (string)Document::createFromResponse(
            new Response(123, [], 'foo.bar'),
            5
        );
        $cache = $this->prophesize(CacheInterface::class);
        $cache->get(Argument::type('string'))->shouldBecalled()->willReturn(resolve($documentString));

        $options = [
            CacheMiddleware::class => [
                Options::CACHE => $cache->reveal(),
                Options::STRATEGY => $this->prophesize(StrategyInterface::class)->reveal(),
            ],
        ];

        $request = new Request('GET', 'foo.bar');

        $response = null;
        $middleware = new CacheMiddleware();
        $middleware->pre($request, 'abc', $options)->otherwise(function ($responseObject) use (&$response): void {
            $response = $responseObject;
        });
        self::assertNotNull($response);

        self::assertSame(123, $response->getStatusCode());
        self::assertSame('foo.bar', $response->getBody()->getContents());
    }

    public function testPreGetNoCache(): void
    {
        $request = new Request('GET', 'foo.bar');

        $cache = $this->prophesize(CacheInterface::class);
        $cache->get(Argument::type('string'))->shouldBecalled()->willReturn(reject());

        $options = [
            CacheMiddleware::class => [
                Options::CACHE => $cache->reveal(),
                Options::STRATEGY => $this->prophesize(StrategyInterface::class)->reveal(),
            ],
        ];

        $middleware = new CacheMiddleware();
        $response = await($middleware->pre($request, 'abc', $options), Factory::create());

        self::assertSame($request, $response);
    }

    public function testPreGetExpired(): void
    {
        $documentString = (string)Document::createFromResponse(
            new Response(123, [], 'foo.bar'),
            0
        );

        \sleep(2);

        $cache = $this->prophesize(CacheInterface::class);
        $cache->get(Argument::type('string'))->shouldBecalled()->willReturn(resolve($documentString));
        $cache->delete(Argument::type('string'))->shouldBecalled();

        $options = [
            CacheMiddleware::class => [
                Options::CACHE => $cache->reveal(),
                Options::STRATEGY => $this->prophesize(StrategyInterface::class)->reveal(),
            ],
        ];

        $request = new Request('GET', 'foo.bar');

        $middleware = new CacheMiddleware();
        $response = await($middleware->pre($request, 'abc', $options), Factory::create());

        self::assertSame($request, $response);
    }

    public function testPost(): void
    {
        $request = new Request('GET', 'foo.bar');

        $body = 'foo.bar';
        $stream = new BufferStream(\strlen($body));
        $stream->write($body);
        $response = (new Response(200, []))->withBody($stream);

        $cache = $this->prophesize(CacheInterface::class);
        $cache->get(Argument::type('string'))->shouldBecalled()->willReturn(reject());
        $cache->set(
            Argument::type('string'),
            Argument::type('string')
        )->shouldBecalled();

        $strategy = $this->prophesize(StrategyInterface::class);
        $strategy->determineTtl(Argument::type(RequestInterface::class), Argument::type(ResponseInterface::class))->shouldBeCalled()->willReturn(true);
        $strategy->decide(Argument::type(RequestInterface::class), Argument::type(ResponseInterface::class))->shouldBeCalled()->willReturn(true);

        $options = [
            CacheMiddleware::class => [
                Options::CACHE => $cache->reveal(),
                Options::STRATEGY => $strategy->reveal(),
            ],
        ];

        $middleware = new CacheMiddleware();
        $middleware->pre($request, 'abc', $options);
        $responseObject = await($middleware->post($response, 'abc', $options), Factory::create());

        self::assertSame($response->getStatusCode(), $responseObject->getStatusCode());
        self::assertSame($body, $responseObject->getBody()->getContents());
    }
}
