<?php

use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Handlers\File;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'File handler resolves the {project} placeholder (sanitized) alongside {channel}',
   test: function () {
      $saved = Record::$provenance;

      // # {project}/{channel} routes by provenance, sanitized (no path traversal)
      $dir = sys_get_temp_dir() . '/bootgly-logtest-proj-' . uniqid();
      $Routed = new File("$dir/{project}/{channel}.log");

      Record::$provenance = 'Alpha App';
      $Routed->handle(new Record(Levels::Info, 'Web', 'alpha-msg'));

      Record::$provenance = 'framework';
      $Routed->handle(new Record(Levels::Info, 'Web', 'core-msg'));

      yield assert(
         assertion: is_file("$dir/Alpha_App/Web.log") && is_file("$dir/framework/Web.log"),
         description: '{project} writes a separate directory per provenance, spaces sanitized to underscores'
      );
      yield assert(
         assertion: str_contains((string) file_get_contents("$dir/Alpha_App/Web.log"), 'alpha-msg')
            && str_contains((string) file_get_contents("$dir/Alpha_App/Web.log"), 'core-msg') === false,
         description: 'each provenance file holds only its own records'
      );

      // # Empty provenance falls back to `default`
      $Record = new Record(Levels::Info, 'Web', 'blank-msg');
      $Record->project = '';
      $Routed->handle($Record);
      yield assert(
         assertion: is_file("$dir/default/Web.log"),
         description: 'an empty project resolves to the default directory'
      );

      // # {channel}-only paths are untouched by provenance
      $chanDir = sys_get_temp_dir() . '/bootgly-logtest-proj-chan-' . uniqid();
      $Plain = new File("$chanDir/{channel}.log");
      Record::$provenance = 'Alpha App';
      $Plain->handle(new Record(Levels::Info, 'Web', 'plain-msg'));
      yield assert(
         assertion: is_file("$chanDir/Web.log"),
         description: 'a path without {project} behaves exactly as before'
      );

      // @ Cleanup
      foreach (["$dir/Alpha_App", "$dir/framework", "$dir/default", $dir, $chanDir] as $path) {
         foreach ((array) glob("$path/*.log") as $file) {
            @unlink((string) $file);
         }
         @rmdir($path);
      }

      Record::$provenance = $saved;
   }
);
