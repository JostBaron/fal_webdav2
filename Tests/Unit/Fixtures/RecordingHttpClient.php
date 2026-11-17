<?php

declare(strict_types=1);

namespace Jbaron\FalWebdav\Tests\Unit\Fixtures;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 client that answers requests from a queue of prepared responses (or exceptions) and
 * records every request it receives, so tests can assert on what was sent to the server.
 */
class RecordingHttpClient implements ClientInterface
{
    /**
     * @var array<ResponseInterface|\Throwable>
     */
    private array $queue = [];

    /**
     * @var array<RequestInterface>
     */
    private array $requests = [];

    public function enqueue(ResponseInterface|\Throwable $responseOrException): self
    {
        $this->queue[] = $responseOrException;
        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if ([] === $this->queue) {
            throw new \LogicException(
                \sprintf('Unexpected request %s %s.', $request->getMethod(), (string)$request->getUri())
            );
        }

        $next = \array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }

    /**
     * @return array<RequestInterface>
     */
    public function getRequests(): array
    {
        return $this->requests;
    }

    public function getLastRequest(): RequestInterface
    {
        if ([] === $this->requests) {
            throw new \LogicException('No request has been sent.');
        }
        return $this->requests[\count($this->requests) - 1];
    }

    public function hasPendingResponses(): bool
    {
        return [] !== $this->queue;
    }
}
