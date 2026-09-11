<?php

declare(strict_types=1);

namespace NeuronAI\Skills\Tests;

use NeuronAI\Skills\Internal\SkillDocumentParser;
use PHPUnit\Framework\TestCase;

class SkillDocumentParserTest extends TestCase
{
    /** @dataProvider yamlDocuments */
    public function test_interprets_yaml_strings(string $yaml, string $description): void
    {
        $result = (new SkillDocumentParser())->parse("---\nname: writing\n".$yaml."\n---\nBody", 'writing');
        $this->assertSame([], $result['warnings']);
        $this->assertSame($description, $result['document']['description'] ?? null);
    }

    /** @return array<string, array{string, string}> */
    public static function yamlDocuments(): array
    {
        return [
            'quoted colon and comment' => ['description: "Write: clearly # please" # comment', 'Write: clearly # please'],
            'single quote escaping' => ["description: 'It''s clear'", "It's clear"],
            'double quote escaping' => ['description: "Write\\nclearly"', "Write\nclearly"],
            'literal block' => ["description: |\n  Write\n  clearly", "Write\nclearly\n"],
            'folded block' => ["description: >-\n  Write\n  clearly", 'Write clearly'],
            'multiline single quote' => ["description: 'Write\n  clearly'", 'Write clearly'],
            'multiline double quote' => ["description: \"Write\n  clearly\"", 'Write clearly'],
            'continued plain scalar' => ["description: Write\n  clearly", 'Write clearly'],
            'explicit string tag' => ['description: !!str 123', '123'],
            'alias' => ["summary: &summary Write clearly\ndescription: *summary", 'Write clearly'],
        ];
    }

    /** @dataProvider explicitScalarKeys */
    public function test_supports_explicit_scalar_mapping_keys(string $yaml): void
    {
        $result = (new SkillDocumentParser())->parse("---\n".$yaml."\n---\nBody", 'writing');
        $this->assertSame([], $result['warnings']);
        $this->assertSame('writing', $result['document']['name'] ?? null);
        $this->assertSame('Works', $result['document']['description'] ?? null);
    }

    /** @return array<string, array{string}> */
    public static function explicitScalarKeys(): array
    {
        return [
            'flow' => ['{? name: writing, ? description: Works}'],
            'anchored flow' => ["name: writing\ndescription: Works\nmetadata: &meta {? author: Alice}\nx-copy: *meta"],
            'nested flow' => ["name: writing\ndescription: Works\nmetadata: {? author: Alice, ? version: '1.0'}"],
            'multiline flow' => ["{\n  ? name: writing,\n  ? description: Works,\n  ? metadata: { ? author: Alice }\n}"],
            'plain' => ["? name\n: writing\n? description\n: Works"],
            'quoted and comments' => ["? 'name' # key\n# separation\n: writing\n? \"description\"\n: Works"],
            'tagged' => ["? !!str name\n: writing\ndescription: Works"],
            'multiline quoted' => ["? \"na\\\n  me\"\n: writing\ndescription: Works"],
            'block scalar key' => ["? |-\n  name\n: writing\ndescription: Works"],
            'indented key' => ["?\n  name\n: writing\ndescription: Works"],
            'nested metadata' => ["name: writing\ndescription: Works\nmetadata:\n  ? 'author'\n  : Alice\n  ? version\n  : '1.0'"],
        ];
    }

    public function test_explicit_keys_preserve_scalar_contents_and_nested_values(): void
    {
        $result = (new SkillDocumentParser())->parse(<<<'SKILL'
            ---
            name: writing
            description: |+
              ? leave this
              : unchanged

            ? metadata
            :
              ? 'author: # key'
              : |-
                ? not a key
                : still content
              ? version
              : "? quoted
                : content"
            ---
            Body
            SKILL, 'writing');
        $this->assertSame([], $result['warnings']);
        $this->assertSame("? leave this\n: unchanged\n\n", $result['document']['description'] ?? null);
        $metadata = $result['document']['frontmatter']->metadata ?? null;
        $this->assertNotNull($metadata);
        $this->assertSame("? not a key\n: still content", $metadata->{'author: # key'});
        $this->assertSame('? quoted : content', $metadata->version);
    }

    public function test_json_style_flow_strings_are_preserved(): void
    {
        $yaml = '{"name":"writing","description":"literal, ? stuff", "metadata":{"text":"other, ? content", ? author: Alice}}';
        $result = (new SkillDocumentParser())->parse("---\n".$yaml."\n---\nBody", 'writing');
        $this->assertSame([], $result['warnings']);
        $this->assertSame('literal, ? stuff', $result['document']['description'] ?? null);
        $this->assertSame('other, ? content', $result['document']['frontmatter']->metadata->text ?? null);
    }

    public function test_flow_indicators_inside_scalars_are_not_modified(): void
    {
        $result = (new SkillDocumentParser())->parse(<<<'SKILL'
            ---
            name: writing
            description: |-
              {? name: writing, ? description: Works}
            metadata:
              quoted: '{? text: keep}'
              double: "{? text: keep}"
              plain: Some text
                {? text}
              multiline: 'Some text
                {? text: keep}'
              ? actual
              : {? key: value}
            ---
            Body
            SKILL, 'writing');
        $this->assertNotNull($result['document']);
        $this->assertSame('{? name: writing, ? description: Works}', $result['document']['description']);
        $metadata = $result['document']['frontmatter']->metadata;
        $this->assertSame('{? text: keep}', $metadata->quoted);
        $this->assertSame('{? text: keep}', $metadata->double);
        $this->assertSame('Some text {? text}', $metadata->plain);
        $this->assertSame('Some text {? text: keep}', $metadata->multiline);
        $this->assertSame('value', $metadata->actual->key);
    }

    public function test_preserves_optional_fields_and_extensions_without_runtime_behavior(): void
    {
        $result = (new SkillDocumentParser())->parse(<<<'SKILL'
            ---
            name: writing
            description: Write clearly
            license: MIT
            compatibility: Requires PHP 8.1
            allowed-tools: Bash(git:*) Read
            metadata: {author: Alice, version: "1.0"}
            custom: {enabled: true, nested: [one, two]}
            ---
            Body
            SKILL, 'writing');
        $this->assertSame([], $result['warnings']);
        $frontmatter = $result['document']['frontmatter'] ?? null;
        $this->assertNotNull($frontmatter);
        $this->assertSame('MIT', $frontmatter->license);
        $this->assertSame('Requires PHP 8.1', $frontmatter->compatibility);
        $this->assertSame('Bash(git:*) Read', $frontmatter->{'allowed-tools'});
        $this->assertSame('Alice', $frontmatter->metadata->author);
        $this->assertSame('1.0', $frontmatter->metadata->version);
        $this->assertTrue($frontmatter->custom->enabled);
    }

    public function test_supports_flow_frontmatter_and_normalizes_numeric_metadata_keys(): void
    {
        foreach (['123', '"123"'] as $key) {
            $result = (new SkillDocumentParser())->parse("---\n{name: writing, description: Good, metadata: {".$key.": value}}\n---\nBody", 'writing');
            $this->assertSame([], $result['warnings']);
            $this->assertSame('value', $result['document']['frontmatter']->metadata->{'123'} ?? null);
        }
    }

    public function test_accepts_unicode_character_boundaries_and_long_markdown(): void
    {
        $name = str_repeat('é', 64);
        $body = str_repeat("Unrestricted Markdown.\n", 600);
        $result = (new SkillDocumentParser())->parse("---\nname: $name\ndescription: ".str_repeat('語', 1024)."\ncompatibility: ".str_repeat('é', 500)."\nmetadata: {}\n---\n".$body, $name);
        $this->assertSame([], $result['warnings']);
        $this->assertSame($body, $result['document']['body'] ?? null);
    }

    /** @dataProvider toleratedViolations */
    public function test_reports_nonconformance_without_excluding_usable_documents(string $fields, string $warning): void
    {
        $result = (new SkillDocumentParser())->parse("---\n".$fields."\n---\nBody", 'writing');
        $this->assertNotNull($result['document']);
        $this->assertStringContainsString($warning, implode(' ', $result['warnings']));
    }

    /** @return array<string, array{string, string}> */
    public static function toleratedViolations(): array
    {
        $base = "name: writing\ndescription: Good";
        return [
            'mismatch' => ["name: other\ndescription: Good", 'storage identifier'],
            'uppercase' => ["name: Écriture\ndescription: Good", 'lowercase'],
            'hyphens' => ["name: -bad--name-\ndescription: Good", 'hyphens'],
            'name length' => ["name: ".str_repeat('é', 65)."\ndescription: Good", '64 characters'],
            'description length' => ["name: writing\ndescription: ".str_repeat('語', 1025), '1024 characters'],
            'explicit omitted value' => [$base."\n? license", 'license must'],
            'explicit omitted value before field' => ["? license\n".$base, 'license must'],
            'license type' => [$base."\nlicense: [MIT]", 'license must'],
            'tools type' => [$base."\nallowed-tools: [Read]", 'allowed-tools must'],
            'compatibility empty' => [$base."\ncompatibility: ''", 'compatibility must'],
            'compatibility type' => [$base."\ncompatibility: true", 'compatibility must'],
            'compatibility length' => [$base."\ncompatibility: ".str_repeat('語', 501), 'compatibility must'],
            'metadata list' => [$base."\nmetadata: []", 'metadata must'],
            'metadata value' => [$base."\nmetadata: {version: 1}", 'metadata must'],
        ];
    }

    /** @dataProvider unusableDocuments */
    public function test_excludes_unusable_documents(string $yaml): void
    {
        $result = (new SkillDocumentParser())->parse("---\n".$yaml."\n---\nBody", 'writing');
        $this->assertNull($result['document']);
        $this->assertNotEmpty($result['warnings']);
    }

    /** @return array<string, array{string}> */
    public static function unusableDocuments(): array
    {
        return [
            'duplicate explicit key' => ["? name\n: writing\n? name\n: duplicate\ndescription: Works"],
            'malformed explicit key' => ["? \"name\n: writing\ndescription: Works"],
            'syntax' => ["name: writing\ndescription: [broken"],
            'top sequence' => ['[writing, description]'],
            'top scalar' => ['writing'],
            'name mapping' => ["name: {x: y}\ndescription: Good"],
            'name number' => ["name: 123\ndescription: Good"],
            'description boolean' => ["name: writing\ndescription: true"],
            'description list' => ["name: writing\ndescription: [Good]"],
            'description whitespace' => ["name: writing\ndescription: '   '"],
            'name whitespace' => ["name: ' '\ndescription: Good"],
            'unsafe object tag' => ["name: writing\ndescription: !php/object something"],
        ];
    }
}
