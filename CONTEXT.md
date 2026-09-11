# Agent Skills

Skills provide reusable task instructions and supporting resources to Neuron AI
agents, using the Agent Skills format.

## Language

**Skill**:
A named collection of task instructions and optional supporting resources,
described by a `SKILL.md` document.
_Avoid_: Package when referring to an individual skill.

**Skill document**:
The `SKILL.md` document containing YAML frontmatter and Markdown instructions.

**Skill metadata**:
The fields in a skill document's frontmatter, including its name and description.

**Skill resource**:
A supporting file belonging to a skill, such as a reference, script or asset.

**Skill catalog**:
The names and descriptions of skills available for an agent to discover before
loading their instructions.
