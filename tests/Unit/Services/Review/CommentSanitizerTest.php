<?php

use App\Services\Review\CommentSanitizer;

beforeEach(function () {
    $this->sanitizer = new CommentSanitizer;
});

it('strips @mentions', function () {
    $result = $this->sanitizer->sanitize('Good job @alice! Also @bob.smith should review.');

    expect($result)
        ->not->toContain('@alice')
        ->not->toContain('@bob.smith')
        ->toContain('Good job');
});

it('strips Bitbucket issue refs at word boundary', function () {
    $result = $this->sanitizer->sanitize('This fixes #123 and also #456.');

    expect($result)
        ->not->toContain('#123')
        ->not->toContain('#456');
});

it('preserves legitimate code identifiers containing hash-like patterns', function () {
    // "#1" inside array index notation or identifier — not a word-boundary issue ref
    $result = $this->sanitizer->sanitize('See IndexError#1 for context and array[0] is fine.');

    // "IndexError#1" has no word boundary before # — should be preserved
    expect($result)->toContain('IndexError#1');
});

it('strips embedded image markdown', function () {
    $result = $this->sanitizer->sanitize('Look at this: ![diagram](https://example.com/img.png) and also ![](url).');

    expect($result)
        ->not->toContain('![')
        ->not->toContain('img.png');
});

it('strips raw HTML tags', function () {
    $result = $this->sanitizer->sanitize('Use <strong>caution</strong> here and <br/> also <script>alert(1)</script>.');

    expect($result)
        ->not->toContain('<strong>')
        ->not->toContain('<script>')
        ->toContain('caution');
});

it('trims leading and trailing whitespace', function () {
    $result = $this->sanitizer->sanitize("  \n\nSome message\n\n  ");

    expect($result)->toBe('Some message');
});

it('collapses multiple blank lines', function () {
    $result = $this->sanitizer->sanitize("Line one\n\n\n\n\nLine two");

    expect($result)->toBe("Line one\n\nLine two");
});

it('leaves clean comment unchanged', function () {
    $message = 'This function is missing a null check on line 42.';
    $result = $this->sanitizer->sanitize($message);

    expect($result)->toBe($message);
});
