<?php

declare(strict_types=1);

use App\Services\Eval\JudgeLlmCallerInterface;
use App\Services\Eval\LlmJudge;

function makeFakeCaller(?string $response = null, ?array &$invocations = null): JudgeLlmCallerInterface
{
    $invocations ??= [];

    return new class($response, $invocations) implements JudgeLlmCallerInterface
    {
        public function __construct(
            private readonly ?string $response,
            private array &$invocations,
        ) {}

        public function complete(string $provider, string $systemPrompt, string $userMessage): string
        {
            $this->invocations[] = compact('provider', 'systemPrompt', 'userMessage');

            return $this->response ?? '{"matches":[],"missed_expected":[],"unexpected_actual":[]}';
        }
    };
}

function judgeWith(JudgeLlmCallerInterface $caller, bool $crossProvider = true): LlmJudge
{
    return new LlmJudge(
        caller: $caller,
        judgePromptPath: __DIR__.'/../../../config/cdv-rabbit/prompts/eval_judge_v1.txt',
        crossProviderJudge: $crossProvider,
    );
}

it('rotates anthropic-reviewed cells to an openai judge (AC41)', function (): void {
    $invocations = [];
    $judge = judgeWith(makeFakeCaller(invocations: $invocations));

    $judge->judge([['path' => 'a.php']], [['path' => 'a.php']], 'anthropic');

    expect($invocations)->toHaveCount(1);
    expect($invocations[0]['provider'])->toBe('openai');
});

it('rotates openai-reviewed cells to an anthropic judge (AC41)', function (): void {
    $invocations = [];
    $judge = judgeWith(makeFakeCaller(invocations: $invocations));

    $judge->judge([['path' => 'a.php']], [['path' => 'a.php']], 'openai');

    expect($invocations)->toHaveCount(1);
    expect($invocations[0]['provider'])->toBe('anthropic');
});

it('never picks the same provider as the reviewer', function (): void {
    $judge = judgeWith(makeFakeCaller());

    expect($judge->pickJudgeProvider('anthropic'))->toBe('openai');
    expect($judge->pickJudgeProvider('openai'))->toBe('anthropic');
});

it('rejects unknown review providers', function (): void {
    $judge = judgeWith(makeFakeCaller());
    $judge->pickJudgeProvider('gemini');
})->throws(InvalidArgumentException::class);

// ADR 0007 §2: same-provider rotation flag for single-credential environments
it('picks the same provider as the reviewer when cross_provider_judge is disabled', function (): void {
    $judge = judgeWith(makeFakeCaller(), crossProvider: false);

    expect($judge->pickJudgeProvider('anthropic'))->toBe('anthropic');
    expect($judge->pickJudgeProvider('openai'))->toBe('openai');
});

it('routes the judge call to the same provider when cross_provider_judge is disabled', function (): void {
    $invocations = [];
    $judge = judgeWith(makeFakeCaller(invocations: $invocations), crossProvider: false);

    $judge->judge([['path' => 'a.php']], [['path' => 'a.php']], 'openai');

    expect($invocations)->toHaveCount(1);
    expect($invocations[0]['provider'])->toBe('openai');
});

it('computes recall and precision from judge matches', function (): void {
    $caller = makeFakeCaller(json_encode([
        'matches' => [
            ['actual_idx' => 0, 'expected_idx' => 0],
            ['actual_idx' => 1, 'expected_idx' => 1],
        ],
        'missed_expected' => [2],
        'unexpected_actual' => [2, 3],
    ]));
    $judge = judgeWith($caller);

    $actual = [
        ['path' => 'a.php', 'message' => 'x'],
        ['path' => 'b.php', 'message' => 'y'],
        ['path' => 'c.php', 'message' => 'hallucinated 1'],
        ['path' => 'd.php', 'message' => 'hallucinated 2'],
    ];
    $expected = [
        ['path' => 'a.php', 'rationale' => 'x'],
        ['path' => 'b.php', 'rationale' => 'y'],
        ['path' => 'z.php', 'rationale' => 'missed'],
    ];

    $verdict = $judge->judge($actual, $expected, 'anthropic');

    expect($verdict['recall'])->toBe(round(2 / 3, 4));
    expect($verdict['precision'])->toBe(round(2 / 4, 4));
    expect($verdict['judge_provider'])->toBe('openai');
    expect($verdict['matched'])->toHaveCount(2);
    expect($verdict['missed'])->toBe([2]);
    expect($verdict['unexpected'])->toBe([2, 3]);
});

it('returns trivial verdict for empty inputs without calling the LLM', function (): void {
    $invocations = [];
    $judge = judgeWith(makeFakeCaller(invocations: $invocations));

    $verdict = $judge->judge([], [], 'anthropic');

    expect($invocations)->toBeEmpty();
    expect($verdict['recall'])->toBe(1.0);
    expect($verdict['precision'])->toBe(1.0);
    expect($verdict['judge_provider'])->toBe('openai');
});

it('extracts JSON object from a noisy response', function (): void {
    $caller = makeFakeCaller('Here is my verdict: {"matches":[{"actual_idx":0,"expected_idx":0}],"missed_expected":[],"unexpected_actual":[]} Thanks!');
    $judge = judgeWith($caller);

    $verdict = $judge->judge(
        [['path' => 'a.php']],
        [['path' => 'a.php']],
        'anthropic',
    );

    expect($verdict['matched'])->toHaveCount(1);
    expect($verdict['recall'])->toBe(1.0);
});

it('throws when judge response is not parseable JSON', function (): void {
    $caller = makeFakeCaller('totally not json');
    $judge = judgeWith($caller);

    $judge->judge([['path' => 'a.php']], [['path' => 'a.php']], 'anthropic');
})->throws(RuntimeException::class);
