## Summary

<!-- What changes and why. Link the issue: "Closes #123". -->

## Type

<!-- feat | fix | refactor | perf | test | docs | style | build | ci | chore — the same type as the commit(s). -->

## Checklist

- [ ] Commits follow Conventional Commits (`<type>(<scope>): <description>`)
- [ ] No third-party dependency added to the framework core
- [ ] Layer rules respected (`ABI → ACI → ADI → API → CLI → WPI`, one way, no skipping)
- [ ] Naming and style conventions followed (single-word verb methods, `null|T`, space before `(`, semantic comments)
- [ ] Tests added or updated in the native runner; a bug fix includes the test that reproduces the bug
- [ ] `vendor/bin/phpstan analyse -c @/phpstan.neon` prints `[OK] No errors`
- [ ] `bootgly lint imports` is clean and the touched suites pass (`bootgly test <suite>`)
- [ ] Documentation updated in `bootgly_docs` — both `en-US` and `pt-BR` — when behavior or public API changes
- [ ] Breaking change? Marked with `!` / `BREAKING CHANGE:` and targeted at the next major

## Notes for the reviewer

<!-- Anything that helps: design decisions, alternatives considered, how you verified it. -->
