<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Providers\OpenAI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Throwable;

final class OpenAiBatchClient
{
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly Factory $http,
        private readonly array $config,
    ) {}

    /** @return array<string, mixed> */
    public function uploadBatchInput(string $path, string $filename): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new OpenAiTransportException(
                'The OpenAI batch input file is not readable.',
                operation: 'upload',
            );
        }

        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw new OpenAiTransportException(
                'The OpenAI batch input file could not be opened.',
                operation: 'upload',
            );
        }

        try {
            return $this->sendJson(
                operation: 'upload',
                resourceId: null,
                callback: fn (PendingRequest $request): Response => $request
                    ->attach('file', $stream, $filename)
                    ->post('/files', ['purpose' => 'batch']),
            );
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  array<string, string>  $metadata
     * @return array<string, mixed>
     */
    public function createBatch(
        string $inputFileId,
        string $endpoint,
        string $completionWindow,
        array $metadata = [],
    ): array {
        $payload = [
            'input_file_id' => $inputFileId,
            'endpoint' => $endpoint,
            'completion_window' => $completionWindow,
        ];

        if ($metadata !== []) {
            $payload['metadata'] = $metadata;
        }

        return $this->sendJson(
            operation: 'create',
            resourceId: $inputFileId,
            callback: fn (PendingRequest $request): Response => $request->post('/batches', $payload),
        );
    }

    /** @return array<string, mixed> */
    public function retrieveBatch(string $batchId): array
    {
        return $this->sendJson(
            operation: 'retrieve',
            resourceId: $batchId,
            callback: fn (PendingRequest $request): Response => $request->get('/batches/'.rawurlencode($batchId)),
        );
    }

    /** @return array<string, mixed> */
    public function cancelBatch(string $batchId): array
    {
        return $this->sendJson(
            operation: 'cancel',
            resourceId: $batchId,
            callback: fn (PendingRequest $request): Response => $request->post('/batches/'.rawurlencode($batchId).'/cancel'),
        );
    }

    public function retrieveFileContent(string $fileId): string
    {
        [$response, $clientRequestId] = $this->send(
            operation: 'file-content',
            resourceId: $fileId,
            callback: fn (PendingRequest $request): Response => $request->get('/files/'.rawurlencode($fileId).'/content'),
        );

        $this->ensureSuccessful($response, 'file-content', $clientRequestId, $fileId);

        return $response->body();
    }

    /**
     * @param  callable(PendingRequest): Response  $callback
     * @return array<string, mixed>
     */
    private function sendJson(
        string $operation,
        ?string $resourceId,
        callable $callback,
    ): array {
        [$response, $clientRequestId] = $this->send($operation, $resourceId, $callback);

        $this->ensureSuccessful($response, $operation, $clientRequestId, $resourceId);

        try {
            $decoded = $response->json();
        } catch (Throwable $exception) {
            throw new OpenAiTransportException(
                'OpenAI returned an invalid JSON response.',
                operation: $operation,
                statusCode: $response->status(),
                requestId: $this->header($response, 'x-request-id'),
                clientRequestId: $clientRequestId,
                resourceId: $resourceId,
                previous: $exception,
            );
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new OpenAiTransportException(
                'OpenAI returned an invalid JSON object response.',
                operation: $operation,
                statusCode: $response->status(),
                requestId: $this->header($response, 'x-request-id'),
                clientRequestId: $clientRequestId,
                resourceId: $resourceId,
            );
        }

        return $decoded;
    }

    /**
     * @param  callable(PendingRequest): Response  $callback
     * @return array{Response, string}
     */
    private function send(
        string $operation,
        ?string $resourceId,
        callable $callback,
    ): array {
        $clientRequestId = (string) Str::uuid();

        try {
            $response = $callback($this->request($clientRequestId, $operation));
        } catch (OpenAiTransportException $exception) {
            throw $exception;
        } catch (ConnectionException $exception) {
            throw new OpenAiTransportException(
                'The OpenAI request failed before a response was received.',
                operation: $operation,
                clientRequestId: $clientRequestId,
                resourceId: $resourceId,
                previous: $exception,
            );
        } catch (Throwable $exception) {
            throw new OpenAiTransportException(
                'The OpenAI request could not be completed.',
                operation: $operation,
                clientRequestId: $clientRequestId,
                resourceId: $resourceId,
                previous: $exception,
            );
        }

        return [$response, $clientRequestId];
    }

    private function request(string $clientRequestId, string $operation): PendingRequest
    {
        $apiKey = $this->config['api_key'] ?? null;

        if (! is_string($apiKey) || $apiKey === '') {
            throw new OpenAiTransportException(
                'The OpenAI API key is not configured.',
                operation: 'configure',
                clientRequestId: $clientRequestId,
            );
        }

        $request = $this->http
            ->baseUrl($this->baseUrl())
            ->withToken($apiKey)
            ->acceptJson()
            ->connectTimeout($this->positiveInteger('connect_timeout', 10))
            ->timeout($this->operationTimeout($operation))
            ->withHeaders(['X-Client-Request-Id' => $clientRequestId]);

        $organization = $this->config['organization'] ?? null;
        $project = $this->config['project'] ?? null;

        if (is_string($organization) && $organization !== '') {
            $request->withHeaders(['OpenAI-Organization' => $organization]);
        }

        if (is_string($project) && $project !== '') {
            $request->withHeaders(['OpenAI-Project' => $project]);
        }

        return $request;
    }

    private function ensureSuccessful(
        Response $response,
        string $operation,
        string $clientRequestId,
        ?string $resourceId,
    ): void {
        if ($response->successful()) {
            return;
        }

        $error = $response->json('error');
        $error = is_array($error) ? $error : [];
        $code = $this->nullableString($error['code'] ?? null);
        $message = $this->safeMessage($error['message'] ?? null);

        throw new OpenAiTransportException(
            $message === null
                ? "OpenAI {$operation} failed with HTTP {$response->status()}."
                : "OpenAI {$operation} failed with HTTP {$response->status()}: {$message}",
            operation: $operation,
            statusCode: $response->status(),
            providerCode: $code,
            requestId: $this->header($response, 'x-request-id'),
            clientRequestId: $clientRequestId,
            resourceId: $resourceId,
        );
    }

    private function baseUrl(): string
    {
        $baseUrl = $this->config['base_url'] ?? self::DEFAULT_BASE_URL;

        return rtrim(is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : self::DEFAULT_BASE_URL, '/');
    }

    private function positiveInteger(string $key, int $default): int
    {
        $value = $this->config[$key] ?? $default;

        return is_int($value) && $value > 0 ? $value : $default;
    }

    private function operationTimeout(string $operation): int
    {
        $key = match ($operation) {
            'upload' => 'upload_timeout',
            'file-content' => 'download_timeout',
            default => 'request_timeout',
        };

        return $this->positiveInteger($key, 60);
    }

    private function header(Response $response, string $name): ?string
    {
        return $this->nullableString($response->header($name));
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function safeMessage(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $message = preg_replace(
            '/\b(authorization|proxy-authorization|cookie|set-cookie|x-api-key|api-key)\s*[:=]\s*[^\r\n]*/iu',
            '$1: [REDACTED]',
            $value,
        ) ?? 'Provider error.';
        $message = preg_replace(
            '/([?&](?:api_key|access_token|token)=)[^&\s]+/iu',
            '$1[REDACTED]',
            $message,
        ) ?? $message;
        $message = preg_replace('/[\r\n\t]+/u', ' ', $message) ?? 'Provider error.';
        $apiKey = $this->config['api_key'] ?? null;

        if (is_string($apiKey) && $apiKey !== '') {
            $message = str_replace($apiKey, '[REDACTED]', $message);
        }

        $message = preg_replace('/\bBearer\s+\S+/i', 'Bearer [REDACTED]', $message) ?? $message;

        return Str::limit($message, 500, '...');
    }
}
