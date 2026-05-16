<?php

declare(strict_types=1);

namespace App\Services\Review;

/**
 * Reconciles an LLM-emitted (path, line) against the real diff hunks before
 * an inline-comment post. Fixes two recurring failure modes that otherwise
 * waste a Finding on the SCM 422 fallback:
 *
 * 1. **Path casing.** LLMs trained on PHP code occasionally emit the
 *    namespace-cased path (`App/Services/...`) instead of the filesystem
 *    path (`app/Services/...`). GitHub is case-sensitive and rejects the
 *    former with "path could not be resolved." The resolver snaps to the
 *    canonical path via case-insensitive lookup.
 *
 * 2. **Off-by-a-few lines.** Even with `+[L<N>]` annotations in the prompt
 *    (PromptBuilder::annotateLineNumbers), the LLM can pick a non-anchorable
 *    line — a blank `+` line, or a line just outside the changed range. The
 *    resolver snaps to the nearest valid `+` line within ±{@link SNAP_RADIUS}.
 *
 * `resolve()` returns null when neither correction is possible; callers
 * should then fall back to summary-body reporting rather than attempting
 * the post.
 */
final class DiffPositionResolver
{
    private const SNAP_RADIUS = 5;

    /** @var array<string, list<int>> path => sorted list of valid head-side `+` line numbers */
    private array $linesByPath = [];

    /** @var array<string, string> lowercase path => canonical path */
    private array $canonicalByLower = [];

    /**
     * @param  list<FileDiff>  $fileDiffs
     */
    public function __construct(array $fileDiffs)
    {
        foreach ($fileDiffs as $fileDiff) {
            $this->linesByPath[$fileDiff->path] = $this->extractAddedLineNumbers($fileDiff->hunks);
            $this->canonicalByLower[strtolower($fileDiff->path)] = $fileDiff->path;
        }
    }

    /**
     * @return array{path: string, line: int}|null
     */
    public function resolve(string $path, int $line): ?array
    {
        $canonicalPath = $this->canonicalPathOrNull($path);

        if ($canonicalPath === null) {
            return null;
        }

        $validLines = $this->linesByPath[$canonicalPath] ?? [];

        if ($validLines === []) {
            return null;
        }

        if (in_array($line, $validLines, true)) {
            return ['path' => $canonicalPath, 'line' => $line];
        }

        $snapped = $this->snapToNearest($line, $validLines);

        if ($snapped === null) {
            return null;
        }

        return ['path' => $canonicalPath, 'line' => $snapped];
    }

    private function canonicalPathOrNull(string $path): ?string
    {
        if (isset($this->linesByPath[$path])) {
            return $path;
        }

        return $this->canonicalByLower[strtolower($path)] ?? null;
    }

    /**
     * Walk the unified-diff hunks once and emit every `+` line's head-side
     * absolute line number. Matches the same accounting rules used by
     * PromptBuilder::annotateLineNumbers so the two stay in lock-step.
     *
     * @return list<int>
     */
    private function extractAddedLineNumbers(string $hunks): array
    {
        $lines = explode("\n", $hunks);
        $headLine = null;
        $added = [];

        foreach ($lines as $line) {
            if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $line, $m) === 1) {
                $headLine = (int) $m[1];

                continue;
            }

            if ($headLine === null
                || $line === ''
                || str_starts_with($line, 'diff --git')
                || str_starts_with($line, 'index ')
                || str_starts_with($line, '--- ')
                || str_starts_with($line, '+++ ')
                || str_starts_with($line, '\\ ')
            ) {
                continue;
            }

            $prefix = $line[0];

            if ($prefix === '+') {
                $added[] = $headLine;
                $headLine++;

                continue;
            }

            if ($prefix === ' ') {
                $headLine++;
            }

            // '-' lines: do not advance head counter, do not record.
        }

        sort($added);

        return $added;
    }

    /**
     * @param  list<int>  $validLines
     */
    private function snapToNearest(int $line, array $validLines): ?int
    {
        $best = null;
        $bestDistance = self::SNAP_RADIUS + 1;

        foreach ($validLines as $candidate) {
            $distance = abs($candidate - $line);

            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best;
    }
}
