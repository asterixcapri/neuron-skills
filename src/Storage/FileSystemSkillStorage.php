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
        $this->discoverSkills();
    }

    public function skills(): array
    {
        return array_keys($this->skillDirectories);
    }

    public function location(string $skill): ?string
    {
        if (!array_key_exists($skill, $this->skillDirectories)) {
            throw new ToolException("Skill \"{$skill}\" is not available.");
        }

        return $this->skillDirectories[$skill];
    }

    public function read(string $skill, string $path): string
    {
        if (!array_key_exists($skill, $this->skillDirectories)) {
            throw new ToolException("Skill \"{$skill}\" is not available.");
        }
        if (!$this->validPath($path)) {
            throw new ToolException("Resource path \"{$path}\" is invalid.");
        }

        $skillDirectory = $this->skillDirectories[$skill];
        $file = realpath($skillDirectory.'/'.$path);
        if ($file === false) {
            throw new ToolException("Resource \"{$path}\" was not found in skill \"{$skill}\".");
        }
        if ($file !== $skillDirectory && !$this->isWithin($file, $skillDirectory)) {
            throw new ToolException("Resource \"{$path}\" escapes skill \"{$skill}\".");
        }
        if (!is_file($file)) {
            throw new ToolException("Resource \"{$path}\" in skill \"{$skill}\" is not a file.");
        }
        if (!is_readable($file)) {
            throw new ToolException("Resource \"{$path}\" in skill \"{$skill}\" could not be read.");
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new ToolException("Resource \"{$path}\" in skill \"{$skill}\" could not be read.");
        }
        if (str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) {
            throw new ToolException(
                "Resource \"{$path}\" in skill \"{$skill}\" contains unsupported binary content.",
            );
        }

        return $contents;
    }

    protected function validPath(string $path): bool
    {
        return $path !== '' && !str_contains($path, "\0");
    }

    protected function discoverSkills(): void
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
