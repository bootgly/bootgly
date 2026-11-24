# Naming conventions

`bootgly lint check` flags method names that are not one base-form verb and lowercase object or
acronym names — the rest is what it cannot judge.

## Methods

- **SHOULD** — A method name is one verb in its base form, even where lint's word list misses the noun or
  state (`status()`, `exists()`). Magic methods and names an interface or a contract imposes are exempt.
- **SHOULD** — A method that IS a loop or continuous process takes the `-ing` form: `reading()`,
  `routing()`, `monitoring()`. One discrete action keeps the base form.
- **SHOULD** — Put the specificity in the object, not in the name: `HTML->render()`, not `renderHTML()` —
  add a class when you need one.
- **MUST** — A method a framework or platform mapper dispatches to by name keeps the name it expects —
  a mapped controller's actions, for one: the platform's build skill lists them.
- **RECOMMEND** — Specific over generic: `execute()`, not `process()`.

## Properties and variables

- **SHOULD** — A local collection of objects starts uppercase too (`$Posts = [$Post]`): lint types only
  properties, parameters and `new` assignments. Callbacks receive `$Request`, `$Response` and `$Router`.

## Entities

- **RECOMMEND** — Classes are nouns (abstract classes and collections plural); traits end in `-able` or
  `-ed` (`Loggable`); enums are plural nouns (`Modes`). Interfaces are nouns too, and take the `-ing`
  form only to avoid a clash with a same-named class in the same directory (class `Template` →
  interface `Templating`). Controllers are plural (`Posts`), models singular (`Post`).
