<?php
return [
   "/(@while)[ ]+?(.+?)[ ]?:/sx" => function ($matches) {
      // @ <expression>
      $expression = $matches[2];

      return <<<PHP
      <?php while ({$expression}): ?>
      PHP;
   },
   "/@while[ ]?;/sx" => function () {
      return <<<PHP
      <?php endwhile; ?>
      PHP;
   }
];
