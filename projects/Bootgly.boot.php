<?php

use Bootgly\CLI;
use Bootgly\WPI;

switch (\PHP_SAPI) {
   // CLI / Console
   case 'cli':
      new CLI;
      break;
   // WPI / Web
   case 'apache2handler':
   case 'litespeed':
   case 'nginx':
   default:
      new WPI;
}
