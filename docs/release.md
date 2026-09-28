# Releases

On a release branch `M.N.x`, publish the next patch tag in that same series,
using the highest published `M.N.P` tag to determine the next patch number.
For example, `0.8.x` with latest tag `0.8.3` releases `0.8.4`; `0.9.x` with
latest tag `0.9.0` releases `0.9.1`. The branch determines the series even when
changes break compatibility. A different series requires an explicit user
request.

## GitHub release notes

When creating or editing release notes:

1. Compare the release with the previous published tag. Account for every
   user-visible feature and API change, including changes introduced by required
   dependencies.
2. Write for developers using the library. Describe new capabilities and how to
   use them. Identify breaking API changes explicitly and provide migration
   instructions with before/after code where useful. Include changed defaults,
   dependency requirements and persisted-data migrations when they affect
   consumers. Focus on observable behavior and integration requirements.
3. Show the complete proposed text in the conversation and obtain approval
   before publishing or updating it on GitHub. Publish the approved text, then
   read it back to verify that it matches.
