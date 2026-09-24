<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\ADI\Database\Pool;
use Bootgly\ADI\Databases\KV as KVDatabase;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\KV;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * RH-H3-residual regression (1.0.x) — a deferred response parked on a
 * dependency must give its pool slot back as soon as its client leaves, and a
 * worker flooded with idle clients must keep a selector reserve for the
 * dependency waits of its deferred responses.
 *
 * Withdrawal leg: the route awaits a Redis GET through the KV response
 * resource, on a per-worker pool (`pool.max = 1`, 3 s operation budget)
 * pointed at an in-process fake peer that never answers. The client
 * half-closes once the command is on the wire. The reactor reaps the parked
 * Fiber and its `finally` withdraws the command locally: the fake peer sees
 * the dependency session torn down (EOF, no byte) far inside the budget and
 * the pool census is empty. A key-value server drops a disconnected client's
 * work at once (`Driver::LINGERING` is false for KV), so no slot is
 * quarantined: a later request on the same route, asked right away, dials a
 * new session through the single slot at once — long before the withdrawn
 * GET's own deadline — and succeeds once the peer answers.
 *
 * Headroom leg: the setup route raises the worker's reserve
 * (`TCP_Server_CLI::$headroom`) to 300 entries — teardown restores it — so the
 * flood needs about 700 descriptors on any host, far below FD_SETSIZE and the
 * common 1024 open-file limit. A deferred Fiber polls a gate with tick waits
 * (no selector seat) while the master opens more idle clients than
 * `Select::CAPACITY - 300`. The worker admits clients exactly up to that
 * census and closes the surplus without a byte. Released, the Fiber awaits
 * its KV reply on the pooled session: the read wait is seated from the
 * reserve and the value arrives while the flood still holds every admissible
 * client slot. A host that still cannot hold the flood (open-file limit,
 * descriptors past FD_SETSIZE) fails the case with its reason — never a pass.
 */
// ! Master <-> gated Fiber channel (headroom leg), inherited by the worker
$gatePair = stream_socket_pair(
   STREAM_PF_UNIX,
   STREAM_SOCK_STREAM,
   STREAM_IPPROTO_IP,
);
if ($gatePair === false) {
   throw new RuntimeException('RH-H3 regression could not create its gate pair.');
}
[$gateWorker, $gateTest] = $gatePair;

// ! Clients opened past the admissible census, all expected to be refused
$surplus = 16;
// ! Budget of each KV operation: a withdrawal must tear the session down and
//   give the slot back far sooner (a withdrawn KV command is never quarantined)
$budget = 3.0;
// ! Selector reserve the worker keeps during this case: the flood then needs
//   about 700 descriptors, far below FD_SETSIZE whatever the host
$reserve = 300;
// ! Admissible client census under that reserve
$cap = Select::CAPACITY - $reserve;

$Probe = new class {
   // # Worker
   public null|KVDatabase $KV = null;
   //   The worker's own reserve, given back by teardown
   public null|int $previous = null;
   // # Master
   /** @var resource|null */
   public mixed $peer = null;
   public int $port = 0;
   /** @var array<int,resource> */
   public array $sessions = [];
   /** @var resource|null */
   public mixed $pooled = null;
   //   When the parked GET was whole on the wire (its deadline follows it by one budget)
   public float $commanded = 0.0;
   // # Evidence
   /** @var array<string,mixed> */
   public array $withdrawal = [];
   /** @var array<string,mixed> */
   public array $reuse = [];
   /** @var array<string,mixed> */
   public array $headroom = [];
   //   Why this host could not run the headroom leg — a failure, never a skip
   public null|string $fault = null;
};

// @ Open descriptors of this process (the listing's own handle included), the
//   highest one and its soft open-file limit — null when unknowable
$Descriptors = static function (): array {
   $open = null;
   $top = null;
   $listing = @scandir('/proc/self/fd');
   if (is_array($listing)) {
      $open = 0;
      foreach ($listing as $descriptor) {
         if (ctype_digit($descriptor)) {
            $open++;
            $top = max($top ?? 0, (int) $descriptor);
         }
      }
   }

   $limit = null;
   $limits = function_exists('posix_getrlimit') ? posix_getrlimit() : false;
   if (is_array($limits) && isset($limits['soft openfiles'])) {
      $soft = $limits['soft openfiles'];
      $limit = $soft === 'unlimited' ? PHP_INT_MAX : (int) $soft;
   }

   return ['descriptors' => $open, 'top' => $top, 'limit' => $limit];
};

// @ Pool and selector census, taken inside the worker — with the peer port of
//   every admitted client on request
$Census = static function (null|KVDatabase $KV, bool $ports = false) use ($Descriptors): array {
   $Pool = $KV?->Pool;
   $Event = TCP_Server_CLI::$Event;
   $reads = null;
   if ($Event instanceof Select) {
      $reads = count((array) new ReflectionProperty(Select::class, 'reads')->getValue($Event));
   }

   // ! Slots still quarantined for withdrawn statements (unexpired only)
   $quarantine = null;
   if ($Pool !== null) {
      $quarantine = 0;
      $now = microtime(true);
      foreach ((array) new ReflectionProperty(Pool::class, 'quarantine')->getValue($Pool) as $deadline) {
         if ($deadline > $now) {
            $quarantine++;
         }
      }
   }

   $census = [
      'busy' => $Pool === null ? null : count($Pool->busy),
      'idle' => $Pool === null ? null : count($Pool->idle),
      'pending' => $Pool === null ? null : count($Pool->pending),
      'created' => $Pool?->created,
      'quarantine' => $quarantine,
      'connections' => count(Connections::$Connections),
      'reads' => $reads,
      ...$Descriptors(),
      'headroom' => TCP_Server_CLI::$headroom,
   ];
   if ($ports) {
      $census['ports'] = [];
      foreach (Connections::$Connections as $Connection) {
         $census['ports'][] = $Connection->port;
      }
   }

   return $census;
};

// @ Bounded read on a blocking stream: until $done($buffer), EOF or the bound
//   (a bound that runs out is never reported as EOF)
$Read = static function (mixed $stream, Closure $done, float $bound): array {
   $buffer = '';
   $eof = false;
   $deadline = microtime(true) + $bound;

   if (is_resource($stream) === false) {
      return ['data' => '', 'eof' => true, 'done' => false];
   }
   stream_set_blocking($stream, true);

   while ($done($buffer) === false) {
      $left = $deadline - microtime(true);
      if ($left <= 0) {
         break;
      }
      stream_set_timeout($stream, (int) $left, (int) (($left - (int) $left) * 1_000_000));

      $chunk = @fread($stream, 8192);
      // ? The bound ran out: a timed-out read returns false, not EOF
      if (($chunk === false || $chunk === '') && stream_get_meta_data($stream)['timed_out']) {
         break;
      }
      if ($chunk === false || ($chunk === '' && feof($stream))) {
         $eof = true;
         break;
      }
      $buffer .= $chunk;
   }

   return ['data' => $buffer, 'eof' => $eof, 'done' => $done($buffer)];
};

// @ A whole HTTP/1.1 response: header block plus its Content-Length body
$Complete = static function (string $wire): bool {
   $end = strpos($wire, "\r\n\r\n");
   if ($end === false) {
      return false;
   }
   if (preg_match('/\r\nContent-Length: (\d+)\r\n/i', substr($wire, 0, $end + 2), $matches) !== 1) {
      return true;
   }

   return strlen($wire) - $end - 4 >= (int) $matches[1];
};

$Decode = static function (string $wire, string $prefix): null|array {
   $end = strpos($wire, "\r\n\r\n");
   if ($end === false) {
      return null;
   }
   $body = substr($wire, $end + 4);
   if (str_starts_with($body, $prefix) === false) {
      return null;
   }
   $decoded = json_decode(substr($body, strlen($prefix)), true);

   return is_array($decoded) ? $decoded : null;
};

// @ Side client carrying one request to this case's handler
$Open = static function (string $hostPort, string $path, int $testIndex): mixed {
   $client = @stream_socket_client("tcp://{$hostPort}", $code, $error, 5);
   if ($client === false) {
      throw new RuntimeException("RH-H3 regression could not open a side client: {$code} {$error}");
   }

   $request = "GET {$path} HTTP/1.1\r\n"
      . "Host: localhost\r\n"
      . "X-Bootgly-Test: {$testIndex}\r\n\r\n";
   $written = @fwrite($client, $request);
   if ($written !== strlen($request)) {
      fclose($client);
      throw new RuntimeException("RH-H3 regression could not send {$path}.");
   }

   return $client;
};

// @ One census through a fresh side client — null when the worker refused it
$Query = static function (string $hostPort, int $testIndex) use ($Complete, $Decode, $Read): null|array {
   $client = @stream_socket_client("tcp://{$hostPort}", $code, $error, 5);
   if ($client === false) {
      return null;
   }
   @fwrite($client, "GET /rh3/census HTTP/1.1\r\nHost: localhost\r\nX-Bootgly-Test: {$testIndex}\r\n\r\n");
   $wire = $Read($client, $Complete, 2.0);
   fclose($client);

   return $Decode($wire['data'], 'RH3-CENSUS:');
};

// @ Teardown through a side client, for a leg that threw: the harness sends
//   no later request then, and the worker must not keep the raised reserve
$Abort = static function (string $hostPort, int $testIndex) use ($Complete, $Read): void {
   $client = @stream_socket_client("tcp://{$hostPort}", $code, $error, 2);
   if ($client === false) {
      return;
   }
   @fwrite($client, "GET /rh3/teardown HTTP/1.1\r\nHost: localhost\r\nX-Bootgly-Test: {$testIndex}\r\n\r\n");
   $Read($client, $Complete, 2.0);
   fclose($client);
};

// @ Exact RESP frame of `GET <key>`
$Frame = static fn (string $key): string => "*2\r\n\$3\r\nGET\r\n\$" . strlen($key) . "\r\n{$key}\r\n";

// @ Master-side cleanup: fake peer, accepted sessions and the gate
$Release = static function () use ($gateTest, $Probe): void {
   foreach ($Probe->sessions as $session) {
      if (is_resource($session)) {
         fclose($session);
      }
   }
   $Probe->sessions = [];
   $Probe->pooled = null;

   if (is_resource($Probe->peer)) {
      fclose($Probe->peer);
   }
   $Probe->peer = null;

   if (is_resource($gateTest)) {
      fclose($gateTest);
   }
};

return new Test(
   description: 'Deferred dependency waits must free their pool slot on disconnect and keep a selector reserve under an idle flood',
   Separator: new Separator(line: true),

   requests: [
      static function () use ($gateWorker, $Probe, $Release): string {
         // @ The master keeps the test end of the gate, never the worker end
         if (is_resource($gateWorker)) {
            fclose($gateWorker);
         }

         // ! Fake Redis peer: the kernel completes every dial, the master
         //   decides if and when a command is answered
         $peer = @stream_socket_server('tcp://127.0.0.1:0', $code, $error);
         if ($peer === false) {
            $Release();

            throw new RuntimeException("RH-H3 regression could not listen for its fake peer: {$code} {$error}");
         }
         $Probe->peer = $peer;
         $address = (string) stream_socket_get_name($peer, false);
         $Probe->port = (int) substr($address, (int) strrpos($address, ':') + 1);

         return "GET /rh3/setup?port={$Probe->port} HTTP/1.1\r\n"
            . "Host: localhost\r\n\r\n";
      },

      static function (string $hostPort, int $testIndex) use (
         $Abort,
         $Frame,
         $Open,
         $Probe,
         $Read,
         $Release,
      ): string {
         try {
            $client = $Open($hostPort, '/rh3/kv?key=rh3:parked', $testIndex);

            // @ The worker dials the peer and writes the GET before it parks
            $session = @stream_socket_accept($Probe->peer, 5);
            if ($session === false) {
               fclose($client);

               throw new RuntimeException('RH-H3 withdrawal leg: the worker never dialed the fake peer.');
            }
            $Probe->sessions[] = $session;

            $frame = $Frame('rh3:parked');
            $command = $Read($session, static fn (string $buffer): bool => strlen($buffer) >= strlen($frame), 5.0);
            if ($command['data'] !== $frame) {
               fclose($client);

               throw new RuntimeException('RH-H3 withdrawal leg: unexpected dependency command '
                  . json_encode($command['data']));
            }
            $Probe->commanded = microtime(true);

            // ! Parked: the command is whole on the wire and the Fiber suspends
            //   on its reply within the same resumption. Half-close and wait
            //   for server EOF — the connection closed, the Fiber was evicted.
            $started = microtime(true);
            if (stream_socket_shutdown($client, STREAM_SHUT_WR) === false) {
               fclose($client);

               throw new RuntimeException('RH-H3 withdrawal leg could not half-close its client.');
            }
            $closed = $Read($client, static fn (): bool => false, 10.0);
            fclose($client);

            // @ The withdrawal tears the dependency session down: EOF, no byte
            $torn = $Read($session, static fn (): bool => false, 5.0);

            $Probe->withdrawal = [
               'client_eof' => $closed['eof'],
               'client_wire' => $closed['data'],
               'session_eof' => $torn['eof'],
               'session_bytes' => $torn['data'],
               'seconds' => round(microtime(true) - $started, 3),
            ];

            return "GET /rh3/census HTTP/1.1\r\n"
               . "Host: localhost\r\n\r\n";
         }
         catch (Throwable $Throwable) {
            $Release();
            $Abort($hostPort, $testIndex);

            throw $Throwable;
         }
      },

      static function (string $hostPort, int $testIndex) use (
         $Abort,
         $budget,
         $Complete,
         $Frame,
         $Open,
         $Probe,
         $Read,
         $Release,
      ): string {
         try {
            // ! Ask right away, far inside the withdrawn GET's own deadline
            //   (one budget after it was issued): a key-value server dropped
            //   that GET with its session, so nothing may hold the slot until
            //   the deadline
            $asked = microtime(true);
            $client = $Open($hostPort, '/rh3/kv?key=rh3:after', $testIndex);

            // @ pool.max = 1: a quarantined slot allows no dial before the
            //   deadline and a leaked slot none at all — a new session dialed
            //   at once proves the slot came back. Bounded past the deadline,
            //   so a pool still holding it shows how long it waited.
            $session = @stream_socket_accept($Probe->peer, $budget + 2.0);
            $dialed = microtime(true);
            if ($session === false) {
               $wire = $Read($client, $Complete, 0.5);
               fclose($client);

               throw new RuntimeException('RH-H3 withdrawal leg: the later request never reached the fake peer '
                  . '(pool slot still held). Wire: ' . json_encode($wire['data']));
            }
            $Probe->sessions[] = $session;
            $Probe->pooled = $session;

            $frame = $Frame('rh3:after');
            $command = $Read($session, static fn (string $buffer): bool => strlen($buffer) >= strlen($frame), 5.0);

            // @ The peer answers now
            $reply = "\$9\r\nRH3-VALUE\r\n";
            $answered = @fwrite($session, $reply) === strlen($reply);
            $wire = $Read($client, $Complete, 5.0);
            fclose($client);

            $Probe->reuse = [
               'asked' => round($asked - $Probe->commanded, 3),
               'dialed' => round($dialed - $Probe->commanded, 3),
               'command' => $command['data'] === $frame,
               'answered' => $answered,
               'wire' => $wire['data'],
            ];

            return "GET /rh3/census HTTP/1.1\r\n"
               . "Host: localhost\r\n\r\n";
         }
         catch (Throwable $Throwable) {
            $Release();
            $Abort($hostPort, $testIndex);

            throw $Throwable;
         }
      },

      static function (string $hostPort, int $testIndex) use (
         $Abort,
         $cap,
         $Complete,
         $Descriptors,
         $Frame,
         $gateTest,
         $Open,
         $Probe,
         $Query,
         $Read,
         $Release,
         $surplus,
      ): string {
         $census = "GET /rh3/census HTTP/1.1\r\n"
            . "Host: localhost\r\n\r\n";

         // @ One gate round trip: a command byte in, one line out
         $Ask = static function (string $signal) use ($gateTest): string {
            if (@fwrite($gateTest, $signal) !== 1) {
               throw new RuntimeException("RH-H3 headroom leg could not signal the gate ({$signal}).");
            }
            stream_set_blocking($gateTest, true);
            stream_set_timeout($gateTest, 5);
            $line = fgets($gateTest);

            return is_string($line) ? $line : '';
         };
         $Parse = static function (string $line, string $prefix): array {
            if (str_starts_with($line, $prefix) === false) {
               throw new RuntimeException('RH-H3 headroom leg: unexpected gate line ' . json_encode($line));
            }
            $decoded = json_decode(substr($line, strlen($prefix)), true);

            return is_array($decoded) ? $decoded : [];
         };

         $client = null;
         /** @var array<int,resource> $flood */
         $flood = [];
         $baseline = null;
         $evidence = [];
         $failed = false;

         try {
            $client = $Open($hostPort, '/rh3/kv/gated?key=rh3:headroom', $testIndex);

            stream_set_blocking($gateTest, true);
            stream_set_timeout($gateTest, 5);
            $parked = fgets($gateTest);
            if ($parked !== "PARKED\n") {
               throw new RuntimeException('RH-H3 headroom leg: the gated Fiber did not park '
                  . json_encode($parked));
            }

            // ! The flood is sized from this case's own reserve, never from
            //   what the worker reports: a worker that ignores the reserve
            //   admits it whole
            $baseline = $Parse($Ask('C'), 'CENSUS:');
            $admit = $cap - (int) $baseline['connections'];
            $count = $admit + $surplus;
            $master = $Descriptors();

            // ? The flood must fit both processes — every admitted client lands
            //   below FD_SETSIZE (1024; the kernel hands out the lowest free
            //   descriptor) in the worker, or the selector guard, not the
            //   reserve, refuses it. A host that cannot hold it fails the
            //   case with its reason: the leg is never skipped.
            $needed = max(
               (int) ($baseline['top'] ?? 0) + 1,
               (int) ($baseline['descriptors'] ?? 0) + $count + 8,
            );
            $fault = match (true) {
               $admit < 1
                  => "the worker already holds {$baseline['connections']} clients: none left to admit under {$cap}",
               $master['descriptors'] === null || ($baseline['descriptors'] ?? null) === null
                  => 'descriptors cannot be counted (/proc/self/fd is unreadable)',
               $master['limit'] === null || ($baseline['limit'] ?? null) === null
                  => 'the open-file limit cannot be read (posix_getrlimit)',
               $master['descriptors'] + $count + 8 > $master['limit']
                  => "the master holds {$master['descriptors']} descriptors under a soft open-file limit of {$master['limit']}: no room for {$count} flood clients",
               $needed > min(1024, (int) $baseline['limit'])
                  => "the worker needs descriptors up to {$needed} (it holds {$baseline['descriptors']}, soft open-file limit {$baseline['limit']}, FD_SETSIZE 1024) for {$count} flood clients",
               default => null,
            };
            if ($fault !== null) {
               $Probe->fault = $fault;
               $evidence = ['baseline' => $baseline, 'master' => $master];
               @fwrite($gateTest, 'Q');

               return $census;
            }

            // @@ Idle flood past the admissible census
            for ($index = 0; $index < $count; $index++) {
               $socket = @stream_socket_client("tcp://{$hostPort}", $code, $error, 5);
               if ($socket === false) {
                  throw new RuntimeException("RH-H3 headroom leg could not open flood client #{$index}: {$code} {$error}");
               }
               stream_set_blocking($socket, false);
               $flood[] = $socket;
            }

            // ! Local port of each flood client: the worker lists its admitted
            //   clients by peer port
            $locals = [];
            foreach ($flood as $index => $socket) {
               $name = (string) stream_socket_get_name($socket, false);
               $locals[$index] = (int) substr($name, (int) strrpos($name, ':') + 1);
            }

            // @@ Settle: every flood client is either admitted (listed by the
            //    worker) or refused (closed by the worker without a byte)
            $refused = [];
            $admitted = 0;
            $bytes = '';
            $settled = [];
            $deadline = microtime(true) + 10.0;
            while (true) {
               foreach ($flood as $index => $socket) {
                  if (isset($refused[$index])) {
                     continue;
                  }
                  $chunk = @fread($socket, 64);
                  if (is_string($chunk)) {
                     $bytes .= $chunk;
                  }
                  if (feof($socket)) {
                     $refused[$index] = true;
                  }
               }

               $settled = $Parse($Ask('P'), 'CENSUS:');
               $listed = array_flip((array) ($settled['ports'] ?? []));
               unset($settled['ports']);
               $admitted = 0;
               foreach ($locals as $index => $port) {
                  if (isset($refused[$index]) === false && isset($listed[$port])) {
                     $admitted++;
                  }
               }
               if ($admitted + count($refused) === count($flood) || microtime(true) > $deadline) {
                  break;
               }
               usleep(20_000);
            }

            // @ A new client is refused while the flood holds every slot: the
            //   worker closes it without a byte (nothing is written to it)
            $late = @stream_socket_client("tcp://{$hostPort}", $code, $error, 5);
            $refusal = $late === false
               ? ['data' => '', 'eof' => false, 'done' => false]
               : $Read($late, static fn (): bool => false, 5.0);
            if (is_resource($late)) {
               fclose($late);
            }

            // @ Released, the Fiber awaits its reply: the read wait needs a
            //   fresh selector seat while the flood is still in place
            if (@fwrite($gateTest, 'G') !== 1) {
               throw new RuntimeException('RH-H3 headroom leg could not release the gate.');
            }
            $frame = $Frame('rh3:headroom');
            $command = $Read($Probe->pooled, static fn (string $buffer): bool => strlen($buffer) >= strlen($frame), 5.0);
            $reply = "\$8\r\nHEADROOM\r\n";
            $answered = @fwrite($Probe->pooled, $reply) === strlen($reply);

            stream_set_timeout($gateTest, 5);
            $result = $Parse((string) fgets($gateTest), 'RESULT:');
            $wire = $Read($client, $Complete, 5.0);

            $evidence = [
               'baseline' => $baseline,
               'master' => $master,
               'cap' => $cap,
               'flood' => count($flood),
               'settled' => $settled,
               'admitted' => $admitted,
               'refused' => count($refused),
               'refused_bytes' => $bytes,
               'late_eof' => $refusal['eof'],
               'late_bytes' => $refusal['data'],
               'command' => $command['data'] === $frame,
               'answered' => $answered,
               'result' => $result,
               'wire' => $wire['data'],
            ];
         }
         catch (Throwable $Throwable) {
            $failed = true;
            $Release();

            throw $Throwable;
         }
         finally {
            // @ Tear the flood down and let the worker shed it before any
            //   other request (a later case must meet an idle worker)
            foreach ($flood as $socket) {
               if (is_resource($socket)) {
                  fclose($socket);
               }
            }
            if (is_resource($client)) {
               fclose($client);
            }

            if ($flood !== [] && $baseline !== null) {
               $recovered = null;
               $deadline = microtime(true) + 10.0;
               while (microtime(true) < $deadline) {
                  $recovered = $Query($hostPort, $testIndex);
                  if ($recovered !== null && (int) $recovered['connections'] <= (int) $baseline['connections']) {
                     break;
                  }
                  usleep(50_000);
               }
               $evidence['recovered'] = $recovered;
            }

            $Probe->headroom = $evidence;

            // ? A leg that threw never reaches the harness teardown
            if ($failed) {
               $Abort($hostPort, $testIndex);
            }
         }

         return $census;
      },

      static fn (): string => "GET /rh3/teardown HTTP/1.1\r\n"
         . "Host: localhost\r\n\r\n",
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use (
      $budget,
      $Census,
      $gateTest,
      $gateWorker,
      $Probe,
      $reserve,
   ): Generator {
      yield $Router->route('/rh3/setup', static function (
         Request $Request,
         Response $Response,
      ) use ($budget, $Census, $gateTest, $Probe, $reserve): Response {
         // @ The worker keeps the worker end of the gate only
         if (is_resource($gateTest)) {
            fclose($gateTest);
         }

         // @ Raise this worker's selector reserve for the headroom leg; its
         //   own value comes back at teardown
         $Probe->previous ??= TCP_Server_CLI::$headroom;
         TCP_Server_CLI::$headroom = $reserve;

         // ! Per-worker pool with one slot: a leaked claim blocks every later command
         $Probe->KV = new KVDatabase([
            'driver' => 'redis',
            'host' => '127.0.0.1',
            'port' => (int) $Request->query('port'),
            'database' => '0',
            'timeout' => $budget,
            'secure' => ['mode' => 'disable'],
            'pool' => ['min' => 0, 'max' => 1],
         ]);

         return $Response(body: 'RH3-SETUP:' . json_encode([
            ...$Census($Probe->KV),
            'previous' => $Probe->previous,
         ]));
      }, GET);

      yield $Router->route('/rh3/kv', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $key = $Request->query('key');

         return $Response->defer(static function (Response $Response) use ($key, $Probe): void {
            if ($Probe->KV === null) {
               $Response(code: 503, body: 'RH3-KV-UNBOUND');

               return;
            }

            $KV = $Response->mount(new KV($Probe->KV), 'RH3KV');

            try {
               $value = $KV->fetch('GET', [$key]);
            }
            catch (Throwable $Throwable) {
               $Response(code: 502, body: 'RH3-KV-ERROR:' . json_encode($Throwable->getMessage()));

               return;
            }

            $Response(body: 'RH3-KV:' . json_encode($value));
         });
      }, GET);

      yield $Router->route('/rh3/kv/gated', static function (
         Request $Request,
         Response $Response,
      ) use ($Census, $gateWorker, $Probe): Response {
         $key = $Request->query('key');

         return $Response->defer(static function (Response $Response) use ($Census, $gateWorker, $key, $Probe): void {
            if ($Probe->KV === null || is_resource($gateWorker) === false) {
               $Response(code: 503, body: 'RH3-GATE-UNBOUND');

               return;
            }

            // ! Tick waits only: the Fiber holds no selector seat while the
            //   master floods the worker
            stream_set_blocking($gateWorker, false);
            fwrite($gateWorker, "PARKED\n");

            // @@ Poll the gate (bounded; a gate the master or teardown closed
            //    abandons it too)
            $deadline = microtime(true) + 20.0;
            while (true) {
               $signal = is_resource($gateWorker) ? fread($gateWorker, 1) : false;
               if ($signal === 'C' || $signal === 'P') {
                  fwrite($gateWorker, 'CENSUS:' . json_encode($Census($Probe->KV, $signal === 'P')) . "\n");

                  continue;
               }
               if ($signal === 'G') {
                  break;
               }
               if (
                  $signal === 'Q'
                  || $signal === false
                  || ($signal === '' && feof($gateWorker))
                  || microtime(true) > $deadline
               ) {
                  $Response(code: 503, body: 'RH3-GATE-ABANDONED');

                  return;
               }

               $Response->wait();
            }

            // @ The read wait on the dependency needs a selector seat now
            $KV = $Response->mount(new KV($Probe->KV), 'RH3KV');
            $value = null;
            $error = null;
            try {
               $value = $KV->fetch('GET', [$key]);
            }
            catch (Throwable $Throwable) {
               $error = $Throwable->getMessage();
            }

            if (is_resource($gateWorker)) {
               fwrite($gateWorker, 'RESULT:' . json_encode([
                  'value' => $value,
                  'error' => $error,
                  'census' => $Census($Probe->KV),
               ]) . "\n");
            }

            $Response(
               code: $error === null ? 200 : 502,
               body: 'RH3-HEADROOM:' . json_encode($error ?? $value),
            );
         });
      }, GET);

      yield $Router->route('/rh3/census', static function (
         Request $Request,
         Response $Response,
      ) use ($Census, $Probe): Response {
         return $Response(body: 'RH3-CENSUS:' . json_encode($Census($Probe->KV)));
      }, GET);

      yield $Router->route('/rh3/teardown', static function (
         Request $Request,
         Response $Response,
      ) use ($Census, $gateWorker, $Probe): Response {
         $census = $Census($Probe->KV);

         // @ Close the pooled dependency sessions and the worker end of the gate
         if ($Probe->KV !== null) {
            foreach ([...$Probe->KV->Pool->idle, ...$Probe->KV->Pool->busy] as $Connection) {
               $Connection->disconnect();
            }
         }
         $Probe->KV = null;
         if (is_resource($gateWorker)) {
            fclose($gateWorker);
         }

         // @ Give the worker its own selector reserve back
         if ($Probe->previous !== null) {
            TCP_Server_CLI::$headroom = $Probe->previous;
            $Probe->previous = null;
         }
         $census['restored'] = TCP_Server_CLI::$headroom;

         return $Response(body: 'RH3-TEARDOWN:' . json_encode($census));
      }, GET);
   },

   test: static function (array $responses) use (
      $budget,
      $cap,
      $Decode,
      $Probe,
      $Release,
      $reserve,
      $surplus,
   ): bool|string {
      $Release();

      if (count($responses) !== 5) {
         return 'RH-H3 regression expected five harness responses, got ' . count($responses) . '.';
      }
      [$setupWire, $withdrawnWire, $reusedWire, $floodedWire, $teardownWire] = $responses;

      $Pool = static fn (null|array $census): null|array => $census === null
         ? null
         : [
            'busy' => $census['busy'] ?? null,
            'idle' => $census['idle'] ?? null,
            'pending' => $census['pending'] ?? null,
            'created' => $census['created'] ?? null,
            'quarantine' => $census['quarantine'] ?? null,
         ];

      // @ Setup: an empty single-slot pool, the worker's reserve raised
      $setup = $Decode($setupWire, 'RH3-SETUP:');
      if (
         $Pool($setup) !== ['busy' => 0, 'idle' => 0, 'pending' => 0, 'created' => 0, 'quarantine' => 0]
         || ($setup['headroom'] ?? null) !== $reserve
         || is_int($setup['previous'] ?? null) === false
      ) {
         return 'RH-H3 setup did not build an empty KV pool under the raised selector reserve: ' . json_encode($setupWire);
      }

      // @ Withdrawal leg: client disconnect while the GET is parked — the
      //   session is torn down at once and the claim is gone; a key-value
      //   server drops that GET with its session, so no slot is quarantined
      $withdrawal = $Probe->withdrawal;
      $withdrawn = $Decode($withdrawnWire, 'RH3-CENSUS:');
      if (
         ($withdrawal['client_eof'] ?? false) !== true
         || ($withdrawal['client_wire'] ?? null) !== ''
         || ($withdrawal['session_eof'] ?? false) !== true
         || ($withdrawal['session_bytes'] ?? null) !== ''
         || ($withdrawal['seconds'] ?? INF) >= $budget / 2
         || $Pool($withdrawn) !== ['busy' => 0, 'idle' => 0, 'pending' => 0, 'created' => 0, 'quarantine' => 0]
      ) {
         Vars::$labels = ['RH-H3 withdrawal on client disconnect'];
         dump(json_encode(['withdrawal' => $withdrawal, 'census' => $withdrawn]));

         return 'RH-H3 regression: a client disconnect while the KV GET was parked did not '
            . 'withdraw it (dependency session torn down, pool claim gone, no slot quarantined). Evidence: '
            . json_encode(['withdrawal' => $withdrawal, 'census' => $withdrawn]);
      }

      // @ Same route, right after: asked far inside the withdrawn GET's
      //   deadline, the single slot dials a new session at once — within a
      //   second, while that deadline is still more than a second away
      $reuse = $Probe->reuse;
      $reused = $Decode($reusedWire, 'RH3-CENSUS:');
      $reuseWire = (string) ($reuse['wire'] ?? '');
      if (
         ($reuse['asked'] ?? INF) >= $budget / 2
         || ($reuse['dialed'] ?? INF) - ($reuse['asked'] ?? 0.0) >= 1.0
         || ($reuse['command'] ?? false) !== true
         || ($reuse['answered'] ?? false) !== true
         || str_starts_with($reuseWire, 'HTTP/1.1 200 OK') === false
         || str_ends_with($reuseWire, "\r\n\r\nRH3-KV:\"RH3-VALUE\"") === false
         || $Pool($reused) !== ['busy' => 0, 'idle' => 1, 'pending' => 0, 'created' => 1, 'quarantine' => 0]
      ) {
         Vars::$labels = ['RH-H3 later request on the freed slot'];
         dump(json_encode(['reuse' => $reuse, 'census' => $reused]));

         return 'RH-H3 regression: the later request on the same route did not get the single pool slot '
            . 'at once (a withdrawn KV GET is never quarantined) and its answer (seconds after the GET: '
            . 'asked, dialed). Evidence: ' . json_encode(['reuse' => $reuse, 'census' => $reused]);
      }

      // @ Headroom leg — a host that cannot hold the flood fails, never skips
      if ($Probe->fault !== null) {
         Vars::$labels = ['RH-H3 headroom leg could not run'];
         dump($Probe->fault);

         return "RH-H3 headroom leg could not run on this host: {$Probe->fault}. Evidence: "
            . json_encode($Probe->headroom);
      }

      $headroom = $Probe->headroom;
      $settled = $headroom['settled'] ?? [];
      $result = $headroom['result'] ?? [];
      $floodWire = (string) ($headroom['wire'] ?? '');
      $baseline = $headroom['baseline'] ?? [];
      $recovered = $headroom['recovered'] ?? null;

      if (
         ($baseline['headroom'] ?? null) !== $reserve
         || ($headroom['cap'] ?? null) !== $cap
         || ($settled['connections'] ?? null) !== $cap
         || ($settled['reads'] ?? PHP_INT_MAX) > $cap + 1
         || ($headroom['admitted'] ?? 0) + ($headroom['refused'] ?? 0) !== ($headroom['flood'] ?? null)
         || ($headroom['refused'] ?? 0) < 1
         || ($headroom['refused'] ?? 0) > $surplus
         || ($headroom['refused_bytes'] ?? null) !== ''
         || ($headroom['late_eof'] ?? false) !== true
         || ($headroom['late_bytes'] ?? null) !== ''
         || ($headroom['command'] ?? false) !== true
         || ($headroom['answered'] ?? false) !== true
         || ($result['value'] ?? null) !== 'HEADROOM'
         || array_key_exists('error', $result) === false
         || $result['error'] !== null
         || ($result['census']['connections'] ?? null) !== $cap
         || ($result['census']['reads'] ?? PHP_INT_MAX) > $cap + 2
         || str_starts_with($floodWire, 'HTTP/1.1 200 OK') === false
         || str_ends_with($floodWire, "\r\n\r\nRH3-HEADROOM:\"HEADROOM\"") === false
         || $recovered === null
         || (int) $recovered['connections'] > (int) ($baseline['connections'] ?? 0)
      ) {
         Vars::$labels = ['RH-H3 selector headroom under an idle flood'];
         dump(json_encode($headroom));

         return "RH-H3 regression: an idle flood past the admissible census did not stop at {$cap} "
            . "clients (Select::CAPACITY - {$reserve}) and leave the deferred dependency wait its "
            . 'selector reserve. Evidence: ' . json_encode($headroom);
      }

      // @ Teardown answered with the pool still intact and the worker's own
      //   reserve given back
      $teardown = $Decode($teardownWire, 'RH3-TEARDOWN:');
      $flooded = $Decode($floodedWire, 'RH3-CENSUS:');
      if (
         $teardown === null
         || $flooded === null
         || ($teardown['busy'] ?? null) !== 0
         || ($teardown['pending'] ?? null) !== 0
         || ($teardown['restored'] ?? null) !== ($setup['previous'] ?? false)
      ) {
         return 'RH-H3 regression: teardown evidence is missing, the pool kept a claim or the worker '
            . 'kept the raised reserve: '
            . json_encode(['census' => $flooded, 'teardown' => $teardown, 'previous' => $setup['previous'] ?? null]);
      }

      return true;
   },
);
