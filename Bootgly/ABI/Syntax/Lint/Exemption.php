<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Syntax\Lint;


use function explode;
use function ltrim;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function strcasecmp;
use function trim;
use InvalidArgumentException;

use Bootgly\ABI\Syntax\Analyzers\Issue;
use Bootgly\ABI\Syntax\Analyzers\Rules;


/**
 * One recorded waiver of a lint rule: the rule, where (a path glob), what
 * (an optional symbol) and why (a required reason).
 *
 * Exemptions live only in `bootgly.Lint.php` — there are no inline ignore
 * comments. The symbol narrows the waiver inside the matched files:
 *
 * - `name` — any reported symbol of that name (`fooBar`, `$url`);
 * - `Class::name` — that method of that class (`Assertion::assertTrue`);
 * - `Class::$name` — that property or variable inside that class.
 *
 * Class and method names compare case-insensitively, as PHP resolves them;
 * `$` names compare exactly. An exemption that waives nothing in a run that
 * judged it is stale, and the run fails until it is removed.
 */
class Exemption
{
   // * Config
   /** The rule this exemption waives */
   public readonly Rules $rule;
   /** The path glob, relative to the bootgly.Lint.php directory */
   public readonly string $path;
   /** Why the rule is waived here — required, never blank */
   public readonly string $reason;
   /** The symbol the waiver is narrowed to — null waives every issue of the rule in the matched files */
   public readonly null|string $symbol;


   /**
    * @param Rules $rule The rule this exemption waives
    * @param string $path A path glob relative to the bootgly.Lint.php directory
    * @param string $reason Why the rule is waived — required
    * @param null|string $symbol `name`, `Class::name` or `Class::$name`; null for the whole path
    *
    * @throws InvalidArgumentException When the reason is blank or the symbol is malformed
    */
   public function __construct (Rules $rule, string $path, string $reason, null|string $symbol = null)
   {
      // ? A waiver without a reason is a silenced rule
      if (trim($reason) === '') {
         throw new InvalidArgumentException(
            "Lint exemption for '{$rule->value}' at '{$path}' needs a reason."
         );
      }
      // ? A symbol is one name, optionally qualified by its class
      $name = '\$?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*';
      if ($symbol !== null && preg_match("/^(?:[A-Za-z_\\x80-\\xff][A-Za-z0-9_\\x80-\\xff]*::)?{$name}$/", $symbol) !== 1) {
         throw new InvalidArgumentException(
            "Lint exemption symbol '{$symbol}' must be name, Class::name or Class::\$name."
         );
      }

      // * Config
      $this->rule = $rule;
      $this->path = $path;
      $this->reason = $reason;
      $this->symbol = $symbol;
   }

   /**
    * Tell whether this exemption covers an issue, by symbol. The rule and the
    * path are matched by the caller (`Lint::select()`).
    *
    * @param Issue $Issue The reported issue
    * @param string $class The short name of the class-like declaration that encloses the issue, '' when none
    *
    * @return bool
    */
   public function cover (Issue $Issue, string $class): bool
   {
      // ?: No symbol — the whole path is waived
      if ($this->symbol === null) {
         return true;
      }

      // ! `Class::name` → the class to match, then the name
      $owner = null;
      $name = $this->symbol;
      if (str_contains($name, '::')) {
         [$owner, $name] = explode('::', $name, 2);
      }

      // ?: Another class
      if ($owner !== null && strcasecmp($owner, $class) !== 0) {
         return false;
      }

      // :
      if (str_starts_with($name, '$')) {
         return ltrim($Issue->symbol, '$') === ltrim($name, '$');
      }

      return strcasecmp($name, $Issue->symbol) === 0;
   }
}
