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

    public function test_lists_documents_keyed_by_skill_name_in_deterministic_order(): void
    {
        $this->insert('writing', 'SKILL.md', 'Writing.');
        $this->insert('writing', 'references/guide.md', 'Guide.');
        $this->insert('analysis', 'SKILL.md', 'Analysis.');
        $this->insert('orphan', 'references/guide.md', 'No document.');

        $this->assertSame(
            ['analysis' => 'Analysis.', 'writing' => 'Writing.'],
            (new DatabaseSkillStorage($this->pdo))->list(),
        );
    }

    public function test_empty_table_has_no_skills(): void
    {
        $this->assertSame([], (new DatabaseSkillStorage($this->pdo))->list());
    }

    public function test_reads_resources_lazily_and_in_full(): void
    {
        $storage = new DatabaseSkillStorage($this->pdo);
        $this->insert('writing', 'references/guide.md', "Guide.\nSecond line.\n");

        $this->assertSame("Guide.\nSecond line.\n", $storage->resource('writing', 'references/guide.md'));
    }

    public function test_missing_resources_and_unknown_skills_throw(): void
    {
        $this->insert('writing', 'SKILL.md', 'Writing.');
        $storage = new DatabaseSkillStorage($this->pdo);

        try {
            $storage->resource('writing', 'missing.md');
            $this->fail('Expected a missing resource to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Resource "missing.md" was not found in skill "writing".', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $storage->resource('unknown', 'guide.md');
    }

    public function test_custom_table_name_is_used(): void
    {
        $this->createTable('custom_skills');
        $this->insert('writing', 'SKILL.md', 'Writing.', 'custom_skills');

        $this->assertSame(['writing' => 'Writing.'], (new DatabaseSkillStorage($this->pdo, 'custom_skills'))->list());
    }

    public function test_only_rows_of_the_configured_scope_are_visible(): void
    {
        $this->insert('writing', 'SKILL.md', 'Default writing.');
        $this->insert('writing', 'SKILL.md', 'Support writing.', scope: 'support');
        $this->insert('refunds', 'SKILL.md', 'Refunds.', scope: 'support');
        $default = new DatabaseSkillStorage($this->pdo);
        $support = new DatabaseSkillStorage($this->pdo, scope: 'support');

        $this->insert('refunds', 'policy.md', 'Policy.', scope: 'support');

        $this->assertSame(['writing' => 'Default writing.'], $default->list());
        $this->assertSame(['refunds' => 'Refunds.', 'writing' => 'Support writing.'], $support->list());
        $this->assertSame('Policy.', $support->resource('refunds', 'policy.md'));

        $this->expectException(RuntimeException::class);
        $default->resource('refunds', 'policy.md');
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
        $storage->resource('writing', 'guide.md');
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
            "CREATE TABLE {$table} (scope VARCHAR(64) NOT NULL, skill_name VARCHAR(255) NOT NULL, path VARCHAR(255) NOT NULL, content TEXT NOT NULL, PRIMARY KEY (scope, skill_name, path))",
        );
    }

    protected function insert(
        string $skill,
        string $path,
        string $content,
        string $table = 'agent_skills',
        string $scope = 'default',
    ): void {
        $this->pdo->prepare("INSERT INTO {$table} (scope, skill_name, path, content) VALUES (?, ?, ?, ?)")
            ->execute([$scope, $skill, $path, $content]);
    }
}
