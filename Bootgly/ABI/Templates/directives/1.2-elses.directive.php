<?php
return [
   "/(@)?@else[\s]?if[\s]+?(.+?)[\s]?:/sx" => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      // @ Conditional
      $conditional = $matches[2];

      return <<<PHP
      <?php elseif ({$conditional}): ?>
      PHP;
   },
   "/(@)?@else[ ]?:/sx" => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      return <<<PHP
      <?php else: ?>
      PHP;
   },
];
