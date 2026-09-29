<?php

declare(strict_types=1);

namespace NeuronAI\Skills\Tools;

use RuntimeException;
use NeuronAI\Skills\SkillRepository;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;

class SkillResourceTool extends Tool implements HasRunKey
{
    use TrackByInputs;

    public function __construct(protected SkillRepository $repository)
    {
        parent::__construct(
            'skill_resource',
            'Read a text file referenced by a loaded skill. When its instructions require a file, read it before continuing. Pass the path relative to the skill directory.',
        );
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
            return $this->repository->readResource($name, $path);
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }
    }
}
