<?php
return [
   "/(@if)[ ]+?(.+?)[ ]?:/sx" => function ($matches) {
      // @ Conditional
      $conditional = $matches[2];

      return <<<PHP
      <?php if ({$conditional}): ?>
      PHP;
   },
   "/(@elseif)[ ]+?(.+?)[ ]?:/sx" => function ($matches) {
      // @ Conditional
      $conditional = $matches[2];

      return <<<PHP
      <?php elseif ({$conditional}): ?>
      PHP;
   },
   "/(@else)[ ]?:/sx" => function () {
      return <<<PHP
      <?php else: ?>
      PHP;
   },
   "/@if[ ]?;/sx" => function () {
      return <<<PHP
      <?php endif; ?>
      PHP;
   }
];
