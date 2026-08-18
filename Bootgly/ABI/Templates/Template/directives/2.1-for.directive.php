<?php
return [
   // (quoted strings are consumed whole and `::` never terminates, so `:`
   //  inside strings and class constants survive; the opener is a single `:`)
   '/(@)?@for[ ]+?((?:[^:\'"]++|::|\'(?:[^\'\\\\]|\\\\.)*+\'|"(?:[^"\\\\]|\\\\.)*+")+?)[ ]?(?<!:):(?!:)/sx' => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      // @ ...<expressions>
      $expressions = trim($matches[2], '()');

      return <<<PHP
      <?php for ({$expressions}): ?>
      PHP;
   },
   "/(@)?@for[ ]?;/sx" => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      return <<<PHP
      <?php endfor; ?>
      PHP;
   },
];
