<?php

declare(strict_types=1);

namespace NeuronAI\Skills\Internal;

use NeuronAI\Exceptions\ToolException;
use NeuronAI\Skills\Storage\SkillStorageInterface;

use function array_key_exists;
use function sort;
use function sprintf;
use function trim;

use const SORT_STRING;

/** @internal */
class SkillRepository
{
    protected const MANIFEST = 'SKILL.md';

    /** @var array<int, array{name: string, description: string}> */
    protected array $catalog = [];

    /** @var array<string, string> */
    protected array $availableSkills = [];

    /** @var list<array{skill: string, message: string}> */
    protected array $diagnostics = [];

    /** @return list<array{skill: string, message: string}> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    public function __construct(protected SkillStorageInterface $storage)
    {
        $this->buildCatalog();
    }

    /** @return array<int, array{name: string, description: string}> */
    public function catalog(): array
    {
        return $this->catalog;
    }

    /** @throws ToolException */
    public function readInstructions(string $name): string
    {
        if (!array_key_exists($name, $this->availableSkills)) {
            throw new ToolException(sprintf('Skill "%s" is not available.', $name));
        }

        $contents = $this->storage->read($this->availableSkills[$name], self::MANIFEST);

        $document = (new SkillDocumentParser())->parse($contents, $this->availableSkills[$name])['document'];
        if ($document === null) {
            throw new ToolException(sprintf('Skill "%s" has invalid frontmatter.', $name));
        }

        return trim($document['body']);
    }

    /** @throws ToolException */
    public function readResource(string $name, string $path): string
    {
        if (!array_key_exists($name, $this->availableSkills)) {
            throw new ToolException(sprintf('Skill "%s" is not available.', $name));
        }
        if ($path === '') {
            throw new ToolException('Resource path "" is invalid.');
        }

        return $this->storage->read($this->availableSkills[$name], $path);
    }

    protected function buildCatalog(): void
    {
        $skills = $this->storage->skills();
        sort($skills, SORT_STRING);

        foreach ($skills as $skill) {
            try {
                $contents = $this->storage->read($skill, self::MANIFEST);
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
                $this->diagnostics[] = ['skill' => $skill, 'message' => sprintf('Skill "%s" is shadowed by "%s".', $name, $this->availableSkills[$name])];
                continue;
            }
            $this->catalog[] = ['name' => $name, 'description' => $document['description']];
            $this->availableSkills[$name] = $skill;
        }
    }
}
