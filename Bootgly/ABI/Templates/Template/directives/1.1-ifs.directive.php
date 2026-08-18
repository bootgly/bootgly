<?php
return [
   // (quoted strings are consumed whole and `::` never terminates, so `:`
   //  inside strings and class constants survive; the opener is a single `:`)
   '/(@)?@if[ ]+?((?:[^:\'"()]++|::|\'(?:[^\'\\\\]|\\\\.)*+\'|"(?:[^"\\\\]|\\\\.)*+"|(?<paren>\((?:[^()\'"]++|\'(?:[^\'\\\\]|\\\\.)*+\'|"(?:[^"\\\\]|\\\\.)*+"|(?P>paren))*+\)))+?)[ ]?(?<!:):(?!:)/sx' => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      // Conditional
      $conditional = $matches[2];
      // ! Both rewrites are anchored to the WHOLE condition and accept only a bare
      //   variable chain, so the marker is the one the author wrote at the end —
      //   a `?->`, a `??` or a ternary inside a real condition is left alone
      // @ Replace Short syntax to isSet(...)
      $conditional = preg_replace(
         '/^(\s*\(?\s*)\$([\w\[\]\'"\->]+)\?\?(\s*\)?\s*)$/sx',
         '${1}isSet(\$${2})${3}',
         $conditional
      );
      // @ Replace Short Syntax to !empty(...)
      $conditional = preg_replace(
         '/^(\s*\(?\s*)\$([\w\[\]\'"\->]+)\?(\s*\)?\s*)$/sx',
         '${1}!empty(\$${2})${3}',
         $conditional
      );

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
