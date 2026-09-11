<?php

declare(strict_types=1);

namespace NeuronAI\Skills;

use Normalizer;
use stdClass;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/** @internal */
final class SkillDocumentParser
{
    /**
     * @return array{document: array{name: string, description: string, body: string, frontmatter: stdClass}|null, warnings: list<string>}
     */
    public function parse(string $contents, string $skill): array
    {
        $parts = $this->extractParts($contents);
        if ($parts === null) {
            return [
                'document' => null,
                'warnings' => ['SKILL.md must begin with YAML frontmatter delimited by ---.'],
            ];
        }

        try {
            $metadata = $this->parseMetadata($parts['frontmatter']);
        } catch (ParseException $exception) {
            return [
                'document' => null,
                'warnings' => ['Unparseable YAML: '.$exception->getMessage()],
            ];
        }
        if (!$metadata instanceof stdClass) {
            return [
                'document' => null,
                'warnings' => ['Frontmatter must be a YAML mapping.'],
            ];
        }

        $warnings = [];
        foreach (['name', 'description'] as $field) {
            $value = $metadata->{$field} ?? null;
            if (!is_string($value) || preg_match('//u', $value) !== 1 || preg_match('/\S/u', $value) !== 1 || str_contains($value, "\0")) {
                $warnings[] = $field.' must be a non-empty UTF-8 string.';
            }
        }
        if ($warnings !== []) {
            return [
                'document' => null,
                'warnings' => $warnings,
            ];
        }

        $name = $metadata->name;
        $description = $metadata->description;
        if (!$this->hasValidNameFormat($name)) {
            $warnings[] = 'name must use lowercase Unicode letters or numbers and single separating hyphens.';
        }
        if (mb_strlen($name, 'UTF-8') > 64) {
            $warnings[] = 'name exceeds 64 characters.';
        }
        if ($name !== $skill) {
            $warnings[] = 'Declared name does not match the storage identifier.';
        }
        if (mb_strlen($description, 'UTF-8') > 1024) {
            $warnings[] = 'description exceeds 1024 characters.';
        }
        foreach (['license', 'allowed-tools'] as $field) {
            if (property_exists($metadata, $field) && !is_string($metadata->{$field})) {
                $warnings[] = $field.' must be a string.';
            }
        }
        if (property_exists($metadata, 'compatibility') && (!is_string($metadata->compatibility)
            || mb_strlen($metadata->compatibility, 'UTF-8') < 1 || mb_strlen($metadata->compatibility, 'UTF-8') > 500)) {
            $warnings[] = 'compatibility must be a string of 1–500 characters.';
        }
        if (property_exists($metadata, 'metadata')) {
            if (!$metadata->metadata instanceof stdClass) {
                $warnings[] = 'metadata must be a mapping of strings to strings.';
            } else {
                foreach (get_object_vars($metadata->metadata) as $value) {
                    if (!is_string($value)) {
                        $warnings[] = 'metadata must be a mapping of strings to strings.';
                        break;
                    }
                }
            }
        }

        return [
            'document' => [
                'name' => $name,
                'description' => $description,
                'body' => $parts['body'],
                'frontmatter' => $metadata,
            ],
            'warnings' => $warnings,
        ];
    }

    /** @return array{frontmatter: string, body: string}|null */
    private function extractParts(string $contents): ?array
    {
        if (preg_match('/\A(?:\xEF\xBB\xBF)?---[^\S\r\n]*\r?\n(.*?)\r?\n---[^\S\r\n]*(?:\r?\n|\z)(.*)\z/s', $contents, $matches) !== 1) {
            return null;
        }

        return [
            'frontmatter' => $matches[1]."\n",
            'body' => $matches[2],
        ];
    }

    /** @throws ParseException */
    private function parseMetadata(string $yaml): mixed
    {
        return Yaml::parse(
            $yaml,
            Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE,
        );
    }

    private function hasValidNameFormat(string $name): bool
    {
        $normalized = Normalizer::normalize($name, Normalizer::FORM_KC);

        return $normalized !== false
            && mb_strtolower($normalized, 'UTF-8') === $normalized
            && preg_match('/\A[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*\z/u', $normalized) === 1;
    }
}
