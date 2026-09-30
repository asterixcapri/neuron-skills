# Neuron 4 upgrade research

Researched on 2026-09-30 against Neuron AI 4.0.1. The migration described below
has been implemented and validated locally as recorded at the end of this note.

## Source of truth

The official documentation index labels the current documentation as v4, but
the public Upgrade page still describes v2 to v3. For the v3 to v4 changes,
use the numbered upgrade guides shipped with the 4.0.1 release and its tagged
source. The installed copies are in `vendor/neuron-core/neuron-ai/upgrade/`.
Sources: [documentation index](https://docs.neuron-ai.dev/llms.txt),
[public Upgrade page](https://docs.neuron-ai.dev/overview/upgrade),
[4.0.1 upgrade guides](https://github.com/neuron-core/neuron-ai/tree/4.0.1/upgrade).

## Tools and run tracking

`Tool` is abstract and has no constructor in v4. `SkillTool` and
`SkillResourceTool` should keep their repository injection, declare their existing
name and description as protected properties, and remove `parent::__construct()`.
Their existing `properties()` and `__invoke()` structure already fits v4. Remove
the `HasRunKey` import and implementation: `getRunKey()` is now required by
`ToolInterface`. Keep `TrackByInputs` so different skill/resource reads have
different run budgets. Host tools built with `new Tool()->setCallable()` in tests
must become concrete subclasses, including anonymous classes where appropriate.
Source: [guide 3](https://github.com/neuron-core/neuron-ai/blob/4.0.1/upgrade/3-tool-is-now-abstract.md).

The v4 `TrackByInputs` hashes declared properties in declaration order. Input
ordering and undeclared arguments no longer create a new budget. Validate that
distinct skill names and resource paths remain distinct; avoid asserting the old
hash representation. Source:
[TrackByInputs source](https://github.com/neuron-core/neuron-ai/blob/4.0.1/src/Tools/TrackByInputs.php).

## Conversation tool calls

An executable tool and its conversation record are now separate. Fixtures for
`FakeAIProvider` should create `ToolCall` records with named arguments (`name`,
`callId`, `inputs`, optional `description`) instead of cloning registered tools.
Pass those records to `ToolCallMessage`; read results through
`ToolResultMessage::getToolCalls()`. Executable tools still have to be registered
on the agent under the call's name. `getResult()` returns `string|ToolOutput` and
throws when no result exists; use `hasResult()` when a call may be pending and
`(string)` or `getText()` when handling an error output. Skill documents and
resources can continue returning strings. Source:
[guide 4](https://github.com/neuron-core/neuron-ai/blob/4.0.1/upgrade/4-toolcall-value-object.md).

## Input validation changes

V4 validates and casts inputs before calling `__invoke()`. A skill name outside
the schema enum is rejected by Neuron as `ToolOutput::error()` before the
repository runs. Tests for an unknown skill should distinguish the repository's
direct behavior from the framework's tool-execution behavior. Required missing
arguments and invalid types also produce error results. Existing valid string
arguments and repository path checks should retain their behavior. Source:
[guide 5](https://github.com/neuron-core/neuron-ai/blob/4.0.1/upgrade/5-tool-properties-validate-and-cast.md).

## Agents and examples

Bind a thread ID before running every test agent and documented example, using
`Agent::make(workflowId: $id)` or `setThreadId($id)`. Reuse the same ID for the
conversation; generate one per interactive CLI session. Agents no longer generate
an ID automatically. Source:
[guide 13](https://github.com/neuron-core/neuron-ai/blob/4.0.1/upgrade/13-bind-a-workflow-id.md).

`chat()` now executes immediately and returns `AgentState`. Existing
`chat(...)->getMessage()` calls remain structurally valid, but `getMessage()` is
nullable in its type contract. `stream()` returns a generator: iterate it directly
instead of `stream(...)->events()`. After complete consumption, `getReturn()`
contains the final state. Source:
[guide 23](https://github.com/neuron-core/neuron-ai/blob/4.0.1/upgrade/23-agent-returns-agent-state.md).

`ToolCallChunk::$tool` is now a `ToolCall`, so the CLI's `getName()` and
`getInputs()` logging can stay. It cannot be tested with `instanceof SkillTool` or
executed. A constructed `ToolCallChunk` now needs the owning message ID as its
first argument. Source:
[guide 39](https://github.com/neuron-core/neuron-ai/blob/4.0.1/upgrade/39-tool-call-chunks.md).

## Dependencies and validation

PHP remains `^8.1`; v4 requires `ext-curl`. Guzzle, PSR HTTP packages and Inspector
are no longer installed transitively by Neuron. This library should declare any
such dependencies it uses directly and ensure curl is available in CI. Source:
[guide 1](https://github.com/neuron-core/neuron-ai/blob/4.0.1/upgrade/1-composer-dependencies-and-php-extensions.md).

Check Composer validation and platform requirements, run the complete PHPUnit
suite and PHPStan, lint the examples, and test both the minimum supported v4
release and the latest allowed release in CI. Focus integration checks on real
agent execution, resource reads, repeated and distinct call budgets, framework
input validation, and a fake-provider streaming turn. The external API example
requires credentials and should be verified separately from offline tests.

## Recommendation

Prefer a migration to `^4.0` for the next toolkit release. This is a project
recommendation based on the changes above: shared storage and repository logic
does not need redesign, while dual-major support would require separate tool
construction, removed-interface handling, conversation fixtures and streaming
examples, plus a v3/v4 CI matrix. Consumers that still use Neuron 3 must retain a compatible toolkit revision. Advertise dual support only if both majors actually
pass their own integration suites; a Composer union alone cannot establish it.

## Local validation

On PHP 8.5.8, both Neuron 4.0.0 and 4.0.1 pass `composer check`: 105 tests,
315 assertions, and no PHPStan errors. The suite includes a fake-provider
streaming turn that loads a skill and its resource, checks the emitted calls and
results, and reads the final message from the generator's return value.

Composer validation, platform requirements, example syntax, and PHPStan on
`examples/agent-loop.php` also pass. CI now covers PHP 8.1–8.5 against 4.0.0
and the latest allowed 4.x version; that matrix has not been run locally.
The live OpenAI demo has not been exercised with API credentials.
