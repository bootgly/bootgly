# Workflow pipelines

## Think before coding

- **SHOULD** — State your assumptions; when a request allows several readings (CLI or WPI? which
  database?), present them instead of picking one silently; say so when a simpler approach exists; stop
  and ask when something is unclear.
- **SHOULD** — Search before inventing, in this order: the CLI scaffolders; the shipped examples in
  `projects/`; the documentation (any page of https://docs.bootgly.com as Markdown by appending `.md`,
  the index at https://docs.bootgly.com/llms.txt, or the MCP server at https://docs.bootgly.com/mcp);
  the pinned source under `Bootgly/` (and `Console/`, `Web/`).
- **MUST** — Never guess a Bootgly signature, named argument, enum case or configuration key. The kit
  pins one release; the documentation describes the latest. When they disagree, the pinned source wins.
- **RECOMMEND** — Split multi-step work into steps you can verify one by one (config → migration →
  model → routes → tests); a step is done when its checks pass.

## Operating the kit

- **SHOULD** — Start your agent in the kit root or in `projects/`: from inside `projects/<Name>/`, most
  agents stop at the project's own repository and never see these rules.
- **MUST** — The kit is a delivery vehicle: never commit, stage, push or discard anything in its own
  repository or its submodules (the project repositories under `projects/` are yours to work in).
  Read-only git (`status`, `log`, `diff`) is fine anywhere. When a `bootgly kit …` message asks for a git
  action, show it to the user and let them choose.
- **MUST** — A project created from scratch is its own git repository; a `--from` copy, a shipped
  example and a `--no-git` project are not until `project <Name> boot`. Before any git write, check that
  `git rev-parse --show-toplevel` prints the project directory; anything else (the kit root, a parent
  repository, an error) — stop and ask.
- **MUST** — Update the kit only with `bootgly kit upgrade` or `kit downgrade` — never `git pull`, never
  `git submodule update --remote`.
- **MUST** — Never edit `Bootgly/`, `Console/` or `Web/`; never edit `projects/Bootgly.projects.php`,
  `projects/AGENTS.md` or `projects/.agents/` — the tooling rewrites them; never edit the kit's own
  `AGENTS.md` — a changed tracked file blocks the next `kit upgrade`.
- **MUST** — Never create a `CLAUDE.md` in the kit root or in `projects/` (and never run `/init` there):
  its presence stops Claude Code from reading these `AGENTS.md` files. If a client cannot read `AGENTS.md`
  natively, a `CLAUDE.md` holding only `@AGENTS.md` is the one exception. A project of yours that wants
  its own `CLAUDE.md` starts it with `@../AGENTS.md` (one more `../` per nested segment).
- **MUST** — Run the kit's own launcher: `bootgly` when the global wrapper is installed, otherwise
  `php ../bootgly` from `projects/` and `php ../../bootgly` from `projects/<Name>/` (one more `../` per
  nested segment). In the `bootgly/bootgly.kit` image: `docker exec -w /bootgly/projects/<Name> <container> bootgly …`.
- **MUST** — Found a framework bug? Work around it in the project and draft the report for the user
  to file upstream — never patch `Bootgly/`, and never file it yourself.

## Secrets and commits

- **MUST** — Never hard-code credentials: bind them to environment keys with `Config`
  (`->bind(key: 'DB_PASSWORD', default: '')`), and never commit a `configs/**/.env` file.
- **SHOULD** — Commit only when the user asks; an approval covers that commit only; never push unless asked.
- **RECOMMEND** — Follow the project's commit convention; with none, use Conventional Commits
  (`feat(cart): add item quantities`).
- **RECOMMEND** — Document the public API of reusable classes with PHPDoc, array shapes included.
