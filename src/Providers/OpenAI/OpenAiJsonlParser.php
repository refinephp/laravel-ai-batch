<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Providers\OpenAI;

use Illuminate\Support\Str;
use JsonException;
use RefinePhp\LaravelAiBatch\Data\BatchError;
use RefinePhp\LaravelAiBatch\Data\BatchResult;

final class OpenAiJsonlParser
{
    /** @return iterable<int, BatchResult> */
    public function parse(string $content, string $fileId): iterable
    {
        if ($content === '') {
            return;
        }

        if (! str_ends_with($content, "\n")) {
            throw new OpenAiJsonlException(
                'The OpenAI batch output ended with a partial JSONL record.',
                fileId: $fileId,
            );
        }

        $seen = [];
        $lines = explode("\n", $content);
        array_pop($lines);

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;

            if ($line === '') {
                throw new OpenAiJsonlException(
                    'The OpenAI batch output contains a blank JSONL record.',
                    fileId: $fileId,
                    lineNumber: $lineNumber,
                );
            }

            try {
                $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new OpenAiJsonlException(
                    'The OpenAI batch output contains malformed JSON.',
                    fileId: $fileId,
                    lineNumber: $lineNumber,
                    previous: $exception,
                );
            }

            if (! is_array($record) || array_is_list($record)) {
                throw $this->malformed($fileId, $lineNumber, 'record must be a JSON object');
            }

            $customId = $record['custom_id'] ?? null;

            if (! is_string($customId) || $customId === '') {
                throw $this->malformed($fileId, $lineNumber, 'custom_id must be a non-empty string');
            }

            if (isset($seen[$customId])) {
                throw $this->malformed($fileId, $lineNumber, 'custom_id is duplicated');
            }

            $seen[$customId] = true;
            $response = $record['response'] ?? null;
            $error = $record['error'] ?? null;

            if (($response === null) === ($error === null)) {
                throw $this->malformed($fileId, $lineNumber, 'exactly one response or error is required');
            }

            if ($response !== null) {
                yield $this->responseResult($customId, $response, $fileId, $lineNumber);

                continue;
            }

            yield $this->executionErrorResult($customId, $error, $fileId, $lineNumber);
        }
    }

    private function responseResult(
        string $customId,
        mixed $response,
        string $fileId,
        int $line,
    ): BatchResult {
        if (! is_array($response) || array_is_list($response)) {
            throw $this->malformed($fileId, $line, 'response must be a JSON object');
        }

        $statusCode = $response['status_code'] ?? null;
        $providerRequestId = $this->nullableString($response['request_id'] ?? null);
        $body = $response['body'] ?? null;

        if (! is_int($statusCode) || $statusCode < 100 || $statusCode > 599) {
            throw $this->malformed($fileId, $line, 'response.status_code must be an HTTP status code');
        }

        if (! is_array($body) || array_is_list($body)) {
            throw $this->malformed($fileId, $line, 'response.body must be a JSON object');
        }

        if ($statusCode >= 200 && $statusCode < 300) {
            return new BatchResult(
                customId: $customId,
                providerRequestId: $providerRequestId,
                statusCode: $statusCode,
                response: $body,
                error: null,
            );
        }

        $providerError = $body['error'] ?? null;
        $providerError = is_array($providerError) && ! array_is_list($providerError) ? $providerError : [];

        $error = new BatchError(
            customId: $customId,
            code: $this->nullableString($providerError['code'] ?? null),
            message: $this->safeMessage($providerError['message'] ?? null)
                ?? "OpenAI request failed with HTTP {$statusCode}.",
            parameter: $this->nullableString($providerError['param'] ?? null),
            line: $line,
            statusCode: $statusCode,
            providerRequestId: $providerRequestId,
        );

        return new BatchResult(
            customId: $customId,
            providerRequestId: $providerRequestId,
            statusCode: $statusCode,
            response: null,
            error: $error,
        );
    }

    private function executionErrorResult(
        string $customId,
        mixed $error,
        string $fileId,
        int $line,
    ): BatchResult {
        if (! is_array($error) || array_is_list($error)) {
            throw $this->malformed($fileId, $line, 'error must be a JSON object');
        }

        $batchError = new BatchError(
            customId: $customId,
            code: $this->nullableString($error['code'] ?? null),
            message: $this->safeMessage($error['message'] ?? null) ?? 'OpenAI could not execute the batch request.',
            parameter: $this->nullableString($error['param'] ?? null),
            line: $line,
        );

        return new BatchResult(
            customId: $customId,
            providerRequestId: null,
            statusCode: null,
            response: null,
            error: $batchError,
        );
    }

    private function malformed(string $fileId, int $line, string $reason): OpenAiJsonlException
    {
        return new OpenAiJsonlException(
            "The OpenAI batch output record is invalid: {$reason}.",
            fileId: $fileId,
            lineNumber: $line,
        );
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

        return Str::limit(preg_replace('/[\r\n\t]+/u', ' ', $value) ?? 'Provider error.', 500, '...');
    }
}
