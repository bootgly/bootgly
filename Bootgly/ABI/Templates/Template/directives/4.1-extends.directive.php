<?php
return [
   '/(@)?@extends[ ]+?([\w\/-]+)[ ]?;/x' => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      $template = $matches[2];

      return <<<PHP
      <?php \Bootgly\ABI\Templates\Sections::extend('{$template}', __FILE__, __LINE__); ?>
      PHP;
   },
];
