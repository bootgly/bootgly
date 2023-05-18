<?php
namespace Bootgly;

switch (\PHP_SAPI) {
   case 'cli':
      $CLI = new CLI;
      break;
   default:
      $Web = new Web;
}
