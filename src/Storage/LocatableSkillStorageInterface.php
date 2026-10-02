<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Storage;

use RuntimeException;

/** A storage whose skills live at a location that host tools can access. */
interface LocatableSkillStorageInterface extends SkillStorageInterface
{
    /**
     * Return the base location of a skill. The location need not be a local filesystem path.
     *
     * @throws RuntimeException
     */
    public function location(string $skill): string;
}
