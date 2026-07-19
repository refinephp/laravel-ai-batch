<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Providers\OpenAI;

use JsonException;
use RefinePhp\LaravelAiBatch\Data\BatchRequest;
use Throwable;

final class OpenAiJsonlWriter
{
    public const MAX_REQUESTS = 50_000;

    public const MAX_BYTES = 200 * 1024 * 1024;

    public const ENDPOINT = '/v1/responses';

    public function __construct(
        private readonly int $maxRequests = self::MAX_REQUESTS,
        private readonly int $maxBytes = self::MAX_BYTES,
    ) {}

    /** @param iterable<int, mixed> $requests */
    public function write(iterable $requests, string $provider): OpenAiJsonlFile
    {
        $path = tempnam(sys_get_temp_dir(), 'laravel-ai-batch-openai-');

        if ($path === false) {
            throw new OpenAiJsonlException('A temporary OpenAI batch input file could not be created.');
        }

        $stream = fopen($path, 'wb');

        if ($stream === false) {
            @unlink($path);

            throw new OpenAiJsonlException('The temporary OpenAI batch input file could not be opened.');
        }

        $seen = [];
        $count = 0;
        $bytes = 0;
        $model = null;

        try {
            foreach ($requests as $batchRequest) {
                if (! $batchRequest instanceof BatchRequest) {
                    throw new OpenAiJsonlException('The OpenAI submission contains an invalid request value.');
                }

                $count++;

                if ($count > $this->maxRequests) {
                    throw new OpenAiJsonlException("An OpenAI batch may contain at most {$this->maxRequests} requests.");
                }

                $customId = $batchRequest->customId();

                if ($customId === '') {
                    throw new OpenAiJsonlException('OpenAI batch custom IDs must not be empty.');
                }

                if (isset($seen[$customId])) {
                    throw new OpenAiJsonlException('OpenAI batch custom IDs must be unique.');
                }

                $seen[$customId] = true;
                $request = $batchRequest->request();

                if ($request->provider() !== $provider) {
                    throw new OpenAiJsonlException('Every OpenAI batch request must use the selected provider.');
                }

                if ($request->method() !== 'POST') {
                    throw new OpenAiJsonlException('OpenAI batch requests must use POST.');
                }

                if ($request->endpoint() !== self::ENDPOINT) {
                    throw new OpenAiJsonlException('This package supports only the OpenAI /v1/responses batch endpoint.');
                }

                if ($request->headers() !== []) {
                    throw new OpenAiJsonlException('OpenAI batch requests cannot contain per-request headers.');
                }

                $body = $request->body();

                if ($body === []) {
                    throw new OpenAiJsonlException('Every OpenAI batch request body must be a JSON object.');
                }

                $requestModel = $body['model'] ?? null;

                if (! is_string($requestModel) || $requestModel === '') {
                    throw new OpenAiJsonlException('Every OpenAI batch request must contain a non-empty model.');
                }

                if ($model === null) {
                    $model = $requestModel;
                } elseif ($model !== $requestModel) {
                    throw new OpenAiJsonlException('Every OpenAI batch request must use the same model.');
                }

                try {
                    $line = json_encode([
                        'custom_id' => $customId,
                        'method' => 'POST',
                        'url' => self::ENDPOINT,
                        'body' => $body,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
                } catch (JsonException $exception) {
                    throw new OpenAiJsonlException(
                        "OpenAI batch request {$count} could not be encoded as JSON.",
                        lineNumber: $count,
                        previous: $exception,
                    );
                }

                $lineBytes = strlen($line);

                if ($bytes + $lineBytes > $this->maxBytes) {
                    throw new OpenAiJsonlException("An OpenAI batch input file may contain at most {$this->maxBytes} bytes.");
                }

                if (fwrite($stream, $line) !== $lineBytes) {
                    throw new OpenAiJsonlException('The OpenAI batch input file could not be written.');
                }

                $bytes += $lineBytes;
            }

            if ($count === 0 || $model === null) {
                throw new OpenAiJsonlException('An OpenAI batch must contain at least one request.');
            }

            if (! fflush($stream)) {
                throw new OpenAiJsonlException('The OpenAI batch input file could not be finalized.');
            }
        } catch (Throwable $exception) {
            fclose($stream);
            @unlink($path);

            if ($exception instanceof OpenAiJsonlException) {
                throw $exception;
            }

            throw new OpenAiJsonlException(
                'The OpenAI batch input file could not be generated.',
                previous: $exception,
            );
        }

        fclose($stream);

        return new OpenAiJsonlFile(
            path: $path,
            filename: 'batch-input-'.bin2hex(random_bytes(12)).'.jsonl',
            endpoint: self::ENDPOINT,
            model: $model,
            requestCount: $count,
            bytes: $bytes,
        );
    }
}
