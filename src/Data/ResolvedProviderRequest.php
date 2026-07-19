<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Data;

final readonly class ResolvedProviderRequest
{
    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function __construct(
        private string $provider,
        private string $method,
        private string $endpoint,
        private array $body,
        private array $headers = [],
    ) {}

    public function provider(): string
    {
        return $this->provider;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return $this->body;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }
}
