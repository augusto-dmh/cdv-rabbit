<?php

declare(strict_types=1);

namespace App\Services\Eval;

interface JudgeLlmCallerInterface
{
    /**
     * Issue a raw LLM completion against the named provider and return the
     * model's textual response verbatim. Implementations are free to choose
     * their own SDK path; the eval harness only requires that the response is
     * a JSON string parseable per the judge prompt contract.
     */
    public function complete(string $provider, string $systemPrompt, string $userMessage): string;
}
