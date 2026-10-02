<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests\Storage;

use InvalidArgumentException;
use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\AgentSkills\Storage\DatabaseSkillStorage;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function sprintf;

#[RequiresPhpExtension('pdo_sqlite')]
class DatabaseSkillStorageTest extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createTable('agent_skills');
    }

    public function test_lists_distinct_skills_in_deterministic_order(): void
    {
        $this->insert('writing', 'SKILL.md', 'Writing.');
        $this->insert('writing', 'references/guide.md', 'Guide.');
        $this->insert('analysis', 'SKILL.md', 'Analysis.');

        $this->assertSame(['analysis', 'writing'], (new DatabaseSkillStorage($this->pdo))->list());
    }

    public function test_empty_table_has_no_skills(): void
    {
        $this->assertSame([], (new DatabaseSkillStorage($this->pdo))->list());
    }

    public function test_reads_files_lazily_and_in_full(): void
    {
        $storage = new DatabaseSkillStorage($this->pdo);
        $this->insert('writing', 'references/guide.md', "Guide.\nSecond line.\n");

        $this->assertSame("Guide.\nSecond line.\n", $storage->read('writing', 'references/guide.md'));
    }

    public function test_equivalent_relative_paths_read_the_same_file(): void
    {
        $this->insert('writing', 'references/guide.md', 'Guide.');
        $storage = new DatabaseSkillStorage($this->pdo);

        $this->assertSame('Guide.', $storage->read('writing', './references//guide.md'));
    }

    #[DataProvider('invalidPaths')]
    public function test_invalid_paths_are_rejected(string $path): void
    {
        $this->insert('writing', 'SKILL.md', 'Writing.');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is invalid');

        (new DatabaseSkillStorage($this->pdo))->read('writing', $path);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPaths(): array
    {
        return [
            'empty' => [''],
            'current directory' => ['.'],
            'absolute' => ['/SKILL.md'],
            'parent' => ['../SKILL.md'],
            'nested parent' => ['references/../../SKILL.md'],
            'backslash' => ['references\\guide.md'],
            'null byte' => ["SKILL.md\0"],
        ];
    }

    public function test_missing_resources_and_unknown_skills_throw(): void
    {
        $this->insert('writing', 'SKILL.md', 'Writing.');
        $storage = new DatabaseSkillStorage($this->pdo);

        try {
            $storage->read('writing', 'missing.md');
            $this->fail('Expected a missing resource to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Resource "missing.md" was not found in skill "writing".', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $storage->read('unknown', 'SKILL.md');
    }

    public function test_binary_content_is_rejected(): void
    {
        $this->insert('writing', 'null.bin', "a\0b");
        $this->insert('writing', 'latin1.txt', "caf\xE9");
        $storage = new DatabaseSkillStorage($this->pdo);

        foreach (['null.bin', 'latin1.txt'] as $path) {
            try {
                $storage->read('writing', $path);
                $this->fail('Expected binary content to throw.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('unsupported binary content', $exception->getMessage());
            }
        }
    }

    public function test_location_is_unavailable(): void
    {
        $this->insert('writing', 'SKILL.md', 'Writing.');

        $this->assertNull((new DatabaseSkillStorage($this->pdo))->location('writing'));
    }

    public function test_custom_table_name_is_used(): void
    {
        $this->createTable('custom_skills');
        $this->insert('writing', 'SKILL.md', 'Writing.', 'custom_skills');

        $this->assertSame(['writing'], (new DatabaseSkillStorage($this->pdo, 'custom_skills'))->list());
    }

    public function test_only_rows_of_the_configured_scope_are_visible(): void
    {
        $this->insert('writing', 'SKILL.md', 'Default writing.');
        $this->insert('writing', 'SKILL.md', 'Support writing.', scope: 'support');
        $this->insert('refunds', 'SKILL.md', 'Refunds.', scope: 'support');
        $default = new DatabaseSkillStorage($this->pdo);
        $support = new DatabaseSkillStorage($this->pdo, scope: 'support');

        $this->assertSame(['writing'], $default->list());
        $this->assertSame(['refunds', 'writing'], $support->list());
        $this->assertSame('Default writing.', $default->read('writing', 'SKILL.md'));
        $this->assertSame('Support writing.', $support->read('writing', 'SKILL.md'));

        $this->expectException(RuntimeException::class);
        $default->read('refunds', 'SKILL.md');
    }

    public function test_scoped_storages_combine_with_a_shared_scope_in_precedence_order(): void
    {
        $manifest = "---\nname: %s\ndescription: %s\n---\n\nBody.\n";
        $this->insert('writing', 'SKILL.md', sprintf($manifest, 'writing', 'Shared writing.'));
        $this->insert('analysis', 'SKILL.md', sprintf($manifest, 'analysis', 'Shared analysis.'));
        $this->insert('writing', 'SKILL.md', sprintf($manifest, 'writing', 'Support writing.'), scope: 'support');

        $skills = new SkillRepository(
            new DatabaseSkillStorage($this->pdo, scope: 'support'),
            new DatabaseSkillStorage($this->pdo),
        );

        $this->assertSame('Support writing.', $skills->get('writing')->description());
        $this->assertSame('Shared analysis.', $skills->get('analysis')->description());
    }

    public function test_unsafe_table_name_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DatabaseSkillStorage($this->pdo, 'skills; DROP TABLE agent_skills');
    }

    #[DataProvider('errorModes')]
    public function test_query_failures_throw_runtime_exceptions_in_any_error_mode(int $errorMode): void
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, $errorMode);
        $storage = new DatabaseSkillStorage($this->pdo, 'missing_table');

        try {
            $storage->list();
            $this->fail('Expected listing a missing table to throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('could not be listed', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not be read');
        $storage->read('writing', 'SKILL.md');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function errorModes(): array
    {
        return [
            'exception' => [PDO::ERRMODE_EXCEPTION],
            'silent' => [PDO::ERRMODE_SILENT],
        ];
    }

    public function test_repository_loads_skills_from_the_database(): void
    {
        $this->insert('writing', 'SKILL.md', "---\nname: writing\ndescription: Write clearly.\n---\n\nBe concise.\n");
        $this->insert('writing', 'references/guide.md', 'Guide.');

        $skill = (new SkillRepository(new DatabaseSkillStorage($this->pdo)))->get('writing');

        $this->assertSame('Be concise.', $skill->readInstructions());
        $this->assertSame('Guide.', $skill->readResource('references/guide.md'));
        $this->assertNull($skill->location());
    }

    protected function createTable(string $table): void
    {
        $this->pdo->exec(
            "CREATE TABLE {$table} (scope VARCHAR(64) NOT NULL, skill VARCHAR(255) NOT NULL, path VARCHAR(255) NOT NULL, content TEXT NOT NULL, PRIMARY KEY (scope, skill, path))",
        );
    }

    protected function insert(
        string $skill,
        string $path,
        string $content,
        string $table = 'agent_skills',
        string $scope = 'default',
    ): void {
        $this->pdo->prepare("INSERT INTO {$table} (scope, skill, path, content) VALUES (?, ?, ?, ?)")
            ->execute([$scope, $skill, $path, $content]);
    }
}
