<?php

namespace App\Services\Llm;

/**
 * Assembles the user-message envelope per plan §3.0.5 (AC24).
 *
 * User-controlled content is XML-escaped (not rejected) so that legitimate
 * source code containing XML/HTML tags is preserved for review correctness.
 * The system prompt instructs Claude that escaped XML inside <diff> reflects
 * source-code XML, not envelope tags — making escape safe and non-lossy.
 */
final class PromptBuilder
{
    /**
     * @param  array{title: string, branch: string, author: string}  $prMetadata
     */
    public function wrap(string $diff, array $prMetadata, bool $annotateLines = false): string
    {
        $title = $this->escape($prMetadata['title'] ?? '');
        $branch = $this->escape($prMetadata['branch'] ?? '');
        $author = $this->escape($prMetadata['author'] ?? '');

        $payload = $annotateLines ? $this->annotateLineNumbers($diff) : $diff;
        $escapedDiff = $this->escape($payload);

        return <<<XML
        <pr_metadata>
          <title>{$title}</title>
          <branch>{$branch}</branch>
          <author>{$author}</author>
        </pr_metadata>
        <diff>
        {$escapedDiff}
        </diff>
        XML;
    }

    /**
     * Prefix every `+` line with its absolute head-side line number `+[L<N>] `.
     *
     * Unified-diff format only exposes the absolute line number at hunk headers
     * (`@@ -X,Y +A,B @@`); every subsequent line, the reader has to count to
     * derive a position. LLMs reliably miscount by a few lines on diffs longer
     * than ~30 lines, producing Findings that the SCM later rejects when
     * posting inline. Annotating each addition with its absolute line number
     * eliminates the counting step — the model reads the number verbatim and
     * emits it back in `findings[].line`.
     *
     * Counter rules:
     * - hunk header @@ -A,B +C,D @@ -> reset head-side counter to C
     * - `+ ...`  (addition)        -> emit `+[L<N>] ...`, increment counter
     * - `-...`  (removal)          -> pass through, do NOT increment
     * - ` ...`  (context)          -> pass through, increment counter
     * - file headers / `\ No newline...` -> pass through
     */
    private function annotateLineNumbers(string $diff): string
    {
        $lines = explode("\n", $diff);
        $headLine = null;
        $out = [];

        foreach ($lines as $line) {
            if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $line, $m) === 1) {
                $headLine = (int) $m[1];
                $out[] = $line;

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
                $out[] = $line;

                continue;
            }

            $prefix = $line[0];

            if ($prefix === '+') {
                $out[] = '+[L'.$headLine.'] '.substr($line, 1);
                $headLine++;

                continue;
            }

            if ($prefix === ' ') {
                $out[] = $line;
                $headLine++;

                continue;
            }

            // '-' and anything else: pass through without incrementing.
            $out[] = $line;
        }

        return implode("\n", $out);
    }

    private function escape(string $value): string
    {
        return str_replace(
            ['&', '<', '>'],
            ['&amp;', '&lt;', '&gt;'],
            $value,
        );
    }
}
