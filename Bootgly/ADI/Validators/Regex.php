<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Validators;


use function array_pop;
use function in_array;
use function is_scalar;
use function ltrim;
use function preg_match;
use function str_contains;
use function strlen;
use function strrpos;
use function strspn;
use function substr;
use InvalidArgumentException;

use Bootgly\ADI\Validation\Condition;
use Bootgly\ADI\Validation\Descriptor;


class Regex extends Condition implements Descriptor
{
   // * Config
   public private(set) string $pattern;

   // * Metadata
   protected string $template = '{field} has an invalid format.';


   public function __construct (string $pattern, string $message = '')
   {
      if (@preg_match($pattern, '') === false) {
         throw new InvalidArgumentException('Invalid validation regex pattern.');
      }

      parent::__construct($message);

      // * Config
      $this->pattern = $pattern;
   }

   /**
    * @param array<string,mixed> $data
    */
   public function validate (string $field, mixed $value, array $data): bool
   {
      return is_scalar($value) && preg_match($this->pattern, (string) $value) === 1;
   }

   /**
    * JSON Schema: the pattern as an ECMA-262 `pattern` (it applies to
    * strings; another rule sets the `type`).
    *
    * The output is valid under the `u` flag JSON Schema validators compile
    * patterns with. The delimiters are dropped, `\A`, `\z`, `\Z` become
    * `^`, `$`, and an escaped punctuation character that is not ECMA-262
    * syntax (`\-`, `\#`, `\@` — what `preg_quote()` writes) becomes the
    * plain character (`\-` stays escaped inside a class). A pattern with a
    * modifier other than `u`/`D`, or a construct whose meaning differs or
    * that ECMA-262 lacks — `(?P`, `(?>`, `(*`, inline modifiers, possessive
    * quantifiers, a brace that is not a `{n}`/`{n,}`/`{n,m}` quantifier, a
    * lone `]`, POSIX classes, a leading `]` in a class, an escaped letter or
    * digit other than `\d \D \w \W \s \S \b \B \n \r \t \f`, `\cX`,
    * `\xHH`, `\p{Xx}` (a general category), `\k<name>` and a one-digit
    * backreference, a quantified lookaround — is not translated: `null`.
    * Without `u` PCRE matches bytes, so such a pattern must be ASCII (no
    * `\p`, no `\x80`–`\xFF`); a pattern that is not valid UTF-8 is refused.
    * Dialects still differ in details (`$` before a final newline, `.` and
    * `\r`, a backreference to a group that did not take part, and with `u`
    * PCRE's Unicode `\d`/`\w`/`\s`/`\b`): the server-side check stays PCRE.
    *
    * @return null|array<string,mixed>
    */
   public function describe (): null|array
   {
      // ! Delimiters and modifiers
      $pattern = ltrim($this->pattern);
      $delimiter = $pattern[0];
      $closing = match ($delimiter) {
         '(' => ')',
         '[' => ']',
         '{' => '}',
         '<' => '>',
         default => $delimiter,
      };
      $end = strrpos($pattern, $closing);
      if ($end === false || $end === 0) {
         return null;
      }
      $body = substr($pattern, 1, $end - 1);
      $modifiers = substr($pattern, $end + 1);

      // ? Only modifiers ECMA-262 needs no inline form for
      if (strspn($modifiers, 'uD') !== strlen($modifiers)) {
         return null;
      }

      // ? Bytes: without `u` PCRE matches bytes, so only ASCII reads the same; never invalid UTF-8
      $unicode = str_contains($modifiers, 'u');
      if (preg_match($unicode ? '//u' : '/\A[\x00-\x7F]*\z/', $body) !== 1) {
         return null;
      }

      // @@ Translate token by token
      $ECMA = '';
      $class = false;
      // ! Open groups: whether each is a lookaround (ECMA-262 refuses a quantified one under `u`)
      $groups = [];
      $length = strlen($body);
      for ($index = 0; $index < $length; $index++) {
         $character = $body[$index];
         $next = $index + 1 < $length ? $body[$index + 1] : '';

         // # Escapes
         if ($character === '\\') {
            // ? A trailing backslash
            if ($next === '') {
               return null;
            }

            $index++;

            // @ Anchors
            if ($class === false && $next === 'A') {
               $ECMA .= '^';
            }
            else if ($class === false && ($next === 'z' || $next === 'Z')) {
               $ECMA .= '$';
            }
            // @ Same meaning in both dialects
            else if (str_contains('^$\\.*+?()[]{}|/dDwWsSbBnrtf', $next) || ($class && $next === '-')) {
               $ECMA .= "\\{$next}";
            }
            // @ Control, hexadecimal, property and backreference escapes
            else if (
               preg_match('/\Gc[A-Za-z]/', $body, $match, 0, $index) === 1
               || preg_match($unicode ? '/\Gx[0-9A-Fa-f]{2}/' : '/\Gx[0-7][0-9A-Fa-f]/', $body, $match, 0, $index) === 1
               || ($unicode && preg_match('/\G[pP]\{(?:L[ultmo]?|LC|M[nce]?|N[dlo]?|P[cdseifo]?|S[mcko]?|Z[slp]?|C[cfsno]?)\}/', $body, $match, 0, $index) === 1)
               || ($class === false && preg_match('/\Gk<[A-Za-z_][A-Za-z0-9_]*>/', $body, $match, 0, $index) === 1)
               || ($class === false && preg_match('/\G[1-9](?![0-9])/', $body, $match, 0, $index) === 1)
            ) {
               $ECMA .= "\\{$match[0]}";
               $index += strlen($match[0]) - 1;
            }
            // ? Any other escaped letter or digit means something else (or nothing) in ECMA-262
            else if (preg_match('/\A[A-Za-z0-9]\z/', $next) === 1) {
               return null;
            }
            // @ Escaped punctuation: the plain character (ECMA-262 rejects the escape under `u`)
            else {
               $ECMA .= $next;
            }

            continue;
         }

         // # Character classes
         if ($class) {
            // ? POSIX classes
            if ($character === '[' && $next === ':') {
               return null;
            }
            if ($character === ']') {
               $class = false;
            }

            $ECMA .= $character;

            continue;
         }
         if ($character === '[') {
            $open = $next === '^' ? 2 : 1;

            // ? A leading `]` is a literal in PCRE, an empty class in ECMA-262
            if (($body[$index + $open] ?? '') === ']') {
               return null;
            }

            $class = true;
            $ECMA .= $character;

            continue;
         }

         // # Groups: only (?: (?= (?! (?<= (?<! (?<name>
         if ($character === '(' && $next === '?') {
            $kind = $body[$index + 2] ?? '';
            $after = $body[$index + 3] ?? '';
            $named = $kind === '<' && $after !== '=' && $after !== '!' && preg_match('/\A[A-Za-z_]/', $after) === 1;

            if (in_array($kind, [':', '=', '!'], true) === false && ($kind !== '<' || ($after !== '=' && $after !== '!' && $named === false))) {
               return null;
            }
         }

         // # Group bounds: a quantifier after a lookaround
         if ($character === '(') {
            $kind = $next === '?' ? ($body[$index + 2] ?? '') : '';
            $after = $body[$index + 3] ?? '';
            $groups[] = $kind === '=' || $kind === '!' || ($kind === '<' && ($after === '=' || $after === '!'));
         }
         else if ($character === ')') {
            $lookaround = array_pop($groups) ?? false;

            if ($lookaround && in_array($next, ['*', '+', '?', '{'], true)) {
               return null;
            }
         }

         // ? PCRE verbs, lone brackets
         if (($character === '(' && $next === '*') || $character === '}' || $character === ']') {
            return null;
         }

         // # Brace quantifiers: any other brace is a literal in PCRE, an error in ECMA-262
         if ($character === '{') {
            if (preg_match('/\G\{[0-9]+(?:,[0-9]*)?\}/', $body, $match, 0, $index) !== 1) {
               return null;
            }

            $index += strlen($match[0]) - 1;

            // ? Possessive
            if (($body[$index + 1] ?? '') === '+') {
               return null;
            }

            $ECMA .= $match[0];

            continue;
         }

         // ? Possessive quantifiers
         if ($next === '+' && in_array($character, ['*', '+', '?'], true)) {
            return null;
         }

         $ECMA .= $character;
      }

      // ? An unterminated class
      if ($class) {
         return null;
      }

      // :
      return ['pattern' => $ECMA];
   }
}
