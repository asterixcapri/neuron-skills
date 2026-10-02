<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Storage;

use DirectoryIterator;
use RuntimeException;

use function array_key_exists;
use function array_keys;
use function file_get_contents;
use function is_dir;
use function is_file;
use function is_readable;
use function ksort;
use function realpath;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_starts_with;

use const DIRECTORY_SEPARATOR;
use const SORT_STRING;

class FileSystemSkillStorage implements LocatableSkillStorageInterface
{
    protected const DOCUMENT = 'SKILL.md';

    /** @var array<string, string>|null */
    protected ?array $skillDirectories = null;

    public function __construct(protected string $skillsRoot)
    {
    }

    public function list(): array
    {
        $documents = [];
        foreach (array_keys($this->directories()) as $skill) {
            try {
                $documents[$skill] = $this->resource((string) $skill, self::DOCUMENT);
            } catch (RuntimeException) {
                continue;
            }
        }

        return $documents;
    }

    public function location(string $skill): string
    {
        $directories = $this->directories();
        if (!array_key_exists($skill, $directories)) {
            throw new RuntimeException(sprintf('Skill "%s" is not available.', $skill));
        }

        return $directories[$skill];
    }

    public function resource(string $skill, string $reference): string
    {
        $directories = $this->directories();
        if (!array_key_exists($skill, $directories)) {
            throw new RuntimeException(sprintf('Skill "%s" is not available.', $skill));
        }
        if (!$this->validPath($reference)) {
            throw new RuntimeException(sprintf('Resource path "%s" is invalid.', $reference));
        }

        $skillDirectory = $directories[$skill];
        $file = realpath($skillDirectory.'/'.$reference);
        if ($file === false) {
            throw new RuntimeException(sprintf('Resource "%s" was not found in skill "%s".', $reference, $skill));
        }
        if ($file !== $skillDirectory && !$this->isWithin($file, $skillDirectory)) {
            throw new RuntimeException(sprintf('Resource "%s" escapes skill "%s".', $reference, $skill));
        }
        if (!is_file($file)) {
            throw new RuntimeException(sprintf('Resource "%s" in skill "%s" is not a file.', $reference, $skill));
        }
        if (!is_readable($file)) {
            throw new RuntimeException(sprintf('Resource "%s" in skill "%s" could not be read.', $reference, $skill));
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Resource "%s" in skill "%s" could not be read.', $reference, $skill));
        }

        return $contents;
    }

    protected function validPath(string $path): bool
    {
        return $path !== '' && !str_contains($path, "\0");
    }

    /**
     * The skill directories found on first access, keyed by storage identifier.
     *
     * @return array<string, string>
     */
    protected function directories(): array
    {
        return $this->skillDirectories ??= $this->discover();
    }

    /** @return array<string, string> */
    protected function discover(): array
    {
        $directories = [];
        if (!is_dir($this->skillsRoot)) {
            return $directories;
        }

        foreach (new DirectoryIterator($this->skillsRoot) as $entry) {
            if ($entry->isDot() || !$entry->isDir()) {
                continue;
            }

            $directory = realpath($entry->getPathname());
            if ($directory !== false) {
                $directories[$entry->getFilename()] = $directory;
            }
        }

        ksort($directories, SORT_STRING);

        return $directories;
    }

    protected function isWithin(string $path, string $directory): bool
    {
        return str_starts_with($path, rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }
}
