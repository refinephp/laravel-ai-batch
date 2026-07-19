<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Providers\OpenAI;

final readonly class OpenAiJsonlFile
{
    public function __construct(
        private string $path,
        private string $filename,
        private string $endpoint,
        private string $model,
        private int $requestCount,
        private int $bytes,
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    public function filename(): string
    {
        return $this->filename;
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function requestCount(): int
    {
        return $this->requestCount;
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function delete(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
