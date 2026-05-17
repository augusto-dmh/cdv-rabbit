<?php

namespace App\Services\Review;

/**
 * Post-LLM sanitization of Claude's raw comment text before posting to Bitbucket.
 *
 * Trade-offs:
 * - @mentions stripped to prevent accidental pings on real accounts; the
 *   regex preserves `@<path>`-style refs (slash-bearing) used by the Agent
 *   Prompt CodeRabbit-parity convention.
 * - BB issue refs (#\d+) stripped only at word boundaries to avoid corrupting
 *   legitimate identifiers like IndexError#1 or array[#1]. This may miss
 *   escaped refs in code blocks — acceptable at MVP.
 * - Raw HTML stripped via strip_tags() — may degrade markdown with intentional
 *   <code> blocks, but Claude rarely produces raw HTML in tool output.
 * - Embedded images stripped — bot should never post binary-content links.
 */
final class CommentSanitizer
{
    public function sanitize(string $rawMessage): string
    {
        $message = $rawMessage;

        // Strip @mentions (e.g. @username, @user.name, @user-name) but preserve
        // @path-like refs (slash-bearing) used by the Agent Prompt convention —
        // `@src/app/Foo.php` is an agent-readable file reference, not a user ping.
        $message = preg_replace('/@(?![\w._-]*\/)[\w._-]+/', '', $message);

        // Strip Bitbucket issue refs at word boundary only (e.g. " #123" not "array#123")
        $message = preg_replace('/(?<!\w)#\d+\b/', '', $message);

        // Strip embedded image markdown: ![alt](url)
        $message = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $message);

        // Strip raw HTML tags
        $message = strip_tags($message);

        // Collapse multiple blank lines left by removals
        $message = preg_replace('/\n{3,}/', "\n\n", $message);

        return trim($message);
    }
}
