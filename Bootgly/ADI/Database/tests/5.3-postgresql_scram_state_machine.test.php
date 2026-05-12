<?php

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function fclose;
use function fread;
use function fwrite;
use function pack;
use function strlen;
use function stream_set_blocking;
use function stream_socket_pair;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Config;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation;
use Bootgly\ADI\Database\OperationStates;
use Bootgly\ADI\Database\Connection\Protocols\PostgreSQL;


return new Specification(
   description: 'Database: PostgreSQL SCRAM state machine writes auth responses and query',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Config = new Config([
         'password' => 'pencil',
         'secure' => [
            'mode' => 'disable',
         ],
         'username' => 'user',
      ]);
      $Connection = new Connection($Config);
      $Connection->attach($client);
      $PostgreSQL = new PostgreSQL($Config, $Connection);
      $PostgreSQL->Authentication->clientNonce = 'fyko+d2lbbFgONRv9qkxdawL';
      $Operation = new Operation($Connection, 'SELECT 1');
      $Operation->state = OperationStates::Connecting;

      $PostgreSQL->advance($Operation);

      yield assert(
         assertion: fread($server, 8192) !== '',
         description: 'State machine writes StartupMessage after connect readiness'
      );

      $saslCode = pack('N', 10);
      $saslData = "{$saslCode}SCRAM-SHA-256\0\0";
      $saslLength = pack('N', strlen($saslData) + 4);
      $sasl = "R{$saslLength}{$saslData}";
      fwrite($server, $sasl);

      $PostgreSQL->advance($Operation);

      $initialResponse = 'n,,n=user,r=fyko+d2lbbFgONRv9qkxdawL';
      $initialResponseLength = pack('N', strlen($initialResponse));
      $initialLength = pack('N', strlen('SCRAM-SHA-256') + strlen($initialResponse) + 9);
      $initialExpected = "p{$initialLength}SCRAM-SHA-256\0{$initialResponseLength}{$initialResponse}";

      yield assert(
         assertion: fread($server, 8192) === $initialExpected,
         description: 'State machine writes SASLInitialResponse'
      );

      $serverFirst = 'r=fyko+d2lbbFgONRv9qkxdawL3rfcNHYJY1ZVvWVs7j,s=QSXCR+Q6sek8bf92,i=4096';
      $continueCode = pack('N', 11);
      $continueData = "{$continueCode}{$serverFirst}";
      $continueLength = pack('N', strlen($continueData) + 4);
      $continue = "R{$continueLength}{$continueData}";
      fwrite($server, $continue);

      $PostgreSQL->advance($Operation);

      $clientFinal = 'c=biws,r=fyko+d2lbbFgONRv9qkxdawL3rfcNHYJY1ZVvWVs7j,p=qQRLRHGPDGjB+7iVAE7NNi5xEoHKHuLCHPNQ8BTmvds=';
      $clientFinalLength = pack('N', strlen($clientFinal) + 4);
      $clientFinalExpected = "p{$clientFinalLength}{$clientFinal}";

      yield assert(
         assertion: fread($server, 8192) === $clientFinalExpected,
         description: 'State machine writes SCRAM client-final-message'
      );

      $serverFinal = 'v=XKW6VuW1FANROQabnJBz1KaeCnQL/HZByQtX/iU+o30=';
      $finalCode = pack('N', 12);
      $finalData = "{$finalCode}{$serverFinal}";
      $finalLength = pack('N', strlen($finalData) + 4);
      $final = "R{$finalLength}{$finalData}";
      $okData = pack('N', 0);
      $okLength = pack('N', strlen($okData) + 4);
      $ok = "R{$okLength}{$okData}";
      $readyLength = pack('N', 5);
      $ready = "Z{$readyLength}I";
      fwrite($server, "{$final}{$ok}{$ready}");

      $PostgreSQL->advance($Operation);

      $queryLength = pack('N', strlen('SELECT 1') + 5);
      $queryExpected = "Q{$queryLength}SELECT 1\0";

      yield assert(
         assertion: fread($server, 8192) === $queryExpected && $Operation->state === OperationStates::Reading,
         description: 'State machine writes query after SCRAM authentication completes'
      );

      fclose($server);
      $Connection->disconnect();
   }
);
