<?php
return [
   '/@(\\\\+);/m' => function ($matches) {
      if ($matches[0]) {
         return str_repeat(PHP_EOL, strlen($matches[1]));
      }
   }
];
