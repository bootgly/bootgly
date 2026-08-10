<?php


use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Endpoints\Server\Status;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;


return new Test(
   description: 'UDP console commands act on the master in-process — pause/resume/stop transition THIS process, never a self-raised signal',
   test: function () {
      // ! Benign catchers for the signals a regressed console would raise at
      //   itself: the suite runner must never be stopped or killed by this
      //   spec, even against a self-signaling tree.
      $previous = [];
      foreach ([SIGTSTP, SIGCONT, SIGINT] as $signal) {
         $previous[$signal] = pcntl_signal_get_handler($signal);
         pcntl_signal($signal, static function (): void {}, false);
      }
      $segments = Display::$segments;
      Display::show(Display::NONE);

      try {
         $Server = new UDP_Server_CLI(Modes::Test);
         $Server->configure(host: '127.0.0.1', port: 19998, workers: 1);

         $StatusProperty = new ReflectionProperty($Server, 'Status');
         $StatusProperty->setValue($Server, Status::Running);
         $CommandsProperty = new ReflectionProperty($Server, 'Commands');
         $Commands = $CommandsProperty->getValue($Server);

         // @ pause — the Status must flip in THIS process, synchronously. A
         //   self-raised SIGTSTP would only be dispatched one console
         //   iteration later — and here it is swallowed by the no-op handler,
         //   so a regressed tree leaves the Status at Running.
         $Commands->command('pause');
         yield assert(
            assertion: $StatusProperty->getValue($Server) === Status::Paused,
            description: 'a typed pause transitions the master to Paused in-process'
         );

         $Commands->command('resume');
         yield assert(
            assertion: $StatusProperty->getValue($Server) === Status::Running,
            description: 'a typed resume transitions the master back to Running in-process'
         );

         // @ Reloading a Modes::Test master would drain the workers and
         //   exec-replace the suite runner itself — the arm must refuse.
         yield assert(
            assertion: $Commands->command('reload') === true,
            description: 'a typed reload on a Modes::Test master is refused without signaling'
         );

         // @ stop — in Test mode stop() returns control to the runner.
         $interact = $Commands->command('stop');
         yield assert(
            assertion: $interact === false
               && $StatusProperty->getValue($Server) === Status::Stopping,
            description: 'a typed stop runs stop() in-process and ends the interaction'
         );
      }
      finally {
         // @ Drain anything a regressed tree queued into the no-op handlers,
         //   then restore the previous dispositions and the display mask.
         pcntl_signal_dispatch();
         foreach ($previous as $signal => $handler) {
            pcntl_signal($signal, $handler, false);
         }
         Display::show($segments);
      }
   }
);
