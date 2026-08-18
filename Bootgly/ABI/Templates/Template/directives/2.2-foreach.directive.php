<?php
return [
   // (quoted strings are consumed whole and `::` never terminates, so `:`
   //  inside strings and class constants survive; the opener is a single `:`)
   '/(@)?@foreach[ ]+?((?:[^:\'"()]++|::|\'(?:[^\'\\\\]|\\\\.)*+\'|"(?:[^"\\\\]|\\\\.)*+"|(?<paren>\((?:[^()\'"]++|\'(?:[^\'\\\\]|\\\\.)*+\'|"(?:[^"\\\\]|\\\\.)*+"|(?P>paren))*+\)))+?)[ ]?(?<!:):(?!:)/sx' => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      // @ <expression> as $key
      $iterable = trim($matches[2], '()');

      preg_match('/\$(.*) +as *(.*)$/is', $iterable, $_matches);

      // ? Not an `<iterable> as <iteration>` expression — emitting '' would drop
      //   the loop opener and leave its @foreach; closer dangling
      if (!isset($_matches[1], $_matches[2])) {
         throw new \Bootgly\ABI\Templates\Template\Exceptions\TemplateException(
            "Invalid @foreach expression: {$iterable}"
         );
      }

      // @
      $iteratee = $_matches[1];
      $iteration = $_matches[2];

      $init = <<<PHP
      \$_ = \Bootgly\ABI\Templates\Iterators::queue(\${$iteratee});
      PHP;

      return <<<PHP
      <?php {$init} foreach (\${$iteratee} as {$iteration}): ?>
      PHP;
   },
   "/(@)?@foreach[ ]?;/sx" => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      return <<<PHP
      <?php \$_?->next(); endforeach; \$_ = \Bootgly\ABI\Templates\Iterators::dequeue(); ?>
      PHP;
   },
];
