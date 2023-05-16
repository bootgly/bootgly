<?php
namespace Bootgly;

switch (\PHP_SAPI) {
   case 'cli':
      $CLI = new CLI;
      $CLI->construct();
      break;
   default:
      $Web = new Web;
      $Web->construct();
}
