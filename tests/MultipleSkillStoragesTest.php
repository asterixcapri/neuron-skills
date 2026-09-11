<?php

declare(strict_types=1);

namespace NeuronAI\Skills\Tests;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Skills\SkillRepository;
use NeuronAI\Skills\Tools\SkillToolkit;
use NeuronAI\Skills\Storage\FileSystemSkillStorage;
use NeuronAI\Skills\Storage\SkillStorageInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\RequestRecord;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class MultipleSkillStoragesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/neuron-multiple-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/project', 0o777, true);
        mkdir($this->root.'/user');
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            assert($file instanceof SplFileInfo);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    private function skill(string $source, string $identifier, string $name, string $description): string
    {
        $directory = $this->root.'/'.$source.'/'.$identifier;
        mkdir($directory);
        $document = "---\nname: {$name}\ndescription: {$description}\n---\n{$source} instructions.\n";
        file_put_contents($directory.'/SKILL.md', $document);
        file_put_contents($directory.'/guide.md', $source.' guide for '.$name);
        return $document;
    }

    public function test_numeric_directory_identifiers_remain_strings_across_storages(): void
    {
        $projectDocument = $this->skill('project', '123', "'123'", 'Project');
        $this->skill('user', '123', "'123'", 'User');
        $project = new FileSystemSkillStorage($this->root.'/project');
        $user = new FileSystemSkillStorage($this->root.'/user');
        $this->assertSame(['123'], $project->list());
        foreach ([new SkillToolkit($project), new SkillToolkit($project, $user)] as $toolkit) {
            $this->assertStringContainsString('Project', $toolkit->guidelines() ?? '');
            [$activation, $resource] = $toolkit->tools();
            $activation->setInputs(['name' => '123'])->execute();
            $this->assertSame('Skill location: '.$this->root."/project/123\n\n".$projectDocument, $activation->getResult());
            $resource->setInputs(['name' => '123', 'path' => 'guide.md'])->execute();
            $this->assertSame("project guide for '123'", $resource->getResult());
        }
    }

    public function test_precedence_keeps_documents_locations_and_resources_together(): void
    {
        $projectDocument = $this->skill('project', 'folder', 'shared', 'Project');
        $userDocument = $this->skill('user', 'folder', 'shared', 'User');
        file_put_contents($this->root.'/user/folder/user-only.md', 'Must not leak');
        $project = new FileSystemSkillStorage($this->root.'/project');
        $user = new FileSystemSkillStorage($this->root.'/user');
        $repository = new SkillRepository($project, $user);
        $this->assertSame([['name' => 'shared', 'description' => 'Project']], $repository->catalog());
        $this->assertSame($projectDocument, $repository->readDocument('shared'));
        $this->assertSame($this->root.'/project/folder', $repository->location('shared'));
        $this->assertSame('project guide for shared', $repository->readResource('shared', 'guide.md'));
        $messages = array_column($repository->diagnostics(), 'message');
        $this->assertContains('Skill "shared" from storage #2 candidate "folder" is shadowed by storage #1 candidate "folder".', $messages);
        $reversed = new SkillRepository($user, $project);
        $this->assertSame($userDocument, $reversed->readDocument('shared'));
        $this->assertSame($this->root.'/user/folder', $reversed->location('shared'));
        $this->assertSame('user guide for shared', $reversed->readResource('shared', 'guide.md'));
        $this->expectException(ToolException::class);
        $repository->readResource('shared', 'user-only.md');
    }

    public function test_unusable_and_unreadable_candidates_allow_fallback_while_warnings_keep_precedence(): void
    {
        $primary = new TrackedSkillStorage([
            'unreadable' => null,
            'invalid' => "---\nname: invalid\ndescription: []\n---\n",
            'z-last' => "---\nname: shared\ndescription: Last\n---\n",
            'a-first' => "---\nname: shared\ndescription: First\n---\n",
        ]);
        $fallback = new TrackedSkillStorage([
            'unreadable' => "---\nname: unreadable\ndescription: Recovered\n---\n",
            'invalid' => "---\nname: invalid\ndescription: Recovered\n---\n",
            'shared' => "---\nname: shared\ndescription: Fallback\n---\n",
        ]);
        $toolkit = new SkillToolkit($primary, $fallback);
        $this->assertSame(['a-first/SKILL.md', 'invalid/SKILL.md', 'unreadable/SKILL.md', 'z-last/SKILL.md'], $primary->reads);
        $this->assertSame(['invalid/SKILL.md', 'shared/SKILL.md', 'unreadable/SKILL.md'], $fallback->reads);
        $guidelines = $toolkit->guidelines() ?? '';
        $this->assertStringContainsString('shared: First', $guidelines);
        $this->assertStringContainsString('invalid: Recovered', $guidelines);
        $this->assertStringContainsString('unreadable: Recovered', $guidelines);
        [$activation, $resource] = $toolkit->tools();
        $activation->setInputs(['name' => 'shared'])->execute();
        $this->assertStringContainsString('unavailable', $activation->getResult());
        $this->assertStringContainsString('description: First', $activation->getResult());
        $resource->setInputs(['name' => 'shared', 'path' => 'guide.md'])->execute();
        $this->assertSame('a-first/guide.md', $resource->getResult());
        $this->assertSame(['invalid/SKILL.md', 'shared/SKILL.md', 'unreadable/SKILL.md'], $fallback->reads);
        $primary->documents['a-first'] = "---\nname: shared\ndescription: Changed\n---\nNew body";
        $activation->execute();
        $this->assertStringContainsString('New body', $activation->getResult());
        $this->assertSame($guidelines, $toolkit->guidelines());
        $this->assertNotEmpty($toolkit->diagnostics());
    }

    public function test_multiple_empty_or_unusable_sources_provide_no_tools_or_guidelines(): void
    {
        $toolkit = new SkillToolkit(new TrackedSkillStorage([]), new TrackedSkillStorage(['broken' => 'invalid']));
        $this->assertSame([], $toolkit->tools());
        $this->assertNull($toolkit->guidelines());
    }

    public function test_neuron_loop_activates_and_reads_distinct_skills_from_both_roots(): void
    {
        $projectDocument = $this->skill('project', 'same-folder', 'writing', 'Write prose');
        $userDocument = $this->skill('user', 'same-folder', 'analysis', 'Analyse evidence');
        $toolkit = new SkillToolkit(
            new FileSystemSkillStorage($this->root.'/project'),
            new FileSystemSkillStorage($this->root.'/user'),
        );
        [$skill, $resource] = $toolkit->tools();
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                (clone $skill)->setCallId('writing')->setInputs(['name' => 'writing']),
                (clone $skill)->setCallId('analysis')->setInputs(['name' => 'analysis']),
            ]),
            new ToolCallMessage(null, [
                (clone $resource)->setCallId('writing-guide')->setInputs(['name' => 'writing', 'path' => 'guide.md']),
                (clone $resource)->setCallId('analysis-guide')->setInputs(['name' => 'analysis', 'path' => 'guide.md']),
            ]),
            new AssistantMessage('Both skills loaded.'),
        );
        $agent = Agent::make()->setAiProvider($provider)->addTool($toolkit);
        $this->assertSame('Both skills loaded.', $agent->chat(new UserMessage('Analyse and write.'))->getMessage()->getContent());
        foreach ([$projectDocument, $userDocument, $this->root.'/project/same-folder', $this->root.'/user/same-folder', 'project guide for writing', 'user guide for analysis'] as $expected) {
            $provider->assertSent(static function (RequestRecord $request) use ($expected): bool {
                foreach ($request->messages as $message) {
                    if ($message instanceof ToolResultMessage) {
                        foreach ($message->getTools() as $tool) {
                            if (str_contains($tool->getResult(), $expected)) {
                                return true;
                            }
                        }
                    }
                }
                return false;
            });
        }
    }
}

class TrackedSkillStorage implements SkillStorageInterface
{
    /** @var list<string> */
    public array $reads = [];

    /** @param array<string, ?string> $documents */
    public function __construct(public array $documents)
    {
    }

    public function list(): array
    {
        return array_keys($this->documents);
    }

    public function location(string $skill): ?string
    {
        return null;
    }

    public function read(string $skill, string $path): string
    {
        $this->reads[] = $skill.'/'.$path;
        if ($path !== 'SKILL.md') {
            return $skill.'/'.$path;
        }
        return $this->documents[$skill] ?? throw new ToolException('Document unreadable.');
    }
}
