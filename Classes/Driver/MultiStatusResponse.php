<?php

declare(strict_types=1);

namespace Jbaron\FalWebdav\Driver;

class MultiStatusResponse
{
    private string $path;
    private int $statusCode;
    private \DOMNode $node;

    public function __construct(string $path, int $statusCode, \DOMNode $node)
    {
        $this->path = $path;
        $this->statusCode = $statusCode;
        $this->node = $node;
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
