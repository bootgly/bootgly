<?php

use Bootgly\WPI;
use Bootgly\API\Project;


if ($this instanceof WPI !== false) {
   return;
}

// @ Select default WPI Project
$Project = self::select('Bootgly.WPI.demo');
if ($Project instanceof Project) {
   require "{$Project->path}index.php";
}
