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


use function count;
use function explode;
use function implode;
use function preg_match;
use function preg_quote;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;
use InvalidArgumentException;


/**
 * A path pattern of a `bootgly.Lint.php` — an exclude or an exemption path —
 * matched against paths relative to the file's directory.
 *
 * - `*` matches any run of characters inside one segment, `?` one character;
 * - a `**` segment matches zero or more whole segments (`**\/tests/**`);
 * - a trailing `/` matches the directory and everything below it (`vendor/`);
 * - otherwise the pattern matches the whole relative path, anchored at the
 *   file's directory (`Bootgly/ABI/Foo.php`, `Bootgly/*\/Foo.php`).
 *
 * A pattern is always relative and stays inside the tree: an absolute path,
 * a `..` segment, a backslash or a control character is refused at
 * construction — and so is a `.` or empty segment (`src/./gen/`,
 * `src//gen/`), which no matched path ever carries.
 */
class Glob
{
   // * Config
   /** The pattern as written */
   public readonly string $pattern;

   // * Metadata
   /** The anchored regular expression the pattern compiles to */
   public readonly string $regex;


   /**
    * @param string $pattern The relative path pattern
    *
    * @throws InvalidArgumentException When the pattern is empty, absolute, climbs out of the tree or carries a backslash, a control character or a `.` or empty segment
    */
   public function __construct (string $pattern)
   {
      // ? A pattern names something inside the tree, in `/` separators
      $normalized = str_starts_with($pattern, './') ? substr($pattern, 2) : $pattern;
      $reason = match (true) {
         $normalized === '' || $normalized === '/'
            => 'is empty',
         str_starts_with($normalized, '/')
            => 'is absolute — patterns are relative to the bootgly.Lint.php directory',
         str_contains($normalized, '\\')
            => 'carries a backslash — use / as the separator',
         preg_match('/[\x00-\x1F\x7F]/', $normalized) === 1
            => 'carries a control character',
         preg_match('#(^|/)\.\.(/|$)#', $normalized) === 1
            => 'climbs out of the tree with ..',
         preg_match('#(^|/)\.(/|$)#', $normalized) === 1 || str_contains($normalized, '//')
            => 'carries an empty or . segment — the paths it is matched against never do',
         default => null
      };
      if ($reason !== null) {
         throw new InvalidArgumentException("Lint pattern '{$pattern}' {$reason}.");
      }

      // * Config
      $this->pattern = $pattern;

      // * Metadata
      $this->regex = $this->compile($normalized);
   }

   /**
    * Tell whether a relative path matches the pattern.
    *
    * @param string $path A path relative to the bootgly.Lint.php directory, `/`-separated
    *
    * @return bool
    */
   public function match (string $path): bool
   {
      return preg_match($this->regex, $path) === 1;
   }

   /**
    * Compile the pattern into an anchored regular expression.
    */
   private function compile (string $pattern): string
   {
      // ! A trailing `/` names a directory: everything below it matches
      $directory = str_ends_with($pattern, '/');
      if ($directory) {
         $pattern = substr($pattern, 0, strlen($pattern) - 1);
      }

      $segments = explode('/', $pattern);
      $last = count($segments) - 1;

      // @@ Segment by segment — `**` spans segments, everything else stays in one
      $regex = '';
      foreach ($segments as $index => $segment) {
         if ($segment === '**') {
            // # Zero or more whole segments; at the end, anything below
            $regex .= $index === $last ? '.*' : '(?:[^/]+/)*';
            continue;
         }

         $parts = [];
         foreach (explode('*', $segment) as $literal) {
            $pieces = [];
            foreach (explode('?', $literal) as $chunk) {
               $pieces[] = preg_quote($chunk, '#');
            }
            $parts[] = implode('[^/]', $pieces);
         }
         $regex .= implode('[^/]*', $parts);

         if ($index !== $last) {
            $regex .= '/';
         }
      }

      // :
      return $directory
         ? "#^{$regex}/#s"
         : "#^{$regex}$#sD";
   }
}
