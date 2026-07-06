<?php
return [
   // With explicit data (explicit keys win): @include name with [...];
   // (the payload consumes quoted strings whole, so `;` inside them survives)
   '/(@)?@include[ ]+?([\w\/-]+)[ ]+with[ ]+((?:[^;\'"]++|\'(?:[^\'\\\\]|\\\\.)*+\'|"(?:[^"\\\\]|\\\\.)*+")+?)[ ]?;/sx' => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      $template = $matches[2];
      $variables = $matches[3];

      return <<<PHP
      <?php echo \Bootgly\ABI\Templates\Template::include('{$template}', ({$variables}) + get_defined_vars()); ?>
      PHP;
   },
   // Sharing the current scope: @include name;
   '/(@)?@include[ ]+?([\w\/-]+)[ ]?;/x' => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      $template = $matches[2];

      return <<<PHP
      <?php echo \Bootgly\ABI\Templates\Template::include('{$template}', get_defined_vars()); ?>
      PHP;
   },
];
