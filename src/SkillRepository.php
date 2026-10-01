<?php

declare(strict_types=1);

namespace NeuronAI\Skills;

use RuntimeException;
use Throwable;
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

    /** @var array<string, Skill> */
    protected array $catalog = [];

    /** @var list<array{skill: string, message: string}> */
    protected array $diagnostics = [];

    /** @var array<int, SkillStorageInterface> */
    private array $pendingStorages = [];

    /** @return list<array{skill: string, message: string}> */
    public function diagnostics(): array
    {
        $this->resolveCatalog();

        return $this->diagnostics;
    }

    public function __construct(SkillStorageInterface ...$storages)
    {
        $this->addStorage(...$storages);
    }

    public function addStorage(SkillStorageInterface ...$storages): void
    {
        foreach ($storages as $storage) {
            $this->pendingStorages[] = $storage;
        }
    }

    /** @return list<Skill> */
    public function catalog(): array
    {
        return array_values($this->resolveCatalog());
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn (Skill $skill): string => $skill->name(), $this->catalog());
    }

    /** @throws RuntimeException */
    public function get(string $name): Skill
    {
        $catalog = $this->resolveCatalog();

        if (!array_key_exists($name, $catalog)) {
            throw new RuntimeException(sprintf('Skill "%s" is not available.', $name));
        }

        return $catalog[$name];
    }

    /** @return array<string, Skill> */
    private function resolveCatalog(): array
    {
        foreach ($this->pendingStorages as $index => $storage) {
            $catalog = $this->catalog;
            $diagnostics = $this->diagnostics;

            try {
                $this->buildCatalog($storage);
            } catch (Throwable $exception) {
                $this->catalog = $catalog;
                $this->diagnostics = $diagnostics;
                throw $exception;
            }

            unset($this->pendingStorages[$index]);
        }

        return $this->catalog;
    }

    protected function buildCatalog(SkillStorageInterface $storage): void
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
            if (array_key_exists($name, $this->catalog)) {
                $this->diagnostics[] = ['skill' => $skill, 'message' => sprintf(
                    'Skill "%s" is shadowed by an earlier candidate with the same name.',
                    $name,
                )];
                continue;
            }
            $this->catalog[$name] = new Skill($name, $document['description'], $storage, $skill);
        }
    }
}
