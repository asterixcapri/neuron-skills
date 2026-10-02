<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests;

use Closure;
use LogicException;
use RuntimeException;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\Tool;
use NeuronAI\AgentSkills\Tools\SkillResourceTool;
use NeuronAI\AgentSkills\Tools\SkillToolkit;
use NeuronAI\AgentSkills\Tools\SkillTool;
use NeuronAI\AgentSkills\Storage\FileSystemSkillStorage;
use NeuronAI\AgentSkills\Storage\LocatableSkillStorageInterface;
use NeuronAI\AgentSkills\Storage\SkillStorageInterface;
use PHPUnit\Framework\TestCase;

use function array_unique;
use function bin2hex;
use function file_exists;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sprintf;
use function sys_get_temp_dir;
use function unlink;

class SkillToolkitTest extends TestCase
{
    protected string $skillsRoot;

    protected function setUp(): void
    {
        $this->skillsRoot = sys_get_temp_dir().'/neuron-toolkit-skills-'.bin2hex(random_bytes(8));
        mkdir($this->skillsRoot.'/writing', 0o777, true);
        file_put_contents($this->skillsRoot.'/writing/SKILL.md', <<<'SKILL'
            ---
            name: writing
            description: Write clear prose
            ---
            # Writing instructions

            Prefer direct sentences.
            SKILL);
        mkdir($this->skillsRoot.'/writing/references');
        file_put_contents($this->skillsRoot.'/writing/references/style.md', "# Style guide\n\nUse concrete words.\n");
        mkdir($this->skillsRoot.'/writing/scripts');
        file_put_contents($this->skillsRoot.'/writing/scripts/check.php', "<?php\n\necho 'checked';\n");
    }

    protected function tearDown(): void
    {
        if (file_exists($this->skillsRoot.'/writing/scripts/check.php')) {
            unlink($this->skillsRoot.'/writing/scripts/check.php');
        }
        if (is_dir($this->skillsRoot.'/writing/scripts')) {
            rmdir($this->skillsRoot.'/writing/scripts');
        }
        if (file_exists($this->skillsRoot.'/writing/references/style.md')) {
            unlink($this->skillsRoot.'/writing/references/style.md');
        }
        if (is_dir($this->skillsRoot.'/writing/references')) {
            rmdir($this->skillsRoot.'/writing/references');
        }
        if (file_exists($this->skillsRoot.'/writing/SKILL.md')) {
            unlink($this->skillsRoot.'/writing/SKILL.md');
        }
        if (is_dir($this->skillsRoot.'/writing')) {
            rmdir($this->skillsRoot.'/writing');
        }
        rmdir($this->skillsRoot);
    }

    public function test_agent_discloses_catalog_and_loads_instructions_through_the_tool_loop(): void
    {
        $repository = new SkillRepository(new FileSystemSkillStorage($this->skillsRoot));
        $toolkit = new SkillToolkit($repository);
        $tools = $toolkit->tools();
        $this->assertCount(2, $tools);
        $skillTool = $tools[0];
        $this->assertInstanceOf(SkillTool::class, $skillTool);
        $this->assertSame(['name'], $skillTool->getRequiredProperties());
        $nameProperty = $skillTool->getProperties()[0];
        $this->assertInstanceOf(ToolProperty::class, $nameProperty);
        $this->assertSame(['writing'], $nameProperty->getEnum());
        $this->assertCount(1, $skillTool->getProperties());

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (new ToolCall($skillTool->getName(), 'call_1'))->setInputs(['name' => 'writing']),
            ]),
            new AssistantMessage('I will follow the writing skill.'),
        );
        $agent = Agent::make()->setThreadId('skills-test')
            ->setAiProvider($provider)
            ->setInstructions('Be helpful.')
            ->addTool($toolkit);

        $response = $agent->chat(new UserMessage('Help me write.'))->getMessage();

        $this->assertSame('I will follow the writing skill.', $response->getContent());
        $provider->assertToolsConfigured(['skill', 'skill_resource']);
        $systemPrompt = $provider->getRecorded()[0]->systemPrompt?->getContent() ?? '';
        $this->assertStringContainsString('writing: Write clear prose', $systemPrompt);
        $this->assertStringContainsString('skill', $systemPrompt);
        $this->assertStringContainsString('read it with `skill_resource` before continuing', $systemPrompt);
        $this->assertStringContainsString('use an available execution tool and set its working directory to the skill location when accessible', $systemPrompt);
        $this->assertStringContainsString('location: '.$this->skillsRoot.'/writing', $systemPrompt);
        $this->assertStringContainsString('Skill instructions do not grant permission to use that tool.', $systemPrompt);
        $this->assertStringNotContainsString('Prefer direct sentences.', $systemPrompt);
        $this->assertStringNotContainsString('Use concrete words.', $systemPrompt);
        $provider->assertSent(fn (RequestRecord $record): bool => $this->hasToolResult(
            $record,
            file_get_contents($this->skillsRoot.'/writing/SKILL.md'),
        ));
    }

    public function test_stream_loads_skills_and_resources_and_returns_the_final_message(): void
    {
        $toolkit = new SkillToolkit(new SkillRepository(new FileSystemSkillStorage($this->skillsRoot)));
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [new ToolCall('skill', 'activate', ['name' => 'writing'])]),
            new ToolCallMessage(null, [new ToolCall('skill_resource', 'read', [
                'name' => 'writing', 'path' => 'references/style.md',
            ])]),
            new AssistantMessage('I read the style guide.'),
        );
        $agent = Agent::make()->setThreadId('skills-stream-test')
            ->setAiProvider($provider)->addTool($toolkit);
        $stream = $agent->stream(new UserMessage('Load the writing skill and its guide.'));
        $calls = [];
        $results = [];
        $text = '';
        foreach ($stream as $chunk) {
            if ($chunk instanceof ToolCallChunk) {
                $calls[] = $chunk->tool->getName();
            } elseif ($chunk instanceof ToolResultChunk) {
                $results[] = $chunk->tool->getResult();
            } elseif ($chunk instanceof TextChunk) {
                $text .= $chunk->content;
            }
        }

        $this->assertSame(['skill', 'skill_resource'], $calls);
        $this->assertSame([
            file_get_contents($this->skillsRoot.'/writing/SKILL.md'),
            "# Style guide\n\nUse concrete words.\n",
        ], $results);
        $this->assertSame('I read the style guide.', $text);
        $this->assertSame($text, $stream->getReturn()->getMessage()->getContent());
        $provider->assertCallCount(3);
    }

    public function test_diagnostics_stay_out_of_agent_context_while_tolerated_skill_loads(): void
    {
        file_put_contents($this->skillsRoot.'/writing/SKILL.md', "---\nname: écriture\ndescription: >-\n  Write\n  clearly\nlicense: MIT\n---\nUnicode skill body.");
        $repository = new SkillRepository(new FileSystemSkillStorage($this->skillsRoot));
        $toolkit = new SkillToolkit($repository);
        $this->assertSame([['skill' => 'writing', 'message' => 'Declared name does not match the storage identifier.']], $repository->diagnostics());
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (new ToolCall('skill', 'unicode'))->setInputs(['name' => 'écriture']),
            ]),
            new AssistantMessage('Loaded.'),
        );
        Agent::make()->setThreadId('skills-test')->setAiProvider($provider)->setInstructions('Be helpful.')->addTool($toolkit)
            ->chat(new UserMessage('Write.'))->getMessage();
        $prompt = $provider->getRecorded()[0]->systemPrompt?->getContent() ?? '';
        $this->assertStringContainsString('écriture: Write clearly', $prompt);
        $this->assertStringNotContainsString('storage identifier', $prompt);
        $this->assertStringNotContainsString('MIT', $prompt);
        $provider->assertSent(fn (RequestRecord $record): bool => $this->hasToolResult($record, file_get_contents($this->skillsRoot.'/writing/SKILL.md')));
    }

    public function test_multiline_description_stays_on_one_catalog_line(): void
    {
        file_put_contents($this->skillsRoot.'/writing/SKILL.md', "---\nname: writing\ndescription: |-\n  First  line\n  Second line\n---\nBody");
        $toolkit = new SkillToolkit(new SkillRepository(new FileSystemSkillStorage($this->skillsRoot)));

        $guidelines = $toolkit->guidelines() ?? '';
        $this->assertStringContainsString('writing: First line Second line', $guidelines);
        $this->assertStringNotContainsString("\nSecond line", $guidelines);
    }

    public function test_unusable_catalog_produces_diagnostics_but_no_tools_or_guidelines(): void
    {
        file_put_contents($this->skillsRoot.'/writing/SKILL.md', "---\nname: writing\ndescription: []\n---\nBody");
        $repository = new SkillRepository(new FileSystemSkillStorage($this->skillsRoot));
        $toolkit = new SkillToolkit($repository);
        $this->assertNotEmpty($repository->diagnostics());
        $this->assertSame([], $toolkit->tools());
        $this->assertNull($toolkit->guidelines());
    }

    public function test_agent_reads_a_resource_through_the_same_tool_loop(): void
    {
        $toolkit = new SkillToolkit(new SkillRepository(new FileSystemSkillStorage($this->skillsRoot)));
        $skillTool = $toolkit->tools()[1];
        $this->assertInstanceOf(SkillResourceTool::class, $skillTool);
        $this->assertSame(['name', 'path'], $skillTool->getRequiredProperties());
        $this->assertCount(2, $skillTool->getProperties());
        $nameProperty = $skillTool->getProperties()[0];
        $this->assertInstanceOf(ToolProperty::class, $nameProperty);
        $this->assertSame(['writing'], $nameProperty->getEnum());
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (new ToolCall($skillTool->getName(), 'call_1'))->setInputs([
                    'name' => 'writing',
                    'path' => 'references/style.md',
                ]),
            ]),
            new AssistantMessage('I read the style guide.'),
        );
        $agent = Agent::make()->setThreadId('skills-test')
            ->setAiProvider($provider)
            ->setInstructions('Be helpful.')
            ->addTool($toolkit);

        $agent->chat(new UserMessage('Read the style guide.'))->getMessage();

        $provider->assertToolsConfigured(['skill', 'skill_resource']);
        $this->assertStringNotContainsString(
            'Use concrete words.',
            $provider->getRecorded()[0]->systemPrompt?->getContent() ?? '',
        );
        $provider->assertSent(fn (RequestRecord $record): bool => $this->hasToolResult(
            $record,
            "# Style guide\n\nUse concrete words.\n",
        ));
    }

    public function test_agent_tracks_distinct_skill_and_resource_reads_separately(): void
    {
        $storage = new class () implements SkillStorageInterface {
            public function list(): array
            {
                return [
                    'analysis' => "---\nname: analysis\ndescription: analysis skill\n---\nanalysis instructions.",
                    'writing' => "---\nname: writing\ndescription: writing skill\n---\nwriting instructions.",
                ];
            }

            public function resource(string $skill, string $reference): string
            {
                return "{$skill}:{$reference}";
            }
        };
        $toolkit = new SkillToolkit(new SkillRepository($storage));
        [$skillTool, $resourceTool] = $toolkit->tools();
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (new ToolCall($skillTool->getName(), 'call_1'))->setInputs(['name' => 'analysis']),
            ]),
            new ToolCallMessage(null, [
                (new ToolCall($skillTool->getName(), 'call_2'))->setInputs(['name' => 'writing']),
            ]),
            new ToolCallMessage(null, [
                (new ToolCall($resourceTool->getName(), 'call_3'))->setInputs([
                    'name' => 'writing',
                    'path' => 'references/style.md',
                ]),
            ]),
            new ToolCallMessage(null, [
                (new ToolCall($resourceTool->getName(), 'call_4'))->setInputs([
                    'name' => 'writing',
                    'path' => 'references/examples.md',
                ]),
            ]),
            new AssistantMessage('All distinct reads completed.'),
        );

        $agent = Agent::make()->setThreadId('skills-test');
        $agent
            ->setAiProvider($provider)
            ->setInstructions('Be helpful.')
            ->addTool($toolkit);
        $agent->toolMaxRuns(1);

        $response = $agent->chat(new UserMessage('Load both skills and resources.'))->getMessage();

        $this->assertSame('All distinct reads completed.', $response->getContent());
        $provider->assertCallCount(5);
    }

    public function test_unknown_skill_is_a_model_readable_result(): void
    {
        $tool = (new SkillToolkit(new SkillRepository(new FileSystemSkillStorage($this->skillsRoot))))->tools()[0];
        $tool->setInputs(['name' => 'unknown']);
        $tool->execute();

        $result = $tool->getResult();
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertSame('Parameter "name" must be one of "writing"; "unknown" given.', $result->getText());
    }

    public function test_unknown_skill_resource_is_a_model_readable_result(): void
    {
        $tool = (new SkillToolkit(new SkillRepository(new FileSystemSkillStorage($this->skillsRoot))))->tools()[1];
        $tool->setInputs(['name' => 'unknown', 'path' => 'guide.md']);
        $tool->execute();

        $result = $tool->getResult();
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertSame('Parameter "name" must be one of "writing"; "unknown" given.', $result->getText());
    }

    public function test_null_byte_resource_path_is_a_model_readable_result(): void
    {
        $tool = (new SkillToolkit(new SkillRepository(new FileSystemSkillStorage($this->skillsRoot))))->tools()[1];
        $tool->setInputs(['name' => 'writing', 'path' => "resource\0.md"]);
        $tool->execute();

        $this->assertSame(sprintf('Resource path "%s" is invalid.', "resource\0.md"), $tool->getResult());
    }

    public function test_empty_resource_path_is_invalid_and_cannot_load_instructions(): void
    {
        $tool = (new SkillToolkit(new SkillRepository(new FileSystemSkillStorage($this->skillsRoot))))->tools()[1];
        $tool->setInputs(['name' => 'writing', 'path' => '']);
        $tool->execute();

        $this->assertSame('Resource path "" is invalid.', $tool->getResult());
        $this->assertStringNotContainsString('Writing instructions', $tool->getResult());
    }

    public function test_agent_reads_a_script_as_unchanged_text_without_executing_it(): void
    {
        $toolkit = new SkillToolkit(new SkillRepository(new FileSystemSkillStorage($this->skillsRoot)));
        $resourceTool = $toolkit->tools()[1];
        $script = "<?php\n\necho 'checked';\n";
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (new ToolCall($resourceTool->getName(), 'call_1'))->setInputs([
                    'name' => 'writing',
                    'path' => 'scripts/check.php',
                ]),
            ]),
            new AssistantMessage('I read the script as text.'),
        );

        Agent::make()->setThreadId('skills-test')
            ->setAiProvider($provider)
            ->setInstructions('Be helpful.')
            ->addTool($toolkit)
            ->chat(new UserMessage('Read the script.'))
            ->getMessage();

        $provider->assertSent(fn (RequestRecord $record): bool => $this->hasToolResult(
            $record,
            $script,
        ));
    }

    public function test_agent_receives_expected_read_failures_without_an_error_handler(): void
    {
        $toolkit = new SkillToolkit(new SkillRepository(new FileSystemSkillStorage($this->skillsRoot)));
        $tool = $toolkit->tools()[1];
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (new ToolCall($tool->getName(), 'failed_read'))->setInputs(['name' => 'writing', 'path' => 'missing.md']),
            ]),
            new AssistantMessage('I could not read that file.'),
        );

        $response = Agent::make()->setThreadId('skills-test')
            ->setAiProvider($provider)
            ->setInstructions('Use the available skills.')
            ->addTool($toolkit)
            ->chat(new UserMessage('Read the writing skill and its guide.'))
            ->getMessage();

        $this->assertSame('I could not read that file.', $response->getContent());
        $provider->assertSent(fn (RequestRecord $record): bool => $this->hasToolResult(
            $record,
            'Resource "missing.md" was not found in skill "writing".',
        ));
    }

    public function test_activation_returns_the_document_discovered_with_the_catalog(): void
    {
        $document = file_get_contents($this->skillsRoot.'/writing/SKILL.md');
        $toolkit = new SkillToolkit(new SkillRepository(new FileSystemSkillStorage($this->skillsRoot)));
        $toolkit->guidelines();
        unlink($this->skillsRoot.'/writing/SKILL.md');

        $tool = $toolkit->tools()[0];
        $tool->setInputs(['name' => 'writing'])->execute();

        $this->assertSame($document, $tool->getResult());
    }

    public function test_tool_delegates_reads_to_the_configured_storage(): void
    {
        $storage = new class () implements SkillStorageInterface {
            public ?string $requestedSkill = null;
            public ?string $requestedReference = null;

            public function list(): array
            {
                return ['remote' => "---\nname: remote\ndescription: Remote skill\n---\nRemote instructions."];
            }

            public function resource(string $skill, string $reference): string
            {
                $this->requestedSkill = $skill;
                $this->requestedReference = $reference;

                return 'Remote resource.';
            }
        };
        $toolkit = new SkillToolkit(new SkillRepository($storage));
        $tool = $toolkit->tools()[1];
        $tool->setInputs(['name' => 'remote', 'path' => 'references/api.md']);
        $tool->execute();

        $this->assertSame('Remote resource.', $tool->getResult());
        $this->assertSame('remote', $storage->requestedSkill);
        $this->assertSame('references/api.md', $storage->requestedReference);
    }

    public function test_unexpected_resource_repository_failures_remain_exceptions(): void
    {
        $storage = new class () implements SkillStorageInterface {
            public function list(): array
            {
                return ['broken' => "---\nname: broken\ndescription: Broken skill\n---\nInstructions."];
            }

            public function resource(string $skill, string $reference): string
            {
                throw new LogicException('Resource storage failed unexpectedly.');
            }
        };
        $toolkit = new SkillToolkit(new SkillRepository($storage));
        $tool = $toolkit->tools()[1];
        $tool->setInputs(['name' => 'broken', 'path' => 'guide.md']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Resource storage failed unexpectedly.');

        $tool->execute();
    }

    public function test_toolkit_snapshots_storage_catalog_and_tracks_natural_inputs_separately(): void
    {
        $storage = new class () implements SkillStorageInterface {
            /** @var array<string, string> */
            public array $manifests = [
                'first' => "---\nname: first\ndescription: First skill\n---\nFirst.",
                'second' => "---\nname: second\ndescription: Second skill\n---\nSecond.",
            ];

            public function list(): array
            {
                return $this->manifests;
            }

            public function resource(string $skill, string $reference): string
            {
                return $skill;
            }
        };
        $toolkit = new SkillToolkit(new SkillRepository($storage));
        $toolkit->guidelines();
        $storage->manifests = [
            'third' => "---\nname: third\ndescription: Third skill\n---\nThird.",
        ];

        $this->assertStringContainsString('first: First skill', $toolkit->guidelines() ?? '');
        $this->assertStringContainsString('second: Second skill', $toolkit->guidelines() ?? '');
        $this->assertStringNotContainsString('third', $toolkit->guidelines() ?? '');
        [$skillTool, $resourceTool] = $toolkit->tools();
        $this->assertInstanceOf(SkillTool::class, $skillTool);
        $this->assertInstanceOf(SkillResourceTool::class, $resourceTool);
        $skillKeys = [];
        foreach (['first', 'second'] as $name) {
            $skillTool->setInputs(['name' => $name]);
            $skillKeys[] = $skillTool->getRunKey();
        }
        $resourceTool->setInputs(['name' => 'first', 'path' => 'references/details.md']);
        $firstResourceKey = $resourceTool->getRunKey();
        $resourceTool->setInputs(['name' => 'first', 'path' => 'references/examples.md']);
        $secondResourceKey = $resourceTool->getRunKey();

        $this->assertCount(2, array_unique($skillKeys));
        $this->assertNotSame($firstResourceKey, $secondResourceKey);
        $this->assertNotContains($firstResourceKey, $skillKeys);
    }

    public function test_empty_catalog_contributes_neither_guidelines_nor_tools(): void
    {
        unlink($this->skillsRoot.'/writing/scripts/check.php');
        rmdir($this->skillsRoot.'/writing/scripts');
        unlink($this->skillsRoot.'/writing/references/style.md');
        rmdir($this->skillsRoot.'/writing/references');
        unlink($this->skillsRoot.'/writing/SKILL.md');
        rmdir($this->skillsRoot.'/writing');
        $toolkit = new SkillToolkit(new SkillRepository(new FileSystemSkillStorage($this->skillsRoot)));

        $this->assertNull($toolkit->guidelines());
        $this->assertSame([], $toolkit->tools());

        $provider = new FakeAIProvider(new AssistantMessage('Hello.'));
        Agent::make()->setThreadId('skills-test')
            ->setAiProvider($provider)
            ->setInstructions('Be helpful.')
            ->addTool($toolkit)
            ->chat(new UserMessage('Hello.'))
            ->getMessage();

        $this->assertSame([], $provider->getRecorded()[0]->tools);
        $this->assertStringNotContainsString('SkillToolkit', $provider->getRecorded()[0]->systemPrompt?->getContent() ?? '');
    }

    public function test_activation_retains_original_yaml_syntax(): void
    {
        $document = "---\nname: writing\ndescription: &summary Works # comment\nmetadata: {summary: *summary}\n---\nBody\n";
        file_put_contents($this->skillsRoot.'/writing/SKILL.md', $document);
        $repository = new SkillRepository(new FileSystemSkillStorage($this->skillsRoot));
        $toolkit = new SkillToolkit($repository);
        $this->assertSame([], $repository->diagnostics());
        $activation = $toolkit->tools()[0];
        $activation->setInputs(['name' => 'writing'])->execute();
        $this->assertSame($document, $activation->getResult());
        $this->assertStringContainsString('location: '.realpath($this->skillsRoot.'/writing'), $toolkit->guidelines() ?? '');
    }

    /** @dataProvider locationFailures */
    public function test_catalog_propagates_location_failures(bool $expected): void
    {
        $storage = new class ($expected) implements LocatableSkillStorageInterface {
            public function __construct(private bool $expected)
            {
            }

            public function list(): array
            {
                return ['writing' => "---\nname: writing\ndescription: Writing\n---\nBody"];
            }

            public function resource(string $skill, string $reference): string
            {
                return '';
            }

            public function location(string $skill): string
            {
                if ($this->expected) {
                    throw new RuntimeException('Location unavailable.');
                }
                throw new LogicException('Location adapter failed.');
            }
        };
        $toolkit = new SkillToolkit(new SkillRepository($storage));
        $this->expectException($expected ? RuntimeException::class : LogicException::class);
        $this->expectExceptionMessage($expected ? 'Location unavailable.' : 'Location adapter failed.');

        $toolkit->guidelines();
    }

    /** @return array<string, array{bool}> */
    public static function locationFailures(): array
    {
        return ['expected' => [true], 'unexpected' => [false]];
    }

    /** @dataProvider hostLocations */
    public function test_activation_preserves_original_document_and_only_reads_requested_resources(?string $location): void
    {
        $document = "---\r\nname: declared\r\ndescription: Remote guidance\r\nallowed-tools: host_read\r\nmetadata: {author: Human}\r\nx-extension: preserved\r\n---\r\n\r\nRead references/guide.md when needed.  \r\n";
        $storage = $location === null
            ? new RemoteSkillStorage($document)
            : new LocatableRemoteSkillStorage($document, $location);
        $toolkit = new SkillToolkit(new SkillRepository($storage));
        $this->assertSame(0, $storage->listCalls);
        $this->assertSame([], $storage->locations);
        $guidelines = $toolkit->guidelines() ?? '';
        $this->assertStringNotContainsString('x-extension', $guidelines);
        if ($location === null) {
            $this->assertStringContainsString("- declared: Remote guidance\n", $guidelines);
            $this->assertStringNotContainsString('location', $guidelines);
            $this->assertStringContainsString('use an available execution tool. Skill instructions', $guidelines);
        } else {
            $this->assertStringContainsString('- declared: Remote guidance (location: '.$location.")\n", $guidelines);
            $this->assertStringContainsString('from its catalog location when one is listed', $guidelines);
        }
        [$activation, $resource] = $toolkit->tools();
        $activation->setInputs(['name' => 'declared'])->execute();
        $this->assertSame($document, $activation->getResult());
        $this->assertSame($location === null ? [] : ['source-id'], $storage->locations);
        $this->assertSame(1, $storage->listCalls);
        $this->assertSame([], $storage->reads);
        $resource->setInputs(['name' => 'declared', 'path' => './references/guide.md'])->execute();
        $this->assertSame('Lazy guide', $resource->getResult());
        $this->assertSame([['source-id', 'references/guide.md']], $storage->reads);
        $this->assertCount(2, $toolkit->tools());
    }

    /** @return array<string, array{?string}> */
    public static function hostLocations(): array
    {
        return ['unavailable' => [null], 'nonlocal' => ['skills://workspace/source-id']];
    }

    public function test_host_tool_executes_the_file_at_the_activated_location_with_neighboring_binary_asset(): void
    {
        $asset = "binary\0asset\xff";
        file_put_contents($this->skillsRoot.'/writing/references/value.bin', $asset);
        $marker = $this->skillsRoot.'/writing/executed';
        $script = <<<'PHP'
            <?php
            file_put_contents(__DIR__.'/../executed', 'yes');
            echo hash('sha256', file_get_contents(__DIR__.'/../references/value.bin'));
            PHP;
        file_put_contents($this->skillsRoot.'/writing/scripts/check.php', $script);
        $toolkit = new SkillToolkit(new SkillRepository(new FileSystemSkillStorage($this->skillsRoot)));
        $guidelines = $toolkit->guidelines() ?? '';
        $location = $this->skillsRoot.'/writing';
        $this->assertStringContainsString('location: '.$location, $guidelines);
        [$skillTool, $resourceTool] = $toolkit->tools();
        $activation = (new ToolCall($skillTool->getName(), 'activate'))->setInputs(['name' => 'writing']);
        // Host-owned execution: the library itself never launches the script.
        $host = new class (function () use ($location, $marker): string {
            $this->assertFileDoesNotExist($marker);
            $this->assertSame(realpath($this->skillsRoot.'/writing'), $location);
            $process = proc_open([PHP_BINARY, $location.'/scripts/check.php'], [1 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $this->assertSame(0, proc_close($process));
            $this->assertIsString($output);
            return $output;
        }) extends Tool {
            protected string $name = 'run_skill_check';
            protected ?string $description = 'Run the permitted example check script.';

            /** @param Closure(): string $callback */
            public function __construct(private Closure $callback)
            {
            }

            public function __invoke(): string
            {
                return ($this->callback)();
            }
        };
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [$activation]),
            new ToolCallMessage(null, [(new ToolCall($resourceTool->getName(), 'read_script'))->setInputs([
                'name' => 'writing', 'path' => 'scripts/check.php',
            ])]),
            new ToolCallMessage(null, [(new ToolCall($host->getName(), 'host_check'))->setInputs([])]),
            new AssistantMessage('Check complete.'),
        );
        try {
            $this->assertFileDoesNotExist($marker);
            Agent::make()->setThreadId('skills-test')->setAiProvider($provider)->setInstructions('Run the permitted check.')
                ->addTool($toolkit)->addTool($host)->chat(new UserMessage('Check the asset.'))->getMessage();
            $this->assertFileExists($marker);
            $provider->assertSent(fn (RequestRecord $record): bool => $this->hasToolResult($record, $script));
            $provider->assertSent(fn (RequestRecord $record): bool => $this->hasToolResult($record, hash('sha256', $asset)));
            $this->assertStringNotContainsString($asset, $provider->getRecorded()[0]->systemPrompt?->getContent() ?? '');
        } finally {
            unlink($this->skillsRoot.'/writing/references/value.bin');
            if (file_exists($marker)) {
                unlink($marker);
            }
        }
    }

    protected function hasToolResult(RequestRecord $record, string $expected): bool
    {
        foreach ($record->messages as $message) {
            if (!$message instanceof ToolResultMessage) {
                continue;
            }

            foreach ($message->getToolCalls() as $tool) {
                if ($tool->getResult() === $expected) {
                    return true;
                }
            }
        }

        return false;
    }
}

class RemoteSkillStorage implements SkillStorageInterface
{
    public int $listCalls = 0;

    /** @var list<array{string, string}> */
    public array $reads = [];

    /** @var list<string> */
    public array $locations = [];

    public function __construct(protected string $document)
    {
    }

    public function list(): array
    {
        ++$this->listCalls;

        return ['source-id' => $this->document];
    }

    public function resource(string $skill, string $reference): string
    {
        $this->reads[] = [$skill, $reference];

        return 'Lazy guide';
    }
}

class LocatableRemoteSkillStorage extends RemoteSkillStorage implements LocatableSkillStorageInterface
{
    public function __construct(string $document, protected string $base)
    {
        parent::__construct($document);
    }

    public function location(string $skill): string
    {
        $this->locations[] = $skill;

        return $this->base;
    }
}
