<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Skills\SkillToolkit;
use NeuronAI\Skills\Storage\FileSystemSkillStorage;
use NeuronAI\Testing\FakeAIProvider;

require dirname(__DIR__).'/vendor/autoload.php';

$toolkit = new SkillToolkit(new FileSystemSkillStorage(__DIR__.'/skills'));
[$skillTool, $resourceTool] = $toolkit->tools();

// A deterministic provider keeps this example runnable without API credentials.
$provider = new FakeAIProvider(
    new ToolCallMessage(null, [
        (clone $skillTool)->setCallId('load_skill')->setInputs(['name' => 'writing']),
    ]),
    new ToolCallMessage(null, [
        (clone $resourceTool)->setCallId('read_style')->setInputs([
            'name' => 'writing',
            'path' => 'references/style.md',
        ]),
    ]),
    new AssistantMessage('Loaded the writing skill and its style guide.'),
);

$agent = Agent::make()
    ->setAiProvider($provider)
    ->setInstructions('Use the available skills when relevant.')
    ->addTool($toolkit);

echo $agent->chat(new UserMessage('Help me write clearly.'))->getMessage()->getContent().PHP_EOL;
