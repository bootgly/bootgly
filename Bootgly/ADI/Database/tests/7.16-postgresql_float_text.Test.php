<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL\Encoder;


return new Test(
   description: 'Database: PostgreSQL encoder renders text floats round-trip exact',
   test: function () {
      $Encoder = new Encoder;
      // ! Text format is mandatory for the first execution of every
      //   parameterized statement — no cached OIDs, so no binary path.
      $bind = fn (float $value): string => $Encoder->encode(Encoder::BIND, [
         'portal' => '',
         'statement' => 's1',
         'parameters' => [$value],
      ]);
      // ! The rendered parameter follows the 4-byte length at offset 13.
      $read = function (string $message): string {
         $length = unpack('N', substr($message, 13, 4));

         return substr($message, 17, $length === false ? 0 : (int) $length[1]);
      };

      $samples = [0.1 + 0.2, 1 / 3, M_PI, 1.2345678901234567, PHP_FLOAT_EPSILON, PHP_FLOAT_MAX, PHP_FLOAT_MIN, -0.0, 1e-320];
      // ! Every ini that governs a float-to-string conversion in PHP: a wire
      //   serializer must render the same bytes under all of them.
      $inis = [
         ['serialize_precision', '-1'],
         ['serialize_precision', '10'],
         ['serialize_precision', '17'],
         ['precision', '10'],
         ['precision', '14'],
         ['precision', '17'],
      ];
      $roundtrip = true;
      $stable = true;

      foreach ($samples as $sample) {
         $expected = $bind($sample);
         $roundtrip = $roundtrip && (float) $read($expected) === $sample;

         foreach ($inis as [$ini, $value]) {
            $previous = (string) ini_set($ini, $value);
            $stable = $stable && $bind($sample) === $expected;
            ini_set($ini, $previous);
         }
      }

      yield assert(
         assertion: $roundtrip,
         description: 'Text-format floats render with digits that read back to the same double'
      );

      yield assert(
         assertion: $stable,
         description: 'The rendering never changes with the precision or serialize_precision inis'
      );

      yield assert(
         assertion: $read($bind(INF)) === 'Infinity'
            && $read($bind(-INF)) === '-Infinity'
            && $read($bind(NAN)) === 'NaN',
         description: 'Infinities and NaN use the spellings PostgreSQL parses and emits'
      );
   }
);
