<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Benchmark\Microbenchmark;


use const PHP_FLOAT_MAX;
use function array_key_first;
use function hrtime;
use function max;
use function min;
use function reset;
use function round;


/**
 * One comparison: the same operation, done N ways, measured against a baseline.
 *
 * This is the unit a microbenchmark is made of. It carries the decision too —
 * a measurement nobody can act on is not worth storing.
 */
class Comparison
{
   // * Config
   public string $name;
   /**
    * Label => the measured code.
    *
    * @var array<string,callable>
    */
   public array $Cases;
   /** Label the ratios are computed against. Empty = the first case. */
   public string $baseline;
   /**
    * Which row to actually use — deliberately not always the fastest one: a row
    * can win the measurement while answering a different question, or win by
    * too little to justify its shape.
    */
   public string $recommendation;
   /** Why — the reasoning this table supports. */
   public string $verdict;
   /** Overrides the runner default when the work per operation is unusually heavy. */
   public null|int $iterations;


   /**
    * @param array<string,callable> $Cases
    */
   public function __construct (
      string $name = '',
      array $Cases = [],
      string $baseline = '',
      string $recommendation = '',
      string $verdict = '',
      null|int $iterations = null
   ) {
      // * Config
      $this->name = $name;
      $this->Cases = $Cases;
      $this->baseline = $baseline;
      $this->recommendation = $recommendation;
      $this->verdict = $verdict;
      $this->iterations = $iterations;
   }

   /**
    * Measure every case and return the section a result file stores.
    *
    * Rounds are INTERLEAVED across cases on purpose: timing one case to
    * completion before the next lets a load spike penalize whichever case
    * happened to be running, which can invert a comparison outright.
    *
    * @param float $floor Calibrated cost of invoking an empty closure.
    * @return array{
    *    section:string, baseline:string, fastest:string, recommendation:string,
    *    stable:bool, worst_spread:float, verdict:string,
    *    rows:array<int,array{
    *       label:string, ns:float, ratio:float, spread:float, floor_bound:bool
    *    }>
    * }
    */
   public function measure (float $floor, int $rounds, int $warmup, int $iterations): array
   {
      $iterations = $this->iterations ?? $iterations;

      // ! Warm every case before any of them is timed
      foreach ($this->Cases as $Case) {
         for ($i = 0; $i < $warmup; $i++) {
            $Case();
         }
      }

      // ! Per-case extremes across rounds
      $best = [];
      $worst = [];
      foreach ($this->Cases as $label => $Case) {
         $best[$label] = PHP_FLOAT_MAX;
         $worst[$label] = 0.0;
      }

      // @@ Interleaved rounds
      for ($round = 0; $round < $rounds; $round++) {
         foreach ($this->Cases as $label => $Case) {
            $started = hrtime(true);

            for ($i = 0; $i < $iterations; $i++) {
               $Case();
            }

            $elapsed = (hrtime(true) - $started) / $iterations;

            $best[$label] = min($best[$label], $elapsed);
            $worst[$label] = max($worst[$label], $elapsed);
         }
      }

      // ---

      // ! Floor-corrected results, plus how noisy each case's rounds were.
      //   A case whose cost is not comfortably above the harness floor cannot
      //   be resolved by it: subtracting leaves noise, and clamping the result
      //   fabricates precision (a near-zero denominator turns a normal run-to-
      //   run wobble into an absurd ratio). Such rows are marked instead.
      $measured = [];
      $spreads = [];
      $bound = [];
      foreach ($best as $label => $elapsed) {
         $corrected = $elapsed - $floor;

         // ? Floor-bound: the harness call costs at least half of what the raw
         //   measurement did, so the corrected value is mostly floor noise —
         //   a small wobble in the floor swings it wildly. Judged against the
         //   RAW measurement, not the corrected one: correcting first hides
         //   exactly the cases where the correction dominates.
         $bound[$label] = $floor > $elapsed * 0.5;
         $measured[$label] = $bound[$label] ? max($corrected, $floor * 0.25) : $corrected;
         $spreads[$label] = $elapsed > 0 ? $worst[$label] / $elapsed : 1.0;
      }

      // ! An explicit baseline beats "whichever happened to be declared first"
      $baselineLabel = $this->baseline !== '' && isSet($measured[$this->baseline])
         ? $this->baseline
         : (string) array_key_first($measured);
      $baseline = $measured[$baselineLabel] ?? reset($measured);

      $rows = [];
      $fastest = null;
      foreach ($measured as $label => $elapsed) {
         $rows[] = [
            'label' => $label,
            'ns' => round($elapsed, 1),
            'ratio' => round($elapsed / $baseline, 4),
            'spread' => round($spreads[$label], 3),
            'floor_bound' => $bound[$label],
         ];

         if ($fastest === null || $elapsed < $measured[$fastest]) {
            $fastest = $label;
         }
      }

      $noisiest = $spreads === [] ? 1.0 : max($spreads);

      // :
      return [
         'section' => $this->name,
         'baseline' => $baselineLabel,
         'fastest' => (string) $fastest,
         'recommendation' => $this->recommendation !== '' ? $this->recommendation : (string) $fastest,
         'stable' => $noisiest <= 1.25,
         'worst_spread' => round($noisiest, 3),
         'verdict' => $this->verdict,
         'rows' => $rows,
      ];
   }
}
