<?php
return [
   // With explicit data (explicit keys win): @component name with [...]:
   // (quoted strings are consumed whole and `::` never terminates, so `:`
   //  inside strings and class constants survive; the opener is a single `:`)
   '/(@)?@component[ ]+?([\w\/-]+)[ ]+with[ ]+((?:[^:\'"]++|::|\'(?:[^\'\\\\]|\\\\.)*+\'|"(?:[^"\\\\]|\\\\.)*+")+?)[ ]?(?<!:):(?!:)/sx' => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      $component = $matches[2];
      $variables = $matches[3];

      return <<<PHP
      <?php \Bootgly\ABI\Templates\Sections::open('{$component}', ({$variables}) + get_defined_vars()); ?>
      PHP;
   },
   // Opener: @component name:
   '/(@)?@component[ ]+?([\w\/-]+)[ ]?:/x' => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      $component = $matches[2];

      return <<<PHP
      <?php \Bootgly\ABI\Templates\Sections::open('{$component}', get_defined_vars()); ?>
      PHP;
   },
   // Closer: @component;
   '/(@)?@component[ ]?;/x' => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      return <<<PHP
      <?php echo \Bootgly\ABI\Templates\Template::compose(); ?>
      PHP;
   },
];
