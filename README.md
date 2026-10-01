# Neuron AI Skills

[![Tests](https://github.com/neuron-core/neuron-skills/actions/workflows/tests.yml/badge.svg)](https://github.com/neuron-core/neuron-skills/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)](composer.json)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

This package provides a `SkillToolkit` component for [Neuron AI](https://github.com/neuron-core/neuron-ai)
agents. It discovers reusable task instructions and lets the agent load them
when needed.

The skill format is based on the open
[Agent Skills specification](https://agentskills.io/specification), which defines
`SKILL.md` documents and their supporting resources.

A skill is a `SKILL.md` document with a name, a description and instructions,
optionally accompanied by references, scripts or other supporting files.
The agent starts with names, descriptions and available locations, then loads
the instructions and resources relevant to its task.

You can install community skills from [skills.sh](https://skills.sh) and combine
multiple skill directories in the same agent.

## When to Use It

- Give your agent task-specific guidance for writing, design or code review.
- Reuse community skills with a Neuron AI agent.
- Share team conventions across agents without duplicating their system prompts.
- Keep detailed instructions and resources available without loading every document upfront.

## Installation

Requires PHP 8.1+ and Neuron AI ^4.0. For Neuron AI 3.x support, use the
[`0.8.x` branch](https://github.com/neuron-core/neuron-skills/tree/0.8.x).

```sh
composer require neuron-core/neuron-skills
```

To try the standalone demo, follow [Runnable Examples](#runnable-examples).

## Quick Start

Install a community skill from your application's root:

```sh
npx skills add juliusbrussee/caveman --skill caveman --agent universal --yes
```

The [Skills CLI](https://github.com/vercel-labs/skills) requires Node.js/npm and
installs `caveman` into `.agents/skills`. Create an agent and register that
directory, replacing `your-api-key` with your OpenAI API key:

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Skills\Storage\FileSystemSkillStorage;
use NeuronAI\Skills\Tools\SkillToolkit;

$toolkit = SkillToolkit::fromStorage(
    new FileSystemSkillStorage(__DIR__.'/.agents/skills'),
);

$agent = Agent::make()
    ->setThreadId('quick-start')
    ->setAiProvider(new OpenAI(key: 'your-api-key', model: 'gpt-5.4-nano'))
    ->addTool($toolkit);

$response = $agent->chat(new UserMessage(
    'Use caveman skill to explain the difference between authentication and authorization.',
));

echo $response->getMessage()->getContent();
```

The agent can load `caveman` and answer in its terse style, for example:

> Authentication: who you are. Authorization: what you can do.
> Login proves identity. Permissions control access.

## Skill Tools

The toolkit registers two tools:

| Tool | Purpose |
| --- | --- |
| `skill` | Load the complete `SKILL.md` for a named skill. |
| `skill_resource` | Read a supporting text file relative to that skill. |

Skills can also include scripts. To execute them, register an execution tool,
such as Neuron's `BashTool`, alongside the toolkit. The library supplies the
instructions and resource locations; your application controls execution.

## Multiple Skill Directories

Pass storage instances in precedence order. For example, combine bundled skills
with skills installed by the CLI:

```php
$toolkit = SkillToolkit::fromStorage(
    new FileSystemSkillStorage(__DIR__.'/skills'),
    new FileSystemSkillStorage(__DIR__.'/.agents/skills'),
);
```

The first usable skill with a given declared name wins. Instructions and
resources are read from that selected source. Restart the agent or recreate the
toolkit after adding skills: discovery is a snapshot.

## Using Skills in Your Application

Share one `SkillRepository` between the toolkit and other application features,
for example slash commands and explicit skill invocation. `catalog()` returns a
list of `Skill` objects; `get($name)` returns the selected skill or throws a
`RuntimeException` when the name is unavailable.

```php
use NeuronAI\Skills\SkillRepository;
use NeuronAI\Skills\Storage\FileSystemSkillStorage;
use NeuronAI\Skills\Tools\SkillToolkit;

$skills = new SkillRepository(
    new FileSystemSkillStorage(__DIR__.'/.agents/skills'),
);
$agent->addTool(new SkillToolkit($skills));

foreach ($skills->catalog() as $skill) {
    echo $skill->name().': '.$skill->description();
}

$skill = $skills->get('caveman');
$instructions = $skill->readInstructions(); // Body without YAML frontmatter.
$document = $skill->readDocument();         // Complete original SKILL.md.
$location = $skill->location();             // Host-accessible location or null.
$resource = $skill->readResource('references/guide.md');
```

## Custom Storage

Implement [`SkillStorageInterface`](src/Storage/SkillStorageInterface.php) to
load skills from another backend. It defines three methods:

- `list()` returns the available storage identifiers.
- `read($skill, $path)` reads a UTF-8 text file relative to a skill.
- `location($skill)` returns a base location accessible to host tools, or `null`
  when none is available.

Use storage identifiers for reads and locations, even when they differ from the
declared skill names. Remote locations require host tools that can access them.
Throw `RuntimeException` for expected read failures, such as missing or
unreadable resources.

## Error Handling

Invalid or unreadable skills are skipped. Use `$skills->diagnostics()` to inspect
loading problems and warnings.

The tools report read failures to the agent. When accessing skills directly,
catch `RuntimeException` for unavailable skills, documents or resources.

## Invocation metadata

Optional and extension metadata is preserved when a skill is loaded. Fields
such as `disable-model-invocation` and `user-invocable` are not enforced by this
library. Applications that depend on invocation restrictions must implement
them in their host agent.

## Runnable Examples

See the [interactive demo guide](examples/README.md) for setup instructions and
sample conversations using skills and supporting resources.

## Contributing

Report bugs and propose changes through [GitHub Issues](https://github.com/neuron-core/neuron-skills/issues)
and pull requests. From the repository root, run the development checks with:

```sh
composer install
composer check
```

`composer check` runs PHPUnit and PHPStan without requiring an API key.
CI covers PHP 8.1–8.5, multiple Neuron AI versions and Symfony YAML compatibility.

## License

[MIT](LICENSE).
