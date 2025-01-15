<?php

namespace Zerotoprod\PackageHelper;

use Closure;
use RuntimeException;

class PackageHelper
{
    public static function publish(string $from, string $to, string $project_namespace, Closure $CopyEvent = null): void
    {
        (new self())->copyFiles($from, $to, $project_namespace, $CopyEvent);
    }

    public static function determineNamespace(array $psr_4, string $to): string
    {
        if (!is_dir($to) && !mkdir($to, 0777, true) && !is_dir($to)) {
            throw new RuntimeException("Directory '$to' could not be created.");
        }

        $realpath = realpath($to);
        if (!$realpath) {
            throw new RuntimeException("Directory '$to' does not exist or is not readable.");
        }

        foreach ($psr_4 as $namespace => $path) {
            $normalized_path = realpath(rtrim($path, '/'));
            if ($normalized_path && strpos($realpath, $normalized_path) === 0) {
                $relative_path = trim(substr($realpath, strlen($normalized_path)), DIRECTORY_SEPARATOR);

                return rtrim($namespace, '\\')
                    .($relative_path ? '\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative_path) : '');
            }
        }

        throw new RuntimeException("No matching PSR-4 mapping found for directory '$to'.");
    }

    private function copyFiles(string $from, string $to, string $namespace, Closure $CopyEvent = null): void
    {
        if (!is_dir($to) && !mkdir($to, 0777, true) && !is_dir($to)) {
            throw new RuntimeException("Failed to create directory '$to'.");
        }

        foreach (array_diff(scandir($from), ['.', '..']) as $item) {
            $from_path = "$from/$item";
            $to_path = "$to/$item";

            if (is_dir($from_path)) {
                $this->copyFiles($from_path, $to_path, $namespace.'\\'.$item, $CopyEvent);
            } else {
                copy($from_path, $to_path);
                $this->updateNamespace($to_path, $namespace);
                if (is_a($CopyEvent, Closure::class)) {
                    $CopyEvent($from_path, $to_path);
                }
            }
        }
    }

    private function updateNamespace(string $filename, string $namespace): void
    {
        if (file_get_contents($filename)) {
            $content = file_get_contents($filename);
        } else {
            throw new RuntimeException("Failed to read file '$filename'.");
        }

        $data = preg_replace('/^namespace\s+.*;/m', "namespace $namespace;", $content);

        if (file_put_contents($filename, $data) === false) {
            throw new RuntimeException("Failed to update file '$filename'.");
        }
    }
}