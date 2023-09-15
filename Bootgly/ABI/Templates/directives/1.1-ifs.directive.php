<?php
return [
   "/(@)?@if[ ]+?(.+?)[ ]?:/sx" => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      // Conditional
      $conditional = $matches[2];
      // @ Replace Short syntax to isSet(...)
      $conditional = preg_replace('/\$(.*?)\?\?/sx', 'isSet(\$${1})', $conditional);
      // @ Replace Short Syntax to !empty(...)
      $conditional = preg_replace('/\$(.*?)\?/sx', '!empty(\$${1})', $conditional);

      return <<<PHP
      <?php if ({$conditional}): ?>
      PHP;
   },
   "/(@)?@if[ ]?;/sx" => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      return <<<PHP
      <?php endif; ?>
      PHP;
   },
];
