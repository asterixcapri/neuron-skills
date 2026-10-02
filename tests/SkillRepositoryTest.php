<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests;

use LogicException;
use RuntimeException;
use stdClass;
use NeuronAI\AgentSkills\Skill;
use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\AgentSkills\Storage\SkillStorageInterface;
use PHPUnit\Framework\TestCase;

use function array_key_exists;
use function sprintf;

class SkillRepositoryTest extends TestCase
{
    /** @dataProvider discoveryAccessors */
    public function test_discovery_is_deferred_until_first_access_and_cached(string $accessor): void
    {
        $storage = new InMemorySkillStorage([]);
        $repository = new SkillRepository($storage);
        $this->assertSame(0, $storage->listCalls);
        $this->assertCount(0, $storage->reads);
        $storage->files['writing'] = ['SKILL.md' => "---\nname: writing\ndescription: Writing\n---\nBody"];

        if ($accessor === 'get') {
            $repository->get('writing');
        } else {
            $repository->{$accessor}();
        }

        $selected = $repository->get('writing');
        $repository->catalog();
        $repository->names();
        $repository->diagnostics();
        $this->assertSame(1, $storage->listCalls);
        $this->assertSame([], $storage->reads);

        $later = new InMemorySkillStorage([
            'writing' => ['SKILL.md' => "---\nname: writing\ndescription: Shadowed\n---\nOther"],
            'extra' => ['SKILL.md' => "---\nname: extra\ndescription: Extra\n---\nExtra"],
        ]);
        $repository->addStorage($later);
        $this->assertSame(0, $later->listCalls);
        $this->assertCount(0, $later->reads);
        $this->assertSame(['writing', 'extra'], $repository->names());
        $this->assertSame($selected, $repository->get('writing'));
        $this->assertSame(1, $storage->listCalls);
        $this->assertSame([], $storage->reads);
        $this->assertSame(1, $later->listCalls);
        $this->assertCount(0, $later->reads);
        $this->assertCount(1, $repository->diagnostics());
    }

    /** @return array<string, array{string}> */
    public static function discoveryAccessors(): array
    {
        return [
            'catalog' => ['catalog'],
            'names' => ['names'],
            'get' => ['get'],
            'diagnostics' => ['diagnostics'],
        ];
    }

    public function test_failed_discovery_can_be_retried_without_partial_catalog_or_duplicate_diagnostics(): void
    {
        $storage = new class ([]) extends InMemorySkillStorage {
            public bool $broken = true;

            public function list(): array
            {
                $documents = parent::list();
                if ($this->broken) {
                    throw new LogicException('Temporary storage failure.');
                }

                return $documents;
            }
        };
        $storage->files = [
            'a-source' => ['SKILL.md' => "---\nname: first\ndescription: First\n---\nBody"],
            'z-broken' => ['SKILL.md' => "---\nname: second\ndescription: Second\n---\nBody"],
        ];
        $repository = new SkillRepository($storage);
        try {
            $repository->catalog();
            $this->fail('Expected discovery to fail.');
        } catch (LogicException $exception) {
            $this->assertSame('Temporary storage failure.', $exception->getMessage());
        }

        $storage->broken = false;
        $this->assertSame(['first', 'second'], $repository->names());
        $this->assertCount(2, $repository->diagnostics());
        $repository->catalog();
        $this->assertSame(2, $storage->listCalls);
    }

    public function test_reads_normalized_instructions_and_location(): void
    {
        $repository = new SkillRepository(new InMemorySkillStorage([
            'writing' => [
                'SKILL.md' => "---\nname: writing\ndescription: Write clear prose\n---\n# Writing instructions\n\nPrefer direct sentences.\n",
            ],
        ]));

        $this->assertSame(
            "# Writing instructions\n\nPrefer direct sentences.",
            $repository->get('writing')->readInstructions(),
        );
        $this->assertNull($repository->get('writing')->location());
    }

    public function test_reads_complete_frontmatter_without_losing_custom_fields(): void
    {
        $storage = new InMemorySkillStorage([
            'writing' => ['SKILL.md' => <<<'SKILL'
                ---
                name: writing
                description: Write clear prose
                license: MIT
                user-invocable: false
                metadata:
                  author: Valerio
                custom:
                  tags: [writing, review]
                ---
                Write directly.
                SKILL],
        ]);
        $skill = (new SkillRepository($storage))->get('writing');
        $frontmatter = $skill->readFrontmatter();

        $this->assertSame('writing', $frontmatter->name);
        $this->assertSame('MIT', $frontmatter->license);
        $this->assertFalse($frontmatter->{'user-invocable'});
        $this->assertInstanceOf(stdClass::class, $frontmatter->metadata);
        $this->assertSame('Valerio', $frontmatter->metadata->author);
        $this->assertInstanceOf(stdClass::class, $frontmatter->custom);
        $this->assertSame(['writing', 'review'], $frontmatter->custom->tags);

        $frontmatter->metadata->author = 'Changed locally';
        $this->assertSame('Valerio', $skill->readFrontmatter()->metadata->author);

        $storage->files['writing']['SKILL.md'] = 'Invalid manifest';
        $this->assertSame('MIT', $skill->readFrontmatter()->license);
        $this->assertSame('Write directly.', $skill->readInstructions());
    }

    public function test_get_rejects_an_unknown_skill(): void
    {
        $repository = new SkillRepository(new InMemorySkillStorage([]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Skill "missing" is not available.');

        $repository->get('missing');
    }

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
        ], array_map(
            static fn (Skill $skill): array => ['name' => $skill->name(), 'description' => $skill->description()],
            $repository->catalog(),
        ));
        $this->assertSame(['analysis', 'writing'], $repository->names());
        $this->assertSame($repository->get('analysis'), $repository->catalog()[0]);
        $this->assertSame($repository->get('writing'), $repository->catalog()[1]);
        $this->assertSame($storage->files['writing']['SKILL.md'], $repository->get('writing')->readDocument());
        $this->assertSame($storage->files['analysis']['SKILL.md'], $repository->get('analysis')->readDocument());
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
        $this->assertSame([['name' => 'shared', 'description' => 'First']], array_map(
            static fn (Skill $skill): array => ['name' => $skill->name(), 'description' => $skill->description()],
            $repository->catalog(),
        ));
        $this->assertSame("---\nname: shared\ndescription: First\n---\nFirst body", $repository->get('shared')->readDocument());
        $this->assertSame('First guide', $repository->get('shared')->readResource('guide.md'));
        $diagnostics = $repository->diagnostics();
        $this->assertSame('a-invalid', $diagnostics[0]['skill']);
        $this->assertSame('z-last', $diagnostics[3]['skill']);
        $this->assertStringContainsString('shadowed', $diagnostics[3]['message']);
    }

    public function test_catalog_and_documents_are_snapshotted_while_resource_reads_are_lazy(): void
    {
        $original = "---\nname: writing\ndescription: Original description\n---\nOriginal body.";
        $storage = new InMemorySkillStorage([
            'writing' => ['SKILL.md' => $original, 'guide.md' => 'Original guide.'],
        ]);
        $repository = new SkillRepository($storage);
        $repository->catalog();
        $storage->files['writing']['SKILL.md'] = "---\nname: writing\ndescription: Changed description\n---\nChanged body.";
        $storage->files['writing']['guide.md'] = 'Changed guide.';
        $storage->files['added']['SKILL.md'] = "---\nname: added\ndescription: Added later\n---\nAdded.";

        $this->assertSame([
            ['name' => 'writing', 'description' => 'Original description'],
        ], array_map(
            static fn (Skill $skill): array => ['name' => $skill->name(), 'description' => $skill->description()],
            $repository->catalog(),
        ));
        $this->assertSame($original, $repository->get('writing')->readDocument());
        $this->assertSame('Original body.', $repository->get('writing')->readInstructions());
        $this->assertSame('Changed guide.', $repository->get('writing')->readResource('guide.md'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Skill "added" is not available.');
        $repository->get('added');
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

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Resource path "" is invalid.');
        $repository->get('writing')->readResource('');
    }

    /** @dataProvider equivalentPaths */
    public function test_storages_receive_normalized_resource_references(string $path, string $reference): void
    {
        $storage = new InMemorySkillStorage([
            'writing' => [
                'SKILL.md' => "---\nname: writing\ndescription: Writing\n---\nInstructions.",
                'guide.md' => 'Guide.',
                'references/guide.md' => 'Reference guide.',
            ],
        ]);
        $skill = (new SkillRepository($storage))->get('writing');

        $this->assertSame($storage->files['writing'][$reference], $skill->readResource($path));
        $this->assertSame([['writing', $reference]], $storage->reads);
    }

    /** @return array<string, array{string, string}> */
    public static function equivalentPaths(): array
    {
        return [
            'canonical' => ['references/guide.md', 'references/guide.md'],
            'current directory' => ['./references/guide.md', 'references/guide.md'],
            'repeated separators' => ['references//guide.md', 'references/guide.md'],
            'backslashes' => ['references\\guide.md', 'references/guide.md'],
            'confined parent' => ['references/../guide.md', 'guide.md'],
            'sibling through parent' => ['scripts/../references/guide.md', 'references/guide.md'],
        ];
    }

    /** @dataProvider invalidPaths */
    public function test_rejects_invalid_resource_paths_before_calling_storage(string $path): void
    {
        $storage = new InMemorySkillStorage([
            'writing' => ['SKILL.md' => "---\nname: writing\ndescription: Writing\n---\nInstructions."],
        ]);
        $skill = (new SkillRepository($storage))->get('writing');

        try {
            $skill->readResource($path);
            $this->fail('Expected an invalid path to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame(sprintf('Resource path "%s" is invalid.', $path), $exception->getMessage());
        }
        $this->assertSame([], $storage->reads);
    }

    /** @return array<string, array{string}> */
    public static function invalidPaths(): array
    {
        return [
            'current directory' => ['.'],
            'absolute' => ['/SKILL.md'],
            'parent' => ['../secret.md'],
            'nested parent' => ['references/../../secret.md'],
            'backslash parent' => ['..\\secret.md'],
            'null byte' => ["guide.md\0"],
        ];
    }

    /** @dataProvider binaryContents */
    public function test_rejects_binary_resources_and_documents(string $contents): void
    {
        $repository = new SkillRepository(new InMemorySkillStorage([
            'broken' => ['SKILL.md' => "---\nname: broken\ndescription: Broken\n---\n".$contents],
            'writing' => [
                'SKILL.md' => "---\nname: writing\ndescription: Writing\n---\nInstructions.",
                'content.bin' => $contents,
            ],
        ]));

        $this->assertSame(['writing'], $repository->names());
        $this->assertSame(
            [['skill' => 'broken', 'message' => 'SKILL.md contains unsupported binary content.']],
            $repository->diagnostics(),
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Resource "content.bin" in skill "writing" contains unsupported binary content.');
        $repository->get('writing')->readResource('content.bin');
    }

    /** @return array<string, array{string}> */
    public static function binaryContents(): array
    {
        return [
            'null byte' => ["text\0binary"],
            'invalid UTF-8' => ["invalid \xC3\x28"],
        ];
    }

    public function test_propagates_expected_resource_failures(): void
    {
        $storage = new InMemorySkillStorage([
            'writing' => ['SKILL.md' => "---\nname: writing\ndescription: Writing\n---\nInstructions."],
        ]);
        $repository = new SkillRepository($storage);
        $storage->failures['writing']['guide.md'] = 'Read failed.';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Read failed.');

        $repository->get('writing')->readResource('guide.md');
    }

    public function test_rejects_resources_from_an_unknown_skill(): void
    {
        $repository = new SkillRepository(new InMemorySkillStorage([]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Skill "unknown" is not available.');

        $repository->get('unknown')->readResource('guide.md');
    }

    public function test_skills_left_out_by_the_storage_are_absent_from_the_catalog(): void
    {
        $storage = new InMemorySkillStorage([
            'writing' => ['SKILL.md' => "---\nname: writing\ndescription: Writing\n---\nInstructions."],
        ]);
        $storage->failures['writing']['SKILL.md'] = 'Skill "writing" has an unavailable SKILL.md.';

        $repository = new SkillRepository($storage);

        $this->assertSame([], $repository->catalog());
        $this->assertSame([], $repository->diagnostics());
    }

    public function test_unexpected_storage_failures_remain_exceptions(): void
    {
        $storage = new class () implements SkillStorageInterface {
            public function list(): array
            {
                throw new LogicException('Storage failed unexpectedly.');
            }

            public function resource(string $skill, string $reference): string
            {
                return '';
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Storage failed unexpectedly.');

        (new SkillRepository($storage))->catalog();
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

    public int $listCalls = 0;

    /** @var list<array{string, string}> */
    public array $reads = [];

    public function list(): array
    {
        ++$this->listCalls;
        $documents = [];
        foreach ($this->files as $skill => $files) {
            if (isset($files['SKILL.md']) && !isset($this->failures[$skill]['SKILL.md'])) {
                $documents[$skill] = $files['SKILL.md'];
            }
        }

        return $documents;
    }

    public function resource(string $skill, string $reference): string
    {
        $this->reads[] = [$skill, $reference];
        if (isset($this->failures[$skill][$reference])) {
            throw new RuntimeException($this->failures[$skill][$reference]);
        }
        if (!array_key_exists($skill, $this->files)) {
            throw new RuntimeException(sprintf('Skill "%s" is not available.', $skill));
        }
        if (!array_key_exists($reference, $this->files[$skill])) {
            throw new RuntimeException(sprintf('Resource "%s" was not found in skill "%s".', $reference, $skill));
        }

        return $this->files[$skill][$reference];
    }
}
