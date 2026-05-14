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
    public function wrap(string $diff, array $prMetadata): string
    {
        $title = $this->escape($prMetadata['title'] ?? '');
        $branch = $this->escape($prMetadata['branch'] ?? '');
        $author = $this->escape($prMetadata['author'] ?? '');
        $escapedDiff = $this->escape($diff);

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

    private function escape(string $value): string
    {
        return str_replace(
            ['&', '<', '>'],
            ['&amp;', '&lt;', '&gt;'],
            $value,
        );
    }
}
