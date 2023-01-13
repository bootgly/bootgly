<?php
namespace Bootgly;


$Web = new Web;

switch (\PHP_SAPI) {
   case 'cli':
      return @include 'cli.constructor.php';
   default:
      new Web;
}
