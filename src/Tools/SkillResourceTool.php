<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tools;

use RuntimeException;
use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;

class SkillResourceTool extends Tool
{
    use TrackByInputs;

    protected string $name = 'skill_resource';

    protected ?string $description = 'Read a text file referenced by a loaded skill. When its instructions require a file, read it before continuing. Pass the path relative to the skill directory.';

    public function __construct(protected SkillRepository $repository)
    {
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'The name of the skill whose resource to read.',
                required: true,
                enum: $this->repository->names(),
            ),
            new ToolProperty(
                name: 'path',
                type: PropertyType::STRING,
                description: 'Path named in the skill instructions, for example references/checks.md.',
                required: true,
            ),
        ];
    }

    public function __invoke(string $name, string $path): string
    {
        try {
            return $this->repository->get($name)->readResource($path);
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }
    }
}
