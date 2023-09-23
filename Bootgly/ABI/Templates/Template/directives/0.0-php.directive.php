<?php
return [
   "/(@)?@:(\s)?/sx" => function ($matches) {
      if ($matches[1]) {
         return substr($matches[0], 1);
      }

      $spaces = $matches[2] ?? '';

      return <<<PHP
      <?php$spaces
      PHP;
   },
   "/(@)?@;/sx" => function ($matches) {
      if ($matches[1]) {
         return substr($matches[0], 1);
      }

      return <<<PHP
      ?>
      PHP;
   },
];
