<?php

namespace App\Services\Review;

/**
 * Splits a multi-file unified diff (git format) into per-file FileDiff chunks.
 *
 * Handles:
 * - CRLF and LF line endings
 * - Unicode paths (no path unquoting — git quotes non-ASCII paths with octal;
 *   we emit the raw quoted string as the path; callers must unescape if needed)
 * - Renamed files via "rename from"/"rename to" metadata lines
 * - Binary files via "Binary files differ" / "Binary files a/X and b/Y differ"
 * - Deleted files (delete file mode) — path parsed from diff --git header
 */
final class DiffChunker
{
    /**
     * @return iterable<FileDiff>
     */
    public function chunk(string $diff): iterable
    {
        // Normalize line endings to LF
        $diff = str_replace("\r\n", "\n", $diff);

        // Split on "diff --git" boundaries, keeping the header with each chunk
        $segments = preg_split('/(?=^diff --git )/m', $diff, flags: PREG_SPLIT_NO_EMPTY);

        if ($segments === false) {
            return;
        }

        foreach ($segments as $segment) {
            $fileDiff = $this->parseSegment($segment);
            if ($fileDiff !== null) {
                yield $fileDiff;
            }
        }
    }

    private function parseSegment(string $segment): ?FileDiff
    {
        $lines = explode("\n", $segment);

        // First line must be "diff --git a/X b/Y"
        $header = $lines[0] ?? '';
        if (! str_starts_with($header, 'diff --git ')) {
            return null;
        }

        $path = $this->extractPath($header, $lines);
        if ($path === null) {
            return null;
        }

        $renamed = false;
        $binary = false;
        $linesAdded = 0;
        $linesRemoved = 0;
        $hunkLines = [];
        $inHunks = false;

        foreach ($lines as $i => $line) {
            if ($i === 0) {
                continue;
            }

            if (str_starts_with($line, 'rename from ') || str_starts_with($line, 'rename to ')) {
                $renamed = true;

                continue;
            }

            if (str_contains($line, 'Binary files') && str_contains($line, 'differ')) {
                $binary = true;

                continue;
            }

            if (str_starts_with($line, '@@')) {
                $inHunks = true;
            }

            if ($inHunks) {
                $hunkLines[] = $line;

                if (str_starts_with($line, '+') && ! str_starts_with($line, '+++')) {
                    $linesAdded++;
                } elseif (str_starts_with($line, '-') && ! str_starts_with($line, '---')) {
                    $linesRemoved++;
                }
            }
        }

        return new FileDiff(
            path: $path,
            hunks: implode("\n", $hunkLines),
            renamed: $renamed,
            binary: $binary,
            linesAdded: $linesAdded,
            linesRemoved: $linesRemoved,
        );
    }

    private function extractPath(string $header, array $lines): ?string
    {
        // Check for "rename to" which gives the destination path
        foreach ($lines as $line) {
            if (str_starts_with($line, 'rename to ')) {
                return substr($line, strlen('rename to '));
            }
        }

        // Parse "diff --git a/path b/path" — take the b/ side
        // Pattern: diff --git a/<path> b/<path>
        if (preg_match('/^diff --git a\/(.+) b\/(.+)$/', $header, $matches)) {
            return $matches[2];
        }

        return null;
    }
}
