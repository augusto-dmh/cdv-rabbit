<?php

namespace App\Services\Llm;

use App\Services\Llm\Dto\ReviewResultDto;

interface LlmDriverInterface
{
    public function reviewDiff(
        string $systemPrompt,
        array $toolSchema,
        string $userMessage,
        array $options = [],
    ): ReviewResultDto;
}
