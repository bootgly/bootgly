<?php
return [
   // Quote-aware expression scan: a `;` inside a string does not truncate it
   '/(@)?@>>\s*((?:[^;\'"]++|\'(?:[^\'\\\\]|\\\\.)*+\'|"(?:[^"\\\\]|\\\\.)*+")+?)\s*;(\r?\n)?/s' => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      $wrapped = $matches[2];

      $whitespace = $matches[3] ?? '';

      return <<<PHP
      <?php echo htmlspecialchars((string) ({$wrapped}), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>{$whitespace}
      PHP;
   },
];
