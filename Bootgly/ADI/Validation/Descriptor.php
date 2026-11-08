<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Validation;


/**
 * Optional contract of a `Condition` that can be expressed as JSON Schema
 * (draft 2020-12) keywords, read by `Validation\Renderer`.
 *
 * A Condition that does not implement it — or whose `describe()` returns
 * `null` — is left out of the rendered schema and listed in the Renderer's
 * `omissions`; it is never rendered as "anything goes" without a trace.
 */
interface Descriptor
{
   /**
    * Describe this rule as JSON Schema keywords for the value it validates.
    *
    * Return the keywords the value must satisfy (`['type' => 'integer']`,
    * `['minimum' => 1, 'minLength' => 1]`, …) — keywords of other instance
    * types are dropped once the value's `type` is known. The entry
    * `'required' => true` is not a keyword: it marks the value as required
    * and non-blank, so the Renderer lists it in its parent's `required`.
    * Return `null` when the rule cannot be expressed.
    *
    * @return null|array<string,mixed> The keywords, or `null` when the rule is not expressible.
    */
   public function describe (): null|array;
}
