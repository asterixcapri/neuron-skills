<?php

declare(strict_types=1);

namespace NeuronAI\Skills;

use NeuronAI\Skills\Storage\SkillStorageInterface;
use RuntimeException;
use stdClass;

use function sprintf;
use function trim;

/** A discovered skill whose document and resources are read on demand. */
final class Skill
{
    public function __construct(
        private readonly string $name,
        private readonly string $description,
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
        return $this->storage->location($this->identifier);
    }

    /** @throws RuntimeException */
    public function readInstructions(): string
    {
        return trim($this->parseDocument($this->storage->read($this->identifier, 'SKILL.md'))['body']);
    }

    /** @throws RuntimeException */
    public function readFrontmatter(): stdClass
    {
        return $this->parseDocument($this->storage->read($this->identifier, 'SKILL.md'))['frontmatter'];
    }

    /** @throws RuntimeException */
    public function readDocument(): string
    {
        $contents = $this->storage->read($this->identifier, 'SKILL.md');
        $this->parseDocument($contents);

        return $contents;
    }

    /** @throws RuntimeException */
    public function readResource(string $path): string
    {
        if ($path === '') {
            throw new RuntimeException('Resource path "" is invalid.');
        }

        return $this->storage->read($this->identifier, $path);
    }

    /** @return array{name: string, description: string, body: string, frontmatter: stdClass} */
    private function parseDocument(string $contents): array
    {
        $document = (new SkillDocumentParser())->parse($contents, $this->identifier)['document'];
        if ($document === null) {
            throw new RuntimeException(sprintf('Skill "%s" has invalid frontmatter.', $this->name));
        }

        return $document;
    }
}
