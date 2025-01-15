#!/usr/bin/env php
<?php

function run(string $command): string
{
    return trim((string)shell_exec($command));
}

function replace_in_file(string $file, array $replacements): void
{
    $contents = file_get_contents($file);

    file_put_contents(
        $file,
        str_replace(
            array_keys($replacements),
            array_values($replacements),
            $contents
        )
    );
}

function ask(string $question, string $default = ''): string
{
    $answer = readline($question.($default ? " ({$default})" : null).': ');

    if (!$answer) {
        return $default;
    }

    return $answer;
}

function confirm(string $question, bool $default = false): bool
{
    $answer = ask($question.' ('.($default ? 'Y/n' : 'y/N').')');

    if (!$answer) {
        return $default;
    }

    return strtolower($answer) === 'y';
}

function writeln(string $line): void
{
    echo $line.PHP_EOL;
}

function replaceForWindows(): array
{
    return preg_split('/\\r\\n|\\r|\\n/', run('dir /S /B * | findstr /v /i .git\ | findstr /v /i vendor | findstr /v /i '.basename(__FILE__).' | findstr /r /i /M /F:/ ":package_namespace :package_classname :package_slug"'));
}

function replaceForAllOtherOSes(): array
{
    return explode(PHP_EOL, run('grep -E -r -l -i ":package_namespace|:package_classname|:package_slug" --exclude-dir=vendor ./* ./.github/* | grep -v '.basename(__FILE__)));
}

function determineSeparator(string $path): string
{
    return str_replace('/', DIRECTORY_SEPARATOR, $path);
}

$package_namespace = ask('Package Namespace');
$package_slug = ask('Package Slug');

writeln('------');
writeln("Package Namespace: $package_namespace");
writeln("Package Slug: $package_slug");
writeln('------');

writeln('This script will replace the above values in all relevant files in the project directory.');

if (!confirm('Modify files?', true)) {
    exit(1);
}

$files = (str_starts_with(strtoupper(PHP_OS), 'WIN') ? replaceForWindows() : replaceForAllOtherOSes());
$time = date('Y-m-d');

foreach ($files as $file) {
    replace_in_file($file, [
        ':package_namespace' => $package_namespace,
        ':package_slug' => $package_slug,
        ':time' => $time,
    ]);

    switch (true) {
        case str_contains($file, determineSeparator('src/Package.php')):
            rename($file, determineSeparator('./src/'.$package_namespace.'.php'));
            break;
        default:
    }
}

echo PHP_EOL;