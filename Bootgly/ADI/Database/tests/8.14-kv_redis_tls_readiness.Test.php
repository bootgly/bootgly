<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\KV;


return new Test(
   description: 'KV(Redis): a pending TLS handshake waits for peer read readiness',
   test: function () {
      $Listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
      $Channel = stream_socket_pair(
         STREAM_PF_UNIX,
         STREAM_SOCK_STREAM,
         STREAM_IPPROTO_IP,
      );
      if (is_resource($Listener) === false || $Channel === false) {
         throw new RuntimeException("TLS readiness fixture could not start: {$error}");
      }

      $address = stream_socket_get_name($Listener, false);
      $separator = is_string($address) ? strrpos($address, ':') : false;
      $port = $separator === false ? 0 : (int) substr($address, $separator + 1);
      $PID = pcntl_fork();
      if ($PID < 0 || $port < 1) {
         throw new RuntimeException('TLS readiness fixture could not fork/resolve listener.');
      }

      if ($PID === 0) {
         fclose($Channel[0]);
         $Peer = @stream_socket_accept($Listener, 5.0);
         fclose($Listener);
         $first = is_resource($Peer)
            ? @stream_socket_recvfrom($Peer, 1, STREAM_PEEK)
            : false;
         @fwrite($Channel[1], (string) json_encode([
            'first_hex' => is_string($first) ? bin2hex($first) : '',
         ]));
         fclose($Channel[1]);

         if (is_resource($Peer)) {
            // @ Stay silent long enough for the parent to classify readiness.
            usleep(250000);
            fclose($Peer);
         }

         exit(is_string($first) && $first !== '' ? 0 : 1);
      }

      fclose($Channel[1]);
      fclose($Listener);
      $KV = new KV([
         'driver' => 'redis',
         'host' => '127.0.0.1',
         'port' => $port,
         'timeout' => 2.0,
         'secure' => [
            'mode' => 'require',
            'verify' => false,
            'name' => false,
         ],
         'pool' => ['max' => 1],
      ]);
      $Operation = $KV->command('PING');
      $KV->advance($Operation);

      $Write = $Operation->Readiness;
      if ($Write instanceof Readiness) {
         $writes = [$Write->socket];
         $reads = [];
         $excepts = [];
         @stream_select($reads, $writes, $excepts, 2, 0);
      }
      $KV->advance($Operation);

      $state = $Operation->state;
      $Readiness = $Operation->Readiness;
      $KV->Connection->disconnect();
      pcntl_waitpid($PID, $status);
      $raw = stream_get_contents($Channel[0]);
      fclose($Channel[0]);
      $Report = is_string($raw) ? json_decode($raw, true) : null;

      yield assert(
         assertion: $state === OperationStates::SSLHandshake
            && $Readiness instanceof Readiness
            && $Readiness->flag === Scheduler::SCHEDULE_READ
            && is_array($Report)
            && ($Report['first_hex'] ?? '') === '16'
            && pcntl_wifexited($status)
            && pcntl_wexitstatus($status) === 0,
         description: 'Pending TLS must park on READ after ClientHello, never hot-spin on WRITE; '
            . 'state=' . $state->value
            . '; readiness=' . ($Readiness instanceof Readiness ? $Readiness->flag : -1)
            . '; evidence=' . json_encode($Report),
      );
   },
);
