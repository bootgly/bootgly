<?php
return [
   // (quoted strings are consumed whole and `::` never terminates, so `:`
   //  inside strings and class constants survive; the opener is a single `:`)
   '/(@)?@switch[\s]+?((?:[^:\'"]++|::|\'(?:[^\'\\\\]|\\\\.)*+\'|"(?:[^"\\\\]|\\\\.)*+")+?)[\s]?(?<!:):(?!:)/sx' => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      // @ Conditional
      $conditional = $matches[2];

      return <<<PHP
      <?php switch ({$conditional}): ?>
      PHP;
   },

   '/(@)?@case[\s]+?((?:[^:\'"]++|::|\'(?:[^\'\\\\]|\\\\.)*+\'|"(?:[^"\\\\]|\\\\.)*+")+?)[\s]?(?<!:):(?!:)/sx' => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      // @ Conditional
      $conditional = $matches[2];

      return <<<PHP
      <?php case {$conditional}: ?>
      PHP;
   },
   "/(@)?@default[\s]*:/sx" => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      return <<<PHP
      <?php default: ?>
      PHP;
   },

   "/(@)?@switch[ ]?;/sx" => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      return <<<PHP
      <?php endswitch; ?>
      PHP;
   },
];
