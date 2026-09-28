<?php

declare(strict_types=1);

namespace NeuronAI\Skills\Tools;

use NeuronAI\Skills\SkillRepository;
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

    public function guidelines(): ?string
    {
        $catalog = $this->repository->catalog();
        if ($catalog === []) {
            return null;
        }

        return "Available skills:\n".$this->formatCatalog($catalog)
            ."\nUse a skill when the user requests it or it is relevant to the task."
            .' Load its complete instructions with `skill` before following them; load each needed skill separately.'
            .' Read supporting text only when needed with `skill_resource`. These tools only read text.'
            .' Use separately authorized host tools to run scripts or inspect binary assets, resolving paths from the skill location when available.'
            .' If a required skill or resource cannot be loaded, report the limitation instead of claiming to have used it.';
    }

    /** @param array<int, array{name: string, description: string}> $catalog */
    private function formatCatalog(array $catalog): string
    {
        $entries = array_map(static function (array $skill): string {
            $description = preg_replace('/\s+/u', ' ', $skill['description']) ?? $skill['description'];

            return '- '.$skill['name'].': '.trim($description);
        }, $catalog);

        return implode("\n", $entries);
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
