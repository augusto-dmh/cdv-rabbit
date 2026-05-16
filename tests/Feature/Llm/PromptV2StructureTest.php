<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

test('AC44: review_v2.txt contains exactly 5 positive <example> tags', function (): void {
    $prompt = File::get(base_path('config/cdv-rabbit/prompts/review_v2.txt'));

    $openCount = preg_match_all('/<example\b[^>]*>/', $prompt);
    $closeCount = preg_match_all('/<\/example>/', $prompt);

    expect($openCount)->toBe(
        5,
        sprintf('Expected exactly 5 positive <example> tags in review_v2.txt; found %d.', $openCount)
    );
    expect($closeCount)->toBe(
        5,
        sprintf('Expected exactly 5 closing </example> tags in review_v2.txt; found %d.', $closeCount)
    );
});

test('AC44: review_v2.txt contains exactly 2 negative <counter_example> tags', function (): void {
    $prompt = File::get(base_path('config/cdv-rabbit/prompts/review_v2.txt'));

    $openCount = preg_match_all('/<counter_example\b[^>]*>/', $prompt);
    $closeCount = preg_match_all('/<\/counter_example>/', $prompt);

    expect($openCount)->toBe(
        2,
        sprintf('Expected exactly 2 negative <counter_example> tags in review_v2.txt; found %d.', $openCount)
    );
    expect($closeCount)->toBe(
        2,
        sprintf('Expected exactly 2 closing </counter_example> tags in review_v2.txt; found %d.', $closeCount)
    );
});

test('AC44: combined review_v2 prompt + schema exceeds 1024-token cacheable prefix threshold', function (): void {
    $prompt = File::get(base_path('config/cdv-rabbit/prompts/review_v2.txt'));
    $schema = File::get(base_path('config/cdv-rabbit/schemas/review_result_v2.json'));

    $combined = $prompt.$schema;

    // Conservative char/4 heuristic — Anthropic's tokenizer is usually denser for English prose,
    // so clearing 1024 tokens here guarantees the real prefix is also cacheable on Sonnet 4.6.
    $approximateTokens = (int) ceil(mb_strlen($combined) / 4);

    expect($approximateTokens)->toBeGreaterThan(
        1024,
        sprintf(
            'Combined v2 prefix is only ~%d tokens (mb_strlen=%d). Anthropic requires >1024 tokens for prompt caching. Expand review_v2.txt to clear the threshold.',
            $approximateTokens,
            mb_strlen($combined)
        )
    );

    // Diagnostic line — visible in --verbose / failure output so reviewers can eyeball cache-prefix headroom.
    fwrite(STDERR, sprintf(
        "\n[PromptV2StructureTest] prompt=%d chars, schema=%d chars, combined=%d chars, ~%d tokens\n",
        mb_strlen($prompt),
        mb_strlen($schema),
        mb_strlen($combined),
        $approximateTokens
    ));
});

test('AC44: review_v2.txt does NOT contain the v1 silence-bias instructions', function (): void {
    $prompt = File::get(base_path('config/cdv-rabbit/prompts/review_v2.txt'));

    $forbiddenPhrases = [
        'do not invent findings',
        'prefer silence over noise',
        'preferred when no review-worthy issues exist',
    ];

    foreach ($forbiddenPhrases as $phrase) {
        expect(mb_stripos($prompt, $phrase))->toBeFalse(
            sprintf('review_v2.txt still contains the v1 silence-bias phrase "%s". This is the bias W7-T3 is explicitly removing.', $phrase)
        );
    }
});

test('AC44: every positive <example> label is prefixed with "positive:"', function (): void {
    $prompt = File::get(base_path('config/cdv-rabbit/prompts/review_v2.txt'));

    preg_match_all('/<example\s+label="([^"]+)">/', $prompt, $matches);

    expect($matches[1])->toHaveCount(5);

    foreach ($matches[1] as $label) {
        expect($label)->toStartWith(
            'positive:',
            sprintf('Positive example label "%s" must begin with the literal substring "positive:".', $label)
        );
    }
});

test('AC44: every negative <counter_example> label is prefixed with "DO NOT EMIT:"', function (): void {
    $prompt = File::get(base_path('config/cdv-rabbit/prompts/review_v2.txt'));

    preg_match_all('/<counter_example\s+label="([^"]+)">/', $prompt, $matches);

    expect($matches[1])->toHaveCount(2);

    foreach ($matches[1] as $label) {
        expect($label)->toStartWith(
            'DO NOT EMIT:',
            sprintf('Counter-example label "%s" must begin with the literal substring "DO NOT EMIT:".', $label)
        );
    }
});

test('AC44: review_v2.txt references the v2 schema name review_result_v2', function (): void {
    $prompt = File::get(base_path('config/cdv-rabbit/prompts/review_v2.txt'));

    expect(mb_stripos($prompt, 'review_result_v2'))->not->toBeFalse(
        'Prompt and schema stay co-named; review_v2.txt must reference review_result_v2 explicitly so drift between the two is immediately visible.'
    );
});

test('AC44: review_v2.txt explicitly forbids praise words in the walkthrough', function (): void {
    $prompt = File::get(base_path('config/cdv-rabbit/prompts/review_v2.txt'));

    // The Walkthrough rules section must list these as forbidden content so the model
    // does not regress into the "nicely centralizes" sycophancy we saw on DocInt PR #35.
    $forbiddenWordsListedAsForbidden = [
        'beneficial',
        'well-structured',
    ];

    foreach ($forbiddenWordsListedAsForbidden as $word) {
        expect(mb_stripos($prompt, $word))->not->toBeFalse(
            sprintf('review_v2.txt must explicitly list "%s" as a forbidden walkthrough word so the model learns to avoid praise.', $word)
        );
    }

    // And the literal banner "praise" must appear so the rule is unambiguous.
    expect(mb_stripos($prompt, 'praise'))->not->toBeFalse(
        'review_v2.txt must explicitly forbid "praise" in the walkthrough rules section.'
    );
});
