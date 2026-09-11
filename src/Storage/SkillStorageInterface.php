<?php

declare(strict_types=1);

namespace NeuronAI\Skills\Storage;

use NeuronAI\Exceptions\ToolException;

interface SkillStorageInterface
{
    /** @return string[] */
    public function list(): array;

    /**
     * Host-accessible base location, or null when unavailable. Not necessarily a local path.
     * @throws ToolException
     */
    public function location(string $skill): ?string;

    /** @throws ToolException */
    public function read(string $skill, string $path): string;
}
