<?php

use function pack;
use function strlen;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL\Encoder;


return new Specification(
   description: 'Database: PostgreSQL encoder emits Extended Query messages',
   test: function () {
      $Encoder = new Encoder;

      $sql = 'SELECT $1';
      $parseTypes = pack('n', 1);
      $parseType = pack('N', 23);
      $parseBody = "s1\0{$sql}\0{$parseTypes}{$parseType}";
      $parseLength = pack('N', strlen($parseBody) + 4);
      $parseExpected = "P{$parseLength}{$parseBody}";

      yield assert(
         assertion: $Encoder->encode(Encoder::PARSE, [
            'statement' => 's1',
            'sql' => $sql,
            'types' => [23],
         ]) === $parseExpected,
         description: 'Parse message contains statement, SQL and parameter type OIDs'
      );

      $formatCount = pack('n', 0);
      $parameterCount = pack('n', 3);
      $parameterOneLength = pack('N', 2);
      $parameterNullLength = pack('N', 0xFFFFFFFF);
      $parameterBoolLength = pack('N', 1);
      $resultFormatCount = pack('n', 0);
      $bindBody = "\0s1\0{$formatCount}{$parameterCount}{$parameterOneLength}42{$parameterNullLength}{$parameterBoolLength}t{$resultFormatCount}";
      $bindLength = pack('N', strlen($bindBody) + 4);
      $bindExpected = "B{$bindLength}{$bindBody}";

      yield assert(
         assertion: $Encoder->encode(Encoder::BIND, [
            'portal' => '',
            'statement' => 's1',
            'parameters' => [42, null, true],
         ]) === $bindExpected,
         description: 'Bind message encodes text parameters and null length'
      );

      $binaryFormatCount = pack('n', 3);
      $binaryFormats = pack('n', 1) . pack('n', 1) . pack('n', 1);
      $binaryParameterCount = pack('n', 3);
      $binaryInteger = pack('N', 42);
      $binaryIntegerLength = pack('N', strlen($binaryInteger));
      $binaryBoolean = "\x01";
      $binaryBooleanLength = pack('N', strlen($binaryBoolean));
      $binaryFloat = pack('E', 1.5);
      $binaryFloatLength = pack('N', strlen($binaryFloat));
      $binaryBody = "\0s1\0{$binaryFormatCount}{$binaryFormats}{$binaryParameterCount}{$binaryIntegerLength}{$binaryInteger}{$binaryBooleanLength}{$binaryBoolean}{$binaryFloatLength}{$binaryFloat}{$resultFormatCount}";
      $binaryLength = pack('N', strlen($binaryBody) + 4);
      $binaryExpected = "B{$binaryLength}{$binaryBody}";

      yield assert(
         assertion: $Encoder->encode(Encoder::BIND, [
            'portal' => '',
            'statement' => 's1',
            'parameters' => [42, true, 1.5],
            'types' => [23, 16, 701],
         ]) === $binaryExpected,
         description: 'Bind message selects binary parameter formats for safe OIDs'
      );

      $describeBody = "P\0";
      $describeLength = pack('N', strlen($describeBody) + 4);
      $executeRows = pack('N', 0);
      $executeBody = "\0{$executeRows}";
      $executeLength = pack('N', strlen($executeBody) + 4);
      $syncLength = pack('N', 4);

      yield assert(
         assertion: $Encoder->encode(Encoder::DESCRIBE, '') === "D{$describeLength}{$describeBody}"
            && $Encoder->encode(Encoder::EXECUTE, '') === "E{$executeLength}{$executeBody}"
            && $Encoder->encode(Encoder::SYNC) === "S{$syncLength}",
         description: 'Describe, Execute and Sync messages are encoded'
      );
   }
);
