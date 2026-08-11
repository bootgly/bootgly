<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL\Encoder;


return new Test(
   description: 'Database: PostgreSQL encoder renders text floats round-trip exact',
   test: function () {
      $Encoder = new Encoder;
      $expected = function (string $rendered): string {
         $formatCount = pack('n', 0);
         $parameterCount = pack('n', 1);
         $length = pack('N', strlen($rendered));
         $resultFormatCount = pack('n', 0);
         $body = "\0s1\0{$formatCount}{$parameterCount}{$length}{$rendered}{$resultFormatCount}";

         return 'B' . pack('N', strlen($body) + 4) . $body;
      };
      $encode = fn (float $value): string => $Encoder->encode(Encoder::BIND, [
         'portal' => '',
         'statement' => 's1',
         'parameters' => [$value],
      ]);

      // ! The `precision` ini governs (string) float casts — pin the default
      //   so the divergence this spec guards against is deterministic.
      $previous = (string) ini_set('precision', '14');

      $samples = [0.1 + 0.2, 1 / 3, M_PI, 1.2345678901234567, PHP_FLOAT_EPSILON, PHP_FLOAT_MAX, PHP_FLOAT_MIN, -0.0];
      $exact = true;
      $roundtrip = true;

      foreach ($samples as $sample) {
         $rendered = var_export($sample, true);
         $exact = $exact && $encode($sample) === $expected($rendered);
         $roundtrip = $roundtrip && (float) $rendered === $sample;
      }

      $specials = $encode(INF) === $expected('INF')
         && $encode(-INF) === $expected('-INF')
         && $encode(NAN) === $expected('NAN');

      ini_set('precision', $previous);

      yield assert(
         assertion: $exact && $roundtrip,
         description: 'Text-format floats render with shortest round-trip digits, immune to the precision ini'
      );

      yield assert(
         assertion: $specials,
         description: 'INF, -INF and NAN keep their PostgreSQL-parsable text spellings'
      );
   }
);
