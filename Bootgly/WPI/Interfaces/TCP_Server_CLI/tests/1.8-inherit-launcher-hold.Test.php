<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI;


use function array_map;
use function assert;
use function class_exists;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_file;
use function pcntl_fork;
use function pcntl_waitpid;
use function rmdir;
use function unlink;

use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Handlers;
use Bootgly\ACI\Logs\Handlers\Memory;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as TCPServer;


if (! class_exists(TCPServerCLIInheritProbe::class, false)) {
   class TCPServerCLIInheritProbe extends TCPServer
   {
      public function inherit (): void
      {
         parent::inherit();
      }
   }
}


/**
 * The detached master keeps the hold its launcher started.
 *
 * A fork starts with an empty hold — a worker must never replay what the
 * master will — but the daemon master is forked from the launcher that ran
 * store(), and nobody else persists what the launcher held. inherit(), called
 * right after detach(), adopts it; a child that does not call it starts empty.
 */
return new Test(
   description: 'inherit() keeps the launcher\'s hold — known by identity — in the forked master; a plain fork starts empty',
   test: function () {
      $dir = Temporaries::reserve('tcp-inherit');
      $marker = "$dir/child.txt";
      $Previous = Logger::$Sinks;

      try {
         // ! The launcher's hold, with one record
         $Hold = new Memory;
         Memory::hold($Hold, []);
         // ! A Memory handler of the project's own beside it — not the hold
         $Own = new Memory;
         Logger::$Sinks = new Handlers;
         Logger::$Sinks->push($Hold);
         Logger::$Sinks->push($Own);
         $Hold->handle(new Record(Levels::Notice, 'TCP.Server.CLI', 'launcher-record'));
         $Own->handle(new Record(Levels::Notice, 'TCP.Server.CLI', 'launcher-own'));
         $Probe = new TCPServerCLIInheritProbe(Modes::Test);

         foreach ([true, false] as $inherits) {
            $pid = pcntl_fork();
            if ($pid === 0) {
               if ($inherits) {
                  $Probe->inherit();
               }
               $Hold->handle(new Record(Levels::Notice, 'TCP.Server.CLI', 'master-record'));
               $Own->handle(new Record(Levels::Notice, 'TCP.Server.CLI', 'master-own'));
               $held = array_map(static fn (Record $Record): string => $Record->message, $Hold->Records);
               $own = array_map(static fn (Record $Record): string => $Record->message, $Own->Records);
               file_put_contents($marker, implode(',', $held) . '|' . implode(',', $own));
               exit(0);
            }
            $status = 0;
            pcntl_waitpid($pid, $status);
            $held = (string) @file_get_contents($marker);

            yield assert(
               assertion: $pid > 0 && $held === ($inherits ? 'launcher-record,master-record|master-own' : 'master-record|master-own'),
               description: $inherits
                  ? "a forked master that inherit()s replays the launcher's record before its own — the hold only, by identity: "
                     . "a project's Memory handler beside it starts empty (held|own: $held)"
                  : "a fork that does not inherit() starts empty (held|own: $held)"
            );
         }
      }
      finally {
         Memory::release();
         Logger::$Sinks = $Previous;
         if (is_file($marker)) {
            unlink($marker);
         }
         rmdir($dir);
      }
   }
);
