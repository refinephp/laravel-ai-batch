<?php

declare(strict_types=1);

namespace RefinePhp\LaravelAiBatch\Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

#[Strict]
final class LookupFact implements Tool
{
    public function description(): string
    {
        return 'Looks up a fact by its subject.';
    }

    public function handle(Request $request): string
    {
        return 'A resolved fact.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'subject' => $schema->string()->required(),
        ];
    }
}
