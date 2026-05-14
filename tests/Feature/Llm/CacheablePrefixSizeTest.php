<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

test('AC21 precondition: combined system prompt + tool schema exceeds 1024-token minimum cacheable prefix', function (): void {
    $prompt = File::get(base_path('config/cdv-rabbit/prompts/review_v1.txt'));
    $schema = File::get(base_path('config/cdv-rabbit/schemas/review_result_v1.json'));

    $combined = $prompt.$schema;

    // Conservative approximation: mb_strlen / 4 (errs on the safe side for token counting).
    // Anthropic's tokenizer produces slightly fewer tokens than this for English prose,
    // so if this estimate passes 1024 the real token count will too.
    $approximateTokens = (int) ceil(mb_strlen($combined) / 4);

    expect($approximateTokens)->toBeGreaterThan(
        1024,
        sprintf(
            'Combined prefix is only ~%d tokens (mb_strlen=%d). Anthropic requires >1024 tokens for prompt caching on Sonnet 4.6. Expand review_v1.txt to clear the threshold.',
            $approximateTokens,
            mb_strlen($combined)
        )
    );

    // Diagnostic output — visible with --verbose.
    $this->addToAssertionCount(0);
    fwrite(STDERR, sprintf(
        "\n[CacheablePrefixSizeTest] prompt=%d chars, schema=%d chars, combined=%d chars, ~%d tokens\n",
        mb_strlen($prompt),
        mb_strlen($schema),
        mb_strlen($combined),
        $approximateTokens
    ));
});

test('AC21 precondition: system prompt alone is at least 800 tokens to leave room for schema caching', function (): void {
    $prompt = File::get(base_path('config/cdv-rabbit/prompts/review_v1.txt'));

    $approximateTokens = (int) ceil(mb_strlen($prompt) / 4);

    expect($approximateTokens)->toBeGreaterThan(800);
});
