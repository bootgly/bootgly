# Naming conventions

## Methods

- **SHOULD** — A method name is one verb in its base form: `boot()`, `render()`, `fetch()`, `check()`.
  Never a noun or a state (`status()`, `loaded()`), never a past or participle form, never several
  words (`getUserData()`, `renderHTML()`). `bootgly lint methods <path>` flags multi-word names. Magic
  methods and names imposed by an interface or a framework contract are exempt.
- **SHOULD** — A method that IS a loop or continuous process takes the `-ing` form: `reading()`,
  `routing()`, `monitoring()`. One discrete action keeps the base form.
- **SHOULD** — Put the specificity in the object, not in the name: `HTML->render()`, not `renderHTML()` —
  add a class when you need one.
- **MUST** — A controller mapped with `Web\App\Controllers::map()` (the `Web/` platform package) names
  its actions `list`, `show`, `create`, `edit`, `update` and `delete` — the router looks for exactly those.
- **RECOMMEND** — Specific over generic: `execute()`, not `process()`.

## Properties and variables

- **SHOULD** — Object instances and collections of objects start uppercase: `$Request`, `$Post`,
  `$Posts`, `public Connection $Connection`. The framework's own signatures use this casing — route
  and controller callbacks receive `$Request`, `$Response` and `$Router`.
- **SHOULD** — Acronyms are always uppercase, even for scalars: `$SQL`, `$URL`, `$HTML`, `$JSON` —
  never `$sql` or `$url`.

## Entities

- **RECOMMEND** — Classes are nouns (abstract classes and collections plural); interfaces end in `-ing`
  (`Logging`); traits in `-able` or `-ed` (`Loggable`); enums are plural nouns (`Modes`). Controllers
  are plural (`Posts`), models singular (`Post`).
