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
use NeuronAI\Skills\Tools\Toolkits\Skills\SkillToolkit;
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

## Multiple Skill Directories

Pass storage instances in precedence order. For project-over-user precedence,
put the project's directory first (set `$userSkillsDirectory` to your user's
installed skills directory):

```php
$toolkit = new SkillToolkit(
    new FileSystemSkillStorage(__DIR__.'/.agents/skills'),
    new FileSystemSkillStorage($userSkillsDirectory),
);
$agent->addTool($toolkit);
$diagnostics = $toolkit->diagnostics(); // Entries contain skill and message.
```

The first usable candidate for a declared name wins: storage order first, then
alphabetical identifiers within each storage. Invalid or unreadable documents
allow a later fallback; usable documents with warnings retain precedence.
Shadowing diagnostics identify both storage numbers (starting at 1) and candidate
identifiers. Nothing is printed automatically.

The combined catalog is a session snapshot. Activation, location and resource
reads all use the winning source, even when folder names match across roots or
differ from declared skill names. A missing resource returns an error; it is never
retrieved from a shadowed skill. Documents and resources are read on request;
only documents are read during initial discovery.

## Available Tools

The toolkit registers two tools that the agent can call:

| Tool | Purpose | Inputs |
| --- | --- | --- |
| `skill` | Load the complete original `SKILL.md` and base location. | `name` |
| `skill_resource` | Read a supporting file from that skill. | `name`, `path` |

Resource paths are relative to the skill, such as `references/style.md`.
Activation preserves the original frontmatter and Markdown, including optional
and extension metadata. The initial catalog contains only names and descriptions.
Read only the resources needed for the task; activation does not read references,
scripts or binary assets automatically.

Both tools read text. The filesystem adapter reports the canonical skill directory,
so authorized host tools can resolve `scripts/check.php` against that location and
execute the actual file with access to neighboring assets. Executing script text
alone may lose that context. Binary assets are available through appropriate host
tools; `skill_resource` rejects binary content. The library never executes scripts.
Metadata such as `allowed-tools` does not enable tools or grant permissions:
execution, file access and authorization remain the host agent's responsibility.

### Running Skill Scripts

Skills can include executable files in `scripts/`. To let your Neuron AI agent
run them, register Neuron's `BashTool` alongside `SkillToolkit`:

```php
use NeuronAI\Tools\Toolkits\FileSystem\BashTool;

// Add to the same agent configured with SkillToolkit above.
$agent->addTool(new BashTool());
```

The agent can then call the `bash` tool with a `command`, such as
`php scripts/check.php`, and set `working_directory` to the skill location
returned by the `skill` tool. This lets scripts resolve relative paths to their
bundled assets. The execution environment must have the required interpreter
and dependencies installed.

`SkillToolkit` supplies the document and resource location; `BashTool` executes
the command. An application-specific execution tool can serve the same role.
Registering an execution tool and managing its permissions are responsibilities
of your application; skill metadata does not register it automatically.

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
- YAML syntax follows Symfony YAML 6.4 as provided. Use ordinary `key: value`
  mappings; explicit mapping keys (`? key`) are not supported. There is no
  custom YAML compatibility layer.

If you add skills while your application is running, create a new storage and
toolkit to make them available.

## Custom Storage

Implement `SkillStorageInterface` with `list()`, `read($skill, $path)` and
`location($skill)`. Use storage identifiers for reads and locations, even when
they differ from declared skill names. Return `null` if no host-accessible
location exists; remote locations require matching host tools and provisioning.

## Error Handling

Unusable skill documents are skipped; recoverable validation issues produce
warnings. Inspect `$toolkit->diagnostics()` for `skill` and `message` entries.
Nothing is printed or sent to the model automatically.

Expected read failures become messages the agent can read. Unexpected exceptions
propagate. See the [validation policy](docs/validation.md) for the detailed rules.

## Runnable Example

The [included example](examples/basic.php) combines project and user directories,
activates writing and analysis skills, reads their guides and uses an explicitly registered host tool to run its script with a
neighboring asset through a Neuron agent. It uses a fake AI provider, so no API key is needed.
Run it from this library's checkout:

```sh
composer install
php examples/basic.php
```

## License

[MIT](LICENSE).
