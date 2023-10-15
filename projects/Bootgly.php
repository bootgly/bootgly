<?php

use Bootgly\ABI\Debugging\Code\Vars;
use Bootgly\CLI;
use Bootgly\WPI;

switch (\PHP_SAPI) {
   // CLI / Console
   case 'cli':
      $CLI = new CLI;
      break;
   // WPI / Web
   case 'apache2handler':
   case 'litespeed':
   case 'nginx':
   default:
      $WPI = new WPI;
}
