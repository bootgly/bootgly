<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Syntax\Analyzers;


/**
 * The style rules `bootgly lint check` enforces — one case per analyzer.
 *
 * A `bootgly.Lint.php` enables rules by listing these cases; an `Exemption`
 * names the case it waives. The backing value is the rule's name in reports
 * and in the deprecated `bootgly lint <rule>` subcommands.
 */
enum Rules: string
{
   /** Missing, unused, backslash-prefixed and misordered `use` imports (fixable) */
   case Imports = 'imports';
   /** The nullable shorthand `?T` instead of `null|T` (fixable) */
   case Nullables = 'nullables';
   /** Constructor property promotion (check-only) */
   case Promotions = 'promotions';
   /** Method names that are not one base-form verb: multi-word, past forms, nouns and predicates (check-only) */
   case Methods = 'methods';
   /** One space before a declaration's `(`, none before a call's (fixable) */
   case Spacing = 'spacing';
   /** Object, object-collection and acronym names of properties, parameters and variables (check-only) */
   case Casing = 'casing';
   /** Config/Data/Metadata property sections: order, membership and visibility (check-only) */
   case Sections = 'sections';
}
