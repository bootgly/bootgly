<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL\Encoder;


return new Test(
   description: 'Database: PostgreSQL encoder binds binary only when the value fits the OID',
   test: function () {
      $Encoder = new Encoder;
      $expected = function (array $formats, array $values): string {
         $formatBytes = '';
         $binary = false;

         foreach ($formats as $format) {
            $formatBytes .= pack('n', $format);
            $binary = $binary || $format !== 0;
         }

         $formatCount = $formatBytes === '' || $binary === false
            ? pack('n', 0)
            : pack('n', count($formats)) . $formatBytes;
         $parameterCount = pack('n', count($values));
         $parameterBytes = '';

         foreach ($values as $value) {
            $parameterBytes .= pack('N', strlen($value)) . $value;
         }

         $resultFormatCount = pack('n', 0);
         $body = "\0s1\0{$formatCount}{$parameterCount}{$parameterBytes}{$resultFormatCount}";

         return 'B' . pack('N', strlen($body) + 4) . $body;
      };
      $bind = fn (array $parameters, array $types): string => $Encoder->encode(Encoder::BIND, [
         'portal' => '',
         'statement' => 's1',
         'parameters' => $parameters,
         'types' => $types,
      ]);

      yield assert(
         assertion: $bind([9000000000], [23]) === $expected([0], ['9000000000']),
         description: 'An int beyond int4 range falls back to text instead of masked binary bytes'
      );

      yield assert(
         assertion: $bind(['abc'], [23]) === $expected([0], ['abc']),
         description: 'A non-int value under an int4 OID travels as text for backend validation'
      );

      yield assert(
         assertion: $bind(['false'], [16]) === $expected([0], ['false']),
         description: 'A string under a boolean OID travels as text instead of truthiness bytes'
      );

      yield assert(
         assertion: $bind([5], [701]) === $expected([0], ['5']),
         description: 'An int under a double OID travels as text instead of a lossy float pack'
      );

      yield assert(
         assertion: $bind([42, true, 1.5], [23, 16, 701])
            === $expected([1, 1, 1], [pack('N', 42), "\x01", pack('E', 1.5)]),
         description: 'Values that provably fit their OIDs keep the binary fast path'
      );

      yield assert(
         assertion: $bind([42, 9000000000], [23, 23])
            === $expected([1, 0], [pack('N', 42), '9000000000']),
         description: 'Per-parameter format codes mix binary and text in one Bind'
      );
   }
);
