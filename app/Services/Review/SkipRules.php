<?php

namespace App\Services\Review;

/**
 * File and PR filtering rules per plan §3 Week 3.
 * Files matching exclusion rules are skipped before sending to Claude.
 */
final class SkipRules
{
    private const EXCLUDED_FILENAMES = [
        'package-lock.json',
        'composer.lock',
        'yarn.lock',
        'Gemfile.lock',
        'poetry.lock',
        'pnpm-lock.yaml',
        'bun.lockb',
    ];

    private const EXCLUDED_EXTENSIONS = [
        'lock',
        'min.js',
        'min.css',
        'png',
        'jpg',
        'jpeg',
        'gif',
        'webp',
        'pdf',
        'zip',
        'tar',
        'gz',
        'woff',
        'woff2',
        'ttf',
        'eot',
        'ico',
    ];

    private const EXCLUDED_PATH_PREFIXES = [
        'vendor/',
        'node_modules/',
        'dist/',
        'public/build/',
        '.next/',
        '.nuxt/',
        'target/',
        'build/',
    ];

    private const MAX_PR_LINES = 8000;

    private const MAX_FILE_LINES = 1500;

    public function isFileExcluded(string $path): bool
    {
        $basename = basename($path);

        if (in_array($basename, self::EXCLUDED_FILENAMES, strict: true)) {
            return true;
        }

        foreach (self::EXCLUDED_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        foreach (self::EXCLUDED_EXTENSIONS as $ext) {
            if (str_ends_with($basename, '.'.$ext)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{lines_added: int, lines_removed: int}  $diffStat
     */
    public function isPrTooLarge(array $diffStat): bool
    {
        return ($diffStat['lines_added'] + $diffStat['lines_removed']) > self::MAX_PR_LINES;
    }

    public function isFileTooLarge(string $diff): bool
    {
        $lines = explode("\n", $diff);
        $added = 0;
        $removed = 0;

        foreach ($lines as $line) {
            if (str_starts_with($line, '+') && ! str_starts_with($line, '+++')) {
                $added++;
            } elseif (str_starts_with($line, '-') && ! str_starts_with($line, '---')) {
                $removed++;
            }
        }

        return ($added + $removed) > self::MAX_FILE_LINES;
    }
}
