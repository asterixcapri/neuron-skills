<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Storage;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

use function array_map;
use function explode;
use function implode;
use function is_string;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_starts_with;

class DatabaseSkillStorage implements SkillStorageInterface
{
    /**
     * The table must expose the text columns `scope`, `skill`, `path` and `content`,
     * with one row per file and a unique key on (`scope`, `skill`, `path`).
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
            $statement = $this->pdo->prepare(
                sprintf('SELECT DISTINCT skill FROM %s WHERE scope = ? ORDER BY skill', $this->table),
            );
            $skills = $statement === false || !$statement->execute([$this->scope])
                ? false
                : $statement->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $exception) {
            throw new RuntimeException('Skills could not be listed: '.$exception->getMessage(), 0, $exception);
        }
        if ($skills === false) {
            throw new RuntimeException('Skills could not be listed.');
        }

        return array_map(strval(...), $skills);
    }

    public function location(string $skill): ?string
    {
        return null;
    }

    public function read(string $skill, string $path): string
    {
        $normalized = $this->normalizePath($path);
        if ($normalized === null) {
            throw new RuntimeException(sprintf('Resource path "%s" is invalid.', $path));
        }

        try {
            $statement = $this->pdo->prepare(
                sprintf('SELECT content FROM %s WHERE scope = ? AND skill = ? AND path = ?', $this->table),
            );
            if ($statement === false || !$statement->execute([$this->scope, $skill, $normalized])) {
                throw new RuntimeException(sprintf('Resource "%s" in skill "%s" could not be read.', $path, $skill));
            }
            $contents = $statement->fetchColumn();
        } catch (PDOException $exception) {
            throw new RuntimeException(
                sprintf('Resource "%s" in skill "%s" could not be read: %s', $path, $skill, $exception->getMessage()),
                0,
                $exception,
            );
        }

        if ($contents === false) {
            throw new RuntimeException(sprintf('Resource "%s" was not found in skill "%s".', $path, $skill));
        }
        if (!is_string($contents) || str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) {
            throw new RuntimeException(
                sprintf('Resource "%s" in skill "%s" contains unsupported binary content.', $path, $skill),
            );
        }

        return $contents;
    }

    /**
     * Reduce a relative path to the canonical form stored in the `path` column,
     * or return null when it is empty, absolute or escapes the skill package.
     */
    protected function normalizePath(string $path): ?string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/')) {
            return null;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                return null;
            }
            $segments[] = $segment;
        }

        return $segments === [] ? null : implode('/', $segments);
    }
}
