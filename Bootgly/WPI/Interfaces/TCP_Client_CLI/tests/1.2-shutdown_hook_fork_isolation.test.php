<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;


return new Specification(
   description: 'It should not let fork-inherited shutdown hooks signal the parent process',
   skip: function_exists('pcntl_fork') === false
      || function_exists('pcntl_signal') === false,
   test: new Assertions(Case: function (): Generator {
      $Sockets = stream_socket_pair(
         STREAM_PF_UNIX,
         STREAM_SOCK_STREAM,
         STREAM_IPPROTO_IP
      );
      if ($Sockets === false) {
         throw new RuntimeException('Could not create the shutdown-hook probe channel.');
      }

      $ProbePID = pcntl_fork();
      if ($ProbePID === -1) {
         fclose($Sockets[0]);
         fclose($Sockets[1]);
         throw new RuntimeException('Could not fork the shutdown-hook probe.');
      }

      if ($ProbePID === 0) {
         fclose($Sockets[0]);
         $signals = 0;
         $status = 0;

         pcntl_async_signals(true);
         pcntl_signal(SIGINT, static function () use (&$signals): void {
            $signals++;
         });

         $Client = new TCP_Client_CLI;
         $ChildPID = pcntl_fork();
         if ($ChildPID === 0) {
            fclose($Sockets[1]);
            exit(0);
         }

         $waited = $ChildPID > 0
            ? pcntl_waitpid($ChildPID, $status)
            : -1;
         usleep(50000);
         pcntl_signal_dispatch();

         $Client->Process->State->clean();
         $result = [
            'signals' => $signals,
            'waited' => $waited,
            'child' => $ChildPID,
            'exited' => pcntl_wifexited($status),
            'status' => pcntl_wifexited($status)
               ? pcntl_wexitstatus($status)
               : -1,
         ];
         fwrite($Sockets[1], json_encode($result, JSON_THROW_ON_ERROR));
         fclose($Sockets[1]);

         // ! On vulnerable code the probe process also owns the original
         //   self-signalling shutdown hook. Ignore that final signal so its
         //   observed child-to-parent result reaches the outer test process.
         pcntl_signal(SIGINT, SIG_IGN);
         exit(
            $signals === 0
            && $waited === $ChildPID
            && pcntl_wifexited($status)
            && pcntl_wexitstatus($status) === 0
               ? 0
               : 1
         );
      }

      fclose($Sockets[1]);
      $JSON = stream_get_contents($Sockets[0]);
      fclose($Sockets[0]);
      pcntl_waitpid($ProbePID, $status);
      $result = is_string($JSON)
         ? json_decode($JSON, true)
         : null;

      yield new Assertion(
         description: 'a child exit does not deliver SIGINT through an inherited client shutdown hook',
         fallback: 'The inherited shutdown hook signalled its parent: ' . json_encode($result),
      )
         ->expect(
            pcntl_wifexited($status)
            && pcntl_wexitstatus($status) === 0
            && is_array($result)
            && ($result['signals'] ?? -1) === 0
            && ($result['waited'] ?? -1) === ($result['child'] ?? null)
            && ($result['exited'] ?? false) === true
            && ($result['status'] ?? -1) === 0
         )
         ->to->be(true)
         ->assert();
   })
);
