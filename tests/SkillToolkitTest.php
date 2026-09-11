<?php

declare(strict_types=1);

namespace NeuronAI\Skills\Tests;

use LogicException;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\Tool;
use NeuronAI\Skills\Tools\Toolkits\Skills\SkillResourceTool;
use NeuronAI\Skills\Tools\Toolkits\Skills\SkillToolkit;
use NeuronAI\Skills\Tools\Toolkits\Skills\SkillTool;
use NeuronAI\Skills\Storage\FileSystemSkillStorage;
use NeuronAI\Skills\Storage\SkillStorageInterface;
use PHPUnit\Framework\TestCase;

use function array_keys;
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
        $toolkit = new SkillToolkit(new FileSystemSkillStorage($this->skillsRoot));
        $tools = $toolkit->tools();
        $this->assertCount(2, $tools);
        $this->assertSame([], $toolkit->diagnostics());
        $skillTool = $tools[0];
        $this->assertInstanceOf(SkillTool::class, $skillTool);
        $this->assertSame(['name'], $skillTool->getRequiredProperties());
        $nameProperty = $skillTool->getProperties()[0];
        $this->assertInstanceOf(ToolProperty::class, $nameProperty);
        $this->assertSame(['writing'], $nameProperty->getEnum());
        $this->assertCount(1, $skillTool->getProperties());

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $skillTool)->setCallId('call_1')->setInputs(['name' => 'writing']),
            ]),
            new AssistantMessage('I will follow the writing skill.'),
        );
        $agent = Agent::make()
            ->setAiProvider($provider)
            ->setInstructions('Be helpful.')
            ->addTool($toolkit);

        $response = $agent->chat(new UserMessage('Help me write.'))->getMessage();

        $this->assertSame('I will follow the writing skill.', $response->getContent());
        $provider->assertToolsConfigured(['skill', 'skill_resource']);
        $systemPrompt = $provider->getRecorded()[0]->systemPrompt ?? '';
        $this->assertStringContainsString('writing: Write clear prose', $systemPrompt);
        $this->assertStringContainsString('skill', $systemPrompt);
        $this->assertStringContainsString('Read only resources needed for the current task', $systemPrompt);
        $this->assertStringContainsString('Resolve relative references against the skill location', $systemPrompt);
        $this->assertStringNotContainsString($this->skillsRoot, $systemPrompt);
        $this->assertStringNotContainsString('Prefer direct sentences.', $systemPrompt);
        $this->assertStringNotContainsString('Use concrete words.', $systemPrompt);
        $provider->assertSent(fn (RequestRecord $record): bool => $this->hasToolResult(
            $record,
            'Skill location: '.$this->skillsRoot."/writing\n\n".file_get_contents($this->skillsRoot.'/writing/SKILL.md'),
        ));
    }

    public function test_diagnostics_stay_out_of_agent_context_while_tolerated_skill_loads(): void
    {
        file_put_contents($this->skillsRoot.'/writing/SKILL.md', "---\nname: écriture\ndescription: >-\n  Write\n  clearly\nlicense: MIT\n---\nUnicode skill body.");
        $toolkit = new SkillToolkit(new FileSystemSkillStorage($this->skillsRoot));
        $this->assertSame([['skill' => 'writing', 'message' => 'Declared name does not match the storage identifier.']], $toolkit->diagnostics());
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $toolkit->tools()[0])->setCallId('unicode')->setInputs(['name' => 'écriture']),
            ]),
            new AssistantMessage('Loaded.'),
        );
        Agent::make()->setAiProvider($provider)->setInstructions('Be helpful.')->addTool($toolkit)
            ->chat(new UserMessage('Write.'))->getMessage();
        $prompt = $provider->getRecorded()[0]->systemPrompt ?? '';
        $this->assertStringContainsString('écriture: Write clearly', $prompt);
        $this->assertStringNotContainsString('storage identifier', $prompt);
        $this->assertStringNotContainsString('MIT', $prompt);
        $provider->assertSent(fn (RequestRecord $record): bool => $this->hasToolResult($record, 'Skill location: '.$this->skillsRoot."/writing\n\n".file_get_contents($this->skillsRoot.'/writing/SKILL.md')));
    }

    public function test_unusable_catalog_produces_diagnostics_but_no_tools_or_guidelines(): void
    {
        file_put_contents($this->skillsRoot.'/writing/SKILL.md', "---\nname: writing\ndescription: []\n---\nBody");
        $toolkit = new SkillToolkit(new FileSystemSkillStorage($this->skillsRoot));
        $this->assertNotEmpty($toolkit->diagnostics());
        $this->assertSame([], $toolkit->tools());
        $this->assertNull($toolkit->guidelines());
    }

    public function test_agent_reads_a_resource_through_the_same_tool_loop(): void
    {
        $toolkit = new SkillToolkit(new FileSystemSkillStorage($this->skillsRoot));
        $skillTool = $toolkit->tools()[1];
        $this->assertInstanceOf(SkillResourceTool::class, $skillTool);
        $this->assertSame(['name', 'path'], $skillTool->getRequiredProperties());
        $this->assertCount(2, $skillTool->getProperties());
        $nameProperty = $skillTool->getProperties()[0];
        $this->assertInstanceOf(ToolProperty::class, $nameProperty);
        $this->assertSame(['writing'], $nameProperty->getEnum());
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $skillTool)->setCallId('call_1')->setInputs([
                    'name' => 'writing',
                    'path' => 'references/style.md',
                ]),
            ]),
            new AssistantMessage('I read the style guide.'),
        );
        $agent = Agent::make()
            ->setAiProvider($provider)
            ->setInstructions('Be helpful.')
            ->addTool($toolkit);

        $agent->chat(new UserMessage('Read the style guide.'))->getMessage();

        $provider->assertToolsConfigured(['skill', 'skill_resource']);
        $this->assertStringNotContainsString(
            'Use concrete words.',
            $provider->getRecorded()[0]->systemPrompt ?? '',
        );
        $provider->assertSent(fn (RequestRecord $record): bool => $this->hasToolResult(
            $record,
            "# Style guide\n\nUse concrete words.\n",
        ));
    }

    public function test_agent_tracks_distinct_skill_and_resource_reads_separately(): void
    {
        $storage = new class () implements SkillStorageInterface {
            public function location(string $skill): ?string
            {
                return null;
            }

            public function list(): array
            {
                return ['analysis', 'writing'];
            }

            public function read(string $skill, string $path): string
            {
                if ($path === 'SKILL.md') {
                    return "---\nname: {$skill}\ndescription: {$skill} skill\n---\n{$skill} instructions.";
                }

                return "{$skill}:{$path}";
            }
        };
        $toolkit = new SkillToolkit($storage);
        [$skillTool, $resourceTool] = $toolkit->tools();
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $skillTool)->setCallId('call_1')->setInputs(['name' => 'analysis']),
            ]),
            new ToolCallMessage(null, [
                (clone $skillTool)->setCallId('call_2')->setInputs(['name' => 'writing']),
            ]),
            new ToolCallMessage(null, [
                (clone $resourceTool)->setCallId('call_3')->setInputs([
                    'name' => 'writing',
                    'path' => 'references/style.md',
                ]),
            ]),
            new ToolCallMessage(null, [
                (clone $resourceTool)->setCallId('call_4')->setInputs([
                    'name' => 'writing',
                    'path' => 'references/examples.md',
                ]),
            ]),
            new AssistantMessage('All distinct reads completed.'),
        );

        $agent = Agent::make();
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
        $tool = (new SkillToolkit(new FileSystemSkillStorage($this->skillsRoot)))->tools()[0];
        $tool->setInputs(['name' => 'unknown']);
        $tool->execute();

        $this->assertSame('Skill "unknown" is not available.', $tool->getResult());
    }

    public function test_unknown_skill_resource_is_a_model_readable_result(): void
    {
        $tool = (new SkillToolkit(new FileSystemSkillStorage($this->skillsRoot)))->tools()[1];
        $tool->setInputs(['name' => 'unknown', 'path' => 'guide.md']);
        $tool->execute();

        $this->assertSame('Skill "unknown" is not available.', $tool->getResult());
    }

    public function test_null_byte_resource_path_is_a_model_readable_result(): void
    {
        $tool = (new SkillToolkit(new FileSystemSkillStorage($this->skillsRoot)))->tools()[1];
        $tool->setInputs(['name' => 'writing', 'path' => "resource\0.md"]);
        $tool->execute();

        $this->assertSame(sprintf('Resource path "%s" is invalid.', "resource\0.md"), $tool->getResult());
    }

    public function test_empty_resource_path_is_invalid_and_cannot_load_instructions(): void
    {
        $tool = (new SkillToolkit(new FileSystemSkillStorage($this->skillsRoot)))->tools()[1];
        $tool->setInputs(['name' => 'writing', 'path' => '']);
        $tool->execute();

        $this->assertSame('Resource path "" is invalid.', $tool->getResult());
        $this->assertStringNotContainsString('Writing instructions', $tool->getResult());
    }

    public function test_agent_reads_a_script_as_unchanged_text_without_executing_it(): void
    {
        $toolkit = new SkillToolkit(new FileSystemSkillStorage($this->skillsRoot));
        $resourceTool = $toolkit->tools()[1];
        $script = "<?php\n\necho 'checked';\n";
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $resourceTool)->setCallId('call_1')->setInputs([
                    'name' => 'writing',
                    'path' => 'scripts/check.php',
                ]),
            ]),
            new AssistantMessage('I read the script as text.'),
        );

        Agent::make()
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

    /** @dataProvider expectedReadFailures */
    public function test_agent_receives_expected_read_failures_without_an_error_handler(
        int $toolIndex,
        bool $invalidManifest,
        string $expected,
    ): void {
        $toolkit = new SkillToolkit(new FileSystemSkillStorage($this->skillsRoot));

        if ($toolIndex === 0) {
            if ($invalidManifest) {
                file_put_contents($this->skillsRoot.'/writing/SKILL.md', 'Invalid document.');
            } else {
                unlink($this->skillsRoot.'/writing/SKILL.md');
            }
        }

        $inputs = $toolIndex === 0
            ? ['name' => 'writing']
            : ['name' => 'writing', 'path' => 'missing.md'];
        $tool = $toolkit->tools()[$toolIndex];
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $tool)->setCallId('failed_read')->setInputs($inputs),
            ]),
            new AssistantMessage('I could not read that file.'),
        );

        $response = Agent::make()
            ->setAiProvider($provider)
            ->setInstructions('Use the available skills.')
            ->addTool($toolkit)
            ->chat(new UserMessage('Read the writing skill and its guide.'))
            ->getMessage();

        $this->assertSame('I could not read that file.', $response->getContent());
        $provider->assertSent(fn (RequestRecord $record): bool => $this->hasToolResult($record, $expected));
    }

    /** @return array<string, array{int, bool, string}> */
    public static function expectedReadFailures(): array
    {
        return [
            'missing instructions' => [0, false, 'Resource "SKILL.md" was not found in skill "writing".'],
            'invalid instructions' => [0, true, 'Skill "writing" has invalid frontmatter.'],
            'missing resource' => [1, false, 'Resource "missing.md" was not found in skill "writing".'],
        ];
    }

    public function test_tool_delegates_reads_to_the_configured_storage(): void
    {
        $storage = new class () implements SkillStorageInterface {
            public ?string $requestedSkill = null;
            public ?string $requestedPath = null;

            public function location(string $skill): ?string
            {
                return null;
            }

            public function list(): array
            {
                return ['remote'];
            }

            public function read(string $skill, string $path): string
            {
                $this->requestedSkill = $skill;
                $this->requestedPath = $path;

                return $path === 'SKILL.md'
                    ? "---\nname: remote\ndescription: Remote skill\n---\nRemote instructions."
                    : 'Remote resource.';
            }
        };
        $toolkit = new SkillToolkit($storage);
        $storage->requestedSkill = null;
        $storage->requestedPath = null;
        $tool = $toolkit->tools()[1];
        $tool->setInputs(['name' => 'remote', 'path' => 'references/api.md']);
        $tool->execute();

        $this->assertSame('Remote resource.', $tool->getResult());
        $this->assertSame('remote', $storage->requestedSkill);
        $this->assertSame('references/api.md', $storage->requestedPath);
    }

    public function test_unexpected_repository_failures_remain_exceptions(): void
    {
        $storage = new class () implements SkillStorageInterface {
            public int $reads = 0;

            public function location(string $skill): ?string
            {
                return null;
            }

            public function list(): array
            {
                return ['broken'];
            }

            public function read(string $skill, string $path): string
            {
                if ($this->reads++ === 0) {
                    return "---\nname: broken\ndescription: Broken skill\n---\nInstructions.";
                }

                throw new LogicException('Storage failed unexpectedly.');
            }
        };
        $toolkit = new SkillToolkit($storage);
        $tool = $toolkit->tools()[0];
        $tool->setInputs(['name' => 'broken']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Storage failed unexpectedly.');

        $tool->execute();
    }

    public function test_unexpected_resource_repository_failures_remain_exceptions(): void
    {
        $storage = new class () implements SkillStorageInterface {
            public int $reads = 0;

            public function location(string $skill): ?string
            {
                return null;
            }

            public function list(): array
            {
                return ['broken'];
            }

            public function read(string $skill, string $path): string
            {
                if ($this->reads++ === 0) {
                    return "---\nname: broken\ndescription: Broken skill\n---\nInstructions.";
                }

                throw new LogicException('Resource storage failed unexpectedly.');
            }
        };
        $toolkit = new SkillToolkit($storage);
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

            public function location(string $skill): ?string
            {
                return null;
            }

            public function list(): array
            {
                return array_keys($this->manifests);
            }

            public function read(string $skill, string $path): string
            {
                return $path === 'SKILL.md' ? $this->manifests[$skill] : $skill;
            }
        };
        $toolkit = new SkillToolkit($storage);
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
        $toolkit = new SkillToolkit(new FileSystemSkillStorage($this->skillsRoot));

        $this->assertNull($toolkit->guidelines());
        $this->assertSame([], $toolkit->tools());

        $provider = new FakeAIProvider(new AssistantMessage('Hello.'));
        Agent::make()
            ->setAiProvider($provider)
            ->setInstructions('Be helpful.')
            ->addTool($toolkit)
            ->chat(new UserMessage('Hello.'))
            ->getMessage();

        $this->assertSame([], $provider->getRecorded()[0]->tools);
        $this->assertStringNotContainsString('SkillToolkit', $provider->getRecorded()[0]->systemPrompt ?? '');
    }

    public function test_activation_retains_original_yaml_syntax(): void
    {
        $document = "---\nname: writing\ndescription: &summary Works # comment\nmetadata: {summary: *summary}\n---\nBody\n";
        file_put_contents($this->skillsRoot.'/writing/SKILL.md', $document);
        $toolkit = new SkillToolkit(new FileSystemSkillStorage($this->skillsRoot));
        $this->assertSame([], $toolkit->diagnostics());
        $activation = $toolkit->tools()[0];
        $activation->setInputs(['name' => 'writing'])->execute();
        $this->assertSame('Skill location: '.realpath($this->skillsRoot.'/writing')."\n\n".$document, $activation->getResult());
    }

    /** @dataProvider locationFailures */
    public function test_activation_location_failures_follow_the_tool_error_policy(bool $expected): void
    {
        $storage = new class ($expected) implements SkillStorageInterface {
            public function __construct(private bool $expected)
            {
            }

            public function list(): array
            {
                return ['writing'];
            }

            public function read(string $skill, string $path): string
            {
                return "---\nname: writing\ndescription: Writing\n---\nBody";
            }

            public function location(string $skill): ?string
            {
                if ($this->expected) {
                    throw new ToolException('Location unavailable.');
                }
                throw new LogicException('Location adapter failed.');
            }
        };
        $activation = (new SkillToolkit($storage))->tools()[0];
        $activation->setInputs(['name' => 'writing']);
        if (!$expected) {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('Location adapter failed.');
        }
        $activation->execute();
        $this->assertSame('Location unavailable.', $activation->getResult());
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
        $storage = new class ($document, $location) implements SkillStorageInterface {
            /** @var list<array{string, string}> */
            public array $reads = [];
            /** @var list<string> */
            public array $locations = [];

            public function __construct(private string $document, private ?string $base)
            {
            }

            public function list(): array
            {
                return ['source-id'];
            }

            public function location(string $skill): ?string
            {
                $this->locations[] = $skill;
                return $this->base;
            }

            public function read(string $skill, string $path): string
            {
                $this->reads[] = [$skill, $path];
                return $path === 'SKILL.md' ? $this->document : 'Lazy guide';
            }
        };
        $toolkit = new SkillToolkit($storage);
        $this->assertSame([['source-id', 'SKILL.md']], $storage->reads);
        $this->assertSame([], $storage->locations);
        $this->assertStringNotContainsString('x-extension', $toolkit->guidelines() ?? '');
        [$activation, $resource] = $toolkit->tools();
        $activation->setInputs(['name' => 'declared'])->execute();
        $prefix = $location === null
            ? 'Skill location: unavailable. Read resources with skill_resource; host file access is not established.'
            : 'Skill location: '.$location;
        $this->assertSame($prefix."\n\n".$document, $activation->getResult());
        $this->assertSame(['source-id'], $storage->locations);
        $this->assertSame([['source-id', 'SKILL.md'], ['source-id', 'SKILL.md']], $storage->reads);
        $resource->setInputs(['name' => 'declared', 'path' => 'references/guide.md'])->execute();
        $this->assertSame('Lazy guide', $resource->getResult());
        $this->assertSame(['source-id', 'references/guide.md'], array_slice($storage->reads, -1)[0]);
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
        $toolkit = new SkillToolkit(new FileSystemSkillStorage($this->skillsRoot));
        [$skillTool, $resourceTool] = $toolkit->tools();
        $activation = (clone $skillTool)->setCallId('activate')->setInputs(['name' => 'writing']);
        // Host-owned execution: the library itself never launches the script.
        $host = (new Tool('run_skill_check', 'Run the permitted example check script.'))
            ->setCallable(function () use ($activation, $marker): string {
                $this->assertFileDoesNotExist($marker);
                $result = $activation->getResult();
                $locationLine = explode("\n", $result, 2)[0];
                $location = substr($locationLine, strlen('Skill location: '));
                $this->assertSame(realpath($this->skillsRoot.'/writing'), $location);
                $process = proc_open([PHP_BINARY, $location.'/scripts/check.php'], [1 => ['pipe', 'w']], $pipes);
                $this->assertIsResource($process);
                $output = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                $this->assertSame(0, proc_close($process));
                $this->assertIsString($output);
                return $output;
            });
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [$activation]),
            new ToolCallMessage(null, [(clone $resourceTool)->setCallId('read_script')->setInputs([
                'name' => 'writing', 'path' => 'scripts/check.php',
            ])]),
            new ToolCallMessage(null, [(clone $host)->setCallId('host_check')->setInputs([])]),
            new AssistantMessage('Check complete.'),
        );
        try {
            $this->assertFileDoesNotExist($marker);
            Agent::make()->setAiProvider($provider)->setInstructions('Run the permitted check.')
                ->addTool($toolkit)->addTool($host)->chat(new UserMessage('Check the asset.'))->getMessage();
            $this->assertFileExists($marker);
            $provider->assertSent(fn (RequestRecord $record): bool => $this->hasToolResult($record, $script));
            $provider->assertSent(fn (RequestRecord $record): bool => $this->hasToolResult($record, hash('sha256', $asset)));
            $this->assertStringNotContainsString($asset, $provider->getRecorded()[0]->systemPrompt ?? '');
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

            foreach ($message->getTools() as $tool) {
                if ($tool->getResult() === $expected) {
                    return true;
                }
            }
        }

        return false;
    }
}
