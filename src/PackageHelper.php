<?php

namespace Zerotoprod\PackageHelper;

use Closure;
use RuntimeException;

/**
 * Helpers for a Composer Package
 *
 * @link https://github.com/zero-to-prod/package-helper
 */
class PackageHelper
{
    /**
     * Publish a directory tree into a target path, updating PHP namespaces as the tree descends.
     *
     * Copies every file/dir from $from to $to. For files, the namespace declaration is rewritten to reflect
     * $project_namespace and nested subdirectories.
     *
     * Example:
     * ```
     * use Zerotoprod\PackageHelper\PackageHelper;
     *
     * PackageHelper::publish(
     *     from: __DIR__.'/stubs',
     *     to: sys_get_temp_dir().'/out',
     *     project_namespace: 'App\\Services',
     *     CopyEvent: function ($from, $to) {
     *         echo "Published $from -> $to\n";
     *     }
     * );
     *```
     *
     * @param  string        $from               Source directory to publish (absolute or relative)
     * @param  string        $to                 Destination directory in the consuming project
     * @param  string        $project_namespace  Root namespace that corresponds to $to (e.g., 'App\\Services')
     * @param  Closure|null  $CopyEvent          Optional callback ($fromFile, $toFile) invoked per copied file
     *
     * @return void
     * @throws RuntimeException If a directory cannot be created or a file cannot be written
     * @link https://github.com/zero-to-prod/package-helper
     */
    public static function publish(string $from, string $to, string $project_namespace, Closure $CopyEvent = null): void
    {
        (new self())->copyFiles($from, $to, $project_namespace, $CopyEvent);
    }

    /**
     * Copy a single file into a directory (default: current working directory) and return the copied path.
     *
     * If $target_path is omitted, the file is copied into getcwd(). The $onCopy callback (if provided) is called
     * with ($source_file, $target_file) after a successful copy.
     *
     * Example:
     * ```
     * use Zerotoprod\PackageHelper\PackageHelper;
     *
     * $to = PackageHelper::copy(
     *     source_file: __DIR__.'/README.md',
     *     target_path: sys_get_temp_dir().'/tmp-copy',
     *     CopyEvent: function ($from, $to) {
     *         echo "Copied $from -> $to\n";
     *     }
     * );
     * // $to now contains the full path of the copied file
     *```
     *
     * @param  string        $source_file  The file to copy
     * @param  string|null   $target_path  Directory to copy into (created if missing); defaults to getcwd()
     * @param  Closure|null  $CopyEvent    Optional callback ($fromFile, $toFile)
     *
     * @return string The full path of the copied file
     * @throws RuntimeException If the source file is missing or target directory cannot be created/written
     * @link https://github.com/zero-to-prod/package-helper
     */
    public static function copy(string $source_file, ?string $target_path = null, Closure $CopyEvent = null): string
    {
        $target_path = rtrim($target_path ?: getcwd(), '/').'/';

        if (!file_exists($source_file)) {
            throw new RuntimeException("Source file not found: $source_file");
        }

        if (!is_dir($target_path) && !@mkdir($target_path, 0755, true) && !is_dir($target_path)) {
            throw new RuntimeException("Could not create target directory: $target_path");
        }

        $target_file = $target_path.basename($source_file);

        if (!copy($source_file, $target_file)) {
            throw new RuntimeException("Failed to copy {$source_file} to {$target_file}");
        }

        if (is_callable($CopyEvent)) {
            $CopyEvent($source_file, $target_file);
        }

        return $target_file;
    }

    /**
     * Determine the fully-qualified PHP namespace for a given directory using a PSR-4 mapping.
     *
     * Ensures $to exists, resolves its real path, then finds the PSR-4 prefix whose path is a prefix
     * of $to. Any remaining subpath becomes namespace suffix components.
     *
     * Example:
     * ```
     *   $mapping = [
     *       'App\\' => '/project/app',
     *       'Lib\\' => '/project/lib',
     *   ];
     *   // If $to is '/project/app/Models', returns 'App\\Models'
     *   $ns = PackageHelper::determineNamespace($mapping, '/project/app/Models');
     *```
     *
     * @param  array   $psr_4  PSR-4 mapping like composer.json autoload.psr-4
     * @param  string  $to     Directory to analyze; will be created if missing
     *
     * @return string Resolved namespace (without trailing backslash)
     * @throws RuntimeException If $to cannot be created/read, or no mapping matches
     * @link https://github.com/zero-to-prod/package-helper
     */
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

    /**
     * Recursively copy a directory tree and rewrite PHP namespaces along the way.
     *
     * This is used internally by publish(). For each file copied, the top-level namespace declaration is
     * replaced with the $namespace argument plus any subdirectory suffix.
     *
     * Example (internal):
     * ```
     *   $this->copyFiles('/pkg`/stubs', '/app/Services', 'App\\Services', $onCopy);
     *```
     *
     * @param  string         $from       Source directory
     * @param  string         $to         Destination directory
     * @param  string         $namespace  Base namespace to apply (without trailing backslash)
     * @param  callable|null  $onCopy     Optional callback ($fromFile, $toFile)
     *
     * @return void
     * @throws RuntimeException If a directory cannot be created
     */
    private function copyFiles(string $from, string $to, string $namespace, ?callable $onCopy = null): void
    {
        if (!is_dir($to) && !mkdir($to, 0777, true) && !is_dir($to)) {
            throw new RuntimeException("Failed to create directory '$to'.");
        }

        foreach (array_diff(scandir($from), ['.', '..']) as $item) {
            $from_path = "$from/$item";
            $to_path = "$to/$item";

            if (is_dir($from_path)) {
                $this->copyFiles($from_path, $to_path, $namespace.'\\'.$item, $onCopy);
            } else {
                copy($from_path, $to_path);
                $this->updateNamespace($to_path, $namespace);
                if (is_callable($onCopy)) {
                    $onCopy($from_path, $to_path);
                }
            }
        }
    }

    /**
     * Replace the file's declared namespace with the provided namespace string.
     *
     * This looks for a line beginning with `namespace ...;` and swaps it with `namespace $namespace;`.
     *
     * Example (internal):
     * ```
     *   $this->updateNamespace('/app/Services/Foo.php', 'App\\Services');
     *```
     *
     * @param  string  $filename   PHP file path to modify
     * @param  string  $namespace  Namespace to write (no trailing backslash)
     *
     * @return void
     * @throws RuntimeException If the file cannot be read or written
     */
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