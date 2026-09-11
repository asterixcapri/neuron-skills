<?php

declare(strict_types=1);

namespace NeuronAI\Skills;

use NeuronAI\Skills\Internal\SkillRepository;
use NeuronAI\Skills\Storage\SkillStorageInterface;
use NeuronAI\Skills\Tool\SkillResourceTool;
use NeuronAI\Skills\Tool\SkillTool;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

use function array_map;
use function implode;

class SkillToolkit extends AbstractToolkit
{
    /** @var array<int, array{name: string, description: string}> */
    protected array $catalog;

    protected SkillRepository $repository;

    public function __construct(SkillStorageInterface $storage)
    {
        $this->repository = new SkillRepository($storage);
        $this->catalog = $this->repository->catalog();
    }

    public function guidelines(): ?string
    {
        if ($this->catalog === []) {
            return null;
        }

        $catalog = array_map(
            fn (array $skill): string => "- {$skill['name']}: {$skill['description']}",
            $this->catalog,
        );

        return "Available skills:\n".implode("\n", $catalog)
            ."\nUse the `skill` tool to load a relevant skill's complete instructions before following them."
            .' Skill instructions may reference other files in their package.'
            .' Always load every referenced file with the `skill_resource` tool.'
            .' The `skill` and `skill_resource` tools only read text and never execute scripts.'
            .' If a loaded file is a script and an appropriate execution tool is available,'
            .' use that separate tool to execute the loaded contents.';
    }

    public function provide(): array
    {
        if ($this->catalog === []) {
            return [];
        }

        $names = array_map(
            fn (array $skill): string => $skill['name'],
            $this->catalog,
        );

        return [
            new SkillTool($this->repository, $names),
            new SkillResourceTool($this->repository, $names),
        ];
    }
}
