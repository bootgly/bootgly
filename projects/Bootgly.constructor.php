<?php

use Bootgly\ACI\Debugger;
use Bootgly\CLI;
use Bootgly\WPI;

switch (\PHP_SAPI) {
   // Console
   case 'cli':
      $CLI = new CLI;
      break;
   // Web
   case 'apache2handler':
   case 'litespeed':
   case 'nginx':
   default:
      $WPI = new WPI;
}
