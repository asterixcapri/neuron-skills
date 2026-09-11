<?php

declare(strict_types=1);

namespace NeuronAI\Skills\Tools\Toolkits\Skills;

use NeuronAI\Exceptions\ToolException;
use NeuronAI\Skills\Internal\SkillRepository;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;

use function in_array;

class SkillResourceTool extends Tool implements HasRunKey
{
    use TrackByInputs;

    /**
     * @internal Created by SkillToolkit; configure tools through the toolkit.
     * @param string[] $skillNames
     */
    public function __construct(
        protected SkillRepository $repository,
        protected array $skillNames,
    ) {
        parent::__construct('skill_resource', 'Read one textual file from an available skill package.');
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'The name of the skill whose resource to read.',
                required: true,
                enum: $this->skillNames,
            ),
            new ToolProperty(
                name: 'path',
                type: PropertyType::STRING,
                description: 'The file path relative to the skill package.',
                required: true,
            ),
        ];
    }

    public function __invoke(string $name, string $path): string
    {
        if (!in_array($name, $this->skillNames, true)) {
            return "Skill \"{$name}\" is not available.";
        }

        try {
            return $this->repository->readResource($name, $path);
        } catch (ToolException $exception) {
            return $exception->getMessage();
        }
    }
}
