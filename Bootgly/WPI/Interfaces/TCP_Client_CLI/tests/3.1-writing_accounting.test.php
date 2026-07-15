<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\HTTP\Tracker;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections\Connection;


if (class_exists('TCPClientAccountingConnection', false) === false) {
   class TCPClientAccountingConnection extends Connection
   {
      public bool $closed = false;


      /** @param resource $Socket */
      public function __construct (mixed &$Socket, TCP_Client_CLI $Client)
      {
         $this->Socket = $Socket;
         $this->Client = $Client;
         $this->id = (int) $Socket;
         $this->timers = [];
         $this->encrypted = false;
         $this->peerEOF = false;
         $this->status = self::STATUS_ESTABLISHED;
         $this->output = '';
         $this->input = '';
         $this->written = 0;
         $this->read = 0;
         $this->writes = 0;
         $this->reads = 0;
         $this->errors = ['write' => 0, 'read' => 0];
         $this->expired = false;
         $this->Connection = $this;
      }

      public function close (): true
      {
         $this->closed = true;
         $this->status = self::STATUS_CLOSED;

         if (is_resource($this->Socket)) {
            @fclose($this->Socket);
         }

         return true;
      }
   }
}


return new Specification(
   description: 'It should preserve the exact unsent suffix after a terminal partial write',
   test: new Assertions(Case: function (): Generator {
      $descriptorSpec = [
         0 => ['pipe', 'r'],
         1 => ['file', '/dev/null', 'w'],
         2 => ['file', '/dev/null', 'w'],
      ];
      $process = proc_open(
         [PHP_BINARY, '-r', 'usleep(5000000);'],
         $descriptorSpec,
         $Pipes,
      );
      if (is_resource($process) === false) {
         throw new RuntimeException('Unable to open the accounting test pipe.');
      }

      $Socket = $Pipes[0];
      stream_set_blocking($Socket, false);
      $Client = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);
      $Client->deadline = microtime(true) - 1;
      $Connection = new TCPClientAccountingConnection($Socket, $Client);
      $payload = str_repeat('0123456789abcdef', 262144);
      $Connection->output = $payload;

      $written = $Connection->writing($Socket);
      $remaining = strlen($Connection->output);
      $accepted = strlen($payload) - $remaining;
      $partial = $accepted > 0 && $remaining > 0;

      $accounting = null;
      if ($partial) {
         $Tracker = new Tracker();
         $Tracker->queue([$accepted, $remaining]);
         $Tracker->accept($remaining);
         $Tracker->close(false);
         $accounting = $Tracker->inspect();
      }

      @proc_terminate($process);
      proc_close($process);

      yield new Assertion(
         description: 'Accepted bytes are not resurrected when zero progress reaches the deadline',
         fallback: 'The disconnect observer received an inexact write suffix!'
      )
         ->expect(
            [
               $written,
               $partial,
               $Connection->output === substr($payload, $accepted),
               $Connection->closed,
               $accounting['sent'] ?? null,
               $accounting['failures'] ?? null,
               $accounting['write_failures'] ?? null,
               $accounting['accounting'] ?? null,
            ],
            \Bootgly\ACI\Tests\Assertion\Auxiliaries\Op::Identical,
            [
               false,
               true,
               true,
               true,
               1,
               ['connection_aborted' => 1],
               ['connection_aborted' => 1],
               true,
            ],
         )
         ->assert();
   })
);
