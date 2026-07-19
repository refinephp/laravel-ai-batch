<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Providers\OpenAI;

use RefinePhp\LaravelAiBatch\Contracts\BatchProvider;
use RefinePhp\LaravelAiBatch\Data\BatchError;
use RefinePhp\LaravelAiBatch\Data\BatchResult;
use RefinePhp\LaravelAiBatch\Data\BatchSubmission;
use RefinePhp\LaravelAiBatch\Data\ProviderBatch;
use RefinePhp\LaravelAiBatch\Enums\BatchStatus;
use RefinePhp\LaravelAiBatch\Exceptions\AmbiguousBatchSubmissionException;
use RefinePhp\LaravelAiBatch\Exceptions\BatchCancellationException;
use RefinePhp\LaravelAiBatch\Exceptions\BatchRefreshException;
use RefinePhp\LaravelAiBatch\Exceptions\BatchResultRetrievalException;
use RefinePhp\LaravelAiBatch\Exceptions\BatchSubmissionException;
use RefinePhp\LaravelAiBatch\Exceptions\InvalidBatchRequestException;
use RefinePhp\LaravelAiBatch\Exceptions\MalformedBatchOutputException;
use RefinePhp\LaravelAiBatch\Exceptions\ProviderMismatchException;

final class OpenAiBatchProvider implements BatchProvider
{
    public const COMPLETION_WINDOW = '24h';

    public function __construct(
        private readonly OpenAiBatchClient $client,
        private readonly OpenAiJsonlWriter $writer,
        private readonly OpenAiStatusMapper $statusMapper,
        private readonly OpenAiJsonlParser $parser,
        private readonly string $connection = 'openai',
    ) {}

    public function name(): string
    {
        return $this->connection;
    }

    public function submit(BatchSubmission $submission): ProviderBatch
    {
        if ($submission->provider() !== $this->name()) {
            throw new ProviderMismatchException('The batch submission does not use this OpenAI provider connection.');
        }

        if ($submission->completionWindow() !== self::COMPLETION_WINDOW) {
            throw new InvalidBatchRequestException('OpenAI supports only a 24h batch completion window.');
        }

        try {
            $input = $this->writer->write($submission->requests(), $this->name());
        } catch (OpenAiJsonlException $exception) {
            throw new BatchSubmissionException($exception->getMessage(), previous: $exception);
        }

        $inputFileId = null;

        try {
            $uploaded = $this->client->uploadBatchInput($input->path(), $input->filename());
            $inputFileId = $this->requiredId($uploaded, 'OpenAI file upload');
            $created = $this->client->createBatch(
                inputFileId: $inputFileId,
                endpoint: $input->endpoint(),
                completionWindow: self::COMPLETION_WINDOW,
                metadata: ['application_batch_id' => $submission->id()],
            );

            $this->validateCreatedBatch($created, $input);

            return $this->statusMapper->map(
                payload: $created,
                localId: $submission->id(),
                provider: $submission->provider(),
                name: $submission->name(),
                inputFileId: $inputFileId,
                requestCount: $submission->requestCount(),
            );
        } catch (OpenAiTransportException $exception) {
            if ($exception->statusCode() === null && in_array($exception->operation(), ['upload', 'create'], true)) {
                $message = $exception->operation() === 'create' && $inputFileId !== null
                    ? sprintf(
                        'OpenAI batch creation may have succeeded after input upload [%s]; reconcile with client request ID [%s].',
                        $this->safeIdentifier($inputFileId),
                        $this->safeIdentifier($exception->clientRequestId() ?? 'unavailable'),
                    )
                    : sprintf(
                        'The OpenAI batch input upload may have succeeded; reconcile with client request ID [%s].',
                        $this->safeIdentifier($exception->clientRequestId() ?? 'unavailable'),
                    );

                throw new AmbiguousBatchSubmissionException($message, previous: $exception);
            }

            throw new BatchSubmissionException($exception->getMessage(), previous: $exception);
        } catch (OpenAiPayloadException $exception) {
            throw new BatchSubmissionException($exception->getMessage(), previous: $exception);
        } finally {
            $input->delete();
        }
    }

    public function refresh(ProviderBatch $batch): ProviderBatch
    {
        $this->assertProvider($batch);

        try {
            $payload = $this->client->retrieveBatch($batch->providerBatchId());

            return $this->statusMapper->map(
                payload: $payload,
                localId: $batch->id(),
                provider: $batch->provider(),
                name: $batch->name(),
                previous: $batch,
            );
        } catch (OpenAiTransportException|OpenAiPayloadException $exception) {
            throw new BatchRefreshException($exception->getMessage(), previous: $exception);
        }
    }

    public function cancel(ProviderBatch $batch): ProviderBatch
    {
        $this->assertProvider($batch);

        if (! $batch->canBeCancelled()) {
            throw new BatchCancellationException('The OpenAI batch is not in a cancellable state.');
        }

        try {
            $payload = $this->client->cancelBatch($batch->providerBatchId());

            return $this->mapLifecyclePayload($payload, $batch);
        } catch (OpenAiTransportException $exception) {
            if ($exception->operation() === 'cancel' && $exception->statusCode() === null) {
                return $this->reconcileCancellation($batch, $exception);
            }

            throw new BatchCancellationException($exception->getMessage(), previous: $exception);
        } catch (OpenAiPayloadException $exception) {
            throw new BatchCancellationException($exception->getMessage(), previous: $exception);
        }
    }

    /** @return iterable<int, BatchResult> */
    public function results(ProviderBatch $batch): iterable
    {
        $this->assertProvider($batch);
        $this->assertResultsReady($batch);

        $seen = [];

        foreach ([$batch->outputFileId(), $batch->errorFileId()] as $fileId) {
            if ($fileId === null) {
                continue;
            }

            foreach ($this->resultsFromFile($fileId) as $result) {
                if (isset($seen[$result->customId()])) {
                    throw new MalformedBatchOutputException(
                        'OpenAI returned the same custom ID in more than one result record.',
                    );
                }

                $seen[$result->customId()] = true;

                yield $result;
            }
        }

        if ($batch->isTerminal()
            && $batch->status() !== BatchStatus::Failed
            && count($seen) !== $batch->requestCount()) {
            throw new MalformedBatchOutputException(sprintf(
                'A terminal OpenAI batch expected %d result records but received %d.',
                $batch->requestCount(),
                count($seen),
            ));
        }
    }

    /** @return iterable<int, BatchError> */
    public function errors(ProviderBatch $batch): iterable
    {
        $this->assertProvider($batch);
        $this->assertResultsReady($batch);

        foreach ($this->results($batch) as $result) {
            if ($result->error() !== null) {
                yield $result->error();
            }
        }
    }

    /** @return iterable<int, BatchResult> */
    private function resultsFromFile(string $fileId): iterable
    {
        try {
            $content = $this->client->retrieveFileContent($fileId);

            yield from $this->parser->parse($content, $fileId);
        } catch (OpenAiJsonlException $exception) {
            $location = $this->safeIdentifier($fileId);

            if ($exception->line() !== null) {
                $location .= " line {$exception->line()}";
            }

            throw new MalformedBatchOutputException(
                "Malformed OpenAI batch output in {$location}: {$exception->getMessage()}",
                previous: $exception,
            );
        } catch (OpenAiTransportException $exception) {
            throw new BatchResultRetrievalException($exception->getMessage(), previous: $exception);
        }
    }

    private function assertProvider(ProviderBatch $batch): void
    {
        if ($batch->provider() !== $this->name()) {
            throw new ProviderMismatchException('The batch snapshot does not use this OpenAI provider connection.');
        }
    }

    private function assertResultsReady(ProviderBatch $batch): void
    {
        if (! $batch->isTerminal()) {
            throw new BatchResultRetrievalException('OpenAI batch results are not ready until the batch is terminal.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function mapLifecyclePayload(array $payload, ProviderBatch $batch): ProviderBatch
    {
        return $this->statusMapper->map(
            payload: $payload,
            localId: $batch->id(),
            provider: $batch->provider(),
            name: $batch->name(),
            previous: $batch,
        );
    }

    private function reconcileCancellation(
        ProviderBatch $batch,
        OpenAiTransportException $cancellationFailure,
    ): ProviderBatch {
        try {
            $payload = $this->client->retrieveBatch($batch->providerBatchId());
            $reconciled = $this->mapLifecyclePayload($payload, $batch);

            if ($reconciled->status() === BatchStatus::Cancelling
                || $reconciled->isTerminal()) {
                return $reconciled;
            }

            throw new BatchCancellationException(
                'The OpenAI cancellation response was lost and cancellation acceptance could not be confirmed.',
                previous: $cancellationFailure,
            );
        } catch (OpenAiTransportException|OpenAiPayloadException) {
            throw new BatchCancellationException(
                'The OpenAI cancellation response was lost and the remote batch state could not be reconciled.',
                previous: $cancellationFailure,
            );
        }
    }

    /** @param array<string, mixed> $payload */
    private function requiredId(array $payload, string $resource): string
    {
        $id = $payload['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new OpenAiPayloadException("{$resource} returned no valid resource ID.");
        }

        return $id;
    }

    /** @param array<string, mixed> $payload */
    private function validateCreatedBatch(array $payload, OpenAiJsonlFile $input): void
    {
        $endpoint = $payload['endpoint'] ?? null;
        $model = $payload['model'] ?? null;

        if ($endpoint !== null && $endpoint !== $input->endpoint()) {
            throw new OpenAiPayloadException('OpenAI created a batch for an unexpected endpoint.');
        }

        if ($model !== null && $model !== $input->model()) {
            throw new OpenAiPayloadException('OpenAI created a batch for an unexpected model.');
        }
    }

    private function safeIdentifier(string $value): string
    {
        $value = preg_replace('/[^\x20-\x7E]/', '?', $value) ?? 'unknown-file';

        return substr($value, 0, 200);
    }
}
