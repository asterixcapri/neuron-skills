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
    protected array $packageDirectories = [];

    public function __construct(protected string $skillsRoot)
    {
        $this->discoverPackages();
    }

    public function packages(): array
    {
        return array_keys($this->packageDirectories);
    }

    public function read(string $package, string $path): string
    {
        if (!array_key_exists($package, $this->packageDirectories)) {
            throw new ToolException("Skill \"{$package}\" is not available.");
        }
        if (!$this->validPath($path)) {
            throw new ToolException("Resource path \"{$path}\" is invalid.");
        }

        $packageDirectory = $this->packageDirectories[$package];
        $file = realpath($packageDirectory.'/'.$path);
        if ($file === false) {
            throw new ToolException("Resource \"{$path}\" was not found in skill \"{$package}\".");
        }
        if ($file !== $packageDirectory && !$this->isWithin($file, $packageDirectory)) {
            throw new ToolException("Resource \"{$path}\" escapes skill \"{$package}\".");
        }
        if (!is_file($file)) {
            throw new ToolException("Resource \"{$path}\" in skill \"{$package}\" is not a file.");
        }
        if (!is_readable($file)) {
            throw new ToolException("Resource \"{$path}\" in skill \"{$package}\" could not be read.");
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new ToolException("Resource \"{$path}\" in skill \"{$package}\" could not be read.");
        }
        if (str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) {
            throw new ToolException(
                "Resource \"{$path}\" in skill \"{$package}\" contains unsupported binary content.",
            );
        }

        return $contents;
    }

    protected function validPath(string $path): bool
    {
        return $path !== '' && !str_contains($path, "\0");
    }

    protected function discoverPackages(): void
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
                $this->packageDirectories[$entry->getFilename()] = $directory;
            }
        }

        $packages = array_keys($this->packageDirectories);
        sort($packages, SORT_STRING);
        $directories = $this->packageDirectories;
        $this->packageDirectories = [];
        foreach ($packages as $package) {
            $this->packageDirectories[$package] = $directories[$package];
        }
    }

    protected function isWithin(string $path, string $directory): bool
    {
        return str_starts_with($path, rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }
}
