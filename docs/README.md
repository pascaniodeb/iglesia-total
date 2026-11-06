# Project docs

Hard rules for this project. They are binding: if code or a change conflicts with a rule here, either fix the code or change the rule explicitly (with the project owner's agreement), never ignore it.

| File                                   | Scope                                          |
| -------------------------------------- | ---------------------------------------------- |
| [rules/workflow.md](rules/workflow.md) | How we work: inline, quality gates, commits    |
| [rules/stack.md](rules/stack.md)       | Stack, tooling, database, environment          |
| [rules/frontend.md](rules/frontend.md) | SSR, TypeScript, design system, UI conventions |

## Adding a rule

1. Put it in the file for its scope (create a new one under `rules/` only if none fits, and list it above).
2. Write it as an imperative, one line, with the reason when it is not obvious.
3. Only record decisions that were agreed. No aspirational rules.
