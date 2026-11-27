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
use Bootgly\ACI\Observability;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Endpoints\Servers\Encoder;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection\Lease;


/**
 * The UDP collectors, in process: a peer counts accepted only once its
 * admission is final, and closed only if it was counted — an admission rolled
 * back is a refusal, never an accepted + closed pair; every refusal counts
 * under its reason; a datagram in counts its bytes and an arrival, a reply
 * out its bytes and a departure; every removal of a counted peer — its
 * release or the ledger rebuild — counts it closed once. With collectors on,
 * a worker registers the transport series (with datagrams) and the loop-lag
 * timer once per registry; with them off, nothing is built.
 */
return new Test(
   description: 'UDP collectors: accepted/closed/active, rollback and refusals by reason, datagram bytes, zero cost when off',
   test: function () {
      $Socket = null;
      $Source = null;
      $segments = Display::$segments;
      $PreviousAlarm = pcntl_signal_get_handler(SIGALRM);
      $OldInstance = Observability::$Instance;
      $collectors = UDP_Server_CLI::$collectors;
      $OldDecoder = UDP_Server_CLI::$Decoder;
      $OldEncoder = UDP_Server_CLI::$Encoder;
      Timer::init(static function (): void {});
      Display::show(Display::NONE);

      try {
         Timer::del();
         $Server = new UDP_Server_CLI(Modes::Test);
         $Server->configure(new UDP_Server_CLI\Configs(host: '127.0.0.1', port: 19996, workers: 1));
         $Socket = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
         yield assert(
            assertion: $Socket !== false,
            description: 'the shared UDP server socket is bound'
         );
         new ReflectionProperty($Server, 'Socket')->setValue($Server, $Socket);
         $Collection = $Server->Connections;
         $Counted = new ReflectionProperty(Connections::class, 'Counted');
         $active = static fn (): int => count($Counted->getValue() ?? []);

         // @ Off: nothing observes the totals
         UDP_Server_CLI::$collectors = false;
         Observability::$Instance = null;
         $Observe = new ReflectionMethod(UDP_Server_CLI::class, 'observe');
         $Observe->invoke($Server);
         yield assert(
            assertion: Observability::$Instance === null,
            description: 'collectors off: no registry is built'
         );

         // @ Three admitted, two closed
         $Admitted = [];
         foreach ([45001, 45002, 45003] as $port) {
            $Admitted[] = $Collection->accept("127.0.0.1:$port");
         }
         yield assert(
            assertion: count(array_filter($Admitted)) === 3 && Connections::$accepted === 3,
            description: 'three new peers are accepted'
         );
         $Admitted[0]->close();
         $Admitted[1]->close();
         // ! A closed peer's tuple is released with its last reference
         unset($Admitted[0], $Admitted[1]);
         gc_collect_cycles();
         Lease::drain();
         yield assert(
            assertion: [Connections::$accepted, Connections::$closed, $active()] === [3, 2, 1],
            description: "accept 3, close 2: accepted 3, closed 2, active 1 — got "
               . json_encode([Connections::$accepted, Connections::$closed, $active()])
         );
         $Again = $Collection->accept('127.0.0.1:45003');
         $Twice = $Collection->accept('127.0.0.1:45004');
         $Twice?->close();
         $Twice?->close();
         unset($Twice);
         gc_collect_cycles();
         Lease::drain();
         yield assert(
            assertion: $Again === $Admitted[2]
               && [Connections::$accepted, Connections::$closed, $active()] === [4, 3, 1],
            description: 'a known peer is not counted again; a peer closed twice counts closed once'
         );

         // @ Refusals: an unreadable address, a blacklisted IP
         $Collection->accept('garbage');
         Connections::$blacklist['127.0.0.2'] = true;
         $Collection->accept('127.0.0.2:1234');
         unset(Connections::$blacklist['127.0.0.2']);
         yield assert(
            assertion: Connections::$refused['peer'] === 1 && Connections::$refused['blacklist'] === 1,
            description: 'an unreadable peer and a blacklisted one count under their reasons'
         );

         // @ Rollback: a stale public entry whose destructor keeps replacing
         //   itself makes the mirror fail after the ledger committed
         $Stale = new class {
            public static int $left = 32;
            public function __destruct ()
            {
               if (--self::$left > 0) {
                  Connections::$Connections['127.0.0.1:45009'] = new self;
               }
            }
         };
         Connections::$Connections['127.0.0.1:45009'] = $Stale;
         $stale = $Stale::class;
         unset($Stale);
         $before = [Connections::$accepted, Connections::$closed, $active()];
         $Rolled = $Collection->accept('127.0.0.1:45009');
         // ! Stop the replacements, then drop the last one: no stale entry
         //   outlives this case
         $stale::$left = 0;
         unset(Connections::$Connections['127.0.0.1:45009']);
         yield assert(
            assertion: $Rolled === null && Connections::$refused['rollback'] === 1,
            description: 'an admission undone after its ledger commit counts as a rollback refusal'
         );
         yield assert(
            assertion: [Connections::$accepted, Connections::$closed, $active()] === $before,
            description: 'a rolled-back admission is neither accepted nor closed'
         );
         yield assert(
            assertion: array_keys(Connections::$refused) === Connections::REFUSALS
               && array_sum(Connections::$refused) === 3,
            description: 'every declared reason is present; only the three refusals counted'
         );

         // @ On: the transport series with datagrams and the loop-lag timer,
         //   once per registry
         UDP_Server_CLI::$collectors = true;
         $Loop = UDP_Server_CLI::$Event;
         $Repeaters = new ReflectionProperty(Select::class, 'Repeaters');
         $timers = $Loop instanceof Select ? count($Repeaters->getValue($Loop)) : 0;
         $Observe->invoke($Server);
         $Observe->invoke($Server);
         $Instance = Observability::$Instance;
         $metrics = $Instance instanceof Observability ? $Instance->Metrics->read() : [];
         $value = static fn (string $name): float => $metrics[$name]['series'][0]['value'] ?? -1.0;
         yield assert(
            assertion: count($metrics['server_connections_accepted_total']['series'] ?? []) === 1
               && isSet($metrics['server_received_datagrams_total'], $metrics['server_sent_datagrams_total'])
               && $metrics['server_connections_accepted_total']['series'][0]['labels'] === ['transport' => 'udp'],
            description: 'collectors on: registered once, with the datagram series, labelled udp'
         );
         yield assert(
            assertion: $value('server_connections_accepted_total') === 4.0
               && $value('server_connections_closed_total') === 3.0
               && $value('server_connections_active') === 1.0,
            description: 'the series read the transport totals; active is the live counted peers'
         );
         yield assert(
            assertion: $Loop instanceof Select
               && count($Repeaters->getValue($Loop)) - $timers === 1
               && isSet($metrics['event_loop_lag_seconds']),
            description: 'collectors on: the loop-lag series, with one recurring timer on the worker loop'
         );

         // @ Bytes: a datagram in counts its bytes and an arrival; the reply
         //   out counts its bytes and a departure
         UDP_Server_CLI::$Decoder = null;
         UDP_Server_CLI::$Encoder = new class implements Encoder {
            public static function encode (Packages $Package, null|int &$length): string
            {
               // :
               return "pong:{$Package->input}";
            }
         };
         stream_set_blocking($Socket, false);
         $target = (string) stream_socket_get_name($Socket, false);
         $Source = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
         $totals = [Connections::$received, Connections::$arrivals, Connections::$sent, Connections::$departures];
         stream_socket_sendto($Source, 'ping', 0, $target);
         usleep(20_000);
         $Collection->Router->reading($Socket);
         $read = [$Source];
         $write = null;
         $except = null;
         $reply = @stream_select($read, $write, $except, 1, 0) === 1
            ? (string) stream_socket_recvfrom($Source, 65_535)
            : '';
         $counted = [
            Connections::$received - $totals[0],
            Connections::$arrivals - $totals[1],
            Connections::$sent - $totals[2],
            Connections::$departures - $totals[3],
         ];
         $evidence = json_encode([...$counted, $reply]);
         yield assert(
            assertion: $counted === [4, 1, 9, 1] && $reply === 'pong:ping',
            description: "a datagram in counts 4 bytes and 1 arrival, its reply 9 bytes and 1 departure — got {$evidence}"
         );

         // @ Every peer released: accepted − closed is the live count, 0
         foreach (Connections::$Connections as $Connection) {
            if ($Connection instanceof Connections\Connection) {
               $Connection->close();
            }
         }
         unset($Connection, $Admitted, $Again);
         gc_collect_cycles();
         Lease::drain();
         $evidence = json_encode([Connections::$accepted, Connections::$closed, $active(), count(Connections::$Connections)]);
         yield assert(
            assertion: Connections::$Connections === [] && $active() === 0
               && Connections::$accepted - Connections::$closed === 0,
            description: "every peer released: accepted − closed is the live count, 0 — got {$evidence}"
         );

         // @ Ceilings: a new peer over the per-IP ceiling counts under ip, one
         //   over the global ceiling under capacity
         $Server->configure(new UDP_Server_CLI\Configs(
            host: '127.0.0.1',
            port: 19996,
            workers: 1,
            maxConnections: 2,
            maxConnectionsPerIP: 1,
            collectors: true
         ));
         $refused = Connections::$refused;
         $First = $Collection->accept('127.0.0.1:46001');
         $Collection->accept('127.0.0.1:46002');
         $Second = $Collection->accept('127.0.0.2:46003');
         $Collection->accept('127.0.0.3:46004');
         $counted = [];
         foreach (Connections::REFUSALS as $reason) {
            $counted[$reason] = Connections::$refused[$reason] - $refused[$reason];
         }
         $evidence = json_encode($counted);
         yield assert(
            assertion: $First !== null && $Second !== null
               && $counted === [
                  'peer' => 0, 'blacklist' => 0, 'capacity' => 1,
                  'ip' => 1, 'rollback' => 0, 'budget' => 0
               ],
            description: "one refusal over the per-IP ceiling under ip, one over the global ceiling under capacity — got {$evidence}"
         );

         // @ Budget: a known peer whose public entry keeps being replaced
         //   exhausts the finite resolution budget of its datagram
         $budget = Connections::$refused['budget'];
         $Mask = new class {
            public static int $left = 0;
            public function __destruct ()
            {
               if (--self::$left > 0) {
                  Connections::$Connections['127.0.0.1:46001'] = new self;
               }
            }
         };
         $mask = $Mask::class;
         // ! More replacements than every resolution step of one datagram can
         //   consume (8 steps of 8 mirror attempts each)
         $mask::$left = 100;
         Connections::$Connections['127.0.0.1:46001'] = $Mask;
         unset($Mask);
         $Masked = $Collection->accept('127.0.0.1:46001');
         $mask::$left = 0;
         unset(Connections::$Connections['127.0.0.1:46001']);
         $Unmasked = $Collection->accept('127.0.0.1:46001');
         yield assert(
            assertion: $Masked === null && Connections::$refused['budget'] - $budget === 1
               && $Unmasked === $First,
            description: 'a datagram that exhausts its resolution budget counts under budget; the next one resolves the peer'
         );

         // @ Rebuild: the ledger rebuild of a rollback settles a peer whose
         //   Connection died before its release drained — closed once, and
         //   never again by that release
         $closed = Connections::$closed;
         $live = $active();
         $Second->close();
         unset($Second);
         gc_collect_cycles();
         $pending = [Connections::$closed - $closed, $live - $active()];
         new ReflectionMethod(Connections::class, 'rebuild')->invoke(null);
         $rebuilt = [Connections::$closed - $closed, $live - $active()];
         Lease::drain();
         $drained = [Connections::$closed - $closed, $live - $active()];
         $evidence = json_encode([$pending, $rebuilt, $drained]);
         yield assert(
            assertion: $pending === [0, 0] && $rebuilt === [1, 1] && $drained === [1, 1],
            description: "the rebuild settles a dead peer once, its release never again — got {$evidence}"
         );

         // @ The last peer released: accepted − closed is the live count, 0
         $First->close();
         unset($First, $Unmasked);
         gc_collect_cycles();
         Lease::drain();
         $evidence = json_encode([Connections::$accepted, Connections::$closed, $active()]);
         yield assert(
            assertion: $active() === 0 && Connections::$accepted - Connections::$closed === 0,
            description: "every admission counted is closed once: accepted − closed is 0 — got {$evidence}"
         );
      }
      finally {
         // ! Self-replacing fixtures stop replacing before the mirror is cleared
         foreach ([$stale ?? null, $mask ?? null] as $fixture) {
            if ($fixture !== null) {
               $fixture::$left = 0;
            }
         }
         foreach (Connections::$Connections as $Connection) {
            if ($Connection instanceof Connections\Connection) {
               $Connection->close();
            }
         }
         Connections::$Connections = [];
         Connections::$blacklist = [];
         unset($Connection, $Admitted, $Again, $Rolled, $First, $Second, $Masked, $Unmasked);
         gc_collect_cycles();
         Lease::drain();
         Timer::del();
         gc_collect_cycles();
         Lease::drain();
         pcntl_alarm(0);
         if ($Source !== null && $Source !== false) {
            @fclose($Source);
         }
         if ($Socket !== null && $Socket !== false) {
            @fclose($Socket);
         }
         UDP_Server_CLI::$Decoder = $OldDecoder;
         UDP_Server_CLI::$Encoder = $OldEncoder;
         UDP_Server_CLI::$collectors = $collectors;
         Observability::$Instance = $OldInstance;
         pcntl_signal(SIGALRM, $PreviousAlarm === false ? SIG_DFL : $PreviousAlarm);
         Display::show($segments);
      }
   }
);
