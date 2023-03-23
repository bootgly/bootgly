<?php
return [
   '/\s@(;)|([*~_-])@/m' => function ($matches) {
      return self::_RESET_FORMAT;
   }
];
