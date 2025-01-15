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
}