<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

// The style rules `bootgly lint check` enforces on your projects — every rule
// is on. Run it from a project (`cd projects/<Name>`): `bootgly lint check`
// checks, `--fix` rewrites what it can (imports, nullable types, spacing).
//
// The paths, excludes and exemption globs are relative to this `projects/`
// directory. A rule you must break somewhere is waived here, never inline —
// each exemption names the rule, the path and the reason:
//
//    new Exemption(
//       rule: Rules::Casing,
//       path: 'Shop/Models/**',
//       reason: 'Column names mirror the legacy schema',
//       symbol: '$sku_id'
//    ),
//
// An exemption that no longer waives anything fails the run until removed.
// `bootgly kit boot` creates this file only where none exists: it is yours.

use Bootgly\ABI\Syntax\Analyzers\Rules;
use Bootgly\ABI\Syntax\Lint;
use Bootgly\ABI\Syntax\Lint\Exemption;


return new Lint(
   version: 1,
   paths: ['.'],
   excludes: [
      '**/vendor/**',
      '**/node_modules/**',
      '**/tests/fixtures/**',
   ],
   rules: [
      Rules::Imports,
      Rules::Nullables,
      Rules::Promotions,
      Rules::Methods,
      Rules::Spacing,
      Rules::Casing,
      Rules::Sections,
   ],
   Exemptions: [
   ]
);
