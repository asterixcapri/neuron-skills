<?php

declare(strict_types=1);

namespace NeuronAI\Skills;

use RuntimeException;
use NeuronAI\Skills\Storage\SkillStorageInterface;

use function array_column;
use function array_key_exists;
use function sort;
use function sprintf;

use const SORT_STRING;

class SkillRepository
{
    protected const MANIFEST = 'SKILL.md';

    /** @var array<string, array{description: string, storage: SkillStorageInterface, identifier: string, ordinal: int}> */
    protected array $skills = [];

    /** @var list<array{skill: string, message: string}> */
    protected array $diagnostics = [];

    /** @return list<array{skill: string, message: string}> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    public function __construct(SkillStorageInterface $storage, SkillStorageInterface ...$fallbackStorages)
    {
        foreach ([$storage, ...$fallbackStorages] as $index => $source) {
            $this->buildCatalog($source, $index + 1);
        }
    }

    /** @return array<int, array{name: string, description: string}> */
    public function catalog(): array
    {
        $catalog = [];
        foreach ($this->skills as $name => $skill) {
            $catalog[] = ['name' => (string) $name, 'description' => $skill['description']];
        }

        return $catalog;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_column($this->catalog(), 'name');
    }

    /** @throws RuntimeException */
    public function readInstructions(string $name): string
    {
        ['storage' => $storage, 'identifier' => $identifier] = $this->getSkill($name);
        $contents = $storage->read($identifier, self::MANIFEST);
        $document = (new SkillDocumentParser())->parse($contents, $identifier)['document'];

        if ($document === null) {
            throw new RuntimeException(sprintf('Skill "%s" has invalid frontmatter.', $name));
        }

        return trim($document['body']);
    }

    /** @throws RuntimeException */
    public function readDocument(string $name): string
    {
        ['storage' => $storage, 'identifier' => $identifier] = $this->getSkill($name);
        $contents = $storage->read($identifier, self::MANIFEST);

        $document = (new SkillDocumentParser())->parse($contents, $identifier)['document'];
        if ($document === null) {
            throw new RuntimeException(sprintf('Skill "%s" has invalid frontmatter.', $name));
        }

        return $contents;
    }

    /** @throws RuntimeException */
    public function location(string $name): ?string
    {
        ['storage' => $storage, 'identifier' => $identifier] = $this->getSkill($name);
        return $storage->location($identifier);
    }

    /** @throws RuntimeException */
    public function readResource(string $name, string $path): string
    {
        ['storage' => $storage, 'identifier' => $identifier] = $this->getSkill($name);
        if ($path === '') {
            throw new RuntimeException('Resource path "" is invalid.');
        }

        return $storage->read($identifier, $path);
    }

    /** @return array{description: string, storage: SkillStorageInterface, identifier: string, ordinal: int} */
    private function getSkill(string $name): array
    {
        if (!array_key_exists($name, $this->skills)) {
            throw new RuntimeException(sprintf('Skill "%s" is not available.', $name));
        }

        return $this->skills[$name];
    }

    protected function buildCatalog(SkillStorageInterface $storage, int $ordinal): void
    {
        $skills = $storage->list();
        sort($skills, SORT_STRING);

        foreach ($skills as $skill) {
            try {
                $contents = $storage->read($skill, self::MANIFEST);
            } catch (RuntimeException $exception) {
                $this->diagnostics[] = ['skill' => $skill, 'message' => $exception->getMessage()];
                continue;
            }

            $parsed = (new SkillDocumentParser())->parse($contents, $skill);
            foreach ($parsed['warnings'] as $message) {
                $this->diagnostics[] = ['skill' => $skill, 'message' => $message];
            }
            $document = $parsed['document'];
            if ($document === null) {
                continue;
            }
            $name = $document['name'];
            if (array_key_exists($name, $this->skills)) {
                $winner = $this->skills[$name];
                $this->diagnostics[] = ['skill' => $skill, 'message' => sprintf(
                    'Skill "%s" from storage #%d candidate "%s" is shadowed by storage #%d candidate "%s".',
                    $name,
                    $ordinal,
                    $skill,
                    $winner['ordinal'],
                    $winner['identifier'],
                )];
                continue;
            }
            $this->skills[$name] = [
                'description' => $document['description'],
                'storage' => $storage,
                'identifier' => $skill,
                'ordinal' => $ordinal,
            ];
        }
    }
}
