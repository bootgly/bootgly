<?php


use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should fire worker timers after fork hygiene despite inherited parent tasks',
   test: new Assertions(Case: function (): Generator {
      // ! SIGALRM handler in the parent too — the dirty task below may arm
      //   an alarm in this process; an unhandled SIGALRM would kill it
      Timer::init(static function (): void {
         Timer::tick();
      });

      // ! Dirty parent task map — the fork-inherited state that used to
      //   leave child alarms disarmed forever: POSIX clears pending alarms
      //   on fork, and Timer::add() only arms one when the map was empty
      $inherited = Timer::add(interval: 3600, handler: static function (): void {});

      $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      [$Reader, $Writer] = $pair !== false ? $pair : [null, null];

      $pid = pcntl_fork();
      if ($pid === 0) {
         // # Child — the exact worker bootstrap sequence (see
         //   TCP_Server_CLI::work() and the UDP fork bodies)
         Timer::del();

         $fired = false;
         Timer::add(interval: 1, handler: static function () use (&$fired): void {
            $fired = true;
         });

         $deadline = time() + 4;
         while ($fired === false && time() < $deadline) {
            usleep(100000);
            pcntl_signal_dispatch();
         }

         fwrite($Writer, $fired ? 'fired' : 'dead');
         fclose($Writer);

         // ! Hard exit — no shutdown handlers, no inherited output flush
         posix_kill(posix_getpid(), SIGKILL);
      }

      // # Parent — drop the dirty task (and ITS pending alarm) right away:
      //   the child already carries the inherited copy, and a parent
      //   SIGALRM would otherwise race the result read below
      Timer::del();

      fclose($Writer);

      // @ Read the verdict without trusting one blocking read — any signal
      //   in this process (SIGCHLD from the dying child included) would
      //   interrupt it and fake an empty result
      stream_set_blocking($Reader, false);
      $verdict = '';
      $deadline = time() + 8;
      while (time() < $deadline) {
         $chunk = fread($Reader, 8);
         if (is_string($chunk) && $chunk !== '') {
            $verdict .= $chunk;
         }
         if (feof($Reader) || $verdict === 'fired' || $verdict === 'dead') {
            break;
         }
         usleep(50000);
      }
      fclose($Reader);
      pcntl_waitpid($pid, $status);

      yield new Assertion(
         description: 'Child timer fires after Timer::del() hygiene with a dirty inherited map',
      )
         ->expect($inherited !== false ? $verdict : 'setup-failed')
         ->to->be('fired')
         ->assert();
   })
);
