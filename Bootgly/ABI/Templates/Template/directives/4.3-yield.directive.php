<?php
return [
   // Block form with default content: @yield name: ...default... @yield;
   '/(@)?@yield[ ]+?([\w\/-]+)[ ]?:/x' => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      $section = $matches[2];

      return <<<PHP
      <?php if (\Bootgly\ABI\Templates\Sections::check('{$section}')): echo \Bootgly\ABI\Templates\Sections::fetch('{$section}'); else: ?>
      PHP;
   },
   // Inline form: @yield name;
   '/(@)?@yield[ ]+?([\w\/-]+)[ ]?;/x' => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      $section = $matches[2];

      return <<<PHP
      <?php echo \Bootgly\ABI\Templates\Sections::fetch('{$section}'); ?>
      PHP;
   },
   // Closer of the block form
   '/(@)?@yield[ ]?;/x' => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      return <<<PHP
      <?php endif; ?>
      PHP;
   },
];
