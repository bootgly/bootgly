<?php
return [
   "/(@)?@if[ ]+?(.+?)[ ]?:/sx" => function ($matches) {
      if ($matches[1]) {
         return substr($matches[0], 1);
      }

      // @ Conditional
      $conditional = $matches[2];

      return <<<PHP
      <?php if ({$conditional}): ?>
      PHP;
   },
   "/(@)?@if[ ]?;/sx" => function ($matches) {
      if ($matches[1]) {
         return substr($matches[0], 1);
      }

      return <<<PHP
      <?php endif; ?>
      PHP;
   },
];
