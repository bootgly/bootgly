<?php
return [
   // Named slot of a component: @slot name: ... @slot;
   // (same capture mechanism as @section — one mechanism, two intent-revealing spellings)
   '/(@)?@slot[ ]+?([\w\/-]+)[ ]?:/x' => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      $slot = $matches[2];

      return <<<PHP
      <?php \Bootgly\ABI\Templates\Sections::start('{$slot}'); ?>
      PHP;
   },
   '/(@)?@slot[ ]?;/x' => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      return <<<PHP
      <?php \Bootgly\ABI\Templates\Sections::end(); ?>
      PHP;
   },
];
