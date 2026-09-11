# Configure skill access through the toolkit

Consumers pass a `SkillStorageInterface` to `SkillToolkit`; the toolkit creates
the shared `SkillRepository` and its tools. The repository is internal because
catalog construction and document reading are library responsibilities, while
the storage is the supported point of customization. Tool classes remain
available for toolkit selection and configuration; their constructors are
internal, and direct repository access is not a supported consumer interface.

Reads return content or throw; tools convert expected `ToolException` failures
into model-readable results and let unexpected exceptions propagate. Neuron AI
3.16.13 rethrows unhandled tool errors by default, so this conversion belongs in
our tools rather than relying on an application error handler. Discovery still
omits invalid or unreadable skill documents; parser behavior and broader YAML
support are unchanged by this decision.
