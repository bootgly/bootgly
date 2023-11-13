<?php

use Bootgly\API\Project;
use Bootgly\API\Projects;

if (self::count() === 0) {
   self::autoboot(self::AUTHOR_DIR);

   // @ Select default project (app example)
   $Project = self::select('Bootgly.WPI.App');

   if ($Project instanceof Project) {
      require $Project->path . 'index.php';
   }
}
