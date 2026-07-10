<?php
return [
   // Quote-aware argument scan: a `;` inside a string does not truncate it.
   // Arguments pass verbatim into the call, so named arguments work:
   // @translate 'msg', ['k' => $v], count: $n, domain: 'app', locale: 'pt-BR';
   '/(@)?@translate\s+((?:[^;\'"]++|\'(?:[^\'\\\\]|\\\\.)*+\'|"(?:[^"\\\\]|\\\\.)*+")+?)\s*;(\r?\n)?/s' => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      $arguments = $matches[2];

      $whitespace = $matches[3] ?? '';

      return <<<PHP
      <?php echo htmlspecialchars((string) \Bootgly\ABI\Data\Language::translate({$arguments}), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>{$whitespace}
      PHP;
   },
];
