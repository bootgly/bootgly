<?php

use function pack;
use function strlen;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Config;
use Bootgly\ADI\Database\Connection\Protocols\PostgreSQL\Encoder;


return new Specification(
   description: 'Database: PostgreSQL encoder emits protocol 3.0 frontend messages',
   test: function () {
      $Config = new Config([
         'database' => 'bootgly_test',
         'username' => 'bootgly',
      ]);
      $Encoder = new Encoder;

      $startupBody = "user\0bootgly\0database\0bootgly_test\0client_encoding\0UTF8\0application_name\0Bootgly\0\0";
      $startupLength = pack('N', strlen($startupBody) + 8);
      $startupVersion = pack('N', 196608);
      $startupExpected = "{$startupLength}{$startupVersion}{$startupBody}";

      yield assert(
         assertion: $Encoder->encode(Encoder::STARTUP, $Config) === $startupExpected,
         description: 'StartupMessage contains protocol version and config fields'
      );

      $queryLength = pack('N', strlen('SELECT 1') + 5);
      $queryExpected = "Q{$queryLength}SELECT 1\0";

      yield assert(
         assertion: $Encoder->encode(Encoder::QUERY, 'SELECT 1') === $queryExpected,
         description: 'Simple Query message contains type, length and SQL terminator'
      );

      $passwordLength = pack('N', strlen('secret') + 5);
      $passwordExpected = "p{$passwordLength}secret\0";

      yield assert(
         assertion: $Encoder->encode(Encoder::PASSWORD, 'secret') === $passwordExpected,
         description: 'PasswordMessage contains type, length and password terminator'
      );

      $saslResponse = 'n,,n=user,r=nonce';
      $saslResponseLength = pack('N', strlen($saslResponse));
      $saslLength = pack('N', strlen('SCRAM-SHA-256') + strlen($saslResponse) + 9);
      $saslExpected = "p{$saslLength}SCRAM-SHA-256\0{$saslResponseLength}{$saslResponse}";

      yield assert(
         assertion: $Encoder->encode(Encoder::SASL, [
            'mechanism' => 'SCRAM-SHA-256',
            'response' => $saslResponse,
         ]) === $saslExpected,
         description: 'SASLInitialResponse contains mechanism and initial response'
      );

      $responseLength = pack('N', strlen('client-final') + 4);
      $responseExpected = "p{$responseLength}client-final";

      yield assert(
         assertion: $Encoder->encode(Encoder::RESPONSE, 'client-final') === $responseExpected,
         description: 'SASLResponse contains response payload without terminator'
      );

      $sslLength = pack('N', 8);
      $sslCode = pack('N', 80877103);
      $sslExpected = "{$sslLength}{$sslCode}";

      yield assert(
         assertion: $Encoder->encode(Encoder::SSL) === $sslExpected,
         description: 'SSLRequest contains PostgreSQL TLS negotiation code'
      );

      $cancelBody = pack('N', 80877102) . pack('N', 123) . pack('N', 456);
      $cancelLength = pack('N', strlen($cancelBody) + 4);
      $cancelExpected = "{$cancelLength}{$cancelBody}";

      yield assert(
         assertion: $Encoder->encode(Encoder::CANCEL, [
            'process' => 123,
            'secret' => 456,
         ]) === $cancelExpected,
         description: 'CancelRequest contains cancellation code and backend key'
      );

      $closeBody = "Sbootgly_old\0";
      $closeLength = pack('N', strlen($closeBody) + 4);
      $closeExpected = "C{$closeLength}{$closeBody}";

      yield assert(
         assertion: $Encoder->encode(Encoder::CLOSE, [
            'type' => 'S',
            'name' => 'bootgly_old',
         ]) === $closeExpected,
         description: 'Close message contains statement type and name'
      );

      $describeStatementBody = "Sbootgly_statement\0";
      $describeStatementLength = pack('N', strlen($describeStatementBody) + 4);
      $describeStatementExpected = "D{$describeStatementLength}{$describeStatementBody}";

      yield assert(
         assertion: $Encoder->encode(Encoder::DESCRIBE, [
            'type' => 'S',
            'name' => 'bootgly_statement',
         ]) === $describeStatementExpected,
         description: 'Describe supports statement targets before Bind'
      );
   }
);
