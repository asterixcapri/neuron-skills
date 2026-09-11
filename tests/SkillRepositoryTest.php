<?php

declare(strict_types=1);

namespace NeuronAI\Skills\Tests;

use LogicException;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Skills\SkillRepository;
use NeuronAI\Skills\Storage\SkillStorageInterface;
use PHPUnit\Framework\TestCase;

use function array_key_exists;
use function sprintf;
use function str_repeat;
use function array_keys;

class SkillRepositoryTest extends TestCase
{
    public function test_builds_a_deterministic_catalog_and_preserves_complete_instructions(): void
    {
        $storage = new InMemorySkillStorage([
            'writing' => [
                'SKILL.md' => "---\nname: writing\ndescription: \"Write clearly: for humans\"\nlicense: MIT\n---\n\nWrite directly.\n",
            ],
            'analysis' => [
                'SKILL.md' => "---\r\nname: analysis\r\ndescription: Analyse evidence\r\n---\r\nAnalyse carefully.\r\n",
            ],
        ]);
        $repository = new SkillRepository($storage);

        $this->assertSame([
            ['name' => 'analysis', 'description' => 'Analyse evidence'],
            ['name' => 'writing', 'description' => 'Write clearly: for humans'],
        ], $repository->catalog());
        $this->assertSame($storage->files['writing']['SKILL.md'], $repository->readDocument('writing'));
        $this->assertSame($storage->files['analysis']['SKILL.md'], $repository->readDocument('analysis'));
    }

    /** @dataProvider invalidSkills */
    public function test_excludes_unusable_skills_with_diagnostics(string $skill, string $contents): void
    {
        $repository = new SkillRepository(new InMemorySkillStorage([$skill => ['SKILL.md' => $contents]]));

        $this->assertSame([], $repository->catalog());
        $this->assertNotEmpty($repository->diagnostics());
    }

    /** @return array<string, array{string, string}> */
    public static function invalidSkills(): array
    {
        return [
            'missing opening delimiter' => ['missing-open', "name: missing-open\ndescription: Missing delimiter\n---\nBody"],
            'missing closing delimiter' => ['missing-close', "---\nname: missing-close\ndescription: Missing delimiter\nBody"],
            'missing name' => ['missing-name', "---\ndescription: Missing name\n---\nBody"],
            'missing description' => ['missing-description', "---\nname: missing-description\n---\nBody"],
            'empty name' => ['empty-name', "---\nname: \ndescription: Empty name\n---\nBody"],
            'empty description' => ['empty-description', "---\nname: empty-description\ndescription: \n---\nBody"],
            'indented name' => ['indented-name', "---\n name: indented-name\ndescription: Indented\n---\nBody"],
            'indented description' => ['indented-description', "---\nname: indented-description\n description: Indented\n---\nBody"],
        ];
    }

    public function test_first_usable_alphabetical_candidate_owns_the_declared_name_and_reads(): void
    {
        $repository = new SkillRepository(new InMemorySkillStorage([
            'z-last' => ['SKILL.md' => "---\nname: shared\ndescription: Last\n---\nLast body"],
            'a-invalid' => ['SKILL.md' => "---\nname: shared\ndescription: []\n---\nInvalid"],
            'b-first' => [
                'SKILL.md' => "---\nname: shared\ndescription: First\n---\nFirst body",
                'guide.md' => 'First guide',
            ],
        ]));
        $this->assertSame([['name' => 'shared', 'description' => 'First']], $repository->catalog());
        $this->assertSame("---\nname: shared\ndescription: First\n---\nFirst body", $repository->readDocument('shared'));
        $this->assertSame('First guide', $repository->readResource('shared', 'guide.md'));
        $diagnostics = $repository->diagnostics();
        $this->assertSame('a-invalid', $diagnostics[0]['skill']);
        $this->assertSame('z-last', $diagnostics[3]['skill']);
        $this->assertStringContainsString('shadowed', $diagnostics[3]['message']);
    }

    public function test_catalog_is_snapshotted_while_instruction_and_resource_reads_are_lazy(): void
    {
        $storage = new InMemorySkillStorage([
            'writing' => [
                'SKILL.md' => "---\nname: writing\ndescription: Original description\n---\nOriginal body.",
                'guide.md' => 'Original guide.',
            ],
        ]);
        $repository = new SkillRepository($storage);
        $storage->files['writing']['SKILL.md'] = "---\nname: writing\ndescription: Changed description\n---\nChanged body.";
        $storage->files['writing']['guide.md'] = 'Changed guide.';
        $storage->files['added']['SKILL.md'] = "---\nname: added\ndescription: Added later\n---\nAdded.";

        $this->assertSame([
            ['name' => 'writing', 'description' => 'Original description'],
        ], $repository->catalog());
        $this->assertSame($storage->files['writing']['SKILL.md'], $repository->readDocument('writing'));
        $this->assertSame('Changed guide.', $repository->readResource('writing', 'guide.md'));
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Skill "added" is not available.');
        $repository->readDocument('added');
    }

    public function test_rejects_an_empty_resource_path_before_calling_storage(): void
    {
        $storage = new InMemorySkillStorage([
            'writing' => [
                'SKILL.md' => "---\nname: writing\ndescription: Writing\n---\nInstructions.",
                '' => 'Instructions exposed as a resource.',
            ],
        ]);
        $repository = new SkillRepository($storage);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Resource path "" is invalid.');
        $repository->readResource('writing', '');
    }

    /** @dataProvider failingReads */
    public function test_propagates_expected_storage_failures(string $path, bool $instructions): void
    {
        $storage = new InMemorySkillStorage([
            'writing' => ['SKILL.md' => "---\nname: writing\ndescription: Writing\n---\nInstructions."],
        ]);
        $repository = new SkillRepository($storage);
        $storage->failures['writing'][$path] = 'Read failed.';

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Read failed.');

        if ($instructions) {
            $repository->readDocument('writing');
        } else {
            $repository->readResource('writing', $path);
        }
    }

    /** @return array<string, array{string, bool}> */
    public static function failingReads(): array
    {
        return [
            'instructions' => ['SKILL.md', true],
            'resource' => ['guide.md', false],
        ];
    }

    public function test_rejects_instructions_with_frontmatter_that_became_invalid(): void
    {
        $storage = new InMemorySkillStorage([
            'writing' => ['SKILL.md' => "---\nname: writing\ndescription: Writing\n---\nInstructions."],
        ]);
        $repository = new SkillRepository($storage);
        $storage->files['writing']['SKILL.md'] = 'Invalid document.';

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Skill "writing" has invalid frontmatter.');

        $repository->readDocument('writing');
    }

    public function test_rejects_resources_from_an_unknown_skill(): void
    {
        $repository = new SkillRepository(new InMemorySkillStorage([]));

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Skill "unknown" is not available.');

        $repository->readResource('unknown', 'guide.md');
    }

    public function test_expected_manifest_storage_failures_exclude_a_package_from_the_catalog(): void
    {
        $storage = new InMemorySkillStorage([
            'writing' => ['SKILL.md' => "---\nname: writing\ndescription: Writing\n---\nInstructions."],
        ]);
        $storage->failures['writing']['SKILL.md'] = 'Skill "writing" has an unavailable SKILL.md.';

        $this->assertSame([], (new SkillRepository($storage))->catalog());
    }

    public function test_unexpected_storage_failures_remain_exceptions(): void
    {
        $storage = new class () implements SkillStorageInterface {
            public function location(string $skill): ?string
            {
                return null;
            }

            public function list(): array
            {
                return ['broken'];
            }

            public function read(string $skill, string $path): string
            {
                throw new LogicException('Storage failed unexpectedly.');
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Storage failed unexpectedly.');

        new SkillRepository($storage);
    }
}

class InMemorySkillStorage implements SkillStorageInterface
{
    /** @param array<string, array<string, string>> $files */
    public function __construct(public array $files)
    {
    }

    /** @var array<string, array<string, string>> */
    public array $failures = [];

    public function location(string $skill): ?string
    {
        return null;
    }

    public function list(): array
    {
        return array_keys($this->files);
    }

    public function read(string $skill, string $path): string
    {
        if (isset($this->failures[$skill][$path])) {
            throw new ToolException($this->failures[$skill][$path]);
        }
        if (!array_key_exists($skill, $this->files)) {
            throw new ToolException(sprintf('Skill "%s" is not available.', $skill));
        }
        if (!array_key_exists($path, $this->files[$skill])) {
            throw new ToolException(sprintf('Resource "%s" was not found in skill "%s".', $path, $skill));
        }

        return $this->files[$skill][$path];
    }
}
