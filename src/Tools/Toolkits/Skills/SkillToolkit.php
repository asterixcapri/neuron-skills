<?php

declare(strict_types=1);

namespace NeuronAI\Skills\Tools\Toolkits\Skills;

use NeuronAI\Skills\Internal\SkillRepository;
use NeuronAI\Skills\Storage\SkillStorageInterface;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

use function array_map;
use function implode;

class SkillToolkit extends AbstractToolkit
{
    /** @var array<int, array{name: string, description: string}> */
    protected array $catalog;

    protected SkillRepository $repository;

    public function __construct(SkillStorageInterface $storage, SkillStorageInterface ...$fallbackStorages)
    {
        $this->repository = new SkillRepository($storage, ...$fallbackStorages);
        $this->catalog = $this->repository->catalog();
    }

    /** @return list<array{skill: string, message: string}> */
    public function diagnostics(): array
    {
        return $this->repository->diagnostics();
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
            .' Resolve relative references against the skill location returned on activation.'
            .' Read only resources needed for the current task with `skill_resource` or authorized host tools.'
            .' The `skill` and `skill_resource` tools only read text and never execute scripts.'
            .' To run a script, use an authorized host execution tool on the file at the skill location,'
            .' preserving access to neighboring assets; executing script text alone may not be equivalent.'
            .' Binary assets require appropriate host tools and are not loaded into context automatically.'
            .' A location may be nonlocal or unavailable; host access and remote provisioning belong to the integration.'
            .' Metadata such as allowed-tools does not enable tools or grant permissions; the agent controls authorization.';
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
