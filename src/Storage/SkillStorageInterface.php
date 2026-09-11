<?php

declare(strict_types=1);

namespace NeuronAI\Skills\Storage;

use NeuronAI\Exceptions\ToolException;

interface SkillStorageInterface
{
    /** @return string[] */
    public function packages(): array;

    /** @throws ToolException */
    public function read(string $package, string $path): string;
}
