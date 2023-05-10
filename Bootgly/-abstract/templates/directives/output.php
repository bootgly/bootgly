<?php
return [
   '/@>>\s*(.+?)\s*;(\r?\n)?/s' => function ($matches) {
      $wrapped = $matches[1];

      $whitespace = $matches[2] ?? '';

      return <<<PHP
      <?php echo {$wrapped}; ?>{$whitespace}
      PHP;
   }
];
