<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Skills\SkillRepository;
use NeuronAI\Skills\Storage\FileSystemSkillStorage;
use NeuronAI\Skills\Tools\SkillToolkit;
use NeuronAI\Tools\Toolkits\FileSystem\BashTool;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

$envFile = __DIR__.'/.env';
if (is_file($envFile)) {
    (new Dotenv())->bootEnv($envFile);
}

$key = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY');
if (!is_string($key) || $key === '') {
    fwrite(STDERR, 'OPENAI_API_KEY not configured. Copy examples/.env.example to examples/.env and add your key.'.PHP_EOL);
    exit(1);
}

$skills = new SkillRepository(new FileSystemSkillStorage(__DIR__.'/skills'));

$agent = Agent::make()
    ->setAiProvider(new OpenAI(key: $key, model: 'gpt-5.4-nano'))
    ->addTool(new SkillToolkit($skills))
    ->addTool(new BashTool());

echo "Type a message, or 'exit' to quit.\n";

while (true) {
    echo "\nYou> ";
    $input = fgets(STDIN);
    if ($input === false) {
        break;
    }

    $input = trim($input);
    if ($input === '') {
        continue;
    }
    if (in_array(strtolower($input), ['exit', 'quit'], true)) {
        break;
    }

    echo 'Agent> ';
    foreach ($agent->stream(new UserMessage($input))->events() as $event) {
        if ($event instanceof ToolCallChunk) {
            echo sprintf(
                "\n[tool: %s %s]\n",
                $event->tool->getName(),
                json_encode($event->tool->getInputs(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            );
        } elseif ($event instanceof TextChunk) {
            echo $event->content;
            flush();
        }
    }

    echo PHP_EOL;
}
