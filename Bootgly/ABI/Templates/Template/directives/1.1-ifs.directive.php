<?php
return [
   // (quoted strings are consumed whole and `::` never terminates, so `:`
   //  inside strings and class constants survive; the opener is a single `:`)
   '/(@)?@if[ ]+?((?:[^:\'"]++|::|\'(?:[^\'\\\\]|\\\\.)*+\'|"(?:[^"\\\\]|\\\\.)*+")+?)[ ]?(?<!:):(?!:)/sx' => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      // Conditional
      $conditional = $matches[2];
      // @ Replace Short syntax to isSet(...)
      $conditional = preg_replace('/\$(.*?)\?\?/sx', 'isSet(\$${1})', $conditional);
      // @ Replace Short Syntax to !empty(...)
      $conditional = preg_replace('/\$(.*?)\?/sx', '!empty(\$${1})', $conditional);

      // ? preg_replace failed on the condition — emitting '' would silently drop
      //   the whole @if block and produce a template that renders wrong output
      if (!is_string($conditional)) {
         throw new \Bootgly\ABI\Templates\Template\Exceptions\TemplateException(
            "Invalid @if condition: {$matches[2]}"
         );
      }

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
