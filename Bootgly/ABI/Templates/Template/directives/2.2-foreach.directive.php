<?php
return [
   "/(@)?@foreach[ ]+?(.+?)[ ]?:/sx" => function ($matches) {
      if (@$matches[1]) {
         return substr($matches[0], 1);
      }

      // @ <expression> as $key
      $iterable = trim($matches[2], '()');

      preg_match('/\$(.*) +as *(.*)$/is', $iterable, $_matches);

      // ?
      if (!isset($_matches[1], $_matches[2])) {
         return ''; // TODO: use custom exception
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
