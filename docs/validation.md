# Skill document validation

The internal parser uses Symfony YAML 6.4 and separates loading eligibility from
format warnings. The toolkit snapshots discovery when constructed. Resources and
instruction files are read lazily; recreate the toolkit to refresh the catalog.

## Loading policy

A document must begin with `---` frontmatter and have a closing `---` line.
Frontmatter must parse as a YAML mapping. Both `name` and `description` must be
nonempty UTF-8 strings, with some non-whitespace content and no NUL characters.
Missing fields, unusable types and unparseable YAML exclude a document. Expected
storage read failures also exclude it and retain their reason; unexpected failures
propagate.

Usable documents stay loadable when their metadata violates these checks:

| Field | Validation warning |
| --- | --- |
| `name` | More than 64 Unicode characters; uppercase or characters other than letters, numbers and single separating hyphens; mismatch with the storage identifier. |
| `description` | More than 1024 Unicode characters. |
| `compatibility` | Present but not a string of 1–500 characters. |
| `license`, `allowed-tools` | Present but not strings. |
| `metadata` | Present but not a mapping with string values. |

Name syntax is checked after NFKC normalization, following the reference
validator's Unicode interpretation. The declared name remains unchanged for
catalog identity and lookup. Lengths count Unicode code points, not bytes.
Markdown length recommendations are not enforced.

All optional fields and unknown extensions are retained in the parser result;
no metadata enables tools or grants permissions. Numeric metadata keys are
normalized to property strings by Symfony, whether originally quoted or not.
This is a deliberate tolerance; metadata values are never implicitly converted
to strings. Empty maps remain maps, distinct from empty sequences.

The repository selects the first usable candidate in alphabetical storage
identifier order for each declared name. Unusable candidates reserve no names.
A shadowed candidate produces a diagnostic. Reads always use the selected
storage identifier even when it differs from the declared name.

`SkillToolkit::diagnostics()` returns a list of `skill` (storage identifier) and
`message` entries, including exclusion reasons and tolerated warnings. It returns
an empty list when discovery finds no problems. These messages are never printed
or included in the agent prompt automatically. No logger or callback is needed.
An empty catalog adds neither tools nor guidelines. Only names and descriptions
enter the initial catalog.

## YAML verification

The [Agent Skills format](https://agentskills.io/specification) defines the
standard fields and authoring constraints. The
[client guide](https://agentskills.io/client-implementation/adding-skills-support)
recommends separating validation warnings from the ability to load useful skills.
The tests exercise these rules independently of adding a parsing dependency.

[Symfony's YAML documentation](https://symfony.com/doc/6.4/components/yaml.html)
mentions a subset of YAML, including a historical limitation on multiline quoted
strings. Fixtures against the installed 6.4 release verify single and double
quoted multiline strings, escapes, comments, literal and folded blocks, plain
continuations, flow mappings, explicit `!!str`, aliases and empty maps. Retaining
the final frontmatter newline is necessary for literal block chomping semantics.

Document streams and directives are outside a single delimited frontmatter
mapping. Compact block sequences cannot represent the required string fields or
string-to-string metadata. Symfony's PHP object and constant execution features
are not enabled. A dependency resolution with Composer's PHP platform set to
8.1.0 verifies that YAML and Unicode polyfills preserve the minimum PHP version;
tests run on the available local PHP runtime.
