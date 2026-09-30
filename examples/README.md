# Try the skills demo

One interactive agent loads two skill sources: `php-check`, included in
`skills/`, and `caveman`, installed into `.agents/skills/` with the Skills CLI.

## Setup

You need PHP 8.1+, Composer, Node.js/npm and an OpenAI API key. For script
execution, the PHP CLI needs `curl`, `json` and `proc_open`. The demo makes real
API requests.

From the repository root:

```sh
composer install
cp examples/.env.example examples/.env
```

Set `OPENAI_API_KEY` in `examples/.env`. The default model is `gpt-5.4-nano`;
change `OPENAI_MODEL` if needed.

Install `caveman` **from `examples/`**, then start the chat:

```sh
cd examples
npx skills add juliusbrussee/caveman --skill caveman --agent universal --yes
php agent-loop.php
```

The startup list should include `php-check` and `caveman`.

## Try this conversation

Enter these messages at `You>`, one after the other. This shortened example
shows the tool calls printed by the script; wording, paths and results can vary.

```text
You> Use caveman skill to explain the difference between authentication and authorization.
Agent>
[tool: skill {"name":"caveman"}]
Authentication: who you are. Authorization: what you can do.

You> Now use php-check: read references/checks.md with skill_resource, then run scripts/check.php with bash and explain the results.
Agent>
[tool: skill {"name":"php-check"}]
[tool: skill_resource {"name":"php-check","path":"references/checks.md"}]
[tool: bash {"command":"php scripts/check.php","working_directory":"/path/to/neuron-skills/examples/skills/php-check"}]
PHP 8.1+, curl, json and proc_open: all checks passed.

You> Does that also confirm that network access and OpenAI requests work?
Agent>
No. The script checks the local PHP runtime, not network access or API requests.

You> exit
```

`skill` loads the instructions, `skill_resource` reads the reference, and `bash`
executes the bundled script. Its output contains `PHP_VERSION`, `CURL_EXTENSION`,
`JSON_EXTENSION` and `PROC_OPEN`; the agent explains any failed checks.

Type `exit` or `quit` to stop. Restart the script after installing new skills.
For integration into your application, see the [Quick Start](../README.md#quick-start).
