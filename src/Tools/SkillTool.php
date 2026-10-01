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

class SkillTool extends Tool implements HasRunKey
{
    use TrackByInputs;

    public function __construct(protected SkillRepository $repository)
    {
        parent::__construct('skill', 'Load an available skill\'s complete SKILL.md.');
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'The name of the skill to load.',
                required: true,
                enum: $this->repository->names(),
            ),
        ];
    }

    public function __invoke(string $name): string
    {
        try {
            return $this->repository->get($name)->readDocument();
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }
    }
}
