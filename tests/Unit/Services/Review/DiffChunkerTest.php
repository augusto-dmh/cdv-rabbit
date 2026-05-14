<?php

use App\Services\Review\DiffChunker;
use App\Services\Review\FileDiff;

beforeEach(function () {
    $this->chunker = new DiffChunker;
});

$multiFileDiff = <<<'DIFF'
diff --git a/app/Foo.php b/app/Foo.php
index abc1234..def5678 100644
--- a/app/Foo.php
+++ b/app/Foo.php
@@ -1,5 +1,6 @@
 <?php
+// added comment
 class Foo {}
diff --git a/app/Bar.php b/app/Bar.php
index 111aaaa..222bbbb 100644
--- a/app/Bar.php
+++ b/app/Bar.php
@@ -10,3 +10,4 @@
 class Bar {
-    public function old() {}
+    public function new() {}
 }
DIFF;

it('chunks a multi-file diff into separate FileDiff objects', function () use ($multiFileDiff) {
    $chunks = iterator_to_array($this->chunker->chunk($multiFileDiff));

    expect($chunks)->toHaveCount(2)
        ->and($chunks[0])->toBeInstanceOf(FileDiff::class)
        ->and($chunks[0]->path)->toBe('app/Foo.php')
        ->and($chunks[1]->path)->toBe('app/Bar.php');
});

it('counts added and removed lines per file', function () use ($multiFileDiff) {
    $chunks = iterator_to_array($this->chunker->chunk($multiFileDiff));

    expect($chunks[0]->linesAdded)->toBe(1)
        ->and($chunks[0]->linesRemoved)->toBe(0)
        ->and($chunks[1]->linesAdded)->toBe(1)
        ->and($chunks[1]->linesRemoved)->toBe(1);
});

it('detects renamed files', function () {
    $diff = <<<'DIFF'
    diff --git a/old/Path.php b/new/Path.php
    similarity index 95%
    rename from old/Path.php
    rename to new/Path.php
    index abc..def 100644
    --- a/old/Path.php
    +++ b/new/Path.php
    @@ -1,2 +1,2 @@
    -old content
    +new content
    DIFF;

    $chunks = iterator_to_array($this->chunker->chunk($diff));

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->renamed)->toBeTrue()
        ->and($chunks[0]->path)->toBe('new/Path.php');
});

it('detects binary files', function () {
    $diff = <<<'DIFF'
    diff --git a/assets/logo.png b/assets/logo.png
    index abc..def 100644
    Binary files a/assets/logo.png and b/assets/logo.png differ
    DIFF;

    $chunks = iterator_to_array($this->chunker->chunk($diff));

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->binary)->toBeTrue()
        ->and($chunks[0]->path)->toBe('assets/logo.png');
});

it('handles CRLF line endings', function () {
    $diff = "diff --git a/foo.php b/foo.php\r\nindex abc..def 100644\r\n--- a/foo.php\r\n+++ b/foo.php\r\n@@ -1 +1 @@\r\n-old\r\n+new\r\n";

    $chunks = iterator_to_array($this->chunker->chunk($diff));

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->path)->toBe('foo.php')
        ->and($chunks[0]->linesAdded)->toBe(1)
        ->and($chunks[0]->linesRemoved)->toBe(1);
});

it('returns empty iterable for empty diff', function () {
    $chunks = iterator_to_array($this->chunker->chunk(''));

    expect($chunks)->toBeEmpty();
});

it('marks non-renamed, non-binary files correctly', function () use ($multiFileDiff) {
    $chunks = iterator_to_array($this->chunker->chunk($multiFileDiff));

    expect($chunks[0]->renamed)->toBeFalse()
        ->and($chunks[0]->binary)->toBeFalse();
});
