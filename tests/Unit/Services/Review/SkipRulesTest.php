<?php

use App\Services\Review\SkipRules;

beforeEach(function () {
    $this->rules = new SkipRules;
});

dataset('excluded_lockfiles', [
    'package-lock.json' => ['package-lock.json'],
    'composer.lock' => ['composer.lock'],
    'yarn.lock' => ['yarn.lock'],
    'Gemfile.lock' => ['Gemfile.lock'],
    'poetry.lock' => ['poetry.lock'],
    'pnpm-lock.yaml' => ['pnpm-lock.yaml'],
    'bun.lockb' => ['bun.lockb'],
    'some.lock' => ['some.lock'],
]);

dataset('excluded_path_prefixes', [
    'vendor' => ['vendor/laravel/framework/src/Foo.php'],
    'node_modules' => ['node_modules/lodash/index.js'],
    'dist' => ['dist/app.js'],
    'public/build' => ['public/build/assets/app.abc123.js'],
    '.next' => ['.next/server/pages/index.js'],
    '.nuxt' => ['.nuxt/dist/client/app.js'],
    'target' => ['target/release/binary'],
    'build' => ['build/outputs/apk/debug.apk'],
]);

dataset('excluded_extensions', [
    'min.js' => ['app.min.js'],
    'min.css' => ['styles.min.css'],
    'png' => ['logo.png'],
    'jpg' => ['photo.jpg'],
    'gif' => ['animation.gif'],
    'webp' => ['image.webp'],
    'pdf' => ['document.pdf'],
    'zip' => ['archive.zip'],
    'woff' => ['font.woff'],
    'ttf' => ['font.ttf'],
    'ico' => ['favicon.ico'],
]);

dataset('included_files', [
    'php file' => ['app/Services/Foo.php'],
    'js file' => ['resources/js/app.js'],
    'vue file' => ['resources/js/Pages/Dashboard.vue'],
    'css file' => ['resources/css/app.css'],
    'blade file' => ['resources/views/welcome.blade.php'],
]);

it('excludes lockfiles', function (string $path) {
    expect($this->rules->isFileExcluded($path))->toBeTrue();
})->with('excluded_lockfiles');

it('excludes generated path prefixes', function (string $path) {
    expect($this->rules->isFileExcluded($path))->toBeTrue();
})->with('excluded_path_prefixes');

it('excludes binary and minified extensions', function (string $path) {
    expect($this->rules->isFileExcluded($path))->toBeTrue();
})->with('excluded_extensions');

it('includes regular source files', function (string $path) {
    expect($this->rules->isFileExcluded($path))->toBeFalse();
})->with('included_files');

it('marks PR as too large when lines exceed 8000', function () {
    expect($this->rules->isPrTooLarge(['lines_added' => 5000, 'lines_removed' => 3001]))->toBeTrue();
});

it('does not mark PR as too large at exactly 8000 lines', function () {
    expect($this->rules->isPrTooLarge(['lines_added' => 4000, 'lines_removed' => 4000]))->toBeFalse();
});

it('marks file as too large when hunks exceed 1500 lines', function () {
    $lines = array_fill(0, 1501, '+added line');
    $diff = implode("\n", $lines);

    expect($this->rules->isFileTooLarge($diff))->toBeTrue();
});

it('does not mark file as too large for small diff', function () {
    $diff = "+added line\n-removed line\n context line";

    expect($this->rules->isFileTooLarge($diff))->toBeFalse();
});
