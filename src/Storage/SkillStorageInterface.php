<?php

declare(strict_types=1);

namespace NeuronAI\Skills\Storage;

use RuntimeException;

interface SkillStorageInterface
{
    /**
     * Return the storage identifiers of available skill packages.
     *
     * @return string[]
     */
    public function list(): array;

    /**
     * Return a base location accessible to host tools, or null if none is available.
     * The location need not be a local filesystem path.
     *
     * @throws RuntimeException
     */
    public function location(string $skill): ?string;

    /**
     * Read a UTF-8 text file at a path relative to the skill package.
     * Throw RuntimeException for expected failures such as an unknown skill, invalid path, or unreadable file.
     *
     * @throws RuntimeException
     */
    public function read(string $skill, string $path): string;
}
