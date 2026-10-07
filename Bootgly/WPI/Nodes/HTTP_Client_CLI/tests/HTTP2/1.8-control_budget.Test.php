<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Errors;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Modules\HTTP2\Settings;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Session;


return new Test(
   description: 'It should charge peer-forced answers to a debt bounded by the quota, clamped by the transport backlog',
   test: new Assertions(Case: function (): Generator {
      // ? The engine must expose the control budget (quota + debt + feed() backlog)
      if (property_exists(Session::class, 'quota') === false) {
         $Evidence = [
            'quota' => false,
            'debt' => property_exists(Session::class, 'debt'),
            'feed' => (new ReflectionMethod(Session::class, 'feed'))->getNumberOfParameters()
         ];
         yield new Assertion(
            description: 'The engine has no control budget (Session::$quota missing): ' . json_encode($Evidence),
         )
            ->expect([property_exists(Session::class, 'quota'), property_exists(Session::class, 'debt')])
            ->to->be([true, true])
            ->assert();
         // :
         return;
      }

      // ! Outbox frame parser — the inline unpack idiom, under test control
      $Parse = static function (string $raw): array {
         $frames = [];
         $length = strlen($raw);
         $offset = 0;
         while ($length - $offset >= 9) {
            $head = unpack('Nword/Cflags/Nstream', $raw, $offset);
            $size = $head['word'] >> 8;
            $frames[] = [
               'type' => $head['word'] & 0xff,
               'flags' => $head['flags'],
               'stream' => $head['stream'] & 0x7fffffff,
               'payload' => substr($raw, $offset + 9, $size)
            ];
            $offset += 9 + $size;
         }
         return $frames;
      };
      // ! Frames of one type (and flag mask) in the outbox, per stream
      $Count = static function (array $frames, int $type, int $flags = 0, null|int $stream = null): int {
         $total = 0;
         foreach ($frames as $frame) {
            if (
               $frame['type'] === $type
               && ($frame['flags'] & $flags) === $flags
               && ($stream === null || $frame['stream'] === $stream)
            ) {
               $total++;
            }
         }
         return $total;
      };
      // ! A settled session (debt 9: the SETTINGS ACK) with one GET stream
      //   open and the transport flushed (outbox moved out)
      $Prepare = static function (int $quota): Session {
         $Session = new Session;
         $Session->quota = $quota;
         $Session->feed(Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, (new Settings)->pack()));
         $Session->open('GET', 'https', 'example.com', '/', []);
         $Session->outbox = '';
         return $Session;
      };
      $Ping = static fn (int $n): string => Frame::pack(HTTP2::FRAME_PING, 0, 0, pack('J', $n));
      // ! The GOAWAY the engine queues on a connection error
      $Goaway = static fn (Errors $Error): string => Frame::pack(
         HTTP2::FRAME_GOAWAY, 0, 0, pack('NN', 0, $Error->value)
      );

      // # (a) PING flood — quota = 17 × k (one PING ACK = 17 octets)
      $k = 4;
      $quota = 17 * $k;
      $Session = $Prepare($quota);
      $results = [];
      // @@ k PINGs, each fed while every earlier answer is still unsent
      for ($i = 0; $i < $k; $i++) {
         $results[] = $Session->feed($Ping($i), strlen($Session->outbox));
      }
      $debt = $Session->debt;
      // @ One more forced answer past the quota
      $tripped = $Session->feed($Ping($k), strlen($Session->outbox));
      $frames = $Parse($Session->outbox);
      $Evidence = [
         'results' => $results,
         'debt' => $debt,
         'tripped' => $tripped,
         'error' => $Session->error?->name,
         'closing' => $Session->closing,
         'outbox' => strlen($Session->outbox),
         'acks' => $Count($frames, HTTP2::FRAME_PING, HTTP2::FLAG_ACK),
         'frames' => count($frames)
      ];
      yield new Assertion(
         description: '(a) k PINGs fit the quota (debt = 17k); the (k+1)-th trips EnhanceYourCalm: ' . json_encode($Evidence),
      )
         ->expect([
            $results,
            $debt,
            $tripped,
            $Session->error,
            $Session->closing
         ])
         ->to->be([
            array_fill(0, $k, true),
            $quota,
            false,
            Errors::EnhanceYourCalm,
            true
         ])
         ->assert();

      yield new Assertion(
         description: '(a) The tripping ACK is never queued: k PING ACKs + GOAWAY(EnhanceYourCalm), outbox = quota + 17: ' . json_encode($Evidence),
      )
         ->expect([
            $Count($frames, HTTP2::FRAME_PING, HTTP2::FLAG_ACK),
            count($frames),
            substr($Session->outbox, -17) === $Goaway(Errors::EnhanceYourCalm),
            strlen($Session->outbox)
         ])
         ->to->be([$k, $k + 1, true, $quota + 17])
         ->assert();

      $record = $Session->done[1] ?? null;
      $Evidence = ['record' => $record === null ? null : [
         'error' => $record['error']?->name,
         'status' => $record['status'],
         'retryable' => $record['retryable']
      ]];
      yield new Assertion(
         description: '(a) The unheaded stream fails as "Control Flood", never retryable: ' . json_encode($Evidence),
      )
         ->expect([
            $record['error'] ?? null,
            $record['status'] ?? null,
            $record['retryable'] ?? true,
            $Session->opened
         ])
         ->to->be([Errors::EnhanceYourCalm, 'Control Flood', false, 0])
         ->assert();

      // # (b) SETTINGS flood — empty SETTINGS frames, 9-octet ACK each
      $quota = 9 * $k;
      $Session = $Prepare($quota);
      $results = [];
      // @@
      for ($i = 0; $i < $k; $i++) {
         $results[] = $Session->feed(Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0), strlen($Session->outbox));
      }
      $debt = $Session->debt;
      // @
      $tripped = $Session->feed(Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0), strlen($Session->outbox));
      $frames = $Parse($Session->outbox);
      $record = $Session->done[1] ?? null;
      $Evidence = [
         'results' => $results,
         'debt' => $debt,
         'tripped' => $tripped,
         'error' => $Session->error?->name,
         'outbox' => strlen($Session->outbox),
         'acks' => $Count($frames, HTTP2::FRAME_SETTINGS, HTTP2::FLAG_ACK),
         'status' => $record['status'] ?? null,
         'retryable' => $record['retryable'] ?? null
      ];
      yield new Assertion(
         description: '(b) k SETTINGS fit the quota (debt = 9k); the (k+1)-th trips "Control Flood": ' . json_encode($Evidence),
      )
         ->expect([
            $results,
            $debt,
            $tripped,
            $Session->error,
            $Session->closing,
            $Count($frames, HTTP2::FRAME_SETTINGS, HTTP2::FLAG_ACK),
            strlen($Session->outbox) <= $quota + 34,
            substr($Session->outbox, -17) === $Goaway(Errors::EnhanceYourCalm),
            $record['status'] ?? null,
            $record['retryable'] ?? true
         ])
         ->to->be([
            array_fill(0, $k, true),
            $quota,
            false,
            Errors::EnhanceYourCalm,
            true,
            $k,
            true,
            true,
            'Control Flood',
            false
         ])
         ->assert();

      // # (c) WINDOW_UPDATE replenishes are peer-forced answers too
      $replenish = Session::$replenish;
      Session::$replenish = 32768;
      try {
         $chunk = str_repeat('d', 16384);
         // ! 4 × 16384 DATA octets = 2 replenish rounds (connection + stream)
         $flood = str_repeat(Frame::pack(HTTP2::FRAME_DATA, 0, 1, $chunk), 4);
         $Respond = static function (int $quota) use ($Prepare): Session {
            $Session = $Prepare($quota);
            $Session->feed(Frame::pack(
               HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS, 1,
               HPACK::encode([[':status', '200']])
            ));
            $Session->outbox = '';
            return $Session;
         };

         // @ Unbounded: count the charge
         $Session = $Respond(0);
         $fed = $Session->feed($flood, strlen($Session->outbox));
         $frames = $Parse($Session->outbox);
         $updates = $Count($frames, HTTP2::FRAME_WINDOW_UPDATE);
         $Evidence = [
            'fed' => $fed,
            'connection' => $Count($frames, HTTP2::FRAME_WINDOW_UPDATE, 0, 0),
            'stream' => $Count($frames, HTTP2::FRAME_WINDOW_UPDATE, 0, 1),
            'debt' => $Session->debt
         ];
         yield new Assertion(
            description: '(c) DATA past Session::$replenish → debt = 13 × WINDOW_UPDATEs queued (2 connection + 2 stream): ' . json_encode($Evidence),
         )
            ->expect([$fed, $Evidence['connection'], $Evidence['stream'], $Session->debt, 13 * $updates])
            ->to->be([true, 2, 2, 52, 52])
            ->assert();

         // @ quota 26: round 2's connection WINDOW_UPDATE (debt 39) trips
         $ConnectionSession = $Respond(26);
         $connection = $ConnectionSession->feed($flood, strlen($ConnectionSession->outbox));
         // @ quota 39: round 2's stream WINDOW_UPDATE (debt 52) trips
         $StreamSession = $Respond(39);
         $stream = $StreamSession->feed($flood, strlen($StreamSession->outbox));
         $Evidence = [
            'connection' => [
               'fed' => $connection,
               'error' => $ConnectionSession->error?->name,
               'updates' => $Count($Parse($ConnectionSession->outbox), HTTP2::FRAME_WINDOW_UPDATE),
               'status' => $ConnectionSession->done[1]['status'] ?? null
            ],
            'stream' => [
               'fed' => $stream,
               'error' => $StreamSession->error?->name,
               'updates' => $Count($Parse($StreamSession->outbox), HTTP2::FRAME_WINDOW_UPDATE),
               'status' => $StreamSession->done[1]['status'] ?? null
            ]
         ];
         yield new Assertion(
            description: '(c) Each WINDOW_UPDATE site trips "Control Flood" past the quota, unqueued: ' . json_encode($Evidence),
         )
            ->expect($Evidence)
            ->to->be([
               'connection' => [
                  'fed' => false,
                  'error' => 'EnhanceYourCalm',
                  'updates' => 2,
                  'status' => 'Control Flood'
               ],
               'stream' => [
                  'fed' => false,
                  'error' => 'EnhanceYourCalm',
                  'updates' => 3,
                  'status' => 'Control Flood'
               ]
            ])
            ->assert();
      }
      finally {
         Session::$replenish = $replenish;
      }

      // # (d) Clamp — a peer that reads its answers never trips
      $quota = 17 * $k;
      $Session = $Prepare($quota);
      $results = [];
      $acks = 0;
      $n = 0;
      // @@ 4 × quota of PING ACKs, k per feed; the transport drains between feeds
      for ($round = 0; $round < 4; $round++) {
         $batch = '';
         for ($i = 0; $i < $k; $i++) {
            $batch .= $Ping($n++);
         }
         $results[] = $Session->feed($batch, 0);
         $acks += $Count($Parse($Session->outbox), HTTP2::FRAME_PING, HTTP2::FLAG_ACK);
         $Session->outbox = '';
      }
      $Evidence = [
         'results' => $results,
         'acks' => $acks,
         'debt' => $Session->debt,
         'error' => $Session->error?->name
      ];
      yield new Assertion(
         description: '(d) Backlog 0 between feeds clears the debt: 4 × quota answered, never tripped: ' . json_encode($Evidence),
      )
         ->expect([$results, $acks, $Session->debt, $Session->error])
         ->to->be([array_fill(0, 4, true), 4 * $k, $quota, null])
         ->assert();

      // # (e) Partial clamp — debt follows the backlog down, never up
      $Session = $Prepare(0);
      $Session->feed(str_repeat($Ping(7), 5), 0);
      $full = $Session->debt;
      $Session->feed('', 1000);
      $above = $Session->debt;
      $Session->feed('', 40);
      $partial = $Session->debt;
      $Session->feed('', -5);
      $negative = $Session->debt;
      $Evidence = [
         'full' => $full,
         'above' => $above,
         'partial' => $partial,
         'negative' => $negative
      ];
      yield new Assertion(
         description: '(e) debt 85 → backlog 1000 keeps 85 → backlog 40 lowers it to 40 → backlog -5 floors at 0: ' . json_encode($Evidence),
      )
         ->expect([$full, $above, $partial, $negative])
         ->to->be([85, 85, 40, 0])
         ->assert();

      // # (f) quota 0 = unbounded
      $Session = $Prepare(0);
      $fed = true;
      // @@ 1000 PINGs, nothing ever drained
      for ($i = 0; $i < 1000 && $fed; $i++) {
         $fed = $Session->feed($Ping($i), strlen($Session->outbox));
      }
      $Evidence = [
         'fed' => $fed,
         'debt' => $Session->debt,
         'acks' => $Count($Parse($Session->outbox), HTTP2::FRAME_PING, HTTP2::FLAG_ACK),
         'error' => $Session->error?->name
      ];
      yield new Assertion(
         description: '(f) quota 0 answers 1000 PINGs held unsent (debt 17000) without tripping: ' . json_encode($Evidence),
      )
         ->expect([$fed, $Session->debt, $Evidence['acks'], $Session->error])
         ->to->be([true, 17000, 1000, null])
         ->assert();

      // # (g) Application bytes are never charged
      $Session = new Session;
      $Session->quota = 1_000_000;
      $Closed = new Settings;
      $Closed->window = 0;
      $Session->feed(Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, $Closed->pack()));
      $settled = $Session->debt;
      // @ HEADERS out, the 25-octet body parked behind a zero stream window
      $id = $Session->open('POST', 'https', 'example.com', '/u', [], str_repeat('x', 25));
      $opened = $Session->debt;
      $Session->outbox = '';
      // @ The window grant pumps the parked DATA out
      $Open = new Settings;
      $Open->window = 100;
      $fed = $Session->feed(Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, $Open->pack()), strlen($Session->outbox));
      $frames = $Parse($Session->outbox);
      $Evidence = [
         'settled' => $settled,
         'opened' => $opened,
         'fed' => $fed,
         'debt' => $Session->debt,
         'acks' => $Count($frames, HTTP2::FRAME_SETTINGS, HTTP2::FLAG_ACK),
         'data' => $Count($frames, HTTP2::FRAME_DATA, HTTP2::FLAG_END_STREAM, $id),
         'outbox' => strlen($Session->outbox)
      ];
      yield new Assertion(
         description: '(g) open() and the pumped DATA are uncharged — debt is the SETTINGS ACK alone: ' . json_encode($Evidence),
      )
         ->expect([$settled, $opened, $fed, $Session->debt, $Evidence['acks'], $Evidence['data'], strlen($Session->outbox)])
         ->to->be([9, 9, true, 9, 1, 1, 9 + 9 + 25])
         ->assert();

      // # (h) A malformed-frame failure keeps the h2 outcome
      $Session = $Prepare(0);
      $other = $Session->open('GET', 'https', 'example.com', '/', []);
      $Session->feed(Frame::pack(
         HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS, 1,
         HPACK::encode([[':status', '200']])
      ));
      // @ PING with a 7-octet payload (RFC 9113 §6.7 — FRAME_SIZE_ERROR)
      $fed = $Session->feed(Frame::pack(HTTP2::FRAME_PING, 0, 0, str_repeat("\0", 7)));
      $Outcome = static fn (array $done, int $id): array => isSet($done[$id])
         ? ['status' => $done[$id]['status'], 'retryable' => $done[$id]['retryable']]
         : ['status' => 'missing', 'retryable' => null];
      $Evidence = [
         'fed' => $fed,
         'error' => $Session->error?->name,
         'headed' => $Outcome($Session->done, 1),
         'unheaded' => $Outcome($Session->done, $other)
      ];
      yield new Assertion(
         description: '(h) FrameSize fail(): status null, retryable === (headed === false): ' . json_encode($Evidence),
      )
         ->expect($Evidence)
         ->to->be([
            'fed' => false,
            'error' => 'FrameSize',
            'headed' => ['status' => null, 'retryable' => false],
            'unheaded' => ['status' => null, 'retryable' => true]
         ])
         ->assert();

      // # (i) The client's own RST_STREAM and GOAWAY are never charged
      $Session = $Prepare(17);
      $before = $Session->debt;
      $Session->reset(1, Errors::Cancel);
      $Session->close();
      $frames = $Parse($Session->outbox);
      $Evidence = [
         'before' => $before,
         'after' => $Session->debt,
         'resets' => $Count($frames, HTTP2::FRAME_RST_STREAM),
         'goaways' => $Count($frames, HTTP2::FRAME_GOAWAY)
      ];
      yield new Assertion(
         description: '(i) reset() and close() queue RST_STREAM and GOAWAY without charging the debt: ' . json_encode($Evidence),
      )
         ->expect($Evidence)
         ->to->be(['before' => 9, 'after' => 9, 'resets' => 1, 'goaways' => 1])
         ->assert();

      // # (j) No backlog given — nothing is known to have drained: the debt is kept
      $Session = $Prepare(17 * 3 + 9);
      $results = [];
      // @@ Four PINGs fed without a backlog; the clamp never runs
      for ($i = 0; $i < 4; $i++) {
         $results[] = $Session->feed($Ping($i));
      }
      $Evidence = ['results' => $results, 'debt' => $Session->debt, 'error' => $Session->error?->name];
      yield new Assertion(
         description: '(j) feed() without a backlog keeps the debt: the 4th PING trips EnhanceYourCalm: ' . json_encode($Evidence),
      )
         ->expect($Evidence)
         ->to->be(['results' => [true, true, true, false], 'debt' => 9 + 17 * 4, 'error' => 'EnhanceYourCalm'])
         ->assert();
   })
);
