<?php
namespace Bootgly;

switch (\PHP_SAPI) {
   case 'cli':
      new CLI;
   default:
      new Web;
}
