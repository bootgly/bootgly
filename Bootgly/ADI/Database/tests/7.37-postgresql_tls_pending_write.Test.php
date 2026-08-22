<?php


use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Database\Pool;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;


$supported = extension_loaded('openssl')
   && function_exists('pcntl_fork')
   && function_exists('stream_socket_enable_crypto');


return new Test(
   description: 'PostgreSQL: TLS backpressure never replaces an OpenSSL pending write buffer',
   skip: $supported === false,
   test: function () {
      $certificate = __DIR__ . '/fixtures/postgresql_tls.pem';

      $Peek = static function (PostgreSQL $PostgreSQL, string $property): mixed {
         return (new ReflectionProperty(PostgreSQL::class, $property))->getValue($PostgreSQL);
      };
      $Wait = static function (float $seconds): void {
         $started = microtime(true);

         while (microtime(true) - $started < $seconds) { /* @ keep the deadline deterministic */ }
      };
      $Drive = static function (
         PostgreSQL $PostgreSQL,
         \Bootgly\ADI\Databases\SQL\Operation $Operation,
         OperationStates $state,
         float $seconds = 5.0
      ): void {
         $deadline = microtime(true) + $seconds;

         while ($Operation->finished === false && $Operation->state !== $state && microtime(true) < $deadline) {
            $PostgreSQL->advance($Operation);
            $Readiness = $Operation->Readiness;

            if ($Readiness === null || is_resource($Readiness->socket) === false) {
               continue;
            }

            $read = [];
            $write = [];
            $except = [];

            if ($Readiness->flag === Scheduler::SCHEDULE_READ) {
               $read[] = $Readiness->socket;
            }
            else {
               $write[] = $Readiness->socket;
            }

            if ($read !== [] || $write !== []) {
               @stream_select($read, $write, $except, 0, 20_000);
            }
         }
      };

      // ! One real PostgreSQL SSLRequest -> TLS -> Startup path. The peer then
      //   stops reading application bytes until the parent exposes the pending
      //   OpenSSL record and explicitly opens the gate.
      $Run = static function (string $mode) use ($certificate, $Drive, $Peek, $Wait): array {
         $Result = [
            'mode' => $mode,
            'exception' => null,
            'hello' => null,
            'peer' => null,
            'packet' => 0,
            'nextPacket' => 0,
            'whole' => 0,
            'blocked' => null,
            'before' => null,
            'after' => null,
         ];
         $ServerContext = stream_context_create([
            'ssl' => [
               'local_cert' => $certificate,
               'verify_peer' => false,
               'allow_self_signed' => true,
            ],
         ]);
         $Listener = @stream_socket_server(
            'tcp://127.0.0.1:0',
            $errorCode,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $ServerContext
         );

         if (is_resource($Listener) === false) {
            $Result['exception'] = "TLS listener failed: {$errorCode} {$error}";

            return $Result;
         }

         $address = (string) stream_socket_get_name($Listener, false);
         $separator = strrpos($address, ':');
         $port = $separator === false ? 0 : (int) substr($address, $separator + 1);
         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

         if ($Pair === false) {
            fclose($Listener);
            $Result['exception'] = 'TLS control socketpair failed.';

            return $Result;
         }

         [$control, $childControl] = $Pair;
         stream_set_timeout($control, 10);
         $PID = pcntl_fork();

         if ($PID === -1) {
            fclose($control);
            fclose($childControl);
            fclose($Listener);
            $Result['exception'] = 'TLS peer fork failed.';

            return $Result;
         }

         if ($PID === 0) {
            fclose($control);
            $client = @stream_socket_accept($Listener, 5.0);
            fclose($Listener);

            if (is_resource($client) === false) {
               @fwrite($childControl, json_encode(['error' => 'accept']) . "\n");
               fclose($childControl);
               exit(0);
            }

            stream_set_blocking($client, true);
            stream_set_timeout($client, 5);

            $Read = static function ($socket, int $length): string {
               $bytes = '';

               while (strlen($bytes) < $length) {
                  $chunk = @fread($socket, $length - strlen($bytes));

                  if ($chunk === false || $chunk === '') {
                     break;
                  }

                  $bytes .= $chunk;
               }

               return $bytes;
            };

            $SSL = $Read($client, 8);
            @fwrite($client, 'S');
            $encrypted = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
            $header = $encrypted === true ? $Read($client, 4) : '';
            $unpacked = strlen($header) === 4 ? unpack('Nlength', $header) : false;
            $startup = is_array($unpacked) ? (int) ($unpacked['length'] ?? 0) : 0;

            if ($startup >= 4) {
               $Read($client, $startup - 4);
            }

            if ($encrypted === true && $startup >= 8) {
               $authentication = 'R' . pack('N', 8) . pack('N', 0);
               $ready = 'Z' . pack('N', 5) . 'I';
               @fwrite($client, "{$authentication}{$ready}");
            }

            @fwrite($childControl, json_encode([
               'ssl' => bin2hex($SSL),
               'encrypted' => $encrypted,
               'startup' => $startup,
            ]) . "\n");

            // @ The parent writes `g` only after the stale/abandon decision.
            $gate = @fread($childControl, 1);
            stream_set_blocking($client, false);
            stream_set_blocking($childControl, false);
            $total = 0;
            $countA = 0;
            $countB = 0;
            $done = false;
            $idle = 0;
            $deadline = microtime(true) + 6.0;

            while (microtime(true) < $deadline) {
               $signal = @fread($childControl, 8192);

               if (is_string($signal) && str_contains($signal, 'd')) {
                  $done = true;
               }

               $bytes = @fread($client, 262_144);

               if ($bytes === false) {
                  break;
               }

               if ($bytes === '') {
                  $idle++;

                  if ($done && $idle >= 50) {
                     break;
                  }

                  usleep(1_000);

                  continue;
               }

               $idle = 0;
               $total += strlen($bytes);
               $countA += substr_count($bytes, 'A');
               $countB += substr_count($bytes, 'B');
            }

            @fwrite($childControl, json_encode([
               'gate' => $gate,
               'total' => $total,
               'A' => $countA,
               'B' => $countB,
            ]) . "\n");
            fclose($client);
            fclose($childControl);
            exit(0);
         }

         fclose($childControl);
         fclose($Listener);
         $Connection = null;
         $released = false;

         try {
            $Config = new Config([
               'driver' => 'pgsql',
               'host' => '127.0.0.1',
               'port' => $port,
               'database' => 'bootgly_tls_test',
               'username' => 'bootgly_tls_test',
               'timeout' => 0.05,
               'secure' => [
                  'mode' => Config::SECURE_REQUIRE,
                  'verify' => false,
                  'name' => false,
               ],
            ]);
            $Connection = new Connection($Config);
            $PostgreSQL = new PostgreSQL($Config, $Connection);
            $Boot = $PostgreSQL->query('SELECT 0');
            $Drive($PostgreSQL, $Boot, OperationStates::Reading);
            $hello = @fgets($control);
            $Result['hello'] = is_string($hello) ? json_decode(trim($hello), true) : null;
            $Result['sessionEncrypted'] = $Peek($PostgreSQL, 'encrypted');

            // # A protocol can also be constructed over an already-ready TLS
            //   stream attached by its caller. That path skips Connecting and
            //   SSLHandshake, so Queued must classify the stream metadata.
            if ($mode === 'attach') {
               $socket = $Connection->socket;

               if (is_resource($socket) === false) {
                  throw new RuntimeException('Attached TLS stream disappeared after Startup.');
               }

               $Connection->attach($socket);
               $PostgreSQL = new PostgreSQL($Config, $Connection);
            }

            // # The same driver opens a new plaintext Connecting session
            //   after this TLS session. The encrypted-session marker must be
            //   reset there; using a fresh driver would miss that transition.
            if ($mode === 'reset') {
               @fwrite($control, 'g');
               $released = true;
               $Connection->disconnect();
               $PlainPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

               if ($PlainPair === false) {
                  throw new RuntimeException('Plaintext reconnect socketpair failed.');
               }

               [$plainClient, $plainServer] = $PlainPair;
               stream_set_blocking($plainClient, false);
               stream_set_blocking($plainServer, false);

               try {
                  $Config->secure['mode'] = Config::SECURE_DISABLE;
                  $Config->secure['verify'] = false;
                  $Config->secure['name'] = false;
                  $Connection->attach($plainClient);
                  $Reconnect = $PostgreSQL->query('SELECT 0');
                  $Reconnect->state = OperationStates::Connecting;
                  $PostgreSQL->advance($Reconnect);
                  $startup = (string) @fread($plainServer, 8_192);
                  $header = strlen($startup) >= 4 ? unpack('Nlength', substr($startup, 0, 4)) : false;
                  $startupLength = is_array($header) ? (int) ($header['length'] ?? 0) : 0;

                  while (@fwrite($plainClient, str_repeat('j', 65_536)) > 0) { /* @ fill the new plaintext pipe */ }

                  $PlainHolder = $PostgreSQL->query('SELECT 1');
                  $PostgreSQL->advance($PlainHolder);
                  $blocked = [
                     'owner' => $Peek($PostgreSQL, 'writing') === $PlainHolder,
                     'wrote' => $Peek($PostgreSQL, 'wrote'),
                  ];
                  $Wait(0.08);
                  $Config->timeout = 5.0;
                  $PlainNext = $PostgreSQL->query('SELECT 2');
                  $PostgreSQL->advance($PlainNext);
                  $withdrawn = [
                     'owner' => $Peek($PostgreSQL, 'writing') === $PlainNext,
                     'holderFinished' => $PlainHolder->finished,
                     'holderError' => $PlainHolder->error,
                     'holderRevoked' => $PlainHolder->revoked,
                     'connected' => $Connection->connected,
                  ];

                  while (($bytes = @fread($plainServer, 262_144)) !== false && $bytes !== '') { /* @ make room */ }

                  $PostgreSQL->advance($PlainNext);
                  $Result['reset'] = [
                     'startup' => $startupLength,
                     'blocked' => $blocked,
                     'withdrawn' => $withdrawn,
                     'nextWrite' => strlen($PlainNext->write),
                     'connected' => $Connection->connected,
                  ];
               }
               finally {
                  fclose($plainServer);
                  $Connection->disconnect();
               }

               return $Result;
            }

            // ! One complete TLS record per operation. OpenSSL therefore gives
            //   either the whole 16 KiB packet or zero; the first zero belongs
            //   entirely to this operation rather than to a partial predecessor.
            $SQL = "SELECT '" . str_repeat('A', 16_369) . "'";
            $Result['packet'] = strlen($PostgreSQL->Encoder->query($SQL));
            $Holder = null;

            for ($attempt = 0; $attempt < 1_024; $attempt++) {
               $Candidate = $PostgreSQL->query($SQL);
               $PostgreSQL->advance($Candidate);

               if (
                  $Candidate->write === ''
                  && $Candidate->state === OperationStates::Reading
                  && $Candidate->finished === false
               ) {
                  $Result['whole']++;

                  continue;
               }

               $Holder = $Candidate;

               break;
            }

            $Result['blocked'] = $Holder === null ? null : [
               'finished' => $Holder->finished,
               'error' => $Holder->error,
               'write' => strlen($Holder->write),
               'owner' => $Peek($PostgreSQL, 'writing') === $Holder,
               'wrote' => $Peek($PostgreSQL, 'wrote'),
               'encrypted' => $Peek($PostgreSQL, 'encrypted'),
               'connected' => $Connection->connected,
               'feof' => is_resource($Connection->socket) ? feof($Connection->socket) : null,
            ];

            if ($Holder !== null && $Connection->connected) {
               if ($mode === 'revoked') {
                  $Holder->revoked = true;
               }

               $Wait(0.08);

               if ($mode === 'abandon') {
                  // @ Traverse the production expiry envelope: Pool::advance()
                  //   expires first, calls Driver::abandon() and settles the
                  //   now-dead connection claim.
                  $Connection->bind($PostgreSQL);
                  $Pool = new Pool($Config, $Connection);
                  $Pool->attach($Connection);
                  $Holder->Pool = $Pool;
                  $Pool->advance($Holder);
                  $Result['before'] = [
                     'owner' => $Peek($PostgreSQL, 'writing') === $Holder,
                     'finished' => $Holder->finished,
                     'error' => $Holder->error,
                     'connected' => $Connection->connected,
                     'created' => $Pool->created,
                     'idle' => count($Pool->idle),
                     'busy' => count($Pool->busy),
                  ];
               }
               else {
                  $Config->timeout = 5.0;
                  $Next = $PostgreSQL->query("SELECT 'B'");
                  $Result['nextPacket'] = strlen($Next->write);
                  $PostgreSQL->advance($Next);
                  $Owner = $Peek($PostgreSQL, 'writing');
                  $Result['before'] = [
                     'owner' => $Owner === $Holder
                        ? 'holder'
                        : ($Owner === $Next ? 'next' : ($Owner === null ? 'none' : 'other')),
                     'holderFinished' => $Holder->finished,
                     'holderError' => $Holder->error,
                     'holderWrite' => strlen($Holder->write),
                     'nextFinished' => $Next->finished,
                     'nextError' => $Next->error,
                     'nextWrite' => strlen($Next->write),
                     'connected' => $Connection->connected,
                  ];
               }

               @fwrite($control, 'g');
               $released = true;

               if (($mode === 'primary' || $mode === 'attach') && isset($Next)) {
                  $deadline = microtime(true) + 3.0;

                  while (
                     $Connection->connected
                     && $Next->finished === false
                     && $Next->write !== ''
                     && microtime(true) < $deadline
                  ) {
                     $PostgreSQL->advance($Next);
                     usleep(10_000);
                  }

                  $Result['after'] = [
                     'owner' => $Peek($PostgreSQL, 'writing') === null ? 'none' : 'set',
                     'holderFinished' => $Holder->finished,
                     'holderError' => $Holder->error,
                     'holderRevoked' => $Holder->revoked,
                     'holderWrite' => strlen($Holder->write),
                     'nextFinished' => $Next->finished,
                     'nextError' => $Next->error,
                     'nextWrite' => strlen($Next->write),
                     'nextState' => $Next->state->name,
                     'connected' => $Connection->connected,
                  ];
               }
               elseif ($mode === 'revoked') {
                  $Result['after'] = [
                     'holderFinished' => $Holder->finished,
                     'holderError' => $Holder->error,
                     'holderRevoked' => $Holder->revoked,
                     'connected' => $Connection->connected,
                  ];
               }
               else {
                  $Result['after'] = $Result['before'];
               }
            }
         }
         catch (Throwable $Throwable) {
            $Result['exception'] = $Throwable->getMessage();
         }
         finally {
            if ($released === false && is_resource($control)) {
               @fwrite($control, 'g');
            }

            if ($Connection instanceof Connection && is_resource($Connection->socket)) {
               $Connection->disconnect();
            }

            if (is_resource($control)) {
               @fwrite($control, 'd');
               $report = stream_get_contents($control);

               if (is_string($report) && trim($report) !== '') {
                  $lines = array_values(array_filter(explode("\n", trim($report))));
                  $last = $lines[count($lines) - 1] ?? '';
                  $Result['peer'] = json_decode($last, true);
               }

               fclose($control);
            }

            pcntl_waitpid($PID, $status);
         }

         return $Result;
      };

      $Primary = $Run('primary');
      $Attached = $Run('attach');
      $Revoked = $Run('revoked');
      $Abandon = $Run('abandon');
      $Reset = $Run('reset');

      // # Plaintext control — zero really means no byte reached the peer here,
      //   so local withdrawal must remain available outside TLS.
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);
      $PlainConfig = new Config(['driver' => 'pgsql', 'timeout' => 0.05]);
      $PlainConnection = new Connection($PlainConfig);
      $PlainConnection->attach($client);
      $PlainPostgreSQL = new PostgreSQL($PlainConfig, $PlainConnection);

      while (@fwrite($client, str_repeat('j', 65_536)) > 0) { /* @ fill the plaintext pipe */ }

      $PlainHolder = $PlainPostgreSQL->query('SELECT 1');
      $PlainPostgreSQL->advance($PlainHolder);
      $plainBlocked = [
         'owner' => $Peek($PlainPostgreSQL, 'writing') === $PlainHolder,
         'wrote' => $Peek($PlainPostgreSQL, 'wrote'),
      ];
      $Wait(0.08);
      $PlainConfig->timeout = 5.0;
      $PlainNext = $PlainPostgreSQL->query('SELECT 2');
      $PlainPostgreSQL->advance($PlainNext);
      $plainWithdrawn = [
         'owner' => $Peek($PlainPostgreSQL, 'writing') === $PlainNext,
         'holderFinished' => $PlainHolder->finished,
         'holderError' => $PlainHolder->error,
         'holderRevoked' => $PlainHolder->revoked,
         'connected' => $PlainConnection->connected,
      ];

      while (($bytes = @fread($server, 262_144)) !== false && $bytes !== '') { /* @ make room */ }

      $PlainPostgreSQL->advance($PlainNext);
      $plainAfter = [
         'nextWrite' => strlen($PlainNext->write),
         'connected' => $PlainConnection->connected,
      ];
      fclose($server);
      $PlainConnection->disconnect();

      // @ All assertions happen after every child and socket has been cleaned.
      yield assert(
         assertion: $Primary['exception'] === null
            && ($Primary['hello']['ssl'] ?? null) === '0000000804d2162f'
            && ($Primary['hello']['encrypted'] ?? null) === true
            && ($Primary['hello']['startup'] ?? 0) >= 8
            && ($Primary['sessionEncrypted'] ?? null) === true,
         description: 'PG-15 control: the real driver completed SSLRequest, TLS and Startup, found: '
            . json_encode([$Primary['exception'], $Primary['hello'], $Primary['sessionEncrypted']])
      );

      yield assert(
         assertion: $Primary['packet'] === 16_384
            && $Primary['whole'] > 0
            && ($Primary['blocked']['write'] ?? null) === 16_384
            && ($Primary['blocked']['wrote'] ?? null) === 0
            && ($Primary['blocked']['encrypted'] ?? null) === true
            && ($Primary['blocked']['owner'] ?? null) === true
            && ($Primary['blocked']['finished'] ?? null) === false
            && ($Primary['blocked']['error'] ?? null) === null
            && ($Primary['blocked']['connected'] ?? null) === true
            && ($Primary['blocked']['feof'] ?? null) === false,
         description: 'PG-15 control: a holder owns a complete 16 KiB TLS record after its first fwrite returned zero, found: '
            . json_encode([$Primary['packet'], $Primary['whole'], $Primary['blocked']])
      );

      $preserved = ($Primary['before']['owner'] ?? null) === 'holder'
         && ($Primary['before']['holderFinished'] ?? null) === false
         && ($Primary['before']['holderError'] ?? null) === null
         && ($Primary['before']['holderWrite'] ?? null) === 16_384
         && ($Primary['before']['nextWrite'] ?? null) === 16;
      $completed = ($Primary['before']['owner'] ?? null) === 'none'
         && ($Primary['before']['holderFinished'] ?? null) === true
         && str_contains((string) ($Primary['before']['holderError'] ?? ''), 'timed out')
         && ($Primary['before']['holderWrite'] ?? null) === 0
         && ($Primary['before']['nextWrite'] ?? null) === 0;

      yield assert(
         assertion: $Primary['nextPacket'] === 16
            && ($preserved || $completed)
            && ($Primary['before']['connected'] ?? null) === true,
         description: 'PG-15: expiry preserves the pending TLS buffer until it either blocks or completes ahead of the next query, found: '
            . json_encode([$Primary['nextPacket'], $Primary['before']])
      );

      yield assert(
         assertion: ($Primary['after']['owner'] ?? null) === 'none'
            && ($Primary['after']['holderFinished'] ?? null) === true
            && str_contains((string) ($Primary['after']['holderError'] ?? ''), 'timed out')
            && ($Primary['after']['holderRevoked'] ?? null) === true
            && ($Primary['after']['holderWrite'] ?? null) === 0
            && ($Primary['after']['nextFinished'] ?? null) === false
            && ($Primary['after']['nextError'] ?? null) === null
            && ($Primary['after']['nextWrite'] ?? null) === 0
            && ($Primary['after']['nextState'] ?? null) === OperationStates::Reading->name
            && ($Primary['after']['connected'] ?? null) === true,
         description: 'PG-15: draining resumes the same TLS buffer, revokes its unseen result and flushes the next query, found: '
            . json_encode($Primary['after'])
      );

      $attachedPreserved = ($Attached['before']['owner'] ?? null) === 'holder'
         && ($Attached['before']['holderFinished'] ?? null) === false
         && ($Attached['before']['holderWrite'] ?? null) === 16_384
         && ($Attached['before']['nextWrite'] ?? null) === 16;
      $attachedCompleted = ($Attached['before']['owner'] ?? null) === 'none'
         && ($Attached['before']['holderFinished'] ?? null) === true
         && ($Attached['before']['holderWrite'] ?? null) === 0
         && ($Attached['before']['nextWrite'] ?? null) === 0;
      $attachedExpectedA = ((int) $Attached['whole'] + 1) * 16_369;

      yield assert(
         assertion: $Attached['exception'] === null
            && ($Attached['hello']['encrypted'] ?? null) === true
            && ($Attached['blocked']['encrypted'] ?? null) === true
            && ($Attached['blocked']['wrote'] ?? null) === 0
            && ($Attached['blocked']['owner'] ?? null) === true
            && ($attachedPreserved || $attachedCompleted)
            && ($Attached['after']['holderFinished'] ?? null) === true
            && ($Attached['after']['holderRevoked'] ?? null) === true
            && ($Attached['after']['nextWrite'] ?? null) === 0
            && ($Attached['after']['connected'] ?? null) === true
            && ($Attached['peer']['A'] ?? null) === $attachedExpectedA
            && ($Attached['peer']['B'] ?? null) === 1,
         description: 'PG-15: a driver created over an attached ready TLS stream classifies it and preserves pending-buffer ownership, found: '
            . json_encode([$Attached['blocked'], $Attached['before'], $Attached['after'], $Attached['peer']])
      );

      $expectedA = ((int) $Primary['whole'] + 1) * 16_369;

      yield assert(
         assertion: ($Primary['peer']['A'] ?? null) === $expectedA
            && ($Primary['peer']['B'] ?? null) === 1,
         description: 'PG-15: the peer receives the whole stale A batch before the B query, found: '
            . json_encode([$expectedA, $Primary['peer']])
      );

      yield assert(
         assertion: ($Revoked['blocked']['wrote'] ?? null) === 0
            && ($Revoked['after']['holderFinished'] ?? null) === true
            && ($Revoked['after']['holderRevoked'] ?? null) === true
            && str_contains((string) ($Revoked['after']['holderError'] ?? ''), 'withdrawn')
            && ($Revoked['after']['connected'] ?? null) === false
            && ($Revoked['peer']['B'] ?? null) === 0,
         description: 'PG-15: a revoked holder with an indeterminate TLS record drops the session instead of running or replacing it, found: '
            . json_encode([$Revoked['blocked'], $Revoked['after'], $Revoked['peer']])
      );

      yield assert(
         assertion: ($Abandon['blocked']['wrote'] ?? null) === 0
            && ($Abandon['before']['finished'] ?? null) === true
            && str_contains((string) ($Abandon['before']['error'] ?? ''), 'timed out')
            && ($Abandon['before']['connected'] ?? null) === false
            && ($Abandon['before']['created'] ?? null) === 0
            && ($Abandon['before']['idle'] ?? null) === 0
            && ($Abandon['before']['busy'] ?? null) === 0,
         description: 'PG-15: Pool expiry drops an encrypted zero-credit session after fail() destroys the only safe retry buffer, found: '
            . json_encode([$Abandon['blocked'], $Abandon['before']])
      );

      yield assert(
         assertion: ($Reset['exception'] ?? null) === null
            && ($Reset['reset']['startup'] ?? null) >= 8
            && ($Reset['reset']['blocked'] ?? null) === ['owner' => true, 'wrote' => 0]
            && ($Reset['reset']['withdrawn'] ?? null) === [
               'owner' => true,
               'holderFinished' => false,
               'holderError' => null,
               'holderRevoked' => false,
               'connected' => true,
            ]
            && ($Reset['reset']['nextWrite'] ?? null) === 0
            && ($Reset['reset']['connected'] ?? null) === true,
         description: 'PG-15 control: the same driver resets TLS state on a new plaintext Connecting session and withdraws locally, found: '
            . json_encode([$Reset['exception'], $Reset['reset']])
      );

      yield assert(
         assertion: $plainBlocked === ['owner' => true, 'wrote' => 0]
            && $plainWithdrawn === [
               'owner' => true,
               'holderFinished' => false,
               'holderError' => null,
               'holderRevoked' => false,
               'connected' => true,
            ]
            && $plainAfter === ['nextWrite' => 0, 'connected' => true],
         description: 'PG-15 control: plaintext zero-byte holders still withdraw locally and leave the transport reusable, found: '
            . json_encode([$plainBlocked, $plainWithdrawn, $plainAfter])
      );
   }
);
