<?php

declare(strict_types=1);

namespace Jbaron\FalWebdav\Driver;

class MultiStatusResponse
{
    public function __construct(
        private readonly string $path,
        private readonly int $statusCode,
        private readonly \DOMNode $node,
    ) {
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getNode(): \DOMNode
    {
        return $this->node;
    }
}
