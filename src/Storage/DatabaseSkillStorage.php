<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Storage;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

use function array_filter;
use function is_string;
use function preg_match;
use function sprintf;

class DatabaseSkillStorage implements SkillStorageInterface
{
    protected const DOCUMENT = 'SKILL.md';

    /**
     * The table must expose the text columns `scope`, `skill_name`, `path` and `content`,
     * with one row per file and a unique key on (`scope`, `skill_name`, `path`).
     * The row whose path is SKILL.md holds the skill document; the others are its resources.
     * Only the rows of the given scope are visible to this storage.
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        protected PDO $pdo,
        protected string $table = 'agent_skills',
        protected string $scope = 'default',
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $table) !== 1) {
            throw new InvalidArgumentException(sprintf('Table name "%s" is invalid.', $table));
        }
    }

    public function list(): array
    {
        try {
            $statement = $this->pdo->prepare(sprintf(
                'SELECT skill_name, content FROM %s WHERE scope = ? AND path = ? ORDER BY skill_name',
                $this->table,
            ));
            $documents = $statement === false || !$statement->execute([$this->scope, self::DOCUMENT])
                ? false
                : $statement->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException $exception) {
            throw new RuntimeException('Skills could not be listed: '.$exception->getMessage(), 0, $exception);
        }
        if ($documents === false) {
            throw new RuntimeException('Skills could not be listed.');
        }

        return array_filter($documents, is_string(...));
    }

    public function resource(string $skill, string $reference): string
    {
        try {
            $statement = $this->pdo->prepare(
                sprintf('SELECT content FROM %s WHERE scope = ? AND skill_name = ? AND path = ?', $this->table),
            );
            if ($statement === false || !$statement->execute([$this->scope, $skill, $reference])) {
                throw new RuntimeException(
                    sprintf('Resource "%s" in skill "%s" could not be read.', $reference, $skill),
                );
            }
            $contents = $statement->fetchColumn();
        } catch (PDOException $exception) {
            throw new RuntimeException(
                sprintf(
                    'Resource "%s" in skill "%s" could not be read: %s',
                    $reference,
                    $skill,
                    $exception->getMessage(),
                ),
                0,
                $exception,
            );
        }

        if ($contents === false) {
            throw new RuntimeException(sprintf('Resource "%s" was not found in skill "%s".', $reference, $skill));
        }
        if (!is_string($contents)) {
            throw new RuntimeException(sprintf('Resource "%s" in skill "%s" could not be read.', $reference, $skill));
        }

        return $contents;
    }
}
