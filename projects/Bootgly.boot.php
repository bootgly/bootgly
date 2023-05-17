<?php
namespace Bootgly;

switch (\PHP_SAPI) {
   case 'cli':
      $CLI = new CLI;

      if (BOOTGLY_DIR === BOOTGLY_WORKABLES_DIR) {
         $CLI::$Commands->route();
      }

      break;
   default:
      $Web = new Web;
}
