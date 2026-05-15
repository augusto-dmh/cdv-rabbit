<?php

declare(strict_types=1);

use App\Logging\ReviewsChannelTap;
use App\Models\Workspace;
use App\Queue\RedactingFailedJobProvider;
use Illuminate\Support\Facades\Schema;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function dpoSignoffPath(): string
{
    return storage_path('app/dpo-signoff-test.json');
}

function writeDpoSignoff(?string $signer = 'Test DPO', ?string $signedAt = null): void
{
    $signedAt ??= now()->toIso8601String();

    file_put_contents(dpoSignoffPath(), json_encode([
        'signer' => $signer,
        'signed_at' => $signedAt,
    ]));
}

function setDpoSignoffPath(): void
{
    config(['cdv-rabbit.dpo_signoff_path' => dpoSignoffPath()]);
}

afterEach(function (): void {
    if (file_exists(dpoSignoffPath())) {
        unlink(dpoSignoffPath());
    }
});

// ---------------------------------------------------------------------------
// Aggregate: all checks green → exit 0
// ---------------------------------------------------------------------------

it('exits 0 when all checks pass', function (): void {
    config([
        'cdv-rabbit.anthropic_dpa_url' => 'https://anthropic.com/dpa',
        'cdv-rabbit.dpo_signoff_path' => dpoSignoffPath(),
    ]);

    writeDpoSignoff();

    $this->artisan('rabbit:lgpd-check')->assertSuccessful();
});

// ---------------------------------------------------------------------------
// Schema audit
// ---------------------------------------------------------------------------

it('passes schema audit when no sensitive columns exist on audited tables', function (): void {
    config([
        'cdv-rabbit.anthropic_dpa_url' => 'https://anthropic.com/dpa',
        'cdv-rabbit.dpo_signoff_path' => dpoSignoffPath(),
    ]);
    writeDpoSignoff();

    $this->artisan('rabbit:lgpd-check')->assertSuccessful();
});

it('fails schema audit when a sensitive column exists on an audited table', function (): void {
    // Add a forbidden column temporarily
    Schema::table('reviews', function ($table) {
        $table->text('diff')->nullable();
    });

    config([
        'cdv-rabbit.anthropic_dpa_url' => 'https://anthropic.com/dpa',
        'cdv-rabbit.dpo_signoff_path' => dpoSignoffPath(),
    ]);
    writeDpoSignoff();

    $this->artisan('rabbit:lgpd-check')->assertFailed();

    Schema::table('reviews', function ($table) {
        $table->dropColumn('diff');
    });
});

// ---------------------------------------------------------------------------
// Encryption casts
// ---------------------------------------------------------------------------

it('passes encryption cast check when both fields use encrypted cast', function (): void {
    config([
        'cdv-rabbit.anthropic_dpa_url' => 'https://anthropic.com/dpa',
        'cdv-rabbit.dpo_signoff_path' => dpoSignoffPath(),
    ]);
    writeDpoSignoff();

    $this->artisan('rabbit:lgpd-check')->assertSuccessful();
});

it('fails encryption cast check when bitbucket_token cast is manipulated', function (): void {
    // Swap the cast by partially mocking the model
    $workspace = Mockery::mock(Workspace::class)->makePartial();
    $workspace->shouldReceive('getCasts')->andReturn([
        'bitbucket_token' => 'string', // plain — not encrypted
        'webhook_secret' => 'encrypted',
        'kill_switch_enabled' => 'boolean',
    ]);

    app()->bind(Workspace::class, fn () => $workspace);

    config([
        'cdv-rabbit.anthropic_dpa_url' => 'https://anthropic.com/dpa',
        'cdv-rabbit.dpo_signoff_path' => dpoSignoffPath(),
    ]);
    writeDpoSignoff();

    $this->artisan('rabbit:lgpd-check')->assertFailed();
});

// ---------------------------------------------------------------------------
// Failed-jobs provider
// ---------------------------------------------------------------------------

it('passes failed-jobs provider check when RedactingFailedJobProvider is bound', function (): void {
    config([
        'cdv-rabbit.anthropic_dpa_url' => 'https://anthropic.com/dpa',
        'cdv-rabbit.dpo_signoff_path' => dpoSignoffPath(),
    ]);
    writeDpoSignoff();

    expect(app('queue.failer'))->toBeInstanceOf(RedactingFailedJobProvider::class);

    $this->artisan('rabbit:lgpd-check')->assertSuccessful();
});

it('fails failed-jobs provider check when a different provider is bound', function (): void {
    $fake = new class
    {
        // not a RedactingFailedJobProvider
    };

    // Use instance() to bypass the extend() wrapper registered in AppServiceProvider
    app()->instance('queue.failer', $fake);

    config([
        'cdv-rabbit.anthropic_dpa_url' => 'https://anthropic.com/dpa',
        'cdv-rabbit.dpo_signoff_path' => dpoSignoffPath(),
    ]);
    writeDpoSignoff();

    $this->artisan('rabbit:lgpd-check')->assertFailed();
});

// ---------------------------------------------------------------------------
// Redacting processor
// ---------------------------------------------------------------------------

it('passes redacting processor check when ReviewsChannelTap is in tap list', function (): void {
    config([
        'cdv-rabbit.anthropic_dpa_url' => 'https://anthropic.com/dpa',
        'cdv-rabbit.dpo_signoff_path' => dpoSignoffPath(),
        'logging.channels.cdv-rabbit-reviews.tap' => [ReviewsChannelTap::class],
    ]);
    writeDpoSignoff();

    $this->artisan('rabbit:lgpd-check')->assertSuccessful();
});

it('fails redacting processor check when tap list is empty', function (): void {
    config([
        'logging.channels.cdv-rabbit-reviews.tap' => [],
        'cdv-rabbit.anthropic_dpa_url' => 'https://anthropic.com/dpa',
        'cdv-rabbit.dpo_signoff_path' => dpoSignoffPath(),
    ]);
    writeDpoSignoff();

    $this->artisan('rabbit:lgpd-check')->assertFailed();
});

// ---------------------------------------------------------------------------
// SecretRedactor sanity
// ---------------------------------------------------------------------------

it('passes secret redactor check when AWS key is redacted', function (): void {
    config([
        'cdv-rabbit.anthropic_dpa_url' => 'https://anthropic.com/dpa',
        'cdv-rabbit.dpo_signoff_path' => dpoSignoffPath(),
    ]);
    writeDpoSignoff();

    $this->artisan('rabbit:lgpd-check')->assertSuccessful();
});

// ---------------------------------------------------------------------------
// Retention schedule
// ---------------------------------------------------------------------------

it('passes retention schedule check when rabbit:purge-stale is in console.php', function (): void {
    config([
        'cdv-rabbit.anthropic_dpa_url' => 'https://anthropic.com/dpa',
        'cdv-rabbit.dpo_signoff_path' => dpoSignoffPath(),
    ]);
    writeDpoSignoff();

    expect(file_get_contents(base_path('routes/console.php')))->toContain('rabbit:purge-stale');

    $this->artisan('rabbit:lgpd-check')->assertSuccessful();
});

// ---------------------------------------------------------------------------
// Anthropic DPA URL
// ---------------------------------------------------------------------------

it('passes anthropic DPA check when url is configured', function (): void {
    config([
        'cdv-rabbit.anthropic_dpa_url' => 'https://anthropic.com/dpa',
        'cdv-rabbit.dpo_signoff_path' => dpoSignoffPath(),
    ]);
    writeDpoSignoff();

    $this->artisan('rabbit:lgpd-check')->assertSuccessful();
});

it('fails anthropic DPA check when url is not set', function (): void {
    config([
        'cdv-rabbit.anthropic_dpa_url' => null,
        'cdv-rabbit.dpo_signoff_path' => dpoSignoffPath(),
    ]);
    writeDpoSignoff();

    $this->artisan('rabbit:lgpd-check')->assertFailed();
});

// ---------------------------------------------------------------------------
// DPO sign-off
// ---------------------------------------------------------------------------

it('passes DPO sign-off check with a valid recent file', function (): void {
    config([
        'cdv-rabbit.anthropic_dpa_url' => 'https://anthropic.com/dpa',
        'cdv-rabbit.dpo_signoff_path' => dpoSignoffPath(),
    ]);
    writeDpoSignoff('LGPD Officer', now()->subMonths(3)->toIso8601String());

    $this->artisan('rabbit:lgpd-check')->assertSuccessful();
});

it('fails DPO sign-off check when file does not exist', function (): void {
    config([
        'cdv-rabbit.anthropic_dpa_url' => 'https://anthropic.com/dpa',
        'cdv-rabbit.dpo_signoff_path' => dpoSignoffPath(),
    ]);
    // Don't write the file

    $this->artisan('rabbit:lgpd-check')->assertFailed();
});

it('fails DPO sign-off check when signed_at is older than 1 year', function (): void {
    config([
        'cdv-rabbit.anthropic_dpa_url' => 'https://anthropic.com/dpa',
        'cdv-rabbit.dpo_signoff_path' => dpoSignoffPath(),
    ]);
    writeDpoSignoff('LGPD Officer', now()->subYears(2)->toIso8601String());

    $this->artisan('rabbit:lgpd-check')->assertFailed();
});

it('fails DPO sign-off check when signer is missing', function (): void {
    config([
        'cdv-rabbit.anthropic_dpa_url' => 'https://anthropic.com/dpa',
        'cdv-rabbit.dpo_signoff_path' => dpoSignoffPath(),
    ]);
    writeDpoSignoff(null, now()->toIso8601String());

    $this->artisan('rabbit:lgpd-check')->assertFailed();
});

// ---------------------------------------------------------------------------
// OpenAI DPA URL (check #9)
// ---------------------------------------------------------------------------

it('check 9 passes when no workspaces use openai', function (): void {
    config([
        'cdv-rabbit.anthropic_dpa_url' => 'https://anthropic.com/dpa',
        'cdv-rabbit.openai_dpa_url' => null,
        'cdv-rabbit.dpo_signoff_path' => dpoSignoffPath(),
    ]);
    writeDpoSignoff();

    $this->artisan('rabbit:lgpd-check')->assertSuccessful();
});

it('check 9 fails when openai workspace exists and dpa url is missing', function (): void {
    Workspace::factory()->create(['llm_provider' => 'openai']);

    config([
        'cdv-rabbit.anthropic_dpa_url' => 'https://anthropic.com/dpa',
        'cdv-rabbit.openai_dpa_url' => null,
        'cdv-rabbit.dpo_signoff_path' => dpoSignoffPath(),
    ]);
    writeDpoSignoff();

    $this->artisan('rabbit:lgpd-check')->assertFailed();
});

it('check 9 passes when openai workspace exists and dpa url is set', function (): void {
    Workspace::factory()->create(['llm_provider' => 'openai']);

    config([
        'cdv-rabbit.anthropic_dpa_url' => 'https://anthropic.com/dpa',
        'cdv-rabbit.openai_dpa_url' => 'https://openai.com/dpa',
        'cdv-rabbit.dpo_signoff_path' => dpoSignoffPath(),
    ]);
    writeDpoSignoff();

    $this->artisan('rabbit:lgpd-check')->assertSuccessful();
});
