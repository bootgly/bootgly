<?php
return [
   "/(@)?@break[ ]?(\d+)?[ ]?;/sx" => function ($matches) {
      if ($matches[1]) {
         return substr($matches[0], 1);
      }

      // @ ?<level:number>;
      $level = $matches[2] ?? '';

      return <<<PHP
      <?php break $level; ?>
      PHP;
   },
   "/(@)?@break[ ]+?(\d+)?[ ]?in[ ]+?(.+?)[ ]?;/sx" => function ($matches) {
      if ($matches[1]) {
         return substr($matches[0], 1);
      }

      // @ ?<level:number>;
      $level = $matches[2] ?? '';
      // @ <conditional>;
      $conditional = $matches[3];

      return <<<PHP
      <?php if ($conditional) break $level; ?>
      PHP;
   },
];
