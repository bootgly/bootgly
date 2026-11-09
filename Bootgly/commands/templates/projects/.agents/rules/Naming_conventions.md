# Naming conventions

`bootgly lint check` flags multi-word method names and lowercase object or acronym names — the
rules below are what it cannot judge.

## Methods

- **SHOULD** — A method name is one verb in its base form: `boot()`, `render()`, `fetch()`, `check()` —
  never a noun, a state (`status()`, `loaded()`) or a past form. Magic methods and names imposed by an
  interface or a framework contract are exempt.
- **SHOULD** — A method that IS a loop or continuous process takes the `-ing` form: `reading()`,
  `routing()`, `monitoring()`. One discrete action keeps the base form.
- **SHOULD** — Put the specificity in the object, not in the name: `HTML->render()`, not `renderHTML()` —
  add a class when you need one.
- **MUST** — A method a framework or platform mapper dispatches to by name keeps the name it expects —
  a mapped controller's actions, for one: the platform's build skill lists them.
- **RECOMMEND** — Specific over generic: `execute()`, not `process()`.

## Properties and variables

- **SHOULD** — Object instances and collections of objects start uppercase (`$Request`, `$Posts`);
  acronyms are uppercase even for scalars (`$SQL`, `$URL`). The framework's own signatures use this
  casing — route and controller callbacks receive `$Request`, `$Response` and `$Router`.

## Entities

- **RECOMMEND** — Classes are nouns (abstract classes and collections plural); traits end in `-able` or
  `-ed` (`Loggable`); enums are plural nouns (`Modes`). Interfaces are nouns too, and take the `-ing`
  form only to avoid a clash with a same-named class in the same directory (class `Template` →
  interface `Templating`). Controllers are plural (`Posts`), models singular (`Post`).
