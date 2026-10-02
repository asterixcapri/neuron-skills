<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills;

use NeuronAI\AgentSkills\Storage\LocatableSkillStorageInterface;
use NeuronAI\AgentSkills\Storage\SkillStorageInterface;
use RuntimeException;
use stdClass;

use function array_pop;
use function explode;
use function implode;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function trim;

/** A discovered skill holding its document, whose resources are read on demand. */
final class Skill
{
    public function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly string $document,
        private readonly SkillStorageInterface $storage,
        private readonly string $identifier,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    /** @throws RuntimeException */
    public function location(): ?string
    {
        if (!$this->storage instanceof LocatableSkillStorageInterface) {
            return null;
        }

        return $this->storage->location($this->identifier);
    }

    /** @throws RuntimeException */
    public function readInstructions(): string
    {
        return trim($this->parseDocument()['body']);
    }

    /** @throws RuntimeException */
    public function readFrontmatter(): stdClass
    {
        return $this->parseDocument()['frontmatter'];
    }

    public function readDocument(): string
    {
        return $this->document;
    }

    /** @throws RuntimeException */
    public function readResource(string $path): string
    {
        $reference = $this->normalizeReference($path);
        if ($reference === null) {
            throw new RuntimeException(sprintf('Resource path "%s" is invalid.', $path));
        }

        $contents = $this->storage->resource($this->identifier, $reference);
        if (str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) {
            throw new RuntimeException(
                sprintf('Resource "%s" in skill "%s" contains unsupported binary content.', $path, $this->name),
            );
        }

        return $contents;
    }

    /**
     * Reduce a path to the reference storages are addressed with, or return null
     * when it is empty, absolute or escapes the skill.
     */
    private function normalizeReference(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/')) {
            return null;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment !== '..') {
                $segments[] = $segment;
                continue;
            }
            if ($segments === []) {
                return null;
            }
            array_pop($segments);
        }

        return $segments === [] ? null : implode('/', $segments);
    }

    /** @return array{name: string, description: string, body: string, frontmatter: stdClass} */
    private function parseDocument(): array
    {
        $document = (new SkillDocumentParser())->parse($this->document, $this->identifier)['document'];
        if ($document === null) {
            throw new RuntimeException(sprintf('Skill "%s" has invalid frontmatter.', $this->name));
        }

        return $document;
    }
}
