<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Driver;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL;
use Bootgly\ADI\Databases\SQL\Operation as SQLOperation;


return new Test(
   description: 'Database: operations and claims from a retired SQL driver cannot reach its replacement',
   test: function () {
      /**
       * Opens a one-connection SQL pool over a socketpair.
       *
       * @return array{SQL, resource}
       */
      $Open = static function (string $driver): array {
         [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($peer, false);

         $Database = new SQL([
            'driver' => $driver,
            'timeout' => 30.0,
            'secure' => ['mode' => 'disable'],
            'pool' => ['min' => 0, 'max' => 1],
         ]);
         $Database->Connection->attach($client);

         return [$Database, $peer];
      };
      /**
       * Builds one single-column SELECT response for the requested engine.
       */
      $Reply = static function (string $driver, Driver $Protocol, int $value): string {
         if ($driver === 'mysql') {
            if ($Protocol instanceof MySQL === false) {
               return '';
            }

            $name = 'value';
            $column = "\x03def\x02db\x05table\x05table"
               . chr(strlen($name)) . $name
               . chr(strlen($name)) . $name
               . "\x0C" . pack('v', 45) . pack('V', 255) . "\x03" . pack('v', 0) . "\x00\x00\x00";
            $eof = "\xFE" . pack('v', 0) . pack('v', 0);
            $text = (string) $value;

            return $Protocol->Encoder->frame("\x01", 1)
               . $Protocol->Encoder->frame($column, 2)
               . $Protocol->Encoder->frame($eof, 3)
               . $Protocol->Encoder->frame(chr(strlen($text)) . $text, 4)
               . $Protocol->Encoder->frame($eof, 5);
         }

         $columnPayload = pack('n', 1)
            . "value\0"
            . pack('N', 0)
            . pack('n', 0)
            . pack('N', 23)
            . pack('n', 4)
            . pack('N', 0xFFFFFFFF)
            . pack('n', 0);
         $rowPayload = pack('n', 1) . pack('N', strlen((string) $value)) . (string) $value;
         $commandPayload = "SELECT 1\0";

         return 'T' . pack('N', strlen($columnPayload) + 4) . $columnPayload
            . 'D' . pack('N', strlen($rowPayload) + 4) . $rowPayload
            . 'C' . pack('N', strlen($commandPayload) + 4) . $commandPayload
            . 'Z' . pack('N', 5) . 'I';
      };
      $Owns = static fn (SQLOperation $Operation, int $value): bool =>
         $Operation->Result?->rows === [['value' => $value]];
      $drivers = [
         'pgsql' => 'PostgreSQL',
         'mysql' => 'MySQL',
      ];

      foreach ($drivers as $driver => $label) {
         // # A quiet operation belongs to the session that prepared it
         //   It is assigned while the first command owns the wire, but is never
         //   advanced, so the old driver's abort cannot find it in its FIFO. The
         //   pool then rebuilds on the same Connection object. Driving the quiet
         //   operation through the retired driver must refuse it locally: if its
         //   SELECT 222 reaches the new socket first, the replacement driver
         //   consumes that answer as the result of SELECT 333.
         [$Database, $deadPeer] = $Open($driver);

         $Doomed = $Database->query('SELECT 111 AS value');
         $Database->advance($Doomed);
         $doomedWire = (string) fread($deadPeer, 8192);

         $Stranded = $Database->query('SELECT 222 AS value');
         $OldProtocol = $Stranded->Protocol;
         $Connection = $Stranded->Connection;
         $quiet = $Stranded->state->name === 'Queued'
            && $Stranded->finished === false
            && $OldProtocol !== null
            && $Connection !== null;

         fclose($deadPeer);
         $Database->advance($Doomed);

         $survived = $quiet
            && $Doomed->finished
            && $Doomed->quarantine
            && $Stranded->finished === false
            && $Stranded->error === null;

         if ($Connection === null || $OldProtocol === null) {
            yield assert(
               assertion: false,
               description: "POOL-31 ({$label}): setup did not assign the quiet operation to the retired session"
            );

            continue;
         }

         [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($peer, false);
         $Connection->attach($client);

         $Fresh = $Database->query('SELECT 333 AS value');
         $NewProtocol = $Fresh->Protocol;
         $rebuilt = $Fresh->Connection === $Connection
            && $NewProtocol !== null
            && $NewProtocol !== $OldProtocol
            && $Connection->Protocol === $NewProtocol;

         yield assert(
            assertion: str_contains($doomedWire, 'SELECT 111 AS value') && $survived && $rebuilt,
            description: "POOL-31 ({$label}): a quiet queued operation survives the abort while the same Connection gets a new driver"
         );

         $Database->advance($Stranded);
         $staleWire = (string) fread($peer, 8192);
         $retiredError = "{$label} connection was torn down before the query was sent.";

         yield assert(
            assertion: $Stranded->finished
               && $Stranded->error === $retiredError
               && $Stranded->quarantine
               && $Stranded->write === ''
               && $staleWire === '',
            description: "POOL-31 ({$label}): the retired driver refuses SELECT 222 and writes no bytes; "
               . 'error=' . ($Stranded->error ?? 'null') . ', wire=' . bin2hex($staleWire)
         );

         $Database->advance($Fresh);
         $freshWire = (string) fread($peer, 8192);
         $replyValue = $staleWire === '' ? 333 : 222;

         if ($NewProtocol !== null) {
            fwrite($peer, $Reply($driver, $NewProtocol, $replyValue));
         }

         $Database->advance($Fresh);

         yield assert(
            assertion: str_contains($freshWire, 'SELECT 333 AS value')
               && $Fresh->finished
               && $Fresh->error === null
               && $Owns($Fresh, 333)
               && $Connection->Protocol === $NewProtocol
               && is_resource($Connection->socket),
            description: "POOL-31 ({$label}): the replacement driver receives the result of its own SELECT 333"
         );

         fclose($peer);
         $Connection->disconnect();

         // # A stale teardown owns no claim on the replacement session
         //   `unlock=true` makes cancel() settle a transaction teardown that
         //   never reached the old wire. Once that wire has died and this same
         //   Connection carries a new driver, severing through the stale driver
         //   would disconnect the fresh SELECT already waiting for its answer.
         [$Database, $deadPeer] = $Open($driver);

         $Doomed = $Database->query('SELECT 111 AS value');
         $Database->advance($Doomed);
         fread($deadPeer, 8192);

         $Teardown = $Database->query('ROLLBACK');
         $Teardown->unlock = true;
         $OldProtocol = $Teardown->Protocol;
         $Connection = $Teardown->Connection;

         fclose($deadPeer);
         $Database->advance($Doomed);

         if ($Connection === null || $OldProtocol === null) {
            yield assert(
               assertion: false,
               description: "POOL-31 ({$label}): setup did not assign the teardown to the retired session"
            );

            continue;
         }

         [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($peer, false);
         $Connection->attach($client);

         $Fresh = $Database->query('SELECT 333 AS value');
         $NewProtocol = $Fresh->Protocol;
         $Database->advance($Fresh);
         $freshWire = (string) fread($peer, 8192);

         $Database->cancel($Teardown);

         $preserved = $Teardown->finished
            && $Teardown->error === 'Database operation was cancelled before reaching the server.'
            && $Connection->Protocol === $NewProtocol
            && is_resource($Connection->socket)
            && $Fresh->finished === false;

         yield assert(
            assertion: $preserved,
            description: "POOL-31 ({$label}): cancelling a stale unlock teardown does not sever the replacement session"
         );

         if ($NewProtocol !== null) {
            @fwrite($peer, $Reply($driver, $NewProtocol, 333));
         }

         $Database->advance($Fresh);

         yield assert(
            assertion: str_contains($freshWire, 'SELECT 333 AS value')
               && $Fresh->finished
               && $Fresh->error === null
               && $Owns($Fresh, 333)
               && $Connection->Protocol === $NewProtocol
               && is_resource($Connection->socket),
            description: "POOL-31 ({$label}): the in-flight replacement operation resolves after the stale teardown is cancelled"
         );

         fclose($peer);
         $Connection->disconnect();

         // # …and the same holds when its own deadline retires it
         //   cancel() is not the only door into settle(): an unsent teardown the
         //   pool expires arrives at the same gate through advance(). The rule
         //   lives in one place for exactly this reason — on this pool it took
         //   three wrong forms on the cancel route while never reaching the
         //   deadline one, which is the route an application hits on its own.
         [$Database, $deadPeer] = $Open($driver);

         $Doomed = $Database->query('SELECT 111 AS value');
         $Database->advance($Doomed);
         fread($deadPeer, 8192);

         // ! A deadline short enough to elapse inside this case, while the
         //   replacement query below keeps the ordinary one.
         $Database->Pool->Config->timeout = 0.05;
         $Teardown = $Database->query('ROLLBACK');
         $Teardown->unlock = true;
         $Database->Pool->Config->timeout = 30.0;
         $OldProtocol = $Teardown->Protocol;
         $Connection = $Teardown->Connection;

         fclose($deadPeer);
         $Database->advance($Doomed);

         if ($Connection === null || $OldProtocol === null) {
            yield assert(
               assertion: false,
               description: "POOL-31 ({$label}): setup did not assign the expiring teardown to the retired session"
            );

            continue;
         }

         [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($peer, false);
         $Connection->attach($client);

         $Fresh = $Database->query('SELECT 333 AS value');
         $NewProtocol = $Fresh->Protocol;
         $Database->advance($Fresh);
         $freshWire = (string) fread($peer, 8192);

         usleep(80_000);
         $Database->advance($Teardown);

         yield assert(
            assertion: $Teardown->finished
               && str_contains((string) $Teardown->error, 'timed out')
               && $Connection->Protocol === $NewProtocol
               && is_resource($Connection->socket)
               && $Fresh->finished === false
               && $Fresh->error === null,
            description: "POOL-31 ({$label}): expiring a stale unlock teardown does not sever the replacement session"
         );

         if ($NewProtocol !== null) {
            @fwrite($peer, $Reply($driver, $NewProtocol, 333));
         }

         $Database->advance($Fresh);

         yield assert(
            assertion: str_contains($freshWire, 'SELECT 333 AS value')
               && $Fresh->finished
               && $Fresh->error === null
               && $Owns($Fresh, 333),
            description: "POOL-31 ({$label}): the replacement operation still resolves after the stale teardown expires"
         );

         fclose($peer);
         $Connection->disconnect();
      }
   }
);
