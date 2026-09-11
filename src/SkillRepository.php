<?php

declare(strict_types=1);

namespace NeuronAI\Skills;

use NeuronAI\Exceptions\ToolException;
use NeuronAI\Skills\Storage\SkillStorageInterface;

use function array_key_exists;
use function sort;
use function sprintf;

use const SORT_STRING;

/** @internal */
class SkillRepository
{
    protected const MANIFEST = 'SKILL.md';

    /** @var array<int, array{name: string, description: string}> */
    protected array $catalog = [];

    /** @var array<string, array{storage: SkillStorageInterface, identifier: string, ordinal: int}> */
    protected array $availableSkills = [];

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
        return $this->catalog;
    }

    /** @throws ToolException */
    public function readDocument(string $name): string
    {
        $source = $this->source($name);
        $contents = $source['storage']->read($source['identifier'], self::MANIFEST);

        $document = (new SkillDocumentParser())->parse($contents, $source['identifier'])['document'];
        if ($document === null) {
            throw new ToolException(sprintf('Skill "%s" has invalid frontmatter.', $name));
        }

        return $contents;
    }

    /** @throws ToolException */
    public function location(string $name): ?string
    {
        $source = $this->source($name);
        return $source['storage']->location($source['identifier']);
    }

    /** @throws ToolException */
    public function readResource(string $name, string $path): string
    {
        $source = $this->source($name);
        if ($path === '') {
            throw new ToolException('Resource path "" is invalid.');
        }

        return $source['storage']->read($source['identifier'], $path);
    }

    /** @return array{storage: SkillStorageInterface, identifier: string, ordinal: int} */
    private function source(string $name): array
    {
        if (!array_key_exists($name, $this->availableSkills)) {
            throw new ToolException(sprintf('Skill "%s" is not available.', $name));
        }

        return $this->availableSkills[$name];
    }

    protected function buildCatalog(SkillStorageInterface $storage, int $ordinal): void
    {
        $skills = $storage->list();
        sort($skills, SORT_STRING);

        foreach ($skills as $skill) {
            try {
                $contents = $storage->read($skill, self::MANIFEST);
            } catch (ToolException $exception) {
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
            if (array_key_exists($name, $this->availableSkills)) {
                $winner = $this->availableSkills[$name];
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
            $this->catalog[] = ['name' => $name, 'description' => $document['description']];
            $this->availableSkills[$name] = ['storage' => $storage, 'identifier' => $skill, 'ordinal' => $ordinal];
        }
    }
}
