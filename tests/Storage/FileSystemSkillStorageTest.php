<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests\Storage;

use FilesystemIterator;
use RuntimeException;
use NeuronAI\AgentSkills\Storage\FileSystemSkillStorage;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function bin2hex;
use function chmod;
use function file_put_contents;
use function is_dir;
use function is_readable;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sprintf;
use function str_repeat;
use function symlink;
use function sys_get_temp_dir;
use function unlink;

class FileSystemSkillStorageTest extends TestCase
{
    protected string $skillsRoot;
    protected string $outsideRoot;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $this->skillsRoot = sys_get_temp_dir().'/neuron-skills-'.$suffix;
        $this->outsideRoot = sys_get_temp_dir().'/neuron-outside-skills-'.$suffix;
        mkdir($this->skillsRoot, 0o777, true);
        mkdir($this->outsideRoot, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->skillsRoot);
        $this->removeDirectory($this->outsideRoot);
    }

    public function test_lists_documents_of_direct_child_packages_in_deterministic_order(): void
    {
        file_put_contents($this->skillsRoot.'/README.md', 'Not a package.');
        file_put_contents($this->skillsRoot.'/SKILL.md', 'Not a package.');
        mkdir($this->skillsRoot.'/writing', 0o777, true);
        file_put_contents($this->skillsRoot.'/writing/SKILL.md', 'Writing document.');
        mkdir($this->skillsRoot.'/analysis', 0o777, true);
        file_put_contents($this->skillsRoot.'/analysis/SKILL.md', 'Analysis document.');
        mkdir($this->skillsRoot.'/nested/ignored', 0o777, true);
        file_put_contents($this->skillsRoot.'/nested/ignored/SKILL.md', 'Nested document.');

        $this->assertSame(
            ['analysis' => 'Analysis document.', 'writing' => 'Writing document.'],
            (new FileSystemSkillStorage($this->skillsRoot))->list(),
        );
    }

    public function test_leaves_out_packages_whose_document_is_missing_or_escapes_the_package(): void
    {
        mkdir($this->skillsRoot.'/empty');
        mkdir($this->skillsRoot.'/directory/SKILL.md', 0o777, true);
        mkdir($this->skillsRoot.'/linked');
        file_put_contents($this->outsideRoot.'/SKILL.md', 'External document.');
        symlink($this->outsideRoot.'/SKILL.md', $this->skillsRoot.'/linked/SKILL.md');
        mkdir($this->skillsRoot.'/writing');
        file_put_contents($this->skillsRoot.'/writing/SKILL.md', 'Writing document.');

        $this->assertSame(['writing' => 'Writing document.'], (new FileSystemSkillStorage($this->skillsRoot))->list());
    }

    public function test_empty_and_nonexistent_roots_have_no_skills(): void
    {
        $this->assertSame([], (new FileSystemSkillStorage($this->skillsRoot))->list());
        $this->assertSame([], (new FileSystemSkillStorage($this->skillsRoot.'/missing'))->list());
    }

    public function test_packages_are_discovered_on_first_access_and_then_snapshotted(): void
    {
        $storage = new FileSystemSkillStorage($this->skillsRoot);
        mkdir($this->skillsRoot.'/writing');
        file_put_contents($this->skillsRoot.'/writing/SKILL.md', 'Writing document.');

        $this->assertSame(['writing' => 'Writing document.'], $storage->list());

        mkdir($this->skillsRoot.'/added');
        file_put_contents($this->skillsRoot.'/added/SKILL.md', 'Added document.');

        $this->assertArrayNotHasKey('added', $storage->list());
        $this->assertStorageError(
            'Skill "added" is not available.',
            fn (): string => $storage->location('added'),
        );
    }

    public function test_files_of_discovered_packages_are_read_lazily_and_in_full(): void
    {
        mkdir($this->skillsRoot.'/writing');
        $path = $this->skillsRoot.'/writing/guide.md';
        file_put_contents($path, 'Original.');
        file_put_contents($this->skillsRoot.'/writing/SKILL.md', 'Original document.');
        $storage = new FileSystemSkillStorage($this->skillsRoot);
        $storage->list();
        $contents = str_repeat('Complete UTF-8 text: café. ', 10000);
        file_put_contents($path, $contents);
        file_put_contents($this->skillsRoot.'/writing/SKILL.md', 'Changed document.');

        $this->assertSame(['writing' => 'Changed document.'], $storage->list());
        $this->assertSame($contents, $storage->resource('writing', 'guide.md'));
    }

    public function test_uses_a_linked_package_directory_as_its_canonical_boundary(): void
    {
        mkdir($this->outsideRoot.'/shared-skill');
        file_put_contents($this->outsideRoot.'/shared-skill/guide.md', 'Shared guide.');
        file_put_contents($this->outsideRoot.'/shared-skill/SKILL.md', 'Shared document.');
        symlink($this->outsideRoot.'/shared-skill', $this->skillsRoot.'/shared-skill');
        $storage = new FileSystemSkillStorage($this->skillsRoot);

        $this->assertSame(['shared-skill' => 'Shared document.'], $storage->list());
        $this->assertSame(realpath($this->outsideRoot.'/shared-skill'), $storage->location('shared-skill'));
        $this->assertSame('Shared guide.', $storage->resource('shared-skill', 'guide.md'));
    }

    public function test_unknown_skill_location_is_an_expected_failure(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Skill "missing" is not available.');
        (new FileSystemSkillStorage($this->skillsRoot))->location('missing');
    }

    public function test_reads_scripts_as_text_without_executing_them(): void
    {
        mkdir($this->skillsRoot.'/writing/scripts', 0o777, true);
        $marker = $this->outsideRoot.'/executed';
        $script = "#!/bin/sh\ntouch {$marker}\n";
        file_put_contents($this->skillsRoot.'/writing/scripts/run.sh', $script);

        $this->assertSame(
            $script,
            (new FileSystemSkillStorage($this->skillsRoot))->resource('writing', 'scripts/run.sh'),
        );
        $this->assertFileDoesNotExist($marker);
    }

    public function test_allows_confined_file_symlinks(): void
    {
        mkdir($this->skillsRoot.'/writing/references', 0o777, true);
        file_put_contents($this->skillsRoot.'/writing/references/guide.md', 'Confined target.');
        symlink('references/guide.md', $this->skillsRoot.'/writing/guide-link.md');

        $this->assertSame(
            'Confined target.',
            (new FileSystemSkillStorage($this->skillsRoot))->resource('writing', 'guide-link.md'),
        );
    }

    public function test_rejects_a_file_symlink_that_escapes_the_package(): void
    {
        mkdir($this->skillsRoot.'/writing');
        file_put_contents($this->outsideRoot.'/secret.md', 'External target.');
        symlink($this->outsideRoot.'/secret.md', $this->skillsRoot.'/writing/secret-link.md');

        $this->assertStorageError(
            'Resource "secret-link.md" escapes skill "writing".',
            fn (): string => (new FileSystemSkillStorage($this->skillsRoot))->resource('writing', 'secret-link.md'),
        );
    }

    /** @dataProvider invalidPaths */
    public function test_rejects_invalid_paths(string $path): void
    {
        mkdir($this->skillsRoot.'/writing');

        $this->assertStorageError(
            sprintf('Resource path "%s" is invalid.', $path),
            fn (): string => (new FileSystemSkillStorage($this->skillsRoot))->resource('writing', $path),
        );
    }

    /** @return array<string, array{string}> */
    public static function invalidPaths(): array
    {
        return [
            'empty' => [''],
            'null byte' => ["resource\0.md"],
        ];
    }

    public function test_rejects_parent_traversal_that_escapes_the_package(): void
    {
        mkdir($this->skillsRoot.'/writing');
        file_put_contents($this->skillsRoot.'/secret.md', 'External target.');

        $this->assertStorageError(
            'Resource "../secret.md" escapes skill "writing".',
            fn (): string => (new FileSystemSkillStorage($this->skillsRoot))->resource('writing', '../secret.md'),
        );
    }

    public function test_reports_unknown_packages_missing_files_and_directories(): void
    {
        mkdir($this->skillsRoot.'/writing/references', 0o777, true);
        $storage = new FileSystemSkillStorage($this->skillsRoot);

        $this->assertStorageError(
            'Skill "missing" is not available.',
            fn (): string => $storage->resource('missing', 'guide.md'),
        );
        $this->assertStorageError(
            'Resource "missing.md" was not found in skill "writing".',
            fn (): string => $storage->resource('writing', 'missing.md'),
        );
        $this->assertStorageError(
            'Resource "references" in skill "writing" is not a file.',
            fn (): string => $storage->resource('writing', 'references'),
        );
    }

    public function test_reports_an_unreadable_file(): void
    {
        mkdir($this->skillsRoot.'/writing');
        $path = $this->skillsRoot.'/writing/locked.txt';
        file_put_contents($path, 'Locked content.');
        chmod($path, 0o000);
        if (is_readable($path)) {
            chmod($path, 0o644);
            $this->markTestSkipped('The current user can read files regardless of their permission bits.');
        }

        try {
            $this->assertStorageError(
                'Resource "locked.txt" in skill "writing" could not be read.',
                fn (): string => (new FileSystemSkillStorage($this->skillsRoot))->resource('writing', 'locked.txt'),
            );
        } finally {
            chmod($path, 0o644);
        }
    }

    public function test_returns_file_contents_unchanged(): void
    {
        mkdir($this->skillsRoot.'/writing');
        file_put_contents($this->skillsRoot.'/writing/content.bin', "text\0binary \xC3\x28");

        $this->assertSame(
            "text\0binary \xC3\x28",
            (new FileSystemSkillStorage($this->skillsRoot))->resource('writing', 'content.bin'),
        );
    }

    /** @param callable(): string $read */
    protected function assertStorageError(string $expected, callable $read): void
    {
        try {
            $read();
            $this->fail('Expected a storage exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expected, $exception->getMessage());
        }
    }

    protected function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isLink() || !$item->isDir() ? unlink($item->getPathname()) : rmdir($item->getPathname());
        }

        rmdir($directory);
    }
}
