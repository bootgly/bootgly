<?php
return [
   // (?!>) keeps `@>>` (escaped output) out of this pattern's reach.
   // The expression is scanned quote-aware so a `;` inside a string literal
   // does not terminate the directive (e.g. `@> "a;b";`).
   '/(@)?@>(?!>)\s*((?:[^;\'"]++|\'(?:[^\'\\\\]|\\\\.)*+\'|"(?:[^"\\\\]|\\\\.)*+")+?)\s*;(\r?\n)?/s' => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      $wrapped = $matches[2];

      $whitespace = $matches[3] ?? '';

      return <<<PHP
      <?php echo {$wrapped}; ?>{$whitespace}
      PHP;
   },
];
