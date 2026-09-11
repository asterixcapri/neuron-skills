<?php

declare(strict_types=1);

namespace NeuronAI\Skills\Storage;

use NeuronAI\Exceptions\ToolException;

interface SkillStorageInterface
{
    /** @return string[] */
    public function skills(): array;

    /** @throws ToolException */
    public function read(string $skill, string $path): string;
}
