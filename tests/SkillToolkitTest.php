<?php

declare(strict_types=1);

namespace NeuronAI\Skills\Tests;

use LogicException;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Skills\Tool\SkillResourceTool;
use NeuronAI\Skills\SkillToolkit;
use NeuronAI\Skills\Tool\SkillTool;
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
        $this->assertStringContainsString('Skill instructions may reference other files in their package.', $systemPrompt);
        $this->assertStringContainsString(
            'Always load every referenced file with the `skill_resource` tool.',
            $systemPrompt,
        );
        $this->assertStringContainsString(
            'The `skill` and `skill_resource` tools only read text and never execute scripts.',
            $systemPrompt,
        );
        $this->assertStringContainsString(
            'If a loaded file is a script and an appropriate execution tool is available, '
                .'use that separate tool to execute the loaded contents.',
            $systemPrompt,
        );
        $this->assertStringNotContainsString('Prefer direct sentences.', $systemPrompt);
        $this->assertStringNotContainsString('Use concrete words.', $systemPrompt);
        $provider->assertSent(fn (RequestRecord $record): bool => $this->hasToolResult(
            $record,
            "# Writing instructions\n\nPrefer direct sentences.",
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
        $provider->assertSent(fn (RequestRecord $record): bool => $this->hasToolResult($record, 'Unicode skill body.'));
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
            public function skills(): array
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

        $this->assertSame("Resource path \"resource\0.md\" is invalid.", $tool->getResult());
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

            public function skills(): array
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

            public function skills(): array
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

            public function skills(): array
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

            public function skills(): array
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
