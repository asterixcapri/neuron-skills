# Neuron AI Skills

This package adds a `SkillToolkit` to your Neuron AI agent so it can discover
and load reusable instructions for specific tasks. A **skill** is a `SKILL.md`
document with a name, a description and instructions, optionally accompanied
by reference guides, examples or other supporting files.

The agent starts with the available names and descriptions. When a skill is
relevant, it loads the instructions and any supporting files it needs. This
keeps specialized guidance available without adding every document to the
system prompt.

The format is based on the [Agent Skills specification](https://agentskills.io/specification).
You can install existing skills from [skills.sh](https://skills.sh) or write your own.

## When to Use It

- Give your agent task-specific guidance for design, writing or code review.
- Reuse community skills with a Neuron AI agent.
- Share team conventions across agents without duplicating their system prompts.
- Keep detailed instructions and examples available for the agent to read when needed.

## Installation

Requires PHP 8.1+ and Neuron AI ^3.16.13.

For a local checkout, register the library with Composer in your application,
then require the package. Adjust the path to match its location:

```sh
composer config repositories.neuron-skills path ../neuron-skills
composer require 'asterixcapri/neuron-skills:@dev'
```

## Quick Start

Install a skill, point the toolkit at its location, and add it to your agent.
This example uses the popular [caveman skill](https://skills.sh/juliusbrussee/caveman/caveman)
to make the agent answer in short, direct phrases. You can try it with a single
question and see the change in the response.

From your application's root, install the skill with the
[Skills CLI](https://github.com/vercel-labs/skills#install-a-skill) (requires Node.js and npm):

```sh
npx skills add juliusbrussee/caveman --skill caveman --agent universal --yes
```

The command installs the skill in `.agents/skills`. In a PHP file at your
application's root, register that folder on your configured Neuron AI agent:

```php
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Skills\SkillToolkit;
use NeuronAI\Skills\Storage\FileSystemSkillStorage;

$storage = new FileSystemSkillStorage(__DIR__.'/.agents/skills');

// $agent already has your AI provider configured.
$agent->addTool(new SkillToolkit($storage));

$response = $agent->chat(
    new UserMessage(
        'Use caveman skill to explain the difference between authentication and authorization.',
    ),
);

echo $response->getMessage()->getContent();
```

The agent can load `caveman` and answer in its terse style. For example:

> Authentication: who you are. Authorization: what you can do.
> Login proves identity. Permissions control access.

To add your own guidance, create a [custom skill](#custom-skills) in the same folder.

## Available Tools

The toolkit registers two tools that the agent can call:

| Tool | Purpose | Inputs |
| --- | --- | --- |
| `skill` | Load a skill's instructions. | `name` |
| `skill_resource` | Read a supporting file from that skill. | `name`, `path` |

Resource paths are relative to the skill, such as `references/style.md`.
Both tools read text. Executing scripts or writing generated code to files
requires separate tools on your agent.

## Custom Skills

Create `.agents/skills/writing/SKILL.md`:

```markdown
---
name: writing
description: Write and edit clear, concise prose
---
# Writing

Prefer direct sentences and concrete words.
Keep each paragraph focused on one idea.
Read references/style.md before editing.
```

Then add `.agents/skills/writing/references/style.md` with your team's style
guide. The agent can load the instructions with `skill` and read the guide
with `skill_resource`.

For the format supported by this library:

- Match `name` to the skill's folder name. Use lowercase letters, digits and
  single separating hyphens, up to 64 characters.
- Use YAML strings for `name` and `description`: quoted values, comments and
  multiline blocks are supported. Names support Unicode letters and numbers.
- Give the skill a description of 1–1024 characters that explains when to use it.
- Place the Markdown instructions after the closing `---`.

If you add skills while your application is running, create a new storage and
toolkit to make them available.

## Error Handling

Skills with unusable YAML or missing, empty or non-string names/descriptions are
omitted. Usable skills with nonconforming metadata remain loadable with warnings,
including names that differ from their directories. Within a storage, the first
usable candidate in alphabetical identifier order wins duplicate declared names.
If no usable skills are available, the toolkit adds no tools or guidelines.

Inspect `$toolkit->diagnostics()` for an array of `skill` and `message` entries.
Diagnostics are not printed or sent to the model automatically. Optional fields
(`license`, `compatibility`, `metadata`, `allowed-tools`) and extensions are
preserved without granting permissions. See the [validation policy](docs/validation.md)
for field checks, YAML behavior and the distinction between warnings and exclusion.

Custom adapters implement `SkillStorageInterface::skills(): array` to enumerate
storage identifiers and `read(string $skill, string $path): string` to read files.

Expected read failures, such as an unknown skill or a missing file, are returned
as readable messages so the agent can respond to them. Unexpected failures
propagate as exceptions.

## Runnable Example

The [included example](examples/basic.php) loads a writing skill and its guide
through a Neuron agent. It uses a fake AI provider, so no API key is needed.
Run it from this library's checkout:

```sh
composer install
php examples/basic.php
```

## License

[MIT](LICENSE).
