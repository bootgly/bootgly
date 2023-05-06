<?php
return [
   '/(\$@){1}(->){1}/sx' => function ($matches) {
      if ($matches[1] === null) {
         return '';
      }

      return '$_->';
   }
];
