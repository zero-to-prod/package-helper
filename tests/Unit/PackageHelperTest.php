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

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file) : unlink($file);
        }
        rmdir($dir);
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
        $mapping = [
            'App\\' => '/var/www/app',
            'Lib\\' => '/var/www/lib',
        ];

        $this->assertSame(
            'App\\Controllers',
            PackageHelper::findNamespaceMapping($mapping, '/var/www/app/Controllers')
        );

        $this->assertSame(
            'Lib\\Utils',
            PackageHelper::findNamespaceMapping($mapping, '/var/www/lib/Utils')
        );
    }

    public function testFindNamespaceMappingThrowsExceptionForNoMatch(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("No matching PSR-4 mapping found for directory '/unknown/path'.");

        $mapping = [
            'App\\' => '/var/www/app',
            'Lib\\' => '/var/www/lib',
        ];

        PackageHelper::findNamespaceMapping($mapping, '/unknown/path');
    }
}