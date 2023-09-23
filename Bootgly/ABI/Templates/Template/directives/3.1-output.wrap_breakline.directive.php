<?php
return [
   '/(@)?@\.>\.\s*(.+?)\s*;(\r?\n)?/s' => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      $wrapped = $matches[2];

      $whitespace = $matches[3] ?? '';

      return <<<PHP
      <?php echo PHP_EOL . {$wrapped} . PHP_EOL; ?>{$whitespace}
      PHP;
   },
];
