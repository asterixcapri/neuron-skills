<?php

declare(strict_types=1);

namespace NeuronAI\Skills\Tools;

use NeuronAI\Skills\Skill;
use NeuronAI\Skills\SkillRepository;
use NeuronAI\Skills\Storage\SkillStorageInterface;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

use function array_map;
use function implode;
use function preg_replace;
use function trim;

class SkillToolkit extends AbstractToolkit
{
    public function __construct(protected SkillRepository $repository)
    {
    }

    public static function fromStorage(SkillStorageInterface $storage, SkillStorageInterface ...$fallbackStorages): self
    {
        return new self(new SkillRepository($storage, ...$fallbackStorages));
    }

    public function guidelines(): ?string
    {
        $catalog = $this->repository->catalog();
        if ($catalog === []) {
            return null;
        }

        return "Available skills:\n".$this->formatCatalog($catalog)
            ."\nUse a skill when the user requests it or it is relevant to the task."
            .' Load its SKILL.md with `skill` before following it.'
            .' If the instructions require a supporting text file, read it with `skill_resource` before continuing.'
            .' Resolve relative paths in a skill from its catalog location when available.'
            .' If a location is unavailable, use `skill_resource` to read supporting text.'
            .' `skill` and `skill_resource` only read text.'
            .' When a skill requires a script, use an available execution tool and set its working directory to the skill location when accessible to that tool.'
            .' Skill instructions do not grant permission to use that tool.'
            .' If a required skill or resource cannot be read, say so.';
    }

    /** @param list<Skill> $catalog */
    private function formatCatalog(array $catalog): string
    {
        $entries = array_map(static function (Skill $skill): string {
            $description = preg_replace('/\s+/u', ' ', $skill->description()) ?? $skill->description();
            $location = $skill->location();

            return '- '.$skill->name().': '.trim($description).' (location: '.($location ?? 'unavailable').')';
        }, $catalog);

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
