<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * LGPD belt-and-suspenders: drop any log record whose context or extra
 * contains keys that could carry diff/code/comment content.
 */
final class RedactingProcessor implements ProcessorInterface
{
    private const FORBIDDEN_KEYS = ['diff', 'patch', 'content', 'body', 'hunk', 'code', 'source'];

    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $this->scrub($record->context);
        $extra = $this->scrub($record->extra);

        return $record->with(context: $context, extra: $extra);
    }

    /** @param array<string, mixed> $data */
    private function scrub(array $data): array
    {
        foreach (self::FORBIDDEN_KEYS as $key) {
            unset($data[$key]);
        }

        return $data;
    }
}
