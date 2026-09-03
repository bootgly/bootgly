<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Tests\HTTP\Dial;


use const BOOTGLY_ROOT_DIR;
use const SIGKILL;
use const STREAM_CRYPTO_METHOD_TLSv1_2_SERVER;
use const STREAM_CRYPTO_METHOD_TLSv1_3_SERVER;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SERVER_BIND;
use const STREAM_SERVER_LISTEN;
use const STREAM_SOCK_STREAM;
use function assert;
use function count;
use function explode;
use function fclose;
use function fread;
use function fwrite;
use function get_resources;
use function getrusage;
use function json_encode;
use function microtime;
use function pcntl_fork;
use function pcntl_waitpid;
use function posix_getpid;
use function posix_kill;
use function str_contains;
use function str_repeat;
use function stream_context_create;
use function stream_set_blocking;
use function stream_socket_accept;
use function stream_socket_client;
use function stream_socket_enable_crypto;
use function stream_socket_get_name;
use function stream_socket_pair;
use function stream_socket_server;
use Fiber;
use LogicException;
use ReflectionMethod;
use RuntimeException;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Events\Cancellation;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\HTTP;


return new Test(
   description: 'Resources: the HTTP resource parks a stalled dial on the worker reactor instead of blocking it',
   test: function () {
      // ! Host reactor standing in for the worker's — restored on teardown
      $Host = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);
      $Event = $Host->Event;
      $OldEvent = isSet(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
      TCP_Server_CLI::$Event = $Event;

      // ! A listener whose accept queue holds ONE connection: with a filler
      //   parked in it, every further SYN is dropped and the peer retransmits
      //   (~1 s) — the only loopback dial that genuinely takes time. Freeing
      //   the slot (accepting the filler) lets the retransmit complete.
      $Stalled = stream_socket_server(
         'tcp://127.0.0.1:0',
         $errno,
         $error,
         STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
         stream_context_create(['socket' => ['backlog' => 0]])
      );
      if ($Stalled === false) {
         throw new RuntimeException('Unable to open the stalled upstream.');
      }
      stream_set_blocking($Stalled, false);
      [, $port] = explode(':', (string) stream_socket_get_name($Stalled, false));
      $Fillers = [];
      $Peers = [];
      $fill = static function () use (&$Fillers, &$Peers, $Stalled, $port): void {
         // ! Known state first: whatever a previous case left in the queue
         //   is accepted (and kept), so exactly one filler fills it again
         while (($Peer = @stream_socket_accept($Stalled, 0)) !== false) {
            stream_set_blocking($Peer, false);
            $Peers[] = $Peer;
         }
         $Filler = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 1);
         if ($Filler === false) {
            throw new RuntimeException('Unable to fill the stalled upstream queue.');
         }
         $Fillers[] = $Filler;
      };

      // ! A plain keep-alive upstream that never answers `/hold` (F13's leg A)
      $Server = stream_socket_server('tcp://127.0.0.1:0');
      if ($Server === false) {
         throw new RuntimeException('Unable to open the holding upstream.');
      }
      stream_set_blocking($Server, false);
      [, $holdPort] = explode(':', (string) stream_socket_get_name($Server, false));

      // ! A TLS upstream whose ServerHello is DELAYED by 0.3 s: the accepted
      //   peer only starts its (non-blocking, reactor-driven) handshake after
      //   the delay, so the client parks inside the handshake
      $certificates = BOOTGLY_ROOT_DIR . 'Bootgly/WPI/Nodes/HTTP_Client_CLI/tests/E2E_SSL/';
      $Slow = stream_socket_server(
         'tcp://127.0.0.1:0',
         $errno,
         $error,
         STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
         stream_context_create(['ssl' => [
            'local_cert' => "{$certificates}localhost.cert.pem",
            'local_pk' => "{$certificates}localhost.key.pem"
         ]])
      );
      if ($Slow === false) {
         throw new RuntimeException('Unable to open the slow TLS upstream.');
      }
      stream_set_blocking($Slow, false);
      [, $slowPort] = explode(':', (string) stream_socket_get_name($Slow, false));

      // ! A listener that accepts TCP and never negotiates: the client parks
      //   inside the handshake until its deadline (or its cancellation)
      $Mute = stream_socket_server('tcp://127.0.0.1:0');
      if ($Mute === false) {
         throw new RuntimeException('Unable to open the mute upstream.');
      }
      stream_set_blocking($Mute, false);
      [, $mutePort] = explode(':', (string) stream_socket_get_name($Mute, false));
      $Muted = [];

      // ! Served BY the host reactor: accepts on the stalled listener only
      //   while `$accepting`, drives the delayed TLS handshakes, answers
      //   every request head with 200 keep-alive
      $serving = true;
      $accepting = false;
      $Negotiating = [];
      $timer = 0;
      $serve = null;
      $serve = function () use (&$serve, &$serving, &$accepting, &$Peers, &$Negotiating, &$Muted, &$timer, $Stalled, $Server, $Slow, $Mute, $Event): void {
         if ($serving === false) {
            return;
         }
         if ($accepting) {
            while (($Peer = @stream_socket_accept($Stalled, 0)) !== false) {
               stream_set_blocking($Peer, false);
               $Peers[] = $Peer;
            }
         }
         $Peer = @stream_socket_accept($Server, 0);
         if ($Peer !== false) {
            stream_set_blocking($Peer, false);
            $Peers[] = $Peer;
         }
         $Peer = @stream_socket_accept($Slow, 0);
         if ($Peer !== false) {
            stream_set_blocking($Peer, false);
            $Negotiating[] = ['Peer' => $Peer, 'at' => microtime(true) + 0.3];
         }
         $Peer = @stream_socket_accept($Mute, 0);
         if ($Peer !== false) {
            $Muted[] = $Peer;
         }
         foreach ($Negotiating as $index => $pending) {
            if (microtime(true) < $pending['at']) {
               continue;
            }
            $crypto = @stream_socket_enable_crypto(
               $pending['Peer'],
               true,
               STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER
            );
            if ($crypto === true) {
               $Peers[] = $pending['Peer'];
               unset($Negotiating[$index]);
            }
            else if ($crypto === false) {
               unset($Negotiating[$index]);
            }
         }
         foreach ($Peers as $Open) {
            $head = @fread($Open, 4096);
            if ($head !== false && $head !== '' && str_contains($head, ' /hold ') === false) {
               @fwrite($Open, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 2\r\nConnection: keep-alive\r\n\r\nok");
            }
         }
         $timer = $Event->defer(microtime(true) + 0.005, $serve);
      };
      $timer = $Event->defer(microtime(true) + 0.005, $serve);

      // ! Pump bridge: runs the host reactor inline for one short slice
      $pump = static function (mixed $value = null) use ($Event): mixed {
         $Event->loop = true; // @phpstan-ignore-line (property on the Select impl)
         $Event->defer(microtime(true) + 0.05, static function () use ($Event): void {
            $Event->loop = false; // @phpstan-ignore-line (property on the Select impl)
         });
         $Event->loop();

         return null;
      };
      $slice = static function (int $slices) use ($pump): void {
         for ($i = 0; $i < $slices; $i++) {
            $pump();
         }
      };
      // ! Reactor-tick probe: fires only if the reactor keeps running
      $tick = static function (float $after) use ($Event): object {
         $Probe = new class { public float $at = 0.0; };
         $Event->defer(microtime(true) + $after, static function () use ($Probe): void {
            $Probe->at = microtime(true);
         });

         return $Probe;
      };
      // ! PHP-level census: every socket here is a PHP stream, and a closed one
      //   leaves the table — portable where /proc is not
      $fds = static fn (): int => count(get_resources('stream'));

      // # A stalled dial parks the Fiber and the reactor keeps ticking
      //   The slot is freed by a reactor timer at 0.3 s — a blocking dial
      //   would never let that timer run, and would fail at its deadline
      $fill();
      $Resource = new HTTP(host: '127.0.0.1', port: (int) $port, connectTimeout: 3, timeout: 3);
      $Resource->schedule($pump);
      $results = [];
      $Fiber = new Fiber(function () use ($Resource, &$results, &$accepting, $tick, $Event): void {
         $Probe = $tick(0.1);
         $Event->defer(microtime(true) + 0.3, static function () use (&$accepting): void {
            $accepting = true;
         });
         $started = microtime(true);
         $Answer = $Resource->request('GET', '/dial');
         $results['A'] = [
            'code' => $Answer->code,
            'body' => $Answer->body,
            'elapsed' => microtime(true) - $started,
            'gap' => $Probe->at > 0.0 ? $Probe->at - $started : 0.0
         ];
      });
      $Token = Cancellation::open($Fiber);
      $Fiber->start();
      $Token->finish();

      yield assert(
         assertion: ($results['A']['code'] ?? 0) === 200
            && ($results['A']['body'] ?? '') === 'ok'
            && ($results['A']['elapsed'] ?? 0.0) >= 0.9 && ($results['A']['elapsed'] ?? 9.9) <= 2.5,
         description: 'A stalled dial completes once the queue frees (SYN retransmit), found: ' . json_encode($results['A'] ?? null)
      );

      yield assert(
         assertion: ($results['A']['gap'] ?? 0.0) >= 0.05 && ($results['A']['gap'] ?? 9.9) <= 0.6,
         description: 'The reactor ticked while the dial was parked, found: ' . json_encode($results['A'] ?? null)
      );

      // # The dial deadline is honoured while parked, by name
      $accepting = false;
      $fill();
      $Short = new HTTP(host: '127.0.0.1', port: (int) $port, connectTimeout: 0.5, timeout: 3);
      $Short->schedule($pump);
      $Fiber = new Fiber(function () use ($Short, &$results, $tick): void {
         $Probe = $tick(0.1);
         $started = microtime(true);
         $Answer = $Short->request('GET', '/dial');
         $results['B'] = [
            'code' => $Answer->code,
            'status' => $Answer->status,
            'elapsed' => microtime(true) - $started,
            'gap' => $Probe->at > 0.0 ? $Probe->at - $started : 0.0
         ];
      });
      $Token = Cancellation::open($Fiber);
      $Fiber->start();
      $Token->finish();

      yield assert(
         assertion: ($results['B']['code'] ?? -1) === 0
            && ($results['B']['status'] ?? '') === 'Connection Failed'
            && ($results['B']['elapsed'] ?? 0.0) >= 0.4 && ($results['B']['elapsed'] ?? 9.9) <= 1.5
            && ($results['B']['gap'] ?? 0.0) >= 0.05 && ($results['B']['gap'] ?? 9.9) <= 0.6,
         description: 'A parked dial that expires fails by name without freezing the reactor, found: ' . json_encode($results['B'] ?? null)
      );

      // # Cancelling the context while its dial is parked closes the socket
      //   The dialing socket is registered nowhere: only park()'s unwind can
      //   close it, and the dropped Fiber must reach it
      $Gone = new HTTP(host: '127.0.0.1', port: (int) $port, connectTimeout: 3, timeout: 3);
      $Gone->schedule(static fn (mixed $value = null): mixed => Fiber::suspend($value));
      $before = $fds();
      $Fiber = new Fiber(function () use ($Gone, &$results): void {
         $Gone->request('GET', '/dial');
         $results['C-resumed'] = true;
      });
      $Token = Cancellation::open($Fiber);
      $suspended = $Fiber->start();
      $Event->bind($Fiber, static function (): void {}, static function (): void {});
      $Event->schedule($Fiber, $suspended);
      $slice(2);
      $dialing = $fds();
      $Token->cancel();
      $Fiber = null;
      // ! The reactor releases an evicted generation at its safe point — the
      //   next turn — never inline from the cancel
      $slice(1);
      $after = $fds();

      yield assert(
         assertion: $dialing === $before + 1 && $after === $before && isSet($results['C-resumed']) === false,
         description: 'A cancelled context releases its parked dial socket, found: ' . json_encode(['before' => $before, 'dialing' => $dialing, 'after' => $after])
      );

      // # F13 — a timeout on client A lands while client B's dial is parked
      //   A's watch timer runs on the reactor stack during B's parked dial;
      //   A's drain then finds its request already terminal
      $accepting = false;
      $fill();
      $Hold = new HTTP(host: '127.0.0.1', port: (int) $holdPort, connectTimeout: 1, timeout: 0.4);
      $Hold->schedule($pump);
      $Resource->schedule($pump);
      $Fiber = new Fiber(function () use ($Hold, $Resource, &$results, &$accepting, $Event): void {
         $Event->defer(microtime(true) + 0.3, static function () use (&$accepting): void {
            $accepting = true;
         });
         $started = microtime(true);
         $Hold->batch();
         $Held = $Hold->request('GET', '/hold');
         $Dialed = $Resource->request('GET', '/dial');
         $dialed = microtime(true) - $started;
         $Hold->drain();
         $results['D'] = [
            'held' => [$Held->code, $Held->status],
            'dialed' => [$Dialed->code, $Dialed->body],
            'dial' => $dialed,
            'elapsed' => microtime(true) - $started
         ];
      });
      $Token = Cancellation::open($Fiber);
      $Fiber->start();
      $Token->finish();

      yield assert(
         assertion: ($results['D']['held'] ?? null) === [0, 'Timeout']
            && ($results['D']['dialed'] ?? null) === [200, 'ok']
            && ($results['D']['dial'] ?? 0.0) >= 0.9
            && ($results['D']['elapsed'] ?? 9.9) <= 2.5,
         description: 'A timeout on one client lands while another client dial is parked (F13), found: ' . json_encode($results['D'] ?? null)
      );

      // # The parked dial waits on WRITABILITY, not on a slice deadline
      //   A loopback dial is writable before it can park (the pre-probe), so
      //   the flag is pinned on the loop itself: a UNIX pair with a full send
      //   buffer, drained by a reactor timer at 0.2 s — a write wait resumes
      //   right then, a read wait only when its 1 s slice expires
      $suspend = static fn (mixed $value = null): mixed => Fiber::suspend($value);
      $drive = static function (Fiber $Fiber, mixed $suspended, string $key) use ($Event, $pump, &$results): void {
         $Event->bind($Fiber, static function (): void {}, static function (): void {});
         $Event->schedule($Fiber, $suspended);
         $stop = microtime(true) + 4.0;
         while (isSet($results[$key]) === false && microtime(true) < $stop) {
            $pump();
         }
      };
      $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      if ($Pair === false) {
         throw new RuntimeException('Unable to open the writability pair.');
      }
      [$Choked, $Drain] = $Pair;
      stream_set_blocking($Choked, false);
      stream_set_blocking($Drain, false);
      while (@fwrite($Choked, str_repeat('x', 65536)) > 0) {
         // ! Fill the send buffer until the pair is no longer writable
      }
      $Connections = $Resource->Client->Connections;
      $Parking = new ReflectionMethod($Connections, 'parking');
      $Fiber = new Fiber(function () use ($Parking, $Connections, $Choked, $suspend, &$results): void {
         $started = microtime(true);
         $writable = $Parking->invoke($Connections, $Choked, $suspend, $started + 3.0, null);
         $results['E'] = ['writable' => $writable, 'elapsed' => microtime(true) - $started];
      });
      $Event->defer(microtime(true) + 0.2, static function () use ($Drain): void {
         while (@fread($Drain, 65536) !== '') {
            // @ Drain: the choked end becomes writable
         }
      });
      $drive($Fiber, $Fiber->start(), 'E');
      fclose($Choked);
      fclose($Drain);

      yield assert(
         assertion: ($results['E']['writable'] ?? false) === true
            && ($results['E']['elapsed'] ?? 9.9) >= 0.15 && ($results['E']['elapsed'] ?? 9.9) <= 0.6,
         description: 'The parked dial resumes on writability, not on the slice deadline, found: ' . json_encode($results['E'] ?? null)
      );

      // # Cancelling the context while its TLS handshake is parked closes
      //   the socket — only handshake()'s unwind can: the Connection is
      //   registered in neither Connections->Connections nor the Pool yet
      $Silent = new HTTP(host: '127.0.0.1', port: (int) $mutePort, secure: [], connectTimeout: 3, timeout: 3);
      $Silent->schedule($suspend);
      $before = $fds();
      $Fiber = new Fiber(function () use ($Silent, &$results): void {
         $Silent->request('GET', '/tls');
         $results['F-resumed'] = true;
      });
      $Token = Cancellation::open($Fiber);
      $suspended = $Fiber->start();
      $Event->bind($Fiber, static function (): void {}, static function (): void {});
      $Event->schedule($Fiber, $suspended);
      $slice(2);
      // ! Two descriptors while parked: the client socket AND the peer this
      //   very process accepted on the mute listener
      $negotiating = $fds();
      $Token->cancel();
      $Fiber = null;
      // ! Released at the reactor's safe point, not inline (see case C)
      $slice(1);
      foreach ($Muted as $Open) {
         @fclose($Open);
      }
      $Muted = [];
      $after = $fds();

      yield assert(
         assertion: $negotiating === $before + 2 && $after === $before && isSet($results['F-resumed']) === false,
         description: 'A cancelled context releases its parked handshake socket, found: ' . json_encode(['before' => $before, 'negotiating' => $negotiating, 'after' => $after])
      );

      // # The TLS handshake parks: a delayed ServerHello does not freeze the
      //   reactor, and the handshake still completes
      $Delayed = new HTTP(host: '127.0.0.1', port: (int) $slowPort, secure: ['cafile' => "{$certificates}localhost.cert.pem"], connectTimeout: 3, timeout: 3);
      $Delayed->schedule($suspend);
      $Fiber = new Fiber(function () use ($Delayed, &$results, $tick): void {
         $Probe = $tick(0.1);
         $started = microtime(true);
         $Answer = $Delayed->request('GET', '/tls');
         $results['G'] = [
            'code' => $Answer->code,
            'body' => $Answer->body,
            'elapsed' => microtime(true) - $started,
            'gap' => $Probe->at > 0.0 ? $Probe->at - $started : 0.0
         ];
      });
      $Token = Cancellation::open($Fiber);
      $drive($Fiber, $Fiber->start(), 'G');
      $Token->finish();

      yield assert(
         assertion: ($results['G']['code'] ?? 0) === 200
            && ($results['G']['body'] ?? '') === 'ok'
            && ($results['G']['elapsed'] ?? 0.0) >= 0.3 && ($results['G']['elapsed'] ?? 9.9) <= 2.0
            && ($results['G']['gap'] ?? 0.0) >= 0.05 && ($results['G']['gap'] ?? 9.9) <= 0.6,
         description: 'A delayed TLS handshake parks with the reactor ticking and still completes, found: ' . json_encode($results['G'] ?? null)
      );

      // # A revoked bridge trips the wire instead of spinning a core
      //   Response::wait() returns without suspending once the generation
      //   settled; a parked dial or handshake must then fail fast and by
      //   name, never burn the connect deadline at 100% CPU
      $cpu = static function (): float {
         $usage = getrusage();

         return $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6
            + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6;
      };
      $accepting = false;
      $fill();
      $Revoked = new HTTP(host: '127.0.0.1', port: (int) $port, connectTimeout: 3, timeout: 3);
      $Revoked->schedule(static fn (mixed $value = null): mixed => null);
      $Fiber = new Fiber(function () use ($Revoked, &$results, $cpu): void {
         $spent = $cpu();
         $started = microtime(true);
         $Answer = $Revoked->request('GET', '/dial');
         $results['H'] = [
            'code' => $Answer->code,
            'status' => $Answer->status,
            'elapsed' => microtime(true) - $started,
            'cpu' => $cpu() - $spent
         ];
      });
      $Token = Cancellation::open($Fiber);
      $Fiber->start();
      $Token->finish();

      yield assert(
         assertion: ($results['H']['code'] ?? -1) === 0
            && ($results['H']['status'] ?? '') === 'Connection Failed'
            && ($results['H']['elapsed'] ?? 9.9) <= 0.5
            && ($results['H']['cpu'] ?? 9.9) <= 0.2,
         description: 'A parked dial on a revoked bridge fails fast by name instead of spinning, found: ' . json_encode($results['H'] ?? null)
      );

      $Muffled = new HTTP(host: '127.0.0.1', port: (int) $mutePort, secure: [], connectTimeout: 3, timeout: 3);
      $Muffled->schedule(static fn (mixed $value = null): mixed => null);
      $Fiber = new Fiber(function () use ($Muffled, &$results, $cpu): void {
         $spent = $cpu();
         $started = microtime(true);
         $Answer = $Muffled->request('GET', '/tls');
         $results['I'] = [
            'code' => $Answer->code,
            'status' => $Answer->status,
            'elapsed' => microtime(true) - $started,
            'cpu' => $cpu() - $spent
         ];
      });
      $Token = Cancellation::open($Fiber);
      $Fiber->start();
      $Token->finish();

      yield assert(
         assertion: ($results['I']['code'] ?? -1) === 0
            && ($results['I']['status'] ?? '') === 'Connection Failed'
            && ($results['I']['elapsed'] ?? 9.9) <= 0.5
            && ($results['I']['cpu'] ?? 9.9) <= 0.2,
         description: 'A parked handshake on a revoked bridge fails fast by name instead of spinning, found: ' . json_encode($results['I'] ?? null)
      );

      // # A parked TLS handshake that never progresses expires by its deadline
      //   The mute peer never sends one TLS byte, so every wake is a slice
      //   expiry, never readability — the shape that proves the parked branch
      //   loops back to negotiate (and re-prove its deadline) instead of
      //   falling through to the blocking select
      $Sealed = new HTTP(host: '127.0.0.1', port: (int) $mutePort, secure: [], connectTimeout: 2, timeout: 2);
      $Sealed->schedule($pump);
      $Fiber = new Fiber(function () use ($Sealed, &$results, $tick): void {
         // ! Lands mid-handshake: a blocking handshake delays it to the deadline
         $Probe = $tick(1.0);
         $started = microtime(true);
         $Answer = $Sealed->request('GET', '/tls');
         $results['J'] = [
            'code' => $Answer->code,
            'status' => $Answer->status,
            'elapsed' => microtime(true) - $started,
            'gap' => $Probe->at > 0.0 ? $Probe->at - $started : 0.0
         ];
      });
      $Token = Cancellation::open($Fiber);
      $Fiber->start();
      $Token->finish();

      yield assert(
         assertion: ($results['J']['code'] ?? -1) === 0
            && ($results['J']['status'] ?? '') === 'Connection Failed'
            && ($results['J']['elapsed'] ?? 0.0) >= 1.9 && ($results['J']['elapsed'] ?? 9.9) <= 2.6
            && ($results['J']['gap'] ?? 0.0) >= 0.9 && ($results['J']['gap'] ?? 9.9) <= 1.3,
         description: 'A parked TLS handshake expires without freezing the reactor, found: ' . json_encode($results['J'] ?? null)
      );

      // # A self-driving client dialed from inside a Fiber keeps the blocking
      //   branch — it owns its loop and carries no bridge, so the parked
      //   branch must stay gated on adoption AND the bridge, not on the Fiber
      //   A self-driving client pumps its own loop, so the upstream cannot be
      //   served by the host reactor: a forked child answers one request
      $Plain = stream_socket_server('tcp://127.0.0.1:0');
      if ($Plain === false) {
         throw new RuntimeException('Unable to open the plain upstream.');
      }
      [, $plainPort] = explode(':', (string) stream_socket_get_name($Plain, false));
      $child = pcntl_fork();
      if ($child === 0) {
         $Peer = @stream_socket_accept($Plain, 3);
         if ($Peer !== false) {
            @fread($Peer, 4096);
            @fwrite($Peer, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
            @fclose($Peer);
         }
         // ! Hard exit — the child carries a copy of the runner, so no
         //   inherited shutdown callback and no destructor may run here
         posix_kill(posix_getpid(), SIGKILL);
      }
      fclose($Plain);
      $Own = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_EMBEDDED);
      $Own->configure(new HTTP_Client_CLI\Configs(host: '127.0.0.1', port: (int) $plainPort));
      $Own->connectTimeout = 1;
      $Own->timeout = 2;
      $Fiber = new Fiber(function () use ($Own, &$results): void {
         $Answer = $Own->request('GET', '/own');
         $results['K'] = [$Answer->code, $Answer->body];
      });
      $Fiber->start();
      if ($child > 0) {
         pcntl_waitpid($child, $status);
      }

      yield assert(
         assertion: ($results['K'] ?? null) === [200, 'ok'],
         description: 'A self-driving client inside a Fiber dials through the blocking branch, found: ' . json_encode($results['K'] ?? null)
      );

      // # Only the selector admission rejection is the parked dial's to absorb
      //   A foreign bridge exception propagates with its own message; the
      //   admission rejection ends the dial as a named failure
      $accepting = false;
      $fill();
      $Alien = new HTTP(host: '127.0.0.1', port: (int) $port, connectTimeout: 3, timeout: 3);
      $Alien->schedule(static function (mixed $value = null): mixed {
         throw new RuntimeException('unrelated bridge failure');
      });
      $Fiber = new Fiber(function () use ($Alien, &$results): void {
         try {
            $Alien->request('GET', '/dial');
            $results['L-alien'] = 'served';
         }
         catch (RuntimeException $Foreign) {
            $results['L-alien'] = $Foreign->getMessage();
         }
      });
      $Token = Cancellation::open($Fiber);
      $Fiber->start();
      $Token->finish();

      $accepting = false;
      $fill();
      $Refused = new HTTP(host: '127.0.0.1', port: (int) $port, connectTimeout: 3, timeout: 3);
      $Refused->schedule(static function (mixed $value = null): mixed {
         throw new RuntimeException('Fiber I/O resource failed selector admission.');
      });
      $Fiber = new Fiber(function () use ($Refused, &$results): void {
         $started = microtime(true);
         $Answer = $Refused->request('GET', '/dial');
         $results['L-refused'] = [$Answer->code, $Answer->status, microtime(true) - $started];
      });
      $Token = Cancellation::open($Fiber);
      $Fiber->start();
      $Token->finish();

      yield assert(
         assertion: ($results['L-alien'] ?? null) === 'unrelated bridge failure'
            && (($results['L-refused'][0] ?? -1) === 0)
            && (($results['L-refused'][1] ?? '') === 'Connection Failed')
            && (($results['L-refused'][2] ?? 9.9) <= 0.5),
         description: 'A foreign bridge exception propagates while an admission rejection fails the dial by name, found: ' . json_encode([$results['L-alien'] ?? null, $results['L-refused'] ?? null])
      );

      // # A foreign bridge failure during a parked TLS handshake propagates too
      //   The mute peer keeps the handshake parked; a bridge that fails with
      //   anything but the admission rejection must surface as itself — not
      //   as a fabricated TLS failure — and the socket it leaves must be closed
      $Strange = new HTTP(host: '127.0.0.1', port: (int) $mutePort, secure: [], connectTimeout: 3, timeout: 3);
      $Strange->schedule(static function (mixed $value = null): mixed {
         throw new LogicException('alien bridge failure');
      });
      // ! Known census: the mute peers earlier cases accepted are released first
      foreach ($Muted as $Open) {
         @fclose($Open);
      }
      $Muted = [];
      $before = $fds();
      $Fiber = new Fiber(function () use ($Strange, $fds, &$results): void {
         try {
            $Strange->request('GET', '/tls');
            $results['M'] = 'served';
         }
         catch (LogicException $Alien) {
            // ! Counted before the generation is released: the handshake's
            //   own close() must have returned the socket, not the abort sweep
            $results['M'] = $Alien->getMessage();
            $results['M-census'] = $fds();
         }
      });
      $Token = Cancellation::open($Fiber);
      $Fiber->start();
      $Token->finish();
      foreach ($Muted as $Open) {
         @fclose($Open);
      }
      $Muted = [];
      $after = $fds();

      yield assert(
         assertion: ($results['M'] ?? null) === 'alien bridge failure'
            && ($results['M-census'] ?? -1) === $before
            && $after === $before,
         description: 'A foreign bridge failure inside a parked handshake propagates and leaves no socket behind, found: ' . json_encode([$results['M'] ?? null, 'before' => $before, 'inside' => $results['M-census'] ?? null, 'after' => $after])
      );

      // # Teardown
      $serving = false;
      $Event->cancel($timer);
      foreach ($Peers as $Open) {
         @fclose($Open);
      }
      foreach ($Negotiating as $pending) {
         @fclose($pending['Peer']);
      }
      foreach ($Muted as $Open) {
         @fclose($Open);
      }
      foreach ($Fillers as $Filler) {
         @fclose($Filler);
      }
      fclose($Stalled);
      fclose($Server);
      fclose($Slow);
      fclose($Mute);
      if ($OldEvent !== null) {
         TCP_Server_CLI::$Event = $OldEvent;
      }
   }
);
