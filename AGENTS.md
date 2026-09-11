## Agent skills

The skills this repository relies on are pinned in `skills-lock.json`. Restore
them in a fresh checkout with `npx skills experimental_install`.

### Issue tracker

Issues are tracked as local Markdown files under `.scratch/`. See `docs/agents/issue-tracker.md`.

### Triage labels

Triage uses the five default canonical labels. See `docs/agents/triage-labels.md`.

### Domain docs

Domain documentation uses the single-context layout. See `docs/agents/domain.md`.

## Coding standards

When writing or reviewing code, follow [docs/coding-standards.md](docs/coding-standards.md).

## Releases

On a release branch `M.N.x`, publish the next patch tag in that same series,
using the highest published `M.N.P` tag to determine the next patch number.
For example, `0.8.x` with latest tag `0.8.3` releases `0.8.4`; `0.9.x` with
latest tag `0.9.0` releases `0.9.1`. The branch determines the series even when
changes break compatibility. A different series requires an explicit user
request.

## Authorship

Commits, pull requests and comments credit the human author alone. Write the
message and stop there: no `Co-Authored-By` trailer, no "generated with" footer,
no coding-agent attribution in any form.
