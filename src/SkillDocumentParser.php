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
        if (preg_match('/\A(?:\xEF\xBB\xBF)?---[^\S\r\n]*\r?\n(.*?)\r?\n---[^\S\r\n]*(?:\r?\n|\z)(.*)\z/s', $contents, $matches) !== 1) {
            return ['document' => null, 'warnings' => ['SKILL.md must begin with YAML frontmatter delimited by ---.']];
        }

        try {
            $metadata = $this->parseYaml($matches[1]."\n");
        } catch (ParseException $exception) {
            return ['document' => null, 'warnings' => ['Unparseable YAML: '.$exception->getMessage()]];
        }
        if (!$metadata instanceof stdClass) {
            return ['document' => null, 'warnings' => ['Frontmatter must be a YAML mapping.']];
        }

        $warnings = [];
        foreach (['name', 'description'] as $field) {
            $value = $metadata->{$field} ?? null;
            if (!is_string($value) || preg_match('//u', $value) !== 1 || preg_match('/\S/u', $value) !== 1 || str_contains($value, "\0")) {
                $warnings[] = $field.' must be a non-empty UTF-8 string.';
            }
        }
        if ($warnings !== []) {
            return ['document' => null, 'warnings' => $warnings];
        }

        $name = $metadata->name;
        $description = $metadata->description;
        $normalized = Normalizer::normalize($name, Normalizer::FORM_KC);
        if ($normalized === false || mb_strtolower($normalized, 'UTF-8') !== $normalized
            || preg_match('/\A[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*\z/u', $normalized) !== 1) {
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
            'document' => ['name' => $name, 'description' => $description, 'body' => $matches[2], 'frontmatter' => $metadata],
            'warnings' => $warnings,
        ];
    }
    private function parseYaml(string $yaml): mixed
    {
        $entries = [];
        $yaml = $this->normalizeFlowKeys($yaml, $entries);
        $flags = Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE;
        while (true) {
            try {
                return $this->restoreExplicitKeys(Yaml::parse($yaml, $flags), $entries);
            } catch (ParseException $exception) {
                // Symfony identifies the syntax node, so scalar contents are never rewritten.
                $lines = explode("\n", str_replace("\r\n", "\n", $yaml));
                $start = $exception->getParsedLine() - 1;
                if (isset($lines[$start]) && preg_match('/^( *):(?:[ \t]|$)/', $lines[$start], $valueMatch) === 1) {
                    // A colon inside a quoted explicit key can fool Symfony's implicit-key matcher.
                    for ($candidate = $start - 1; $candidate >= 0; --$candidate) {
                        if (trim($lines[$candidate]) === '' || str_starts_with(ltrim($lines[$candidate]), '#')
                            || strlen($lines[$candidate]) - strlen(ltrim($lines[$candidate], ' ')) > strlen($valueMatch[1])) {
                            continue;
                        }
                        if (str_starts_with($lines[$candidate], $valueMatch[1].'? ')) {
                            $start = $candidate;
                        }
                        break;
                    }
                }
                if (!isset($lines[$start]) || preg_match('/^( *)(?:\?)(?:[ \t]+(.*)|$)/', $lines[$start], $match) !== 1) {
                    throw $exception;
                }
                $indent = $match[1];
                for ($end = $start + 1; $end < count($lines); ++$end) {
                    $line = $lines[$end];
                    if (preg_match('/^'.preg_quote($indent, '/').':(?:[ \t]|$)/', $line) === 1) {
                        break;
                    }
                    if (trim($line) !== '' && !str_starts_with(ltrim($line), '#')
                        && strlen($line) - strlen(ltrim($line, ' ')) <= strlen($indent)) {
                        break;
                    }
                }
                $hasValue = isset($lines[$end]) && preg_match('/^'.preg_quote($indent, '/').':(?:[ \t]|$)/', $lines[$end]) === 1;
                // Keep key nodes in the document so Symfony resolves anchors in their original scope.
                [$keyMarker, $valueMarker] = $this->explicitEntry($yaml, $entries);
                $lines[$start] = $indent.$keyMarker.': '.($match[2] ?? '');
                if ($hasValue) {
                    $lines[$end] = $indent.$valueMarker.substr($lines[$end], strlen($indent));
                } else {
                    array_splice($lines, $end, 0, [$indent.$valueMarker.': null']);
                }
                $yaml = implode("\n", $lines);
            }
        }
    }

    /**
     * @param array<string, string> $entries
     * @return array{string, string}
     */
    private function explicitEntry(string $yaml, array &$entries): array
    {
        $marker = '__skill_explicit_'.count($entries);
        while (str_contains($yaml, $marker)) {
            $marker .= '_';
        }
        $entries[$marker.'_key'] = $marker.'_value';
        return [$marker.'_key', $marker.'_value'];
    }

    /** @param array<string, string> $entries */
    private function restoreExplicitKeys(mixed $node, array $entries): mixed
    {
        if (is_array($node)) {
            return array_map(fn (mixed $value): mixed => $this->restoreExplicitKeys($value, $entries), $node);
        }
        if (!$node instanceof stdClass) {
            return $node;
        }
        $result = new stdClass();
        foreach (get_object_vars($node) as $key => $value) {
            if (in_array($key, $entries, true)) {
                continue;
            }
            if (isset($entries[$key])) {
                if (!is_scalar($value) && $value !== null) {
                    throw new ParseException('Mapping keys must be scalars.');
                }
                $actualKey = (string) $value;
                $value = $node->{$entries[$key]};
            } else {
                $actualKey = (string) $key;
            }
            if (property_exists($result, $actualKey)) {
                throw new ParseException('Duplicate key "'.$actualKey.'" detected.');
            }
            $result->{$actualKey} = $this->restoreExplicitKeys($value, $entries);
        }
        return $result;
    }

    /**
     * Normalize explicit entries without interpreting their scalar nodes.
     * @param array<string, string> $entries
     */
    private function normalizeFlowKeys(string $yaml, array &$entries): string
    {
        $lines = explode("\n", $yaml);
        $collections = [];
        $quote = null;
        $scalarIndent = null;
        $nodeStart = true;
        foreach ($lines as $lineIndex => $line) {
            $indent = strlen($line) - strlen(ltrim($line, ' '));
            if ($scalarIndent !== null && (trim($line) === '' || $indent > $scalarIndent)) {
                continue;
            }
            $scalarIndent = null;
            $quotedNode = false;
            $inValue = false;
            $plainValue = false;
            if ($collections === [] && $quote === null) {
                $nodeStart = true;
            }
            for ($index = $indent; $index < strlen($line); ++$index) {
                $char = $line[$index];
                $next = $line[$index + 1] ?? '';
                if ($quote !== null) {
                    if ($quote === '"' && $char === '\\') {
                        ++$index;
                    } elseif ($char === $quote) {
                        if ($quote === "'" && $next === "'") {
                            ++$index;
                        } else {
                            $quote = null;
                            $quotedNode = true;
                        }
                    }
                    continue;
                }
                if ($char === '#' && ($index === 0 || ctype_space($line[$index - 1]))) {
                    break;
                }
                if (ctype_space($char)) {
                    continue;
                }
                if ($nodeStart && ($char === "'" || $char === '"')) {
                    $quote = $char;
                    $nodeStart = false;
                    continue;
                }
                if ($nodeStart && ($char === '!' || $char === '&')) {
                    while ($index + 1 < strlen($line) && !ctype_space($line[$index + 1])) {
                        ++$index;
                    }
                    continue;
                }
                if ($nodeStart && ($char === '{' || $char === '[')) {
                    $collections[] = ['map' => $char === '{', 'key' => true];
                    continue;
                }
                $last = array_key_last($collections);
                if ($last !== null) {
                    if (($char === '}' || $char === ',') && isset($collections[$last]['explicit'])) {
                        $insertion = ', '.$collections[$last]['explicit'].': null';
                        $line = substr_replace($line, $insertion, $index, 0);
                        $index += strlen($insertion);
                        unset($collections[$last]['explicit']);
                    }
                    if ($char === '}' || $char === ']') {
                        array_pop($collections);
                        $nodeStart = false;
                        continue;
                    }
                    if ($char === ',') {
                        $collections[$last]['key'] = true;
                        $nodeStart = true;
                        continue;
                    }
                    if ($char === '?' && $nodeStart && $collections[$last]['map']
                        && $collections[$last]['key'] && ($next === '' || ctype_space($next))) {
                        [$keyMarker, $valueMarker] = $this->explicitEntry($yaml, $entries);
                        $collections[$last]['explicit'] = $valueMarker;
                        $replacement = $keyMarker.': ';
                        $line = substr_replace($line, $replacement, $index, 1);
                        $index += strlen($replacement) - 1;
                        continue;
                    }
                }
                if ($char === ':' && ($quotedNode || $next === '' || ctype_space($next) || ($last !== null && str_contains('{}[],', $next)))) {
                    if ($last !== null) {
                        if (isset($collections[$last]['explicit'])) {
                            $replacement = ', '.$collections[$last]['explicit'].': ';
                            $line = substr_replace($line, $replacement, $index, 1);
                            $index += strlen($replacement) - 1;
                            unset($collections[$last]['explicit']);
                        }
                        $collections[$last]['key'] = false;
                    }
                    $nodeStart = true;
                    $quotedNode = false;
                    $inValue = true;
                    $plainValue = false;
                    continue;
                }
                if ($nodeStart && $last === null && ($char === '?' || $char === '-') && ctype_space($next)) {
                    continue;
                }
                if ($nodeStart && $last === null && ($char === '|' || $char === '>')) {
                    $scalarIndent = $indent;
                    break;
                }
                if ($last === null && $inValue) {
                    $plainValue = true;
                }
                $quotedNode = false;
                $nodeStart = false;
            }
            $lines[$lineIndex] = $line;
            if ($collections === [] && $quote === null && $plainValue) {
                $scalarIndent = $indent;
            }
        }
        return implode("\n", $lines);
    }
}
