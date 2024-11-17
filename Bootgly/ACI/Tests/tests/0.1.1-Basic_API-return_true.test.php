<?php

return [
   // @ configure
   'separator.line' => 'Basic API',
   'describe' => 'It should assert returning true',
   // @ simulate
   // ...
   // @ test
   'test' => function (): bool
   {
      return true === true;
   }
];
