<?php

declare(strict_types=1);

namespace NeuronAI\Skills\Tool;

use NeuronAI\Exceptions\ToolException;
use NeuronAI\Skills\Internal\SkillRepository;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;

use function in_array;

class SkillTool extends Tool implements HasRunKey
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
        parent::__construct('skill', 'Load an available skill\'s complete SKILL.md and base location.');
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'The name of the skill to load.',
                required: true,
                enum: $this->skillNames,
            ),
        ];
    }

    public function __invoke(string $name): string
    {
        if (!in_array($name, $this->skillNames, true)) {
            return "Skill \"{$name}\" is not available.";
        }

        try {
            $document = $this->repository->readInstructions($name);
            $location = $this->repository->location($name);
            $context = $location === null
                ? 'Skill location: unavailable. Read resources with skill_resource; host file access is not established.'
                : 'Skill location: '.$location;

            return $context."\n\n".$document;
        } catch (ToolException $exception) {
            return $exception->getMessage();
        }
    }
}
