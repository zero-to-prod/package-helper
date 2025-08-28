<?php

namespace Tests\Unit;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Tests\TestCase;
use Zerotoprod\PackageHelper\PackageHelper;

class PackageHelperTest extends TestCase
{
    private $testFromDir;
    private $testToDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testFromDir = sys_get_temp_dir().'/from_dir';
        $this->testToDir = sys_get_temp_dir().'/to_dir';

        mkdir($this->testFromDir.'/Subdir', 0777, true);
        file_put_contents($this->testFromDir.'/file1.php', "<?php\nnamespace OldNamespace;\n\nclass Test {}");
        file_put_contents($this->testFromDir.'/Subdir/file2.php', "<?php\nnamespace OldNamespace\\Subdir;\n\nclass SubTest {}");
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->testFromDir);
        $this->deleteDirectory($this->testToDir);

        parent::tearDown();
    }

    public function testFindNamespaceMappingWithRelativePath(): void
    {
        // Create a directory structure inside $this->testToDir
        // so we can get a realpath that actually exists.
        $relativeDir = $this->testToDir . '/src/Models';
        mkdir($relativeDir, 0777, true);

        // Map "App\" => $this->testToDir . '/src'
        $mapping = [
            'App\\' => $this->testToDir . '/src',
        ];

        // Change into $this->testToDir so that "./src/Models" is valid
        chdir($this->testToDir);

        // Now we call the method with a relative path.
        // With the fix, realpath('./src/Models') should yield
        // something like /tmp/<random>/to_dir/src/Models
        $result = PackageHelper::determineNamespace($mapping, './src/Models');

        // Ensure we get back the correct namespace
        $this->assertSame('App\\Models', $result);
    }

    public function testPublishCopiesFilesWithUpdatedNamespace(): void
    {
        $namespace = 'NewNamespace';
        PackageHelper::publish(
            $this->testFromDir,
            $this->testToDir,
            $namespace,
            function ($from, $to) {
                $this->assertFileExists($to);
            }
        );

        $this->assertDirectoryExists("$this->testToDir/Subdir");
        $this->assertFileExists("$this->testToDir/file1.php");
        $this->assertFileExists("$this->testToDir/Subdir/file2.php");

        $this->assertStringContainsString(
            "namespace $namespace;",
            file_get_contents("$this->testToDir/file1.php")
        );

        $this->assertStringContainsString(
            "namespace $namespace\\Subdir;",
            file_get_contents("$this->testToDir/Subdir/file2.php")
        );
    }

    public function testFindNamespaceMappingReturnsCorrectNamespace(): void
    {
        // 1) Create a temporary base directory for this test.
        //    We'll store everything under sys_get_temp_dir() to ensure the path exists for realpath().
        $baseDir = sys_get_temp_dir() . '/my-test-dir-' . uniqid();

        // 2) Create sub-directories: app/Controllers and lib/Utils
        $appControllersDir = $baseDir . '/app/Controllers';
        $libUtilsDir       = $baseDir . '/lib/Utils';

        mkdir($appControllersDir, 0777, true);
        mkdir($libUtilsDir, 0777, true);

        // 3) Set up the PSR-4 mapping to point to these real directories on disk.
        $mapping = [
            'App\\' => $baseDir . '/app',
            'Lib\\' => $baseDir . '/lib',
        ];

        // 4) Assert that findNamespaceMapping() returns what we expect.
        //    Now that realpath($appControllersDir) is valid, the method won't fail early.
        $this->assertSame(
            'App\\Controllers',
            PackageHelper::determineNamespace($mapping, $appControllersDir)
        );

        $this->assertSame(
            'Lib\\Utils',
            PackageHelper::determineNamespace($mapping, $libUtilsDir)
        );

        // 5) Cleanup after ourselves
        //    (Optional if your test harness auto-cleans tmp directories.)
        $this->deleteDirectory($baseDir);
    }

    /**
     * Recursively delete a directory.
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item) : unlink($item);
        }
        rmdir($dir);
    }

    private function createSourceFile(string $name = 'test_file.txt', string $content = 'Test content'): string
    {
        $path = $this->testFromDir . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }


    public function testFindNamespaceMappingThrowsExceptionForNoMatch(): void
    {
        $unknownDirectory = sys_get_temp_dir() . '/unknown-test';
        // Make sure it’s empty or doesn’t match your PSR-4
        mkdir($unknownDirectory);

        // This ensures the directory physically exists for realpath().
        // Now the code won't throw "Directory does not exist..."
        // but will continue to the mismatch logic.

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("No matching PSR-4 mapping found for directory '$unknownDirectory'.");

        $mapping = [
            'App\\' => '/var/www/app',
            'Lib\\' => '/var/www/lib',
        ];

        // Because $unknownDirectory doesn't start with /var/www/app or /var/www/lib,
        // it should trigger "No matching PSR-4 mapping found" once realpath() returns a valid path.
        PackageHelper::determineNamespace($mapping, $unknownDirectory);

        // Cleanup
        rmdir($unknownDirectory);
    }

    public function testCopyCopiesFileToDefaultPath(): void
    {
        $source_file = $this->createSourceFile();

        $returned = PackageHelper::copy($source_file);

        $this->assertFileExists($returned);
        $this->assertStringContainsString('Test content', file_get_contents($returned));

        unlink($returned);
    }

    public function testCopyCopiesFileToSpecifiedPath(): void
    {
        $source_file = $this->createSourceFile();

        $custom_target_dir = $this->testToDir . '/custom/';
        $returned = PackageHelper::copy($source_file, $custom_target_dir);

        $this->assertFileExists($returned);
        $this->assertStringContainsString('Test content', file_get_contents($returned));
    }

    public function testCopyCallsCopyEventWhenProvided(): void
    {
        $source_file = $this->createSourceFile();

        $custom_target_dir = $this->testToDir . '/custom/';
        $expected_target_file = $custom_target_dir . 'test_file.txt';

        $callback_called = false;
        $callback_from = null;
        $callback_to = null;

        $returned = PackageHelper::copy(
            $source_file,
            $custom_target_dir,
            function ($from, $to) use (&$callback_called, &$callback_from, &$callback_to) {
                $callback_called = true;
                $callback_from = $from;
                $callback_to = $to;
            }
        );

        $this->assertTrue($callback_called);
        $this->assertSame($source_file, $callback_from);
        $this->assertSame($expected_target_file, $callback_to);
        $this->assertSame($expected_target_file, $returned);
    }

    public function testCopyThrowsExceptionForNonExistentSourceFile(): void
    {
        $non_existent_file = $this->testFromDir . '/non_existent.txt';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Source file not found: {$non_existent_file}");

        PackageHelper::copy($non_existent_file);
    }

    public function testCopyThrowsExceptionWhenTargetDirectoryCannotBeCreated(): void
    {
        $source_file = $this->testFromDir . '/test_file.txt';
        file_put_contents($source_file, 'Test content');

        // Ensure the test directory exists first
        if (!is_dir($this->testToDir)) {
            mkdir($this->testToDir, 0777, true);
        }

        // Create a file where we want a directory to force mkdir to fail
        $blocking_file = $this->testToDir . '/blocking_file';
        file_put_contents($blocking_file, 'blocking');
        $invalid_target_dir = $blocking_file . '/subdir/';

        $this->expectException(RuntimeException::class);

        try {
            PackageHelper::copy($source_file, $invalid_target_dir);
        } catch (RuntimeException $e) {
            // Check that the exception contains expected text about directory creation failure
            $this->assertStringContainsString('Could not create target directory', $e->getMessage());
            throw $e; // Re-throw to satisfy expectException
        } finally {
            if (file_exists($blocking_file)) {
                unlink($blocking_file);
            }
        }
    }
}