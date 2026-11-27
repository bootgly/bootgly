<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\DNS_Client_CLI\Tests\TCP;


use const STREAM_SERVER_BIND;
use function array_column;
use function array_shift;
use function assert;
use function ceil;
use function count;
use function fclose;
use function fread;
use function fwrite;
use function hrtime;
use function is_resource;
use function is_string;
use function json_encode;
use function pack;
use function str_repeat;
use function str_split;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_recvfrom;
use function stream_socket_sendto;
use function stream_socket_server;
use function strlen;
use function strrpos;
use function substr;
use function unpack;
use Fiber;

use Bootgly\ABI\Data\DNS;
use Bootgly\ABI\Data\DNS\Message;
use Bootgly\ABI\Data\DNS\Message\Record;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\DNS_Client_CLI;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Answer\Outcomes;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Cache;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Query;
use Bootgly\WPI\Nodes\DNS_Client_CLI\tests\fixtures\Harness;


return new Test(
   description: 'DNS_Client_CLI TCP leg: an answer in quick pieces never trips the stall guard; a parked one waits on the reactor',
   test: function () {
      // ! An in-process nameserver: UDP answers TC, TCP answers in 24 pieces — one per wake
      $UDP = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
      assert(is_resource($UDP));
      $local = (string) stream_socket_get_name($UDP, false);
      $port = (int) substr($local, (int) strrpos($local, ':') + 1);
      $TCP = stream_socket_server("tcp://127.0.0.1:{$port}", $code, $message);
      assert(is_resource($TCP));
      stream_set_blocking($TCP, false);

      $Peer = null;
      $buffer = '';
      /** @var list<string> $pieces */
      $pieces = [];
      $wakes = 0;
      // ! The bridge never suspends: each call serves the next step of the exchange at once, so
      //   every wake is fast — and every TCP wake brings one more piece of the answer
      $Bridge = static function (mixed $value = null) use ($UDP, $TCP, &$Peer, &$buffer, &$pieces, &$wakes): mixed {
         $wakes++;
         // @ A UDP question: answer it truncated
         $read = [$UDP];
         $write = null;
         $except = null;
         if (@stream_select($read, $write, $except, 0, 0) > 0) {
            $bytes = stream_socket_recvfrom($UDP, 65535, 0, $address);
            $Asked = is_string($bytes) ? DNS::decode($bytes) : null;
            if ($Asked instanceof Message) {
               $reply = DNS::encode(new Message(id: $Asked->id, response: true, truncated: true, recursion: true, recursive: true, Questions: $Asked->Questions));
               if (is_string($reply)) {
                  stream_socket_sendto($UDP, $reply, 0, $address);
               }
            }
         }
         // @ The TCP connection, its question, then one piece of the answer per call
         if ($Peer === null) {
            $Accepted = @stream_socket_accept($TCP, 0);
            if ($Accepted !== false) {
               stream_set_blocking($Accepted, false);
               $Peer = $Accepted;
            }
         }
         if ($Peer !== null && $pieces === [] && $buffer !== 'answered') {
            $buffer .= (string) @fread($Peer, 65535);
            if (strlen($buffer) >= 2 && strlen($buffer) >= 2 + (int) unpack('n', $buffer)[1]) {
               $Asked = DNS::decode(substr($buffer, 2));
               if ($Asked instanceof Message) {
                  $Records = [];
                  for ($index = 0; $index < 12; $index++) {
                     $Records[] = new Record($Asked->Questions[0]->name, DNS::TYPE_TXT, 60, [str_repeat('t', 100)]);
                  }
                  $reply = (string) DNS::encode(new Message(id: $Asked->id, response: true, recursion: true, recursive: true, Questions: $Asked->Questions, Answers: $Records));
                  $frame = pack('n', strlen($reply)) . $reply;
                  $pieces = str_split($frame, (int) ceil(strlen($frame) / 24));
                  $buffer = 'answered';
               }
            }
         }
         if ($Peer !== null && $pieces !== []) {
            fwrite($Peer, (string) array_shift($pieces));
         }

         return null;
      };

      // @ The query on a worker-like reactor, inside a Fiber, through that bridge
      $Loop = Harness::loop();
      $Query = new Query(
         name: 'trickle.test',
         types: [DNS::TYPE_TXT],
         nameservers: ['127.0.0.1'],
         port: $port,
         timeout: 2.0,
         attempts: 1,
         start: 0,
         EDNS: false,
         Cache: new Cache()
      );
      new Fiber(static function () use ($Query, $Loop, $Bridge): void {
         $Query->execute($Loop, $Bridge);
      })->start();

      if (is_resource($Peer)) {
         fclose($Peer);
      }
      fclose($TCP);
      fclose($UDP);

      $Answer = $Query->Answers[DNS::TYPE_TXT] ?? null;
      yield assert(
         assertion: $Answer?->Outcome === Outcomes::Answered && count($Answer->Records) === 12 && $pieces === [],
         description: 'a TCP answer read in 24 quick pieces is answered: '
            . json_encode([$Answer?->Outcome->name, count($Answer->Records ?? []), $Query->faults, $wakes])
      );
      Harness::restore();

      // @ Parked on a worker: the TCP answer arrives in 4 pieces 60 ms apart, each wake on the reactor
      $Harness = Harness::start('dns-tcp');
      try {
         $Harness->Nameserver->script('big.test', DNS::TYPE_TXT, [
            'truncated' => true,
            'tcp' => ['answers' => [['big.test', DNS::TYPE_TXT, 60, ['over tcp']]], 'trickle' => [4, 0.06]],
         ]);
         $DNS = new DNS_Client_CLI()->configure($Harness->configure(['timeout' => 2]));
         $Suspend = static fn (mixed $value = null): mixed => Fiber::suspend($value);
         $Parked = null;
         $started = hrtime(true);
         Harness::run([
            static function () use ($DNS, $Suspend, &$Parked): void {
               $Parked = $DNS->query('big.test', DNS::TYPE_TXT, $Suspend);
            },
         ], 6.0);
         $took = (hrtime(true) - $started) / 1_000_000_000;
         $transports = array_column($Harness->Nameserver->log(), 'transport');
      }
      finally {
         $Harness->stop();
      }
      yield assert(
         assertion: $Parked?->Outcome === Outcomes::Answered && ($Parked->Records[0]->data ?? null) === ['over tcp']
            && $took >= 0.15 && $transports === ['udp', 'tcp'],
         description: 'a parked lookup waits on the reactor for a TCP answer in slow pieces: '
            . json_encode([$Parked?->Outcome->name, $took, $transports])
      );
   }
);
