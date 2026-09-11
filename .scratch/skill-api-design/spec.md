# Skill interface and standards compliance

Design status: approved by the user on 2026-09-11.
Implementation status: implemented on `implement/skill-api-design`; all three tickets resolved.
Implementation resumed at the user's request through `implement-spec`; this
supersedes the earlier pause on 2026-09-11.

## Agreed decisions

- Consumers configure `SkillToolkit` with a `SkillStorageInterface`.
- `SkillRepository` is internal and shared by the toolkit's tools.
- Reads return content or throw. Tools convert expected failures to model-readable
  messages; unexpected failures propagate. See
  [ADR-0001](../../docs/adr/0001-toolkit-owns-skill-access.md).
- Validate the fields defined by the official [Agent Skills specification](https://agentskills.io/specification).
  By subsequent user decision, YAML syntax support follows `symfony/yaml` as-is.
  Do not implement a custom YAML compatibility layer; document its limitations.
  This supersedes the original requirement to implement every valid YAML form.
- Rename the storage operation `packages()` to `list()` and its `$package`
  argument to `$skill`.
- Support discovering skills from multiple configured directories in the same
  toolkit. The previous single-root restriction is no longer sufficient.
- Accept multiple storage instances as `new SkillToolkit($primaryStorage,
  $fallbackStorage, ...)`. Each filesystem adapter owns one root; the internal
  repository merges their catalogs and retains the selected source for reads.
  The first configured storage takes precedence on duplicate declared names;
  alphabetical candidate order applies within each storage. Record shadowed
  skills in diagnostics. Callers put project storage before user storage to
  obtain the official guide's project-over-user precedence.
- Make scripts and assets usable through the host agent's tools. Do not add an
  independent script executor; execution and authorization belong to the host.
- Include the skill's location in the information supplied to the agent, so its
  existing tools can locate scripts and resolve relative resource paths. For
  filesystem storage, this is the skill directory path. Remote storage still
  needs an explicit host access contract; a local path must not be assumed.
- Separate validation diagnostics from loading eligibility, following the
  official client guide's lenient approach. Name/directory mismatches and
  overlong names produce warnings but do not prevent loading. Missing or empty
  descriptions and unparseable YAML prevent loading and produce diagnostics.
  The treatment of other malformed fields must be made explicit in the design.
- The internal repository collects loading diagnostics. Expose them through
  `SkillToolkit::diagnostics()` as an array of entries containing `skill` and
  `message`, or an empty array when there are none. The toolkit delegates access;
  it does not own validation. No automatic output, logger dependency or required
  callback is introduced in the first version.
- Use `symfony/yaml` as the YAML parsing dependency while retaining PHP 8.1
  support. An internal skill document parser separates frontmatter from Markdown,
  delegates YAML syntax to Symfony, and applies Agent Skills validation with
  diagnostics. The repository continues to coordinate discovery and reading.
  Accept the YAML subset provided by Symfony; validation of skill fields remains
  the responsibility of this parser.
- On activation, return the complete `SKILL.md`, including its original
  frontmatter, together with the skill's location. The initial catalog remains
  limited to name and description. Preserve optional metadata, including
  `allowed-tools`, without granting permissions or enabling tools implicitly.
- Resolve duplicate declared names with the agreed storage precedence first,
  then alphabetical candidate order. Only successfully loaded candidates reserve
  names, so an unreadable or unusable skill does not mask a usable fallback.

## Approved design

The public setup continues to support `new SkillToolkit($storage)`. The storage enumerates
skills and reads their files; the internal document parser interprets and
validates the document; the internal repository owns the catalog and diagnostics;
the tools format model-facing results.

For multiple directories, use the agreed variadic storage configuration. Keep
storage identifiers scoped to their originating storage, so identical directory
names in different roots do not accidentally address the wrong source.

Expose the agreed location behavior through
`location(string $skill): ?string` to `SkillStorageInterface`. This identifies the
skill's base location as understood by the host's tools, not necessarily a local
filesystem path. The filesystem adapter returns its canonical skill directory.
An adapter without a host-accessible location returns null; reads through the
skill tools still work, but the library must not claim that host tools can access
or execute its files. Remote provisioning and tool access belong to the host
integration, with no invented paths or implicit downloading.

Keep storage identifiers separate from frontmatter names internally so a
tolerated name/directory mismatch still reads from the correct source. Validate
all standard fields; use warnings for usable but nonconforming metadata, and skip
documents whose YAML or required identity/description cannot be interpreted.
Preserve the original document on activation, including optional and extension
fields, without using metadata as authorization. Document the detailed validation
policy alongside its tests.

The compliance checks must cover YAML forms, Unicode names, required and optional
fields, tolerated violations, diagnostics, name collisions, lazy resource access,
location reporting, and expected versus unexpected runtime failures. Test through
the toolkit and an actual Neuron tool loop as well as the parser's document cases.
Update the README and runnable example to the resulting public interface.

All three implementation tickets are resolved. The storage rename, YAML parser,
validation diagnostics, full-document activation, host location contract and
ordered multiple-storage selection are implemented. The detailed loading policy
and YAML compatibility checks are documented in
[validation.md](../../docs/validation.md).

## Implementation verification

- Local checks passed on PHP 8.5.8: Composer strict validation, 101 PHPUnit tests
  with 294 assertions, PHPStan and the runnable example. Dependency resolution
  targets PHP 8.1; the GitHub Actions matrix covers PHP 8.1 through 8.5.
- Standards review found no documented-rule violations. Its two maintenance
  observations were addressed by centralizing source lookup and using the
  storage interface directly in the test double.
- Spec review fixed numeric filesystem identifiers, which remain covered.
  The custom support for explicit YAML keys and their anchor/alias scope was subsequently removed at the user's
  request in favor of Symfony YAML as-is. Tests protect literal strings and
  original document content during activation.
- Actual Neuron tool loops use a deterministic provider to verify the content
  supplied to the model. The host-tool scenario executes the activated script
  file with its neighboring asset; it does not claim live-model inference.
- Script execution, authorization, binary access, remote provisioning and
  host-specific context management remain responsibilities of the host agent.
  The toolkit supplies text reads and the configured opaque location, and never
  enables tools from metadata or creates an execution environment.

## Research

- [Pi error handling](research/pi-error-handling.md)
- [Agent Skills conformance audit](research/agent-skills-conformance.md)
