<?php
return [
   '/(@)?@section[ ]+?([\w\/-]+)[ ]?:/x' => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      $section = $matches[2];

      return <<<PHP
      <?php \Bootgly\ABI\Templates\Sections::start('{$section}'); ?>
      PHP;
   },
   '/(@)?@section[ ]?;/x' => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      return <<<PHP
      <?php \Bootgly\ABI\Templates\Sections::end(); ?>
      PHP;
   },
];
