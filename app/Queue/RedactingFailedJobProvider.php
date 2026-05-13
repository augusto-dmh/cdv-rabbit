<?php

namespace App\Queue;

use App\Events\RedactionApplied;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Failed\FailedJobProviderInterface;

class RedactingFailedJobProvider implements FailedJobProviderInterface
{
    /** @var list<string> */
    private const SENSITIVE_KEYS = ['diff', 'patch', 'content', 'body', 'hunk', 'code', 'source'];

    public function __construct(
        private readonly FailedJobProviderInterface $inner,
        private readonly Dispatcher $events,
    ) {}

    public function log($connection, $queue, $payload, $exception): int|string|null
    {
        $decoded = json_decode($payload, true);

        if (is_array($decoded)) {
            $redactedKeys = [];
            $decoded = $this->redact($decoded, $redactedKeys);

            if ($redactedKeys !== []) {
                $workspaceId = $this->extractWorkspaceId($decoded);
                $this->events->dispatch(new RedactionApplied($workspaceId, $redactedKeys));
                logger()->warning('RedactingFailedJobProvider: redacted sensitive keys from failed job payload', [
                    'keys' => $redactedKeys,
                    'workspace_id' => $workspaceId,
                ]);
            }

            $payload = json_encode($decoded);
        }

        return $this->inner->log($connection, $queue, $payload, $exception);
    }

    public function all(): array
    {
        return $this->inner->all();
    }

    public function find($id): ?\stdClass
    {
        return $this->inner->find($id);
    }

    public function forget($id): bool
    {
        return $this->inner->forget($id);
    }

    public function flush(?int $hours = null): void
    {
        $this->inner->flush($hours);
    }

    /**
     * @param  array<mixed>  $data
     * @param  list<string>  $redactedKeys
     * @return array<mixed>
     */
    private function redact(array $data, array &$redactedKeys): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->redact($value, $redactedKeys);
            } elseif (is_string($value) && in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                $data[$key] = '<<REDACTED>>';
                $redactedKeys[] = (string) $key;
            }
        }

        return $data;
    }

    /** @param array<mixed> $decoded */
    private function extractWorkspaceId(array $decoded): ?int
    {
        $id = $decoded['workspace_id'] ?? $decoded['data']['workspace_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }
}
