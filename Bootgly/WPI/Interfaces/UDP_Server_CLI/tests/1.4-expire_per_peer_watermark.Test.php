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
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection;


return new Test(
   description: 'It should keep the expire()/limit() activity watermarks per peer, not shared across every Connection',
   test: new Assertions(Case: function (): Generator {
      $Socket = null;

      try {
         // ! Hermetic shared state — direct Connection objects are outside
         //   the admitted registry/central supervisor; expire()/limit() can
         //   therefore be exercised deterministically with stats disabled.
         Timer::del();
         Connections::$Connections = [];
         Connections::$blacklist = [];
         Connections::$stats = false;

         // ! One real bound UDP socket shared by every peer Connection —
         //   the real UDP topology
         $Socket = stream_socket_server(
            'udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND
         );

         yield new Assertion(
            description: 'the shared UDP server socket is bound',
         )
            ->expect($Socket !== false)
            ->to->be(true)
            ->assert();
         if ($Socket === false) {
            return;
         }

         // # Case A — two peers with differing write counts must BOTH expire
         //   once idle past the timeout
         $A = new Connection($Socket, '127.0.0.1:1111');
         $B = new Connection($Socket, '127.0.0.1:2222');
         $A->writes = 1;
         $B->writes = 2;

         // @ Tick 1 — both peers show fresh activity: watermarks sync, no
         //   expiration either way
         $A->expire(30);
         $B->expire(30);

         // @ Idle both peers past the timeout, then tick 2
         $A->used = time() - 31;
         $B->used = time() - 31;
         $observed = [
            'A' => $A->expire(30),
            'B' => $B->expire(30),
            'closedA' => $A->status > Connections::STATUS_ESTABLISHED,
            'closedB' => $B->status > Connections::STATUS_ESTABLISHED,
         ];

         yield new Assertion(
            description: 'two idle peers with differing write counts are both'
               . ' reclaimed on the next tick',
            fallback: 'A shared watermark masked the idleness: '
               . json_encode($observed)
         )
            ->expect(
               $observed,
               Op::Identical,
               [
                  'A' => true,
                  'B' => true,
                  'closedA' => true,
                  'closedB' => true,
               ],
            )
            ->assert();

         // # Case B — single-peer control: expires in HEAD and post-fix alike
         $C = new Connection($Socket, '127.0.0.1:3333');
         $C->writes = 3;
         $C->expire(30);
         $C->used = time() - 31;

         yield new Assertion(
            description: 'a lone idle peer is reclaimed on schedule (control)',
         )
            ->expect($C->expire(30))
            ->to->be(true)
            ->assert();

         // # Case C — limit() must measure each peer's own writes, not the
         //   delta against whatever peer ticked before it
         $D = new Connection($Socket, '127.0.0.1:4444');
         $E = new Connection($Socket, '127.0.0.1:5555');
         $D->writes = 3;
         $E->writes = 6;

         $observed = [
            'D' => $D->limit(5),
            'E' => $E->limit(5),
            'blacklisted' => isSet(Connections::$blacklist['127.0.0.1']),
            'closedE' => $E->status > Connections::STATUS_ESTABLISHED,
         ];

         yield new Assertion(
            description: 'the rate limiter blacklists the abusive peer on its'
               . ' own count while sparing the quiet one',
            fallback: 'The shared watermark hid the abusive peer: '
               . json_encode($observed)
         )
            ->expect(
               $observed,
               Op::Identical,
               [
                  'D' => false,
                  'E' => true,
                  'blacklisted' => true,
                  'closedE' => true,
               ],
            )
            ->assert();
      }
      finally {
         // @ Cleanup
         foreach (Connections::$Connections as $Connection) {
            $Connection->close();
         }
         Connections::$Connections = [];
         Connections::$blacklist = [];
         Connections::$stats = false;
         Timer::del();

         if ($Socket !== null && $Socket !== false) {
            @fclose($Socket);
         }
      }
   })
);
