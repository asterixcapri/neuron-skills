<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Storage;

use RuntimeException;

interface SkillStorageInterface
{
    /**
     * Return the SKILL.md document of every available skill, keyed by storage identifier.
     * Skills whose document cannot be read are left out.
     *
     * @return array<string, string>
     */
    public function list(): array;

    /**
     * Read a supporting text resource of a skill.
     * The reference is the one used in the skill instructions, already reduced to
     * forward-slash separated segments without a leading slash, "." or "..".
     * Throw RuntimeException for expected failures such as an unknown skill or a missing resource.
     *
     * @throws RuntimeException
     */
    public function resource(string $skill, string $reference): string;
}
