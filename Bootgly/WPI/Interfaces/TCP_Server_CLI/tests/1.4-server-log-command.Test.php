<?php

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Handlers\Pipe as PipeHandler;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as TCPServer;


if (! class_exists('TCPServerCLILogCommandProbe', false)) {
   class TCPServerCLILogCommandProbe extends TCPServer
   {
      public function pipe (): void
      {
         parent::pipe();
      }
   }
}


return new Test(
   description: 'Server `@log on`/`@log off`: sessions arm and disarm the worker tap without touching Display',
   test: function () {
      $OldTap = Logger::$Tap;
      $oldSegments = Display::$segments;

      try {
         Logger::$Tap = null;

         $Probe = new TCPServerCLILogCommandProbe(Modes::Foreground);
         $Probe->pipe();

         // # `@log on` arms the process tap; Display stays per-mode
         $before = Display::$segments;
         $Probe->{'@log on'};
         yield assert(
            assertion: Logger::$Tap instanceof PipeHandler,
            description: 'a `log on` command routes this process\'s records into the pipe'
         );
         yield assert(
            assertion: Display::$segments === $before,
            description: 'arming never touches the Display mask (unlike Monitor\'s sink())'
         );

         // # Re-arming is idempotent
         $Armed = Logger::$Tap;
         $Probe->{'@log on'};
         yield assert(
            assertion: Logger::$Tap === $Armed,
            description: 'a second `log on` keeps the existing handler (??= semantics)'
         );

         // # `@log off` disarms
         $Probe->{'@log off'};
         yield assert(
            assertion: Logger::$Tap === null,
            description: 'a `log off` command returns the process to the zero-cost state'
         );

         // # Monitor mode: the tap is the TUI's food — sessions never disarm it
         $Probe->Mode = Modes::Monitor;
         $Probe->{'@log on'};
         $Probe->{'@log off'};
         yield assert(
            assertion: Logger::$Tap instanceof PipeHandler,
            description: 'in Monitor mode `log off` leaves the viewer\'s tap armed'
         );
      }
      finally {
         Logger::$Tap = $OldTap;
         Display::show($oldSegments);
      }
   }
);
