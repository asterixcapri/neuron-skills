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

Enter these messages at `You>`, one after the other. This example shows an
actual conversation; wording, paths and results can vary on your machine.

```text
$ php agent-loop.php
Available skills: php-check, caveman
Try php-check for runtime checks or caveman for terse answers.
See examples/README.md for setup and both scenarios.
Type a message, or 'exit' to quit.

You> use caveman skill to explain the universe
Agent>
[tool: skill {"name":"caveman"}]
Universe = space + time + matter + energy.
Big Bang start: hot dense state, then expansion, cooling.
Stars form: gravity clumps gas, nuclear fusion makes elements.
Supernova spread elements, build planets, life possible.
Cosmos keeps expanding; dark energy drives late-time acceleration.
Gravity shapes: galaxies, clusters, filaments, voids.
Physics rules stay: quantum for tiny, relativity for big gravity.

You> very well done!
Agent> Thanks.

You> now use php-check skill and explain the results
Agent>
[tool: skill {"name":"php-check"}]

[tool: skill_resource {"name":"php-check","path":"references/checks.md"}]

[tool: bash {"command":"php scripts/check.php","working_directory":"/home/asterix/asterixcapri/neuron-skills/examples/skills/php-check"}]
Caveman results:

- PHP_VERSION=8.5.8. Pass. Need PHP 8.1+.
- CURL_EXTENSION=loaded. Pass. cURL HTTP ok.
- JSON_EXTENSION=loaded. Pass. JSON ops ok.
- PROC_OPEN=available. Pass. Can spawn external cmd.

Next: runtime ok. No app cfg/API test here. Run your app tests next.

You> nice
Agent> Nice.

You> quit
```

`skill` loads the instructions, `skill_resource` reads the reference, and `bash`
executes the bundled script. Its output contains `PHP_VERSION`, `CURL_EXTENSION`,
`JSON_EXTENSION` and `PROC_OPEN`; the agent explains any failed checks.

Type `exit` or `quit` to stop. Restart the script after installing new skills.
For integration into your application, see the [Quick Start](../README.md#quick-start).
