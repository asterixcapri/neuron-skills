<?php

declare(strict_types=1);

use NeuronAI\Agent\Agent;
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

$model = $_ENV['OPENAI_MODEL'] ?? getenv('OPENAI_MODEL');
$model = is_string($model) && $model !== '' ? $model : 'gpt-4o-mini';

$skills = new SkillRepository(new FileSystemSkillStorage(__DIR__.'/skills'));
$toolkit = new SkillToolkit($skills);
$bash = BashTool::make();

$agent = Agent::make()
    ->setAiProvider(new OpenAI(key: $key, model: $model))
    ->setInstructions('Use the available skills and host tools when relevant.')
    ->addTool($toolkit)
    ->addTool($bash);

echo $agent->chat(new UserMessage(
    'Use the writing skill to check its bundled sample. Run the skill script with the available host tool and report its output.',
))->getMessage()->getContent().PHP_EOL;
