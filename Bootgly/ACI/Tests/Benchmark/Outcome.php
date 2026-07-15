<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Benchmark;


use function array_keys;
use function implode;
use function is_array;
use RuntimeException;


/**
 * Validate whether one benchmark round produced attributable measurements.
 */
final class Outcome
{
   /**
    * Return a user-facing failure reason, or null for a reportable round.
    *
    * Empty maps remain valid for optional unavailable opponents only when at
    * least one selected opponent produced a real measurement.
    *
    * @param array<string,array<string,Result>> $Results
    * @param array<string> $selectedOpponents
    */
   public static function check (array $Results, array $selectedOpponents): null|string
   {
      $returnedOpponents = [];
      foreach (array_keys($Results) as $opponent) {
         $returnedOpponents[Configs::slug((string) $opponent)] = true;
      }

      $missingOpponents = [];
      foreach ($selectedOpponents as $opponent) {
         if (!isset($returnedOpponents[Configs::slug($opponent)])) {
            $missingOpponents[] = $opponent;
         }
      }
      if ($missingOpponents !== []) {
         return 'Benchmark runner returned no terminal result for: '
            . implode(', ', $missingOpponents) . '.';
      }

      $reportable = false;
      foreach ($Results as $opponent => $LoadResults) {
         if (!is_array($LoadResults)) {
            throw new RuntimeException("Benchmark runner returned an invalid result map for: {$opponent}");
         }
         foreach ($LoadResults as $Result) {
            if (!($Result instanceof Result)) {
               throw new RuntimeException("Benchmark runner returned an invalid result for: {$opponent}");
            }
            $reportable = $reportable
               || $Result->rps !== null
               || $Result->time !== null
               || $Result->memory !== null;
         }
      }

      return $reportable
         ? null
         : 'Benchmark round produced no reportable measurement; result was not saved.';
   }
}
