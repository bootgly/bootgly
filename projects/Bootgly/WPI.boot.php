<?php

use Bootgly\API\Project;

// @ Select default WPI Project
$Project = self::select('Bootgly.WPI.demo');

if ($Project instanceof Project) {
   require $Project->path . 'index.php';
}
