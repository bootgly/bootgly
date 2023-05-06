<?php
return [
   "/(@for)[ ]+?(.+?)[ ]?:/sx" => function ($matches) {
      // @ ...<expressions>
      $expressions = trim($matches[2], '()');

      return <<<PHP
      <?php for ({$expressions}): ?>
      PHP;
   },
   "/@for[ ]?;/sx" => function () {
      return <<<PHP
      <?php endfor; ?>
      PHP;
   }
];
