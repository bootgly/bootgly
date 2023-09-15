<?php
return [
   "/(@)?@while[ ]+?(.+?)[ ]?:/sx" => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      // @ <expression>
      $expression = $matches[2];

      return <<<PHP
      <?php while ({$expression}): ?>
      PHP;
   },
   "/(@)?@while[ ]?;/sx" => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      return <<<PHP
      <?php endwhile; ?>
      PHP;
   },
];
