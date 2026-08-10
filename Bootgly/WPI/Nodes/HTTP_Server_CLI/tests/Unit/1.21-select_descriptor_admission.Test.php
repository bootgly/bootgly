<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;


/**
 * Regression for H2: one descriptor beyond select()'s representable range
 * must never poison the persistent reactor sets.
 *
 * The exact FD_SETSIZE boundary is a property of the PHP build. This case
 * therefore discovers it through the same zero-time stream_select() call used
 * by the production admission guard instead of assuming descriptor 1024.
 */
return new Test(
   description: 'Select must reject an unrepresentable high descriptor without blocking a low descriptor',
   test: new Assertions(Case: function (): Generator {
      $Selector = new class extends Select {
         // ! The admission path does not use the transport-bound Connections
         //   dependency, so this focused probe deliberately skips it.
         public function __construct () {}

         /**
          * @param resource $Socket
          */
         public function validate ($Socket, int $flag): bool
         {
            return parent::check($Socket, $flag);
         }
      };

      $Low = stream_socket_pair(
         STREAM_PF_UNIX,
         STREAM_SOCK_STREAM,
         STREAM_IPPROTO_IP
      );
      $High = false;
      $Fillers = [];
      $lowAdded = false;

      try {
         // @ Keep the control pair below the boundary, then retain real file
         //   descriptors until a newly-created socket crosses it.
         for ($attempt = 0; $attempt < 1_400; $attempt++) {
            $Filler = fopen('/dev/null', 'rb');
            if ($Filler === false) {
               break;
            }
            $Fillers[] = $Filler;

            $Pair = @stream_socket_pair(
               STREAM_PF_UNIX,
               STREAM_SOCK_STREAM,
               STREAM_IPPROTO_IP
            );
            if ($Pair === false) {
               break;
            }

            $reads = [$Pair[0]];
            $writes = [];
            $excepts = [];
            $selected = @stream_select($reads, $writes, $excepts, 0, 0);
            if ($selected === false) {
               $High = $Pair;
               break;
            }

            fclose($Pair[0]);
            fclose($Pair[1]);
         }

         yield new Assertion(
            description: 'the test process reaches a descriptor rejected by its select backend',
         )
            ->expect([
               'discovered' => $High !== false,
               'fillers' => count($Fillers),
            ])
            ->to->be([
               'discovered' => true,
               'fillers' => count($Fillers),
            ])
            ->assert();

         if ($High === false) {
            return;
         }

         $lowValidated = $Selector->validate($Low[0], Select::EVENT_READ);
         $highValidated = $Selector->validate($High[0], Select::EVENT_READ);
         $lowAdded = $Selector->add($Low[0], Select::EVENT_READ, null);
         $highAdded = $Selector->add($High[0], Select::EVENT_READ, null);

         yield new Assertion(
            description: 'the admission guard accepts the low descriptor and rejects the high descriptor',
         )
            ->expect([
               $lowValidated,
               $highValidated,
               $lowAdded,
               $highAdded,
            ])
            ->to->be([true, false, true, false])
            ->assert();

         $written = fwrite($Low[1], 'L');
         $reads = [$Low[0]];
         $writes = [];
         $excepts = [];
         $selected = @stream_select($reads, $writes, $excepts, 0, 0);
         $payload = $selected === 1
            ? fread($Low[0], 1)
            : false;

         yield new Assertion(
            description: 'rejecting the high descriptor leaves the admitted low descriptor readable',
         )
            ->expect([$written, $selected, $payload])
            ->to->be([1, 1, 'L'])
            ->assert();
      }
      finally {
         if ($lowAdded) {
            $Selector->del($Low[0], Select::EVENT_READ);
         }

         if ($High !== false) {
            fclose($High[0]);
            fclose($High[1]);
         }
         foreach ($Fillers as $Filler) {
            fclose($Filler);
         }
         fclose($Low[0]);
         fclose($Low[1]);
      }

      // # A worker's mandatory one-second SIGALRM interrupts a blocking
      //   stream_select. Three such EINTR returns must not be mistaken for a
      //   persistently invalid set and recycle an otherwise healthy worker.
      $Server = new TCP_Server_CLI;
      $Connections = new Connections($Server);
      $AlarmSelector = new Select($Connections);
      $Idle = stream_socket_pair(
         STREAM_PF_UNIX,
         STREAM_SOCK_STREAM,
         STREAM_IPPROTO_IP
      );
      if ($Idle === false) {
         throw new RuntimeException('Could not create the selector alarm fixture.');
      }

      $OldHandler = pcntl_signal_get_handler(SIGALRM);
      $oldAlarm = pcntl_alarm(0);
      $signals = 0;
      $caught = '';
      $alarmAdded = false;
      $started = microtime(true);

      try {
         $alarmAdded = $AlarmSelector->add(
            $Idle[0],
            Select::EVENT_READ,
            null
         );
         pcntl_signal(
            SIGALRM,
            static function () use (&$signals, $AlarmSelector): void {
               $signals++;
               if ($signals >= 4) {
                  $AlarmSelector->loop = false;
                  return;
               }

               pcntl_alarm(1);
            }
         );
         pcntl_alarm(1);
         $AlarmSelector->loop();
      }
      catch (Throwable $Throwable) {
         $caught = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         pcntl_alarm(0);
         pcntl_signal(SIGALRM, $OldHandler);
         if ($oldAlarm > 0) {
            pcntl_alarm($oldAlarm);
         }
         if ($alarmAdded) {
            $AlarmSelector->del($Idle[0], Select::EVENT_READ);
         }
         fclose($Idle[0]);
         fclose($Idle[1]);
      }
      $duration = microtime(true) - $started;

      yield new Assertion(
         description: 'repeated alarm interruptions do not recycle an idle valid selector',
      )
         ->expect([
            'added' => $alarmAdded,
            'signals' => $signals,
            'caught' => $caught,
            'duration' => $duration >= 3.0 && $duration < 6.0,
         ])
         ->to->be([
            'added' => true,
            'signals' => 4,
            'caught' => '',
            'duration' => true,
         ])
         ->assert();
   })
);
