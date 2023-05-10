<?php
return [
   "/@break[ ]?(\d+)?[ ]?;/sx" => function ($matches) {
      // @ ?<level:number>;
      $level = $matches[1] ?? '';

      return <<<PHP
      <?php break $level; ?>
      PHP;
   },
   "/@break[ ]+?(\d+)?[ ]?in[ ]+?(.+?)[ ]?;/sx" => function ($matches) {
      // @ ?<level:number>;
      $level = $matches[1] ?? '';
      // @ <conditional>;
      $conditional = $matches[2];

      return <<<PHP
      <?php if ($conditional) break $level; ?>
      PHP;
   }
];
