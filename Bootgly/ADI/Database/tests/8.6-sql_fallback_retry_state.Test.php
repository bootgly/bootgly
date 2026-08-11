<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Config as SQLConfig;
use Bootgly\ADI\Databases\SQL\Operation as SQLOperation;


return new Test(
   description: 'Database: replica fallback retries with clean per-attempt SQL state',
   test: function () {
      [$replicaClient, $replicaServer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      [$primaryClient, $primaryServer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($replicaClient, false);
      stream_set_blocking($replicaServer, false);
      stream_set_blocking($primaryClient, false);
      stream_set_blocking($primaryServer, false);

      $Database = new SQL([
         'pool' => [
            'min' => 0,
            'max' => 1,
         ],
         'routing' => [
            'sticky' => 0.0,
         ],
         'replicas' => [
            [
               'host' => 'replica-fallback.local',
               'pool' => [
                  'min' => 0,
                  'max' => 1,
               ],
            ],
         ],
      ]);
      $Database->Connection->attach($primaryClient);
      $Database->ReplicaPools[0]->Connection->attach($replicaClient);

      // ! PostgreSQL backend framing (single int `value` column)
      $description = static function (): string {
         $payload = pack('n', 1) . "value\0" . pack('N', 0) . pack('n', 0) . pack('N', 23)
            . pack('n', 4) . pack('N', 0xFFFFFFFF) . pack('n', 0);

         return 'T' . pack('N', strlen($payload) + 4) . $payload;
      };
      $row = static function (string $value): string {
         $payload = pack('n', 1) . pack('N', strlen($value)) . $value;

         return 'D' . pack('N', strlen($payload) + 4) . $payload;
      };
      $error = static function (string $message): string {
         $payload = "SERROR\0C57014\0M{$message}\0\0";

         return 'E' . pack('N', strlen($payload) + 4) . $payload;
      };
      $complete = static function (string $tag): string {
         $payload = "{$tag}\0";

         return 'C' . pack('N', strlen($payload) + 4) . $payload;
      };
      $ready = 'Z' . pack('N', 5) . 'I';

      $SQL = 'SELECT value FROM readings';
      $query = 'Q' . pack('N', strlen($SQL) + 5) . "{$SQL}\0";
      $partial = $description() . $row('1') . $row('2')
         . $error('canceling statement due to statement timeout') . $ready;
      $full = $description() . $row('10') . $row('20') . $row('30') . $row('40')
         . $complete('SELECT 4') . $ready;

      // # Replica attempt — partial rows, then statement_timeout
      $Operation = $Database->query($SQL);

      yield assert(
         assertion: $Operation->Pool === $Database->ReplicaPools[0]
            && $Operation->FallbackPool === $Database->Pool,
         description: 'Safe read is routed to the replica with the primary as fallback'
      );

      $Database->advance($Operation);

      yield assert(
         assertion: fread($replicaServer, 8192) === $query,
         description: 'Replica attempt writes the query through the replica socket'
      );

      fwrite($replicaServer, $partial);
      $Database->advance($Operation);

      yield assert(
         assertion: $Operation->fallback
            && $Operation->Pool === $Database->Pool
            && $Operation->error === null
            && $Operation->finished === false
            && $Operation->rows === []
            && $Operation->columns === []
            && $Operation->types === []
            && $Operation->affected === 0,
         description: 'Fallback retry restarts with clean per-attempt SQL state'
      );

      // # Primary attempt — full result
      $Database->advance($Operation);

      yield assert(
         assertion: fread($primaryServer, 8192) === $query,
         description: 'Fallback attempt re-issues the query through the primary socket'
      );

      fwrite($primaryServer, $full);
      $Database->advance($Operation);

      yield assert(
         assertion: $Operation->finished
            && $Operation->error === null
            && count($Operation->rows) === 4
            && ($Operation->rows[0]['value'] ?? null) === 10
            && ($Operation->rows[3]['value'] ?? null) === 40
            && count($Operation->Result->rows ?? []) === 4,
         description: 'Fallback result contains only the primary attempt rows'
      );

      yield assert(
         assertion: $Operation->status === 'SELECT 4'
            && $Operation->affected === 4
            && $Operation->columns === ['value'],
         description: 'Fallback result keeps coherent status, affected count and columns'
      );

      yield assert(
         assertion: count($Database->ReplicaPools[0]->idle) === 1
            && $Database->ReplicaPools[0]->healthy
            && $Database->ReplicaPools[0]->failures === 0
            && count($Database->Pool->idle) === 1,
         description: 'Server-error fallback releases both connections without quarantine'
      );

      // # retry() unit surface — every per-attempt field resets
      $Retry = new SQLOperation(null, 'SELECT 1 AS value');
      $Retry->statement = 'stale';
      $Retry->portal = 'stale_portal';
      $Retry->prepared = true;
      $Retry->status = 'SELECT 2';
      $Retry->rows = [['value' => 1]];
      $Retry->columns = ['value'];
      $Retry->types = [23];
      $Retry->parameterTypes = [23];
      $Retry->affected = 2;
      $Retry->fail('Replica read failed.');
      // ! fail() clears write — re-stain it to prove retry() clears it on its own
      $Retry->write = "\x01";
      $Retry->retry();

      yield assert(
         assertion: $Retry->statement === '' && $Retry->portal === '' && $Retry->prepared === false
            && $Retry->write === '' && $Retry->status === '' && $Retry->rows === []
            && $Retry->columns === [] && $Retry->types === [] && $Retry->parameterTypes === []
            && $Retry->affected === 0 && $Retry->state === OperationStates::Pending
            && $Retry->finished === false && $Retry->error === null,
         description: 'retry() resets every per-attempt SQL protocol field'
      );

      $Pinned = new Connection(new SQLConfig([]));
      $Retry->retry($Pinned);

      yield assert(
         assertion: $Retry->Connection === $Pinned,
         description: 'retry() forwards the pinned connection to the base operation'
      );

      fclose($replicaServer);
      fclose($primaryServer);
      $Database->ReplicaPools[0]->Connection->disconnect();
      $Database->Connection->disconnect();
   }
);
