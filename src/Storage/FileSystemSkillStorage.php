<?php

declare(strict_types=1);

namespace NeuronAI\Skills\Storage;

use DirectoryIterator;
use NeuronAI\Exceptions\ToolException;

use function array_key_exists;
use function array_keys;
use function file_get_contents;
use function is_dir;
use function is_file;
use function is_readable;
use function preg_match;
use function realpath;
use function rtrim;
use function sort;
use function sprintf;
use function str_contains;
use function str_starts_with;

use const DIRECTORY_SEPARATOR;
use const SORT_STRING;

class FileSystemSkillStorage implements SkillStorageInterface
{
    /** @var array<string, string> */
    protected array $skillDirectories = [];

    public function __construct(protected string $skillsRoot)
    {
        $this->discover();
    }

    public function list(): array
    {
        return array_map(strval(...), array_keys($this->skillDirectories));
    }

    public function location(string $skill): ?string
    {
        if (!array_key_exists($skill, $this->skillDirectories)) {
            throw new ToolException(sprintf('Skill "%s" is not available.', $skill));
        }

        return $this->skillDirectories[$skill];
    }

    public function read(string $skill, string $path): string
    {
        if (!array_key_exists($skill, $this->skillDirectories)) {
            throw new ToolException(sprintf('Skill "%s" is not available.', $skill));
        }
        if (!$this->validPath($path)) {
            throw new ToolException(sprintf('Resource path "%s" is invalid.', $path));
        }

        $skillDirectory = $this->skillDirectories[$skill];
        $file = realpath($skillDirectory.'/'.$path);
        if ($file === false) {
            throw new ToolException(sprintf('Resource "%s" was not found in skill "%s".', $path, $skill));
        }
        if ($file !== $skillDirectory && !$this->isWithin($file, $skillDirectory)) {
            throw new ToolException(sprintf('Resource "%s" escapes skill "%s".', $path, $skill));
        }
        if (!is_file($file)) {
            throw new ToolException(sprintf('Resource "%s" in skill "%s" is not a file.', $path, $skill));
        }
        if (!is_readable($file)) {
            throw new ToolException(sprintf('Resource "%s" in skill "%s" could not be read.', $path, $skill));
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new ToolException(sprintf('Resource "%s" in skill "%s" could not be read.', $path, $skill));
        }
        if (str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) {
            throw new ToolException(
                sprintf('Resource "%s" in skill "%s" contains unsupported binary content.', $path, $skill),
            );
        }

        return $contents;
    }

    protected function validPath(string $path): bool
    {
        return $path !== '' && !str_contains($path, "\0");
    }

    protected function discover(): void
    {
        if (!is_dir($this->skillsRoot)) {
            return;
        }

        foreach (new DirectoryIterator($this->skillsRoot) as $entry) {
            if ($entry->isDot() || !$entry->isDir()) {
                continue;
            }

            $directory = realpath($entry->getPathname());
            if ($directory !== false) {
                $this->skillDirectories[$entry->getFilename()] = $directory;
            }
        }

        $skills = array_keys($this->skillDirectories);
        sort($skills, SORT_STRING);
        $directories = $this->skillDirectories;
        $this->skillDirectories = [];
        foreach ($skills as $skill) {
            $this->skillDirectories[$skill] = $directories[$skill];
        }
    }

    protected function isWithin(string $path, string $directory): bool
    {
        return str_starts_with($path, rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }
}
