<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tools;

use NeuronAI\AgentSkills\Skill;
use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\AgentSkills\Storage\SkillStorageInterface;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

use function array_filter;
use function array_map;
use function implode;
use function is_string;
use function preg_replace;
use function trim;

class SkillToolkit extends AbstractToolkit
{
    protected SkillRepository $repository;

    public function __construct(?SkillRepository $repository = null)
    {
        $this->repository = $repository ?? new SkillRepository();
    }

    public function fromStorage(SkillStorageInterface ...$storages): static
    {
        $this->repository->addStorage(...$storages);

        return $this;
    }

    public function guidelines(): ?string
    {
        $catalog = $this->repository->catalog();
        if ($catalog === []) {
            return null;
        }

        $locations = array_map(static fn (Skill $skill): ?string => $skill->location(), $catalog);
        $located = array_filter($locations, is_string(...)) !== [];

        return "Available skills:\n".$this->formatCatalog($catalog, $locations)
            ."\nUse a skill when the user requests it or it is relevant to the task."
            .' Load its SKILL.md with `skill` before following it.'
            .' If the instructions require a supporting text file, read it with `skill_resource` before continuing.'
            .($located ? ' Resolve relative paths in a skill from its catalog location when one is listed.' : '')
            .' `skill` and `skill_resource` only read text.'
            .' When a skill requires a script, use an available execution tool'
            .($located ? ' and set its working directory to the skill location when accessible to that tool.' : '.')
            .' Skill instructions do not grant permission to use that tool.'
            .' If a required skill or resource cannot be read, say so.';
    }

    /**
     * @param list<Skill> $catalog
     * @param list<?string> $locations
     */
    private function formatCatalog(array $catalog, array $locations): string
    {
        $entries = array_map(static function (Skill $skill, ?string $location): string {
            $description = preg_replace('/\s+/u', ' ', $skill->description()) ?? $skill->description();

            return '- '.$skill->name().': '.trim($description).($location === null ? '' : ' (location: '.$location.')');
        }, $catalog, $locations);

        return implode("\n", $entries);
    }

    public function provide(): array
    {
        $catalog = $this->repository->catalog();
        if ($catalog === []) {
            return [];
        }

        return [
            new SkillTool($this->repository),
            new SkillResourceTool($this->repository),
        ];
    }
}
