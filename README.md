# Neuron AI Skills

[![Tests](https://github.com/neuron-core/agent-skills/actions/workflows/tests.yml/badge.svg)](https://github.com/neuron-core/agent-skills/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)](composer.json)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

> [!IMPORTANT]
> Get early access to new features, exclusive tutorials, and expert tips for building AI agents in PHP. Join a community of PHP developers pioneering the future of AI development.
> [Subscribe to the newsletter](https://neuron-ai.dev)

> Before moving on, support the Neuron AI community giving a GitHub star ⭐️. Thank you!

Add Agent Skills to your [Neuron AI](https://github.com/neuron-core/neuron-ai)
agents with `SkillToolkit`. Combine your own skills with community packages and
make them available to the agent through a single toolkit.

The library handles skill discovery and provides tools for loading instructions
and supporting resources when needed. It follows the open
[Agent Skills specification](https://agentskills.io/specification) and supports
local directories, SQL databases and custom storage.

![Neuron Agent Skills Package](docs/cover.png)

## Installation

Requires PHP 8.1+.

| Agent Skills | Neuron AI |
| --- | --- |
| 1.x (current) | 4.x |
| 0.x | 3.x |

```sh
composer require neuron-core/agent-skills
```

## Quick Start

Find community skills on [skills.sh](https://skills.sh) and install one from
your application's root:

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
use NeuronAI\AgentSkills\Storage\FileSystemSkillStorage;
use NeuronAI\AgentSkills\Tools\SkillToolkit;

$toolkit = SkillToolkit::make()
    ->fromStorage(new FileSystemSkillStorage(__DIR__.'/.agents/skills'));

$agent = Agent::make()
    ->setThreadId('quick-start')
    ->setAiProvider(new OpenAI(key: 'your-api-key', model: 'gpt-5.4-nano'))
    ->addTool($toolkit);

$response = $agent->chat(new UserMessage(
    'Use caveman skill to explain how the universe works.',
));

echo $response->getMessage()->getContent();

// Actual response (excerpt):
// Cosmic history: big bang expansion. Early hot plasma cooled; atoms formed.
// Gravity pulled gas into stars, stars forged heavier elements. Supernovae
// spread elements; mergers build galaxies.
```

## How Skills Work

The agent initially sees each skill's name and description, plus its location
when the storage provides one. When a skill is relevant to the task, it uses `skill` to load its instructions. If those
instructions reference supporting files, it can read them with `skill_resource`.
This keeps the initial context small while making the full skill available when
needed.

The toolkit registers two tools:

| Tool | Purpose |
| --- | --- |
| `skill` | Load the complete `SKILL.md` for a named skill. |
| `skill_resource` | Read a supporting text file relative to that skill. |

Skills can also include scripts. To execute them, register an execution tool,
such as Neuron's `BashTool`, alongside the toolkit. The library supplies the
instructions and resource locations; your application controls execution.

## Multiple Skill Directories

Configure the storages before registering the toolkit on your agent. Pass them
in precedence order. For example, combine bundled skills
with skills installed by the CLI:

```php
$toolkit = SkillToolkit::make()
    ->fromStorage(
        new FileSystemSkillStorage(__DIR__.'/skills'),
        new FileSystemSkillStorage(__DIR__.'/.agents/skills'),
    );
```

The first usable skill with a given declared name wins. Instructions and
resources are read from that selected source. Each storage is discovered on
first access: the catalog and every `SKILL.md` are loaded once and then reused,
while supporting resources are read on demand. Restart the agent or recreate the
toolkit after adding skills or editing a `SKILL.md`.

## Accessing Skills Directly

Share one `SkillRepository` between the toolkit and other application features,
for example slash commands and explicit skill invocation. `catalog()` returns a
list of `Skill` objects; `get($name)` returns the selected skill or throws a
`RuntimeException` when the name is unavailable.

```php
use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\AgentSkills\Storage\FileSystemSkillStorage;
use NeuronAI\AgentSkills\Tools\SkillToolkit;

$skills = new SkillRepository(
    new FileSystemSkillStorage(__DIR__.'/.agents/skills'),
);
$agent->addTool(new SkillToolkit($skills));

foreach ($skills->catalog() as $skill) {
    echo $skill->name().': '.$skill->description();
}

$skill = $skills->get('caveman');
$frontmatter = $skill->readFrontmatter();   // Parsed YAML metadata as stdClass.
$instructions = $skill->readInstructions(); // Body without YAML frontmatter.
$document = $skill->readDocument();         // Complete original SKILL.md.
$location = $skill->location();             // Host-accessible location or null.
$resource = $skill->readResource('references/guide.md');
```

`readResource()` accepts the path as written in the skill instructions. It
resolves `.`, `..` and backslashes, rejects paths that are absolute or leave the
skill, and rejects content that is not UTF-8 text, whichever storage holds the
skill.

Optional and extension metadata is preserved when a skill is loaded. Fields
such as `disable-model-invocation` and `user-invocable` are not enforced by this
library. Applications that depend on invocation restrictions must implement
them in their host agent.

## Database Storage

`DatabaseSkillStorage` loads skills from any SQL database reachable through
PDO, such as MySQL, PostgreSQL or SQLite. It requires the `pdo` extension and
the driver for your database. Pass your own connection and, optionally, the
table name (`agent_skills` by default) and a scope (`default` by default):

```php
use NeuronAI\AgentSkills\Storage\DatabaseSkillStorage;
use NeuronAI\AgentSkills\Tools\SkillToolkit;

$pdo = new PDO('mysql:host=127.0.0.1;dbname=app;charset=utf8mb4', 'user', 'password');

$toolkit = SkillToolkit::make()
    ->fromStorage(new DatabaseSkillStorage($pdo, 'agent_skills'));
```

### Creating the table

The library does not create the table. Run the script for your database once,
or copy it into a migration.

MySQL / MariaDB:

```sql
CREATE TABLE IF NOT EXISTS agent_skills (
    scope VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'default',
    skill_name VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    path VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    content LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    PRIMARY KEY (scope, skill_name, path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

PostgreSQL:

```sql
CREATE TABLE IF NOT EXISTS agent_skills (
    scope VARCHAR(64) NOT NULL DEFAULT 'default',
    skill_name VARCHAR(191) NOT NULL,
    path VARCHAR(512) NOT NULL,
    content TEXT NOT NULL,
    PRIMARY KEY (scope, skill_name, path)
);
```

SQLite:

```sql
CREATE TABLE IF NOT EXISTS agent_skills (
    scope TEXT NOT NULL DEFAULT 'default',
    skill_name TEXT NOT NULL,
    path TEXT NOT NULL,
    content TEXT NOT NULL,
    PRIMARY KEY (scope, skill_name, path)
);
```

The `utf8mb4_bin` collation keeps MySQL lookups case-sensitive, matching
PostgreSQL, SQLite and the filesystem storage. If you use another table name,
change it in the script and pass it as the second constructor argument. On
MySQL, keep `scope` at 64 characters or fewer: the primary key is already close
to InnoDB's 3072-byte index limit.

### Storing skills

The table holds one row per file:

| Column | Content |
| --- | --- |
| `scope` | The set of skills the row belongs to. Defaults to `default`. |
| `skill_name` | The name of the skill, as declared in its `SKILL.md`, such as `caveman`. |
| `path` | `SKILL.md` for the skill document. For a resource, the path the instructions refer to it by, with forward slashes and no leading slash, `.` or `..`, such as `references/guide.md`. |
| `content` | The UTF-8 text of the file. |

Every skill needs a `SKILL.md` row; other rows are its supporting resources:

```sql
INSERT INTO agent_skills (skill_name, path, content) VALUES
('writing', 'SKILL.md', '---
name: writing
description: Write clear, concise prose.
---

Prefer short sentences. See references/guide.md for the style guide.
'),
('writing', 'references/guide.md', 'Use the active voice.');
```

Rows inserted without a `scope` belong to the `default` scope.

### Scoping skills

A storage only sees the rows of its scope, so one table can serve a different
list of skills per agent, tenant or user. Pass the scope as the third
constructor argument:

```php
$support = new DatabaseSkillStorage($pdo, 'agent_skills', 'support');
$tenant = new DatabaseSkillStorage($pdo, 'agent_skills', "tenant:{$tenantId}");
```

To share skills between scopes without duplicating rows, combine a scoped
storage with a common one. Skills in the first storage take precedence:

```php
$toolkit = SkillToolkit::make()
    ->fromStorage(
        new DatabaseSkillStorage($pdo, 'agent_skills', 'support'),
        new DatabaseSkillStorage($pdo, 'agent_skills', 'default'),
    );
```

Database skills have no host-accessible location, so agents read their
supporting files through `skill_resource` and bundled scripts cannot be
executed in place.

## Custom Storage

Implement [`SkillStorageInterface`](src/Storage/SkillStorageInterface.php) to
load skills from another backend. It defines two methods:

- `list()` returns the `SKILL.md` document of every available skill, keyed by
  storage identifier. Leave out skills whose document cannot be read.
- `resource($skill, $reference)` returns a supporting text resource of a skill.

```php
use NeuronAI\AgentSkills\Storage\SkillStorageInterface;

class ApiSkillStorage implements SkillStorageInterface
{
    public function list(): array
    {
        // ['writing' => "---\nname: writing\ndescription: ...\n---\nInstructions"]
        return $this->client->skillDocuments();
    }

    public function resource(string $skill, string $reference): string
    {
        return $this->client->skillResource($skill, $reference)
            ?? throw new RuntimeException("Resource \"{$reference}\" was not found in skill \"{$skill}\".");
    }
}
```

The storage identifier is the key your backend knows a skill by, normally its
declared name. The library passes it back as `$skill`, even when it differs from
the name declared in the document.

`$reference` arrives already normalized: forward-slash separated segments with
no leading slash, `.` or `..`, such as `references/guide.md`. Treat it as a
lookup key. Throw `RuntimeException` for expected failures, such as a missing
resource. The library checks that documents and resources are UTF-8 text.

When host tools can reach the skill files, for example to execute bundled
scripts, implement
[`LocatableSkillStorageInterface`](src/Storage/LocatableSkillStorageInterface.php)
instead. It adds `location($skill)`, which returns the base location of a skill.
The location need not be a local path, but host tools must be able to access it.

### Upgrading a storage written for 1.0

| 1.0 | Now |
| --- | --- |
| `list()` returns identifiers | `list()` returns `identifier => SKILL.md contents` |
| `read($skill, 'SKILL.md')` | The document comes from `list()` |
| `read($skill, $path)` | `resource($skill, $reference)` |
| `location($skill): ?string` on every storage | `location($skill): string` on `LocatableSkillStorageInterface` only |

Path validation and the UTF-8 check moved into the library, so a storage no
longer needs its own.

## Error Handling

Skills with an invalid `SKILL.md` are skipped. Use `$skills->diagnostics()` to
inspect loading problems and warnings. Skills a storage leaves out, such as a
directory without a readable `SKILL.md`, are not reported.

The tools report read failures to the agent. When accessing skills directly,
catch `RuntimeException` for unavailable skills or resources.

## Runnable Examples

See the [interactive demo guide](examples/README.md) for setup instructions and
sample conversations using skills and supporting resources.

## Contributing

Report bugs and propose changes through [GitHub Issues](https://github.com/neuron-core/agent-skills/issues)
and pull requests. From the repository root, run the development checks with:

```sh
composer install
composer check
```

`composer check` runs PHPUnit and PHPStan without requiring an API key.
CI covers PHP 8.1–8.5, multiple Neuron AI versions and Symfony YAML compatibility.

## License

[MIT](LICENSE).
