<?php
return [
   "/@continue[ ]?(\d+)?[ ]?;/sx" => function ($matches) {
      // @ ?<level:number>;
      $level = $matches[1] ?? '';

      return <<<PHP
      <?php continue $level; ?>
      PHP;
   },
   "/@continue[ ]+?(\d+)?[ ]?in[ ]+?(.+?)[ ]?;/sx" => function ($matches) {
      // @ ?<level:number>;
      $level = $matches[1] ?? '';
      // @ <conditional>;
      $conditional = $matches[2];

      return <<<PHP
      <?php if ($conditional) continue $level; ?>
      PHP;
   }
];
