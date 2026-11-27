<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Observability;
use Bootgly\ACI\Events\Loops;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Endpoints\Servers\Encoder;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Events;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;


/**
 * The TCP collectors, in process on loopback: a connection counts accepted
 * where it is registered and closed where it is unregistered, so accepted −
 * closed is the live count; every refusal counts under its reason, a loop
 * shed as accepted + closed + refused{loop}; bytes count what a read received
 * and only what a write delivered — a short write and each drain after it;
 * `stats reset` never zeroes the totals. With collectors on, a worker
 * registers the transport series and the loop-lag timer on the registry
 * once; with them off, nothing is built.
 */
return new Test(
   description: 'TCP collectors: accepted/closed/active, refusals by reason, received and delivered bytes, zero cost when off',
   test: function () {
      // ! A loop whose registration can be refused
      $Loop = new class implements Events, Loops, Scheduler {
         public bool $accept = true;
         public function add ($Socket, int $flag, $payload): bool { return $this->accept; }
         public function del ($Socket, int $flag): bool { return true; }
         public function loop (): void {}
         public function destroy (): void {}
         public function schedule (Fiber $Fiber, mixed $value = null, int $flag = self::SCHEDULE_READ): bool { return false; }
         public function defer (float|int $deadline, Closure $Callback): int { return 0; }
         public function cancel (int $ID): bool { return false; }
         public function interrupt (Fiber $Fiber, Throwable $Throwable): bool { return false; }
      };
      $segments = Display::$segments;
      $OldEvent = isSet(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
      $OldInstance = Observability::$Instance;
      $collectors = TCP_Server_CLI::$collectors;
      // ! Every other static this case writes
      $OldDecoder = TCP_Server_CLI::$Decoder;
      $OldEncoder = TCP_Server_CLI::$Encoder;
      $context = isSet(TCP_Server_CLI::$context) ? TCP_Server_CLI::$context : null;
      $ceilings = [
         TCP_Server_CLI::$maxConnections,
         TCP_Server_CLI::$headroom,
         TCP_Server_CLI::$maxConnectionsPerIP,
         TCP_Server_CLI::$maxPendingHandshakes,
      ];
      $socketTimeout = (string) ini_get('default_socket_timeout');
      $Clients = [];
      Display::show(Display::NONE);

      try {
         $Server = new TCP_Server_CLI(Modes::Test);
         TCP_Server_CLI::$Event = $Loop;
         // ! A loopback listener as the server's socket
         $Listener = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
         yield assert(
            assertion: is_resource($Listener),
            description: 'loopback listener'
         );
         stream_set_blocking($Listener, false);
         new ReflectionProperty(TCP_Server_CLI::class, 'Socket')->setValue($Server, $Listener);
         $address = (string) stream_socket_get_name($Listener, false);
         $open = static function () use ($address, &$Clients) {
            $Client = stream_socket_client("tcp://$address", $code, $message, 1.0);
            $Clients[] = $Client;
            usleep(20_000);
            return $Client;
         };
         $Collection = $Server->Connections;

         // @ Off: the totals are kept, nothing observes them
         TCP_Server_CLI::$collectors = false;
         Observability::$Instance = null;
         $Observe = new ReflectionMethod(TCP_Server_CLI::class, 'observe');
         $Observe->invoke($Server);
         yield assert(
            assertion: Observability::$Instance === null && Connections::$stats === false,
            description: 'collectors off: no registry is built and stats stay off'
         );

         // @ Three accepted, two closed
         for ($i = 0; $i < 3; $i++) {
            $open();
            $Collection->connect();
         }
         $Connections = array_values(Connections::$Connections);
         $Connections[0]->close();
         $Connections[1]->close();
         yield assert(
            assertion: [Connections::$accepted, Connections::$closed, count(Connections::$Connections)] === [3, 2, 1],
            description: 'accept 3, close 2: accepted 3, closed 2, active 1'
         );
         $Connections[1]->close();
         yield assert(
            assertion: Connections::$closed === 2,
            description: 'a second close() of one connection is not counted again'
         );

         // @ Refusals: an empty accept queue (no wait), a loop that refuses the socket
         $timeout = (string) ini_get('default_socket_timeout');
         ini_set('default_socket_timeout', '0');
         $Collection->connect();
         ini_set('default_socket_timeout', $timeout);
         $open();
         $Loop->accept = false;
         $Collection->connect();
         $Loop->accept = true;
         yield assert(
            assertion: Connections::$refused['accept'] === 1 && Connections::$refused['loop'] === 1,
            description: 'a failed accept and a loop shed count under their reasons'
         );
         yield assert(
            assertion: [Connections::$accepted, Connections::$closed, count(Connections::$Connections)] === [4, 3, 1],
            description: 'a loop shed is accepted + closed: accepted − closed stays the live count'
         );
         yield assert(
            assertion: array_keys(Connections::$refused) === Connections::REFUSALS
               && array_sum(Connections::$refused) === 2,
            description: 'every declared reason is present; only the two refusals counted'
         );

         // @ Bytes: a delivered write counts, a failed one does not
         Connections::$stats = true;
         $Live = $Connections[2];
         $Live->writing($Live->Socket, null, 'hello');
         yield assert(
            assertion: Connections::$sent === 5,
            description: 'a delivered write counts its bytes'
         );
         // ! Reset the peer: the next write fails
         $Peer = $Clients[2];
         $Raw = socket_import_stream($Peer);
         socket_set_option($Raw, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
         fclose($Peer);
         usleep(20_000);
         $Live->writing($Live->Socket, null, 'world');
         yield assert(
            assertion: Connections::$sent === 5,
            description: 'a failed write counts no bytes'
         );

         // @ `stats reset` zeroes the stats, never the totals
         Connections::$reads = 9;
         $Collection->{'@stats reset'};
         yield assert(
            assertion: Connections::$reads === 0
               && Connections::$accepted === 4 && Connections::$closed === 4
               && Connections::$sent === 5 && Connections::$refused['loop'] === 1,
            description: 'stats reset leaves the monotonic totals'
         );

         // @ On: registered once per registry, with the loop-lag timer on a Repeating loop
         TCP_Server_CLI::$collectors = true;
         Connections::$stats = false;
         $Select = new Select($Collection);
         TCP_Server_CLI::$Event = $Select;
         $Observe->invoke($Server);
         $Observe->invoke($Server);
         $Repeaters = new ReflectionProperty(Select::class, 'Repeaters');
         $Instance = Observability::$Instance;
         $metrics = $Instance instanceof Observability ? $Instance->Metrics->read() : [];
         yield assert(
            assertion: Connections::$stats === true && count($Repeaters->getValue($Select)) === 1,
            description: 'collectors on: stats on, one loop-lag timer'
         );
         yield assert(
            assertion: count($metrics['server_connections_accepted_total']['series'] ?? []) === 1
               && isSet($metrics['event_loop_lag_seconds'])
               && count($metrics['server_connections_refused_total']['series'] ?? []) === count(Connections::REFUSALS),
            description: 'registered once per registry, with one refusal series per reason'
         );
         $value = static fn (string $name): float => $metrics[$name]['series'][0]['value'];
         yield assert(
            assertion: $value('server_connections_accepted_total') === 4.0
               && $value('server_connections_closed_total') === 4.0
               && $value('server_connections_active') === 0.0
               && $value('server_sent_bytes_total') === 5.0
               && $metrics['server_connections_accepted_total']['series'][0]['labels'] === ['transport' => 'tcp'],
            description: 'the series read the transport totals, labelled tcp'
         );

         // @ Bytes on a live connection, on the worker's loop: a read counts
         //   what it received; a reply larger than the socket buffers counts
         //   what each write delivered — the short write, then every drain —
         //   never the rest still pending
         TCP_Server_CLI::$maxConnections = 0;
         TCP_Server_CLI::$headroom = 32;
         TCP_Server_CLI::$maxConnectionsPerIP = 0;
         TCP_Server_CLI::$context = [];
         TCP_Server_CLI::$Decoder = null;
         TCP_Server_CLI::$Encoder = new class implements Encoder {
            public static function encode (Packages $Package, null|int &$length): string
            {
               // : 1 MiB
               return str_repeat('pong', 262_144);
            }
         };
         $retained = TCP_Server_CLI::$pendingBytes;
         $Client = $open();
         $Collection->connect();
         $Served = array_values(Connections::$Connections)[0];
         // ! A small send buffer: the reply cannot leave in one write
         $Raw = socket_import_stream($Served->Socket);
         socket_set_option($Raw, SOL_SOCKET, SO_SNDBUF, 4_096);
         fwrite($Client, 'ping');
         usleep(20_000);
         $received = Connections::$received;
         $sent = Connections::$sent;
         $Served->reading($Served->Socket);
         $pending = strlen($Served->pendingBuffer);
         $delivered = Connections::$sent - $sent;
         yield assert(
            assertion: Connections::$received - $received === 4,
            description: 'a read counts the bytes it received'
         );
         yield assert(
            assertion: $pending > 0 && $delivered === 1_048_576 - $pending,
            description: "a short write counts what it delivered, never the rest still pending — delivered {$delivered}, pending {$pending}"
         );
         // @@ The client reads; each write readiness resumes the pending rest
         //    (bounded by time, not by turns: a delayed ACK can hold a turn)
         stream_set_blocking($Client, false);
         $wire = 0;
         $deadline = hrtime(true) + 5_000_000_000;
         while ($wire < 1_048_576 && hrtime(true) < $deadline) {
            do {
               $chunk = (string) fread($Client, 65_536);
               $wire += strlen($chunk);
            }
            while ($chunk !== '');

            if ($Served->pendingBuffer !== '') {
               $Served->writing($Served->Socket);
            }
            usleep(1_000);
         }
         $drained = Connections::$sent - $sent;
         yield assert(
            assertion: $Served->pendingBuffer === '' && $wire === 1_048_576 && $drained === 1_048_576,
            description: "every drain counts what it delivered: the whole reply, once — sent {$drained}, wire {$wire}"
         );

         // @ Refusals by reason, each counted once under its own: a peer gone
         //   before its accept, a blacklisted IP, then every admission ceiling
         $refused = Connections::$refused;
         $accepted = Connections::$accepted;
         $closed = Connections::$closed;
         // ! A peer that resets before the server accepts it: its address can
         //   no longer be read (no wait if the accept queue dropped it)
         $Gone = $open();
         $Raw = socket_import_stream($Gone);
         socket_set_option($Raw, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
         fclose($Gone);
         usleep(20_000);
         ini_set('default_socket_timeout', '0');
         $Collection->connect();
         ini_set('default_socket_timeout', $socketTimeout);
         // ! A blacklisted IP
         Connections::$blacklist['127.0.0.1'] = true;
         $open();
         $Collection->connect();
         unset(Connections::$blacklist['127.0.0.1']);
         // ! The global ceiling, filled by the one live connection
         TCP_Server_CLI::$maxConnections = 1;
         $open();
         $Collection->connect();
         // ! The selector headroom, clamped to a ceiling of two live
         //   connections: one more is admitted, the next one refused
         TCP_Server_CLI::$maxConnections = 0;
         TCP_Server_CLI::$headroom = Select::CAPACITY;
         $open();
         $Collection->connect();
         $open();
         $Collection->connect();
         // ! The per-IP ceiling, filled by the two live connections of 127.0.0.1
         TCP_Server_CLI::$headroom = 32;
         TCP_Server_CLI::$maxConnectionsPerIP = 2;
         $open();
         $Collection->connect();
         // ! The pending TLS handshakes ceiling: one handshake pending fills it
         TCP_Server_CLI::$maxConnectionsPerIP = 0;
         TCP_Server_CLI::$maxPendingHandshakes = 1;
         TCP_Server_CLI::$context = ['ssl' => []];
         $open();
         $Collection->connect();
         $open();
         $Collection->connect();
         TCP_Server_CLI::$context = [];
         $counted = [];
         foreach (Connections::REFUSALS as $reason) {
            $counted[$reason] = Connections::$refused[$reason] - $refused[$reason];
         }
         // ! Linux still hands out a connection its peer reset before the accept
         //   (`peer`); another kernel may drop it from the queue, and the
         //   accept then fails (`accept`): one refusal either way
         if (PHP_OS_FAMILY !== 'Linux') {
            $counted['peer'] += $counted['accept'];
            $counted['accept'] = 0;
         }
         $evidence = json_encode($counted);
         yield assert(
            assertion: $counted === [
               'accept' => 0, 'peer' => 1, 'blacklist' => 1, 'capacity' => 1,
               'headroom' => 1, 'ip' => 1, 'handshakes' => 1, 'loop' => 0
            ],
            description: "each refusal counts once, under its own reason — got {$evidence}"
         );
         $census = [
            Connections::$accepted - $accepted,
            Connections::$closed - $closed,
            count(Connections::$Connections),
            Connections::$pendingHandshakes,
         ];
         $evidence = json_encode($census);
         yield assert(
            assertion: $census === [2, 0, 3, 1],
            description: "a refused connection is neither accepted nor closed: accepted +2, closed +0, 3 live, 1 handshake pending — got {$evidence}"
         );
         // @ Every live connection closed: accepted − closed is the live count, 0
         foreach (array_values(Connections::$Connections) as $Connection) {
            $Connection->close();
         }
         yield assert(
            assertion: Connections::$Connections === [] && Connections::$pendingHandshakes === 0
               && Connections::$accepted - Connections::$closed === 0
               && TCP_Server_CLI::$pendingBytes === $retained,
            description: 'closing every live connection brings accepted − closed back to 0 and holds no reply bytes'
         );
      }
      finally {
         foreach ($Clients as $Client) {
            if (is_resource($Client)) {
               @fclose($Client);
            }
         }
         if (isSet($Listener) && is_resource($Listener)) {
            fclose($Listener);
         }
         foreach (Connections::$Connections as $Connection) {
            $Connection->close();
         }
         Connections::$Connections = [];
         Connections::$blacklist = [];
         Connections::$stats = false;
         TCP_Server_CLI::$collectors = $collectors;
         TCP_Server_CLI::$Decoder = $OldDecoder;
         TCP_Server_CLI::$Encoder = $OldEncoder;
         [
            TCP_Server_CLI::$maxConnections,
            TCP_Server_CLI::$headroom,
            TCP_Server_CLI::$maxConnectionsPerIP,
            TCP_Server_CLI::$maxPendingHandshakes,
         ] = $ceilings;
         // ! A typed static no earlier case set cannot return to unset: it is
         //   left inert, as a worker boots with it — an empty context
         TCP_Server_CLI::$context = $context ?? [];
         ini_set('default_socket_timeout', $socketTimeout);
         Observability::$Instance = $OldInstance;
         if ($OldEvent !== null) {
            TCP_Server_CLI::$Event = $OldEvent;
         }
         Display::show($segments);
      }
   }
);
