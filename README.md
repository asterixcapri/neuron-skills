# Neuron AI Skills

[![Tests](https://github.com/asterixcapri/neuron-skills/actions/workflows/tests.yml/badge.svg)](https://github.com/asterixcapri/neuron-skills/actions/workflows/tests.yml)
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
[`0.8.x` branch](https://github.com/asterixcapri/neuron-skills/tree/0.8.x).
There is no tagged release yet; install from a local checkout until the first
release is available.

From your application's root, clone this repository alongside it and register
it as a Composer path repository:

```sh
git clone https://github.com/asterixcapri/neuron-skills.git ../neuron-skills
composer config repositories.neuron-skills path ../neuron-skills
composer require 'asterixcapri/neuron-skills:@dev'
```

Adjust the path if your checkout is elsewhere. To try the standalone demo,
follow [Runnable Examples](#runnable-examples) instead.

## Quick Start

Install a community skill from your application's root:

```sh
npx skills add juliusbrussee/caveman --skill caveman --agent universal --yes
```

The [Skills CLI](https://github.com/vercel-labs/skills) requires Node.js/npm and
installs `caveman` into `.agents/skills`. Register that directory on your
configured Neuron AI agent:

```php
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Skills\Storage\FileSystemSkillStorage;
use NeuronAI\Skills\Tools\SkillToolkit;

$toolkit = SkillToolkit::fromStorage(
    new FileSystemSkillStorage(__DIR__.'/.agents/skills'),
);

// $agent already has your AI provider and a thread ID configured.
$agent->addTool($toolkit);

$response = $agent->chat(new UserMessage(
    'Use caveman skill to explain the difference between authentication and authorization.',
));

echo $response->getMessage()->getContent();
```

The agent can load `caveman` and answer in its terse style, for example:

> Authentication: who you are. Authorization: what you can do.
> Login proves identity. Permissions control access.

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

Names and descriptions are captured during discovery. Documents, locations and
resources are read on demand from the selected storage. `SkillDocumentParser`
remains an internal parsing detail. Reads can throw `RuntimeException` for
unavailable contents or invalid frontmatter.

If only the toolkit needs the skills, construct it directly from storages:

```php
$toolkit = SkillToolkit::fromStorage(
    new FileSystemSkillStorage(__DIR__.'/.agents/skills'),
);
```

The repository methods `readInstructions($name)`, `readDocument($name)`,
`location($name)` and `readResource($name, $path)` have moved to `Skill`.
Catalog entries now expose methods instead of array keys.

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
$skills = new SkillRepository(
    new FileSystemSkillStorage(__DIR__.'/skills'),
    new FileSystemSkillStorage(__DIR__.'/.agents/skills'),
);
```

The first usable skill with a given declared name wins. Instructions and
resources are read from that selected source. Restart the agent or recreate the
storage and repository after adding skills: discovery is a snapshot.

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

Unusable skill documents are skipped. Inspect `$skills->diagnostics()` for
entries containing `skill` and `message`, including loading failures, metadata
warnings and shadowed skills. Diagnostics are not printed automatically by the
library; the demo displays them at startup.

When the agent calls `skill` or `skill_resource`, expected read failures become
messages it can read. Unexpected exceptions propagate to the application.
Direct repository reads throw exceptions for expected failures as well.
Neuron AI validates tool arguments before execution; a skill name outside the
catalog produces a `ToolOutput` error that the agent can read.

## Invocation metadata

Optional and extension metadata is preserved when a skill is loaded. Fields
such as `disable-model-invocation` and `user-invocable` are not enforced by this
library. Applications that depend on invocation restrictions must implement
them in their host agent.

## Runnable Examples

The [interactive demo](examples/README.md) loads two skill sources in one agent:

- **Included:** `php-check` reads a reference and runs a bundled PHP script.
- **Installed:** `caveman`, added with the Skills CLI, changes response style.

You need PHP 8.1+, Composer, Node.js/npm and an OpenAI API key. The demo makes real
API requests. From a checkout of this repository:

```sh
composer install
cp examples/.env.example examples/.env
```

Edit `examples/.env` and set `OPENAI_API_KEY`. Then install `caveman` **from
`examples/`** and start the agent:

```sh
cd examples
npx skills add juliusbrussee/caveman --skill caveman --agent universal --yes
php agent-loop.php
```

First, send:

> Use php-check to check this PHP runtime and explain the result.

Type `exit`, restart the script, and try:

> Use caveman skill to explain the difference between authentication and authorization.

The [demo guide](examples/README.md) includes setup and a sample conversation
showing `skill`, `skill_resource` and `bash` calls.

## Contributing

Report bugs and propose changes through [GitHub Issues](https://github.com/asterixcapri/neuron-skills/issues)
and pull requests. From the repository root, run the development checks with:

```sh
composer install
composer check
```

`composer check` runs PHPUnit and PHPStan without requiring an API key.
CI covers PHP 8.1–8.5, multiple Neuron AI versions and Symfony YAML compatibility.

## License

[MIT](LICENSE).
