# Bootgly projects — agent rules

<!-- Machine-managed: `bootgly kit boot` lays this file and `.agents/` down, and `kit upgrade` rewrites them. Do not edit. -->

Every directory here is an application built on the Bootgly framework this kit pins (`../Bootgly/`,
read-only). Before you write, review or answer questions about code under `projects/`, read the rule
files in `.agents/rules/` — one per section:

- `.agents/rules/Architecture_principles.md`
- `.agents/rules/Coding_styles.md`
- `.agents/rules/Naming_conventions.md`
- `.agents/rules/Organizational_structures.md`
- `.agents/rules/Testing_guidelines.md`
- `.agents/rules/Workflow_pipelines.md`

Each rule carries a tier: **MUST** — the framework, its tooling or the kit break, or the user's work is
put at risk, without it; **SHOULD** — the house style and working habits (`bootgly lint` checks part of
the style); **RECOMMEND** — judgement. An explicit instruction from the user overrides SHOULD and
RECOMMEND; before going against a MUST, tell the user what breaks. In the rules, paths starting with
`projects/`, `Bootgly/`, `Console/` or `Web/` are relative to the kit root; project paths (`configs/`,
`router/`, `tests/`, ...) to the project.

Operating the kit itself — install, create, import, upgrade and the non-interactive flags — is covered
by `../AGENTS.md`.

@.agents/rules/Architecture_principles.md
@.agents/rules/Coding_styles.md
@.agents/rules/Naming_conventions.md
@.agents/rules/Organizational_structures.md
@.agents/rules/Testing_guidelines.md
@.agents/rules/Workflow_pipelines.md
