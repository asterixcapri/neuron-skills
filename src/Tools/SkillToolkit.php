<?php

declare(strict_types=1);

namespace NeuronAI\Skills\Tools;

use NeuronAI\Skills\SkillRepository;
use NeuronAI\Skills\Storage\SkillStorageInterface;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

use function array_map;
use function implode;

class SkillToolkit extends AbstractToolkit
{
    protected SkillRepository $repository;

    public function __construct(SkillStorageInterface $storage, SkillStorageInterface ...$fallbackStorages)
    {
        $this->repository = new SkillRepository($storage, ...$fallbackStorages);
    }

    /** @return list<array{skill: string, message: string}> */
    public function diagnostics(): array
    {
        return $this->repository->diagnostics();
    }

    public function guidelines(): ?string
    {
        $catalog = $this->repository->catalog();
        if ($catalog === []) {
            return null;
        }

        $entries = array_map(
            fn (array $skill): string => "- {$skill['name']}: {$skill['description']}",
            $catalog,
        );

        return "Available skills:\n".implode("\n", $entries)
            ."\nSelect skills explicitly requested by the user or relevant to the task; use only the skills needed."
            ."\nUse the `skill` tool to load a relevant skill's complete instructions before following them."
            .' When multiple skills are needed, follow their dependencies and load each before performing its part of the task.'
            .' When the agent permits user-facing progress messages, briefly announce which skill you are using and why;'
            .' for multiple skills, state their order and explain transitions as they happen.'
            .' Respect the host agent\'s communication policy and required output format; do not add conversational text to structured-only responses.'
            .' Resolve relative references against the skill location returned on activation.'
            .' Read only resources needed for the current task with `skill_resource` or authorized host tools.'
            .' Reuse relevant skill resources instead of recreating them.'
            .' If a skill or required resource cannot be loaded, do not claim to have followed it;'
            .' report the limitation through the agent\'s permitted response format and use an appropriate fallback when the task allows it.'
            .' The `skill` and `skill_resource` tools only read text and never execute scripts.'
            .' To run a script, use an authorized host execution tool on the file at the skill location,'
            .' preserving access to neighboring assets; executing script text alone may not be equivalent.'
            .' Binary assets require appropriate host tools and are not loaded into context automatically.'
            .' A location may be nonlocal or unavailable; host access and remote provisioning belong to the integration.'
            .' Metadata such as allowed-tools does not enable tools or grant permissions; the agent controls authorization.';
    }

    public function provide(): array
    {
        $catalog = $this->repository->catalog();
        if ($catalog === []) {
            return [];
        }

        $names = array_map(
            fn (array $skill): string => $skill['name'],
            $catalog,
        );

        return [
            new SkillTool($this->repository, $names),
            new SkillResourceTool($this->repository, $names),
        ];
    }
}
