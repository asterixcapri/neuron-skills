<?php

declare(strict_types=1);

namespace NeuronAI\Skills\Internal;

use NeuronAI\Exceptions\ToolException;
use NeuronAI\Skills\Storage\SkillStorageInterface;

use function array_key_exists;
use function preg_match;
use function preg_split;
use function sort;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

use const SORT_STRING;

/** @internal */
class SkillRepository
{
    protected const MANIFEST = 'SKILL.md';

    /** @var array<int, array{name: string, description: string}> */
    protected array $catalog = [];

    /** @var array<string, true> */
    protected array $availableSkills = [];

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

        $contents = $this->storage->read($name, self::MANIFEST);

        $document = $this->parseManifest($contents);
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

        return $this->storage->read($name, $path);
    }

    protected function buildCatalog(): void
    {
        $packages = $this->storage->packages();
        sort($packages, SORT_STRING);

        foreach ($packages as $package) {
            try {
                $contents = $this->storage->read($package, self::MANIFEST);
            } catch (ToolException) {
                continue;
            }

            $document = $this->parseManifest($contents);
            if ($document === null) {
                continue;
            }

            $name = $document['name'];
            $description = $document['description'];
            if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $name) !== 1
                || strlen($name) > 64
                || $name !== $package
                || preg_match('/\A.{1,1024}\z/usD', $description) !== 1) {
                continue;
            }

            $this->catalog[] = [
                'name' => $name,
                'description' => $description,
            ];
            $this->availableSkills[$name] = true;
        }
    }

    /** @return array{name: string, description: string, body: string}|null */
    protected function parseManifest(string $contents): ?array
    {
        if (preg_match('/\A---\r?\n(.*?)\r?\n---(?:\r?\n|\z)(.*)\z/s', $contents, $matches) !== 1) {
            return null;
        }

        $metadata = [];
        foreach (preg_split('/\r?\n/', $matches[1]) ?: [] as $line) {
            if (str_starts_with($line, 'name:')) {
                $metadata['name'] = trim(substr($line, strlen('name:')));
            } elseif (str_starts_with($line, 'description:')) {
                $metadata['description'] = trim(substr($line, strlen('description:')));
            }
        }
        if (!isset($metadata['name'], $metadata['description'])) {
            return null;
        }

        return [
            'name' => $metadata['name'],
            'description' => $metadata['description'],
            'body' => $matches[2],
        ];
    }

}
