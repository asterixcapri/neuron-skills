<?php

declare(strict_types=1);

namespace NeuronAI\Skills;

use RuntimeException;
use NeuronAI\Skills\Storage\SkillStorageInterface;

use function array_key_exists;
use function array_map;
use function array_values;
use function sort;
use function sprintf;

use const SORT_STRING;

class SkillRepository
{
    protected const MANIFEST = 'SKILL.md';

    /** @var array<string, array{skill: Skill, identifier: string, ordinal: int}> */
    protected array $skills = [];

    /** @var list<array{skill: string, message: string}> */
    protected array $diagnostics = [];

    private int $storageCount = 0;

    /** @return list<array{skill: string, message: string}> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    public function __construct(SkillStorageInterface ...$storages)
    {
        $this->addStorage(...$storages);
    }

    public function addStorage(SkillStorageInterface ...$storages): void
    {
        foreach ($storages as $storage) {
            $this->buildCatalog($storage, ++$this->storageCount);
        }
    }

    /** @return list<Skill> */
    public function catalog(): array
    {
        return array_values(array_map(static fn (array $entry): Skill => $entry['skill'], $this->skills));
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn (Skill $skill): string => $skill->name(), $this->catalog());
    }

    /** @throws RuntimeException */
    public function get(string $name): Skill
    {
        if (!array_key_exists($name, $this->skills)) {
            throw new RuntimeException(sprintf('Skill "%s" is not available.', $name));
        }

        return $this->skills[$name]['skill'];
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
                'skill' => new Skill($name, $document['description'], $storage, $skill),
                'identifier' => $skill,
                'ordinal' => $ordinal,
            ];
        }
    }
}
