<?php
return [
   "/(@)?(@if)[ ]+?(.+?)[ ]?:/sx" => function ($matches) {
      if ($matches[1]) {
         return substr($matches[0], 1);
      }

      // @ Conditional
      $conditional = $matches[3];

      return <<<PHP
      <?php if ({$conditional}): ?>
      PHP;
   },
   "/(@)?(@elseif)[ ]+?(.+?)[ ]?:/sx" => function ($matches) {
      if ($matches[1]) {
         return substr($matches[0], 1);
      }

      // @ Conditional
      $conditional = $matches[3];

      return <<<PHP
      <?php elseif ({$conditional}): ?>
      PHP;
   },
   "/(@)?(@else)[ ]?:/sx" => function ($matches) {
      if ($matches[1]) {
         return substr($matches[0], 1);
      }

      return <<<PHP
      <?php else: ?>
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
