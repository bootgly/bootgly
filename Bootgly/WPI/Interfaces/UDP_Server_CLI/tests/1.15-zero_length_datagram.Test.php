<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection\Lease;


return new Test(
   description: 'BG-H7-2 / UDP-9: a zero-length datagram is counted and skipped without ending the drain',
   test: new Assertions(Case: function (): Generator {
      $segments = Display::$segments;
      $PreviousAlarm = pcntl_signal_get_handler(SIGALRM);
      $PreviousHandler = isSet(SAPI::$Handler) ? SAPI::$Handler : null;
      $PreviousDecoder = UDP_Server_CLI::$Decoder;
      Timer::init(static function (): void {});
      Display::show(Display::NONE);
      $Socket = false;
      /** @var array<int,resource> $Sources */
      $Sources = [];

      try {
         Timer::del();
         $Server = new UDP_Server_CLI(Modes::Test);
         $Server->configure(new UDP_Server_CLI\Configs(host: '127.0.0.1', port: 19995, workers: 1, maxDatagramsPerTick: 4));
         UDP_Server_CLI::$Decoder = null;
         $handled = [];
         SAPI::$Handler = static function (string $input) use (&$handled): string {
            $handled[] = $input;

            return "ok:{$input}";
         };
         $Socket = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
         if ($Socket === false) {
            throw new RuntimeException('Could not bind the loopback UDP socket.');
         }
         stream_set_blocking($Socket, false);
         (new ReflectionProperty($Server, 'Socket'))->setValue($Server, $Socket);
         $target = (string) stream_socket_get_name($Socket, false);
         $Connections = $Server->Connections;

         /** Open a source socket on $IP and return [socket, "ip:port"]. */
         $Open = static function (string $IP) use (&$Sources): array {
            $Source = stream_socket_server("udp://{$IP}:0", $code, $message, STREAM_SERVER_BIND);
            if ($Source === false) {
               throw new RuntimeException("Could not bind {$IP}.");
            }
            $Sources[] = $Source;

            return [$Source, (string) stream_socket_get_name($Source, false)];
         };
         /** Send datagrams from one source, then run ONE readiness turn. */
         $Turn = static function ($Source, array $payloads) use ($Socket, $target, $Connections): void {
            foreach ($payloads as $payload) {
               stream_socket_sendto($Source, $payload, 0, $target);
            }
            usleep(20_000);
            $read = [$Socket];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 200_000) === 1) {
               $Connections->Router->reading($Socket);
            }
         };

         // # Empty datagrams are counted and skipped inside ONE turn (batch 4)
         [$S] = $Open('127.0.0.1');
         $reads = Connections::$reads;
         $errors = Connections::$errors['read'];
         $Turn($S, ['', 'A', '', 'B']);
         $Observed = [
            'one turn delivered' => $handled,
            'empties counted' => Connections::$errors['read'] - $errors,
            'reads counted' => Connections::$reads - $reads,
         ];
         // # The batch bound still holds across empties (batch 4)
         $handled = [];
         $errors = Connections::$errors['read'];
         $Turn($S, ['', '', '', '', '', '', 'Z']);
         $Observed['first turn'] = [$handled, Connections::$errors['read'] - $errors];
         $Turn($S, []);
         $Observed['second turn'] = [$handled, Connections::$errors['read'] - $errors];

         yield new Assertion(description: 'a zero-length datagram never ends the drain and is never dropped uncounted')
            ->expect($Observed, Op::Identical, [
               'one turn delivered' => ['A', 'B'],
               'empties counted' => 2,
               'reads counted' => 2,
               'first turn' => [[], 4],
               'second turn' => [['Z'], 6],
            ])
            ->assert();
      }
      finally {
         foreach (Connections::$Connections as $Connection) {
            $Connection->close();
         }
         Connections::$Connections = [];
         Connections::$blacklist = [];
         unset($Connection);
         gc_collect_cycles();
         Lease::drain();
         Timer::del();
         gc_collect_cycles();
         Lease::drain();
         pcntl_alarm(0);
         foreach ($Sources as $Source) {
            @fclose($Source);
         }
         if ($Socket !== false) {
            @fclose($Socket);
         }
         if ($PreviousHandler !== null) {
            SAPI::$Handler = $PreviousHandler;
         }
         UDP_Server_CLI::$Decoder = $PreviousDecoder;
         pcntl_signal(SIGALRM, $PreviousAlarm === false ? SIG_DFL : $PreviousAlarm);
         Display::show($segments);
      }
   }),
);
