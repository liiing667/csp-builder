<?php

namespace ParagonIE\CSPBuilderTest;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Minimal in-memory PSR-7 message used to assert the injected header value.
 */
class MessageStub implements MessageInterface
{
    /** @var array<string, string[]> */
    private $headers = [];

    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    public function withProtocolVersion($version): MessageInterface
    {
        return $this;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader($name): bool
    {
        return isset($this->headers[\strtolower($name)]);
    }

    public function getHeader($name): array
    {
        return $this->headers[\strtolower($name)] ?? [];
    }

    public function getHeaderLine($name): string
    {
        return \implode(', ', $this->getHeader($name));
    }

    public function withHeader($name, $value): MessageInterface
    {
        $clone = clone $this;
        $clone->headers[\strtolower($name)] = (array) $value;
        return $clone;
    }

    public function withAddedHeader($name, $value): MessageInterface
    {
        $clone = clone $this;
        $key = \strtolower($name);
        foreach ((array) $value as $part) {
            $clone->headers[$key][] = $part;
        }
        return $clone;
    }

    public function withoutHeader($name): MessageInterface
    {
        $clone = clone $this;
        unset($clone->headers[\strtolower($name)]);
        return $clone;
    }

    public function getBody(): StreamInterface
    {
        throw new \BadMethodCallException('Not implemented');
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        throw new \BadMethodCallException('Not implemented');
    }
}
