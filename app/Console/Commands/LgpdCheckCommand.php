<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Logging\RedactingProcessor;
use App\Logging\ReviewsChannelTap;
use App\Models\Workspace;
use App\Queue\RedactingFailedJobProvider;
use App\Services\Review\SecretRedactor;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CI-runnable LGPD posture check.
 *
 * Recommended CI step: `php artisan rabbit:lgpd-check`
 * Exit 1 fails the deploy. Run on every deploy to enforce DPO sign-off and data-protection invariants.
 */
class LgpdCheckCommand extends Command
{
    protected $signature = 'rabbit:lgpd-check';

    protected $description = 'Run LGPD compliance checks. Exit 0 = all green. Exit 1 = one or more checks failed.';

    private const SENSITIVE_COLUMNS = ['diff', 'patch', 'content', 'body', 'hunk', 'code', 'source', 'prompt', 'response', 'raw_payload'];

    private const AUDITED_TABLES = ['reviews', 'review_comments', 'reviews_llm_calls', 'webhook_deliveries'];

    public function handle(): int
    {
        $results = [
            'Schema audit (no sensitive columns)' => $this->checkSchema(),
            'Encryption casts (bitbucket_token + webhook_secret)' => $this->checkEncryptionCasts(),
            'Failed-jobs provider (RedactingFailedJobProvider)' => $this->checkFailedJobsProvider(),
            'Redacting processor wired to reviews channel' => $this->checkRedactingProcessor(),
            'SecretRedactor sanitises AWS key pattern' => $this->checkSecretRedactor(),
            'Retention command scheduled daily' => $this->checkRetentionScheduled(),
            'Anthropic DPA URL configured' => $this->checkAnthropicDpaUrl(),
            'OpenAI DPA URL configured (when workspaces use OpenAI)' => $this->checkOpenAiDpaUrl(),
            'GitHub DPA URL configured (when workspaces use GitHub)' => $this->checkGithubDpaUrl(),
            'DPO sign-off on record (< 1 year)' => $this->checkDpoSignoff(),
        ];

        $rows = [];
        $allPassed = true;

        foreach ($results as $label => [$passed, $reason]) {
            $rows[] = [
                $passed ? '<fg=green>PASS</>' : '<fg=red>FAIL</>',
                $label,
                $reason,
            ];

            if (! $passed) {
                $allPassed = false;
            }
        }

        $this->table(['Status', 'Check', 'Detail'], $rows);

        if ($allPassed) {
            $this->info('All LGPD checks passed.');

            return self::SUCCESS;
        }

        $this->error('One or more LGPD checks failed. Resolve before deploying.');

        return self::FAILURE;
    }

    /** @return array{bool, string} */
    private function checkSchema(): array
    {
        foreach (self::AUDITED_TABLES as $table) {
            foreach (self::SENSITIVE_COLUMNS as $column) {
                if (Schema::hasColumn($table, $column)) {
                    return [false, "Column [{$column}] found on [{$table}]"];
                }
            }
        }

        return [true, 'No sensitive columns found on audited tables'];
    }

    /** @return array{bool, string} */
    private function checkEncryptionCasts(): array
    {
        $casts = app(Workspace::class)->getCasts();

        foreach (['bitbucket_token', 'webhook_secret'] as $field) {
            if (! isset($casts[$field])) {
                return [false, "Cast missing for [{$field}]"];
            }

            $cast = $casts[$field];

            // Accept 'encrypted' or 'encrypted:string'
            if (! str_starts_with((string) $cast, 'encrypted')) {
                return [false, "Cast for [{$field}] is [{$cast}], expected encrypted"];
            }
        }

        return [true, 'bitbucket_token and webhook_secret use encrypted cast'];
    }

    /** @return array{bool, string} */
    private function checkFailedJobsProvider(): array
    {
        $failer = app('queue.failer');

        if (! ($failer instanceof RedactingFailedJobProvider)) {
            return [false, 'queue.failer is '.get_class($failer).', expected RedactingFailedJobProvider'];
        }

        return [true, 'queue.failer is RedactingFailedJobProvider'];
    }

    /** @return array{bool, string} */
    private function checkRedactingProcessor(): array
    {
        $channelConfig = config('logging.channels.cdv-rabbit-reviews');

        if (! $channelConfig) {
            return [false, 'Log channel [cdv-rabbit-reviews] not configured'];
        }

        $taps = $channelConfig['tap'] ?? [];

        foreach ($taps as $tap) {
            if ($tap === ReviewsChannelTap::class) {
                // ReviewsChannelTap pushes RedactingProcessor in its __invoke
                return [true, 'ReviewsChannelTap (with RedactingProcessor) wired to cdv-rabbit-reviews'];
            }
        }

        return [false, 'ReviewsChannelTap not found in tap list for cdv-rabbit-reviews channel'];
    }

    /** @return array{bool, string} */
    private function checkSecretRedactor(): array
    {
        $redactor = new SecretRedactor;
        $result = $redactor->redact('AKIAIOSFODNN7EXAMPLE');

        if (! str_contains($result->sanitized, '<<SECRET_REDACTED>>')) {
            return [false, 'SecretRedactor did not redact AWS key pattern'];
        }

        return [true, 'SecretRedactor correctly redacts AWS key pattern'];
    }

    /** @return array{bool, string} */
    private function checkRetentionScheduled(): array
    {
        $consolePath = base_path('routes/console.php');

        if (! file_exists($consolePath)) {
            return [false, 'routes/console.php not found'];
        }

        $contents = file_get_contents($consolePath);

        if (! str_contains($contents, 'rabbit:purge-stale')) {
            return [false, 'rabbit:purge-stale not found in routes/console.php'];
        }

        return [true, 'rabbit:purge-stale is scheduled in routes/console.php'];
    }

    /** @return array{bool, string} */
    private function checkAnthropicDpaUrl(): array
    {
        $url = config('cdv-rabbit.anthropic_dpa_url');

        if (empty($url)) {
            return [false, 'ANTHROPIC_DPA_URL env var not set — operator must configure before go-live'];
        }

        return [true, 'anthropic_dpa_url is set'];
    }

    /** @return array{bool, string} */
    private function checkOpenAiDpaUrl(): array
    {
        $hasOpenAiWorkspace = DB::table('workspaces')->where('llm_provider', 'openai')->exists();

        if (! $hasOpenAiWorkspace) {
            return [true, 'No workspaces use OpenAI — check not required'];
        }

        $url = config('cdv-rabbit.openai_dpa_url');

        if (empty($url)) {
            return [false, 'Env var OPENAI_DPA_URL not set (required when any workspace uses OpenAI)'];
        }

        return [true, 'openai_dpa_url is set'];
    }

    /** @return array{bool, string} */
    private function checkGithubDpaUrl(): array
    {
        $hasGithubWorkspace = DB::table('workspaces')->where('scm_provider', 'github_cloud')->exists();

        if (! $hasGithubWorkspace) {
            return [true, 'No workspaces use GitHub — check not required'];
        }

        $url = config('cdv-rabbit.github_dpa_url');

        if (empty($url)) {
            return [false, 'Env var GITHUB_DPA_URL not set (required when any workspace uses GitHub)'];
        }

        return [true, 'github_dpa_url is set'];
    }

    /** @return array{bool, string} */
    private function checkDpoSignoff(): array
    {
        $path = config('cdv-rabbit.dpo_signoff_path', storage_path('app/dpo-signoff.json'));

        if (! file_exists($path)) {
            return [false, "DPO sign-off file not found at [{$path}]"];
        }

        $data = json_decode(file_get_contents($path), true);

        if (! is_array($data)) {
            return [false, 'DPO sign-off file is not valid JSON'];
        }

        if (empty($data['signer'])) {
            return [false, 'DPO sign-off file missing [signer] field'];
        }

        if (empty($data['signed_at'])) {
            return [false, 'DPO sign-off file missing [signed_at] field'];
        }

        try {
            $signedAt = Carbon::parse($data['signed_at']);
        } catch (\Throwable) {
            return [false, 'DPO sign-off [signed_at] is not a valid date'];
        }

        if ($signedAt->diffInDays(now()) > 365) {
            return [false, "DPO sign-off is older than 1 year (signed {$signedAt->toDateString()})"];
        }

        return [true, "Signed by {$data['signer']} on {$signedAt->toDateString()}"];
    }
}
