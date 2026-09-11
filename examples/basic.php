<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Skills\SkillToolkit;
use NeuronAI\Skills\Storage\FileSystemSkillStorage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\Tool;

require dirname(__DIR__).'/vendor/autoload.php';

$toolkit = new SkillToolkit(new FileSystemSkillStorage(__DIR__.'/skills'));
[$skillTool, $resourceTool] = $toolkit->tools();
$activation = (clone $skillTool)->setCallId('load_skill')->setInputs(['name' => 'writing']);

// This host grants access to this one example script. The toolkit never executes it.
$checkTool = (new Tool('check_writing', 'Run the permitted writing check.'))
    ->setCallable(function () use ($activation): string {
        $result = $activation->getResult();
        $location = substr(explode("\n", $result, 2)[0], strlen('Skill location: '));
        $permitted = realpath(__DIR__.'/skills/writing');
        if ($permitted === false || $location !== $permitted) {
            throw new RuntimeException('The example only permits its bundled writing skill.');
        }
        // Run the file, preserving __DIR__ so it can read its neighboring asset.
        $process = proc_open([PHP_BINARY, $location.'/scripts/check.php'], [1 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the example check.');
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0 || $output === false) {
            throw new RuntimeException('The example check failed.');
        }
        echo $output.PHP_EOL;
        return $output;
    });

// Deterministic tool calls demonstrate the real Neuron loop without LLM inference.
$provider = new FakeAIProvider(
    new ToolCallMessage(null, [$activation]),
    new ToolCallMessage(null, [
        (clone $resourceTool)->setCallId('read_style')->setInputs([
            'name' => 'writing',
            'path' => 'references/style.md',
        ]),
    ]),
    new ToolCallMessage(null, [(clone $checkTool)->setCallId('check')->setInputs([])]),
    new AssistantMessage('Loaded the complete writing skill, read its guide and ran the host check.'),
);

$agent = Agent::make()
    ->setAiProvider($provider)
    ->setInstructions('Use the available skills when relevant.')
    ->addTool($toolkit)
    ->addTool($checkTool);

echo $agent->chat(new UserMessage('Help me write clearly.'))->getMessage()->getContent().PHP_EOL;
