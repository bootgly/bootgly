<?php
return [
   "/(@foreach)[ ]+?(.+?)[ ]?:/sx" => function ($matches) {
      // @ <expression> as $key
      $iterable = trim($matches[2], '()');

      // TODO Add Loop Variables only if meta variable ($@) exists inside foreach
      preg_match('/\$(.*) +as *(.*)$/is', $iterable, $_matches);
      $iteratee = $_matches[1];
      $iteration = $_matches[2];

      $init = <<<PHP
      \$_ = new \Bootgly\ABI\iterators\Iterator(\$$iteratee);
      PHP;

      return <<<PHP
      <?php {$init} foreach (\$_ as {$iteration}): ?>
      PHP;
   },
   "/@foreach[ ]?;/sx" => function () {
      return <<<PHP
      <?php endforeach; ?>
      PHP;
   }
];
