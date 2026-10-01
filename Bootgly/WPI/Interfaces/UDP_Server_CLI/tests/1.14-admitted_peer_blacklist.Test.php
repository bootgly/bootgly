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
   description: 'BG-H7-1: a blacklisted IP stops being served to its already-admitted peers',
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
         $Server->configure(new UDP_Server_CLI\Configs(host: '127.0.0.1', port: 19996, workers: 1, maxConnections: 64, maxConnectionsPerIP: 16));
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

         // # An admitted peer whose IP limit() blacklists is retired on its next datagram
         [$A, $peerA] = $Open('127.0.0.1');
         [$B, $peerB] = $Open('127.0.0.1');
         $Turn($A, ['a1']);
         $Turn($B, ['b1']);
         $Limited = Connections::$Connections[$peerA]->limit(1);
         $errors = Connections::$errors['connection'];
         $handled = [];
         $Turn($B, ['b2']);
         $Observed = [
            'limit() blacklisted' => $Limited === true && isSet(Connections::$blacklist['127.0.0.1']),
            'admitted peer refused' => $handled,
            'admitted peer retired' => isSet(Connections::$Connections[$peerB]) === false,
            'refusal counted' => Connections::$errors['connection'] - $errors,
         ];
         // ! Control: another IP keeps being served
         [$F] = $Open('127.0.0.3');
         $handled = [];
         $Turn($F, ['f1']);
         $Turn($F, ['f2']);
         $Observed['other IP served'] = $handled;

         // # Rewriting the public $ip neither redirects limit() nor check()
         Connections::$blacklist = [];
         [$D, $peerD] = $Open('127.0.0.2');
         $Turn($D, ['d1']);
         Connections::$Connections[$peerD]->ip = '127.0.0.99';
         Connections::$Connections[$peerD]->limit(1);
         $Observed['limit() keys the admission IP'] = array_keys(Connections::$blacklist);
         [$E] = $Open('127.0.0.2');
         $handled = [];
         $Turn($E, ['e1']);
         $Observed['new peer from the limited IP'] = $handled;
         [$G, $peerG] = $Open('127.0.0.4');
         $Turn($G, ['g1']);
         Connections::$blacklist['127.0.0.4'] = true;
         Connections::$Connections[$peerG]->ip = '127.0.0.98';
         $Observed['check() keys the admission IP'] = Connections::$Connections[$peerG]->check();

         yield new Assertion(description: 'blacklisting reaches admitted peers through the immutable admission key')
            ->expect($Observed, Op::Identical, [
               'limit() blacklisted' => true,
               'admitted peer refused' => [],
               'admitted peer retired' => true,
               'refusal counted' => 1,
               'other IP served' => ['f1', 'f2'],
               'limit() keys the admission IP' => ['127.0.0.2'],
               'new peer from the limited IP' => [],
               'check() keys the admission IP' => false,
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
