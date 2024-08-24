<?php

use const Bootgly\WPI;
use Bootgly\WPI\Nodes\HTTP_Server_;
use Bootgly\API\Project;


if ($this instanceof WPI !== false) {
   return;
}

// @ Check if HTTP Server was instantiated
if ( (WPI->Server ?? false) === false) {
   if (PHP_SAPI !== 'cli') {
      new HTTP_Server_;
   }
}

// @ Select default WPI Project
$Project = self::select('Bootgly.WPI.demo');
if ($Project instanceof Project) {
   require "{$Project->path}index.php";
}
