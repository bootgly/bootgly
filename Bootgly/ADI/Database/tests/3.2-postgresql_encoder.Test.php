<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Config as DatabaseConfig;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL\Encoder;


return new Test(
   description: 'Database: PostgreSQL encoder emits protocol 3.0 frontend messages',
   test: function () {
      $Config = new Config([
         'database' => 'bootgly_test',
         'username' => 'bootgly',
      ]);
      $Encoder = new Encoder;

      // ! The pinned settings — the text formats the driver writes and decodes
      //   (1.1.0 adds DateStyle and standard_conforming_strings after
      //   client_encoding — only names a stock PgBouncer tracks, so a pooler
      //   accepts the message).
      $pinned = "client_encoding\0UTF8\0DateStyle\0ISO\0standard_conforming_strings\0on\0";
      $startupBody = "user\0bootgly\0database\0bootgly_test\0{$pinned}application_name\0Bootgly\0\0";
      $startupLength = pack('N', strlen($startupBody) + 8);
      $startupVersion = pack('N', 196608);
      $startupExpected = "{$startupLength}{$startupVersion}{$startupBody}";

      yield assert(
         assertion: $Encoder->encode(Encoder::STARTUP, $Config) === $startupExpected,
         description: 'StartupMessage contains protocol version, config fields and the pinned settings in order'
      );

      // # Session parameters — after user/database and the pinned settings, in config order
      $frame = static function (string $body): string {
         $length = pack('N', strlen($body) + 8);
         $version = pack('N', 196608);

         return "{$length}{$version}{$body}";
      };
      $Parameterized = new Config([
         'database' => 'bootgly_test',
         'username' => 'bootgly',
         'parameters' => [
            'search_path' => 'app, public',
            'statement_timeout' => 5000,
            'myapp.tenant' => '',
         ],
      ]);
      $parameterized = $frame(
         "user\0bootgly\0database\0bootgly_test\0{$pinned}application_name\0Bootgly\0search_path\0app, public\0statement_timeout\0005000\0myapp.tenant\0\0\0"
      );

      yield assert(
         assertion: $Encoder->encode(Encoder::STARTUP, $Parameterized) === $parameterized,
         description: 'StartupMessage carries the session parameters after the default application_name, in order, an integer in decimal and an empty value kept'
      );

      $Named = new Config([
         'database' => 'bootgly_test',
         'username' => 'bootgly',
         'parameters' => [
            'TimeZone' => 'UTC',
            'Application_Name' => 'billing',
         ],
      ]);
      $named = $frame(
         "user\0bootgly\0database\0bootgly_test\0{$pinned}TimeZone\0UTC\0Application_Name\0billing\0\0"
      );

      yield assert(
         assertion: $Encoder->encode(Encoder::STARTUP, $Named) === $named,
         description: 'A configured application_name, in any letter case, replaces the Bootgly default instead of riding beside it'
      );

      // ? The default is decided over the whole map, not by its last entry.
      $Leading = new Config([
         'database' => 'bootgly_test',
         'username' => 'bootgly',
         'parameters' => [
            'application_name' => 'billing',
            'TimeZone' => 'UTC',
            'search_path' => 'app',
         ],
      ]);
      $leadingEncoded = $Encoder->encode(Encoder::STARTUP, $Leading);
      $leading = $frame(
         "user\0bootgly\0database\0bootgly_test\0{$pinned}application_name\0billing\0TimeZone\0UTC\0search_path\0app\0\0"
      );
      $count = substr_count(strtolower($leadingEncoded), "application_name\0");

      yield assert(
         assertion: $leadingEncoded === $leading && $count === 1,
         description: "An application_name that is not the last entry still replaces the default: sent once ({$count}), in its place"
      );

      $Empty = new Config([
         'database' => 'bootgly_test',
         'username' => 'bootgly',
         'parameters' => [],
      ]);

      yield assert(
         assertion: $Encoder->encode(Encoder::STARTUP, $Empty) === $startupExpected,
         description: 'An empty parameter map encodes the same bytes as no map'
      );

      $refusal = '';
      try {
         $Encoder->encode(Encoder::STARTUP, new DatabaseConfig);
      }
      catch (InvalidArgumentException $Exception) {
         $refusal = $Exception->getMessage();
      }

      yield assert(
         assertion: $refusal === 'PostgreSQL startup payload must be a SQL Config.',
         description: "A config without session parameters (the paradigm-neutral base) is refused — {$refusal}"
      );

      // ? A NUL written into username or database after the config was built
      //   would end the value early: refused at encode time too.
      foreach (['username', 'database'] as $key) {
         $Mutated = new Config(['database' => 'bootgly_test', 'username' => 'bootgly']);
         $Mutated->{$key} = "bootgly\0replication\0database";
         $refusal = '';
         try {
            $Encoder->encode(Encoder::STARTUP, $Mutated);
         }
         catch (InvalidArgumentException $Exception) {
            $refusal = $Exception->getMessage();
         }

         yield assert(
            assertion: $refusal === 'PostgreSQL startup username and database must not contain a NUL byte.',
            description: "A NUL in a {$key} changed after the config was built is refused — {$refusal}"
         );
      }

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
