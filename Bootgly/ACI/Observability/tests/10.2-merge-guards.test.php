<?php

use Bootgly\ACI\Observability\Data\Snapshot;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Snapshot::merge guards type conflicts and histogram bucket-schema mismatches',
   test: function () {
      // # Same name, different type → keep existing (do not blend a gauge into a counter)
      $A = new Snapshot([
         'x' => ['type' => 'counter', 'help' => '', 'series' => [['labels' => [], 'value' => 5.0]]],
      ]);
      $B = new Snapshot([
         'x' => ['type' => 'gauge', 'help' => '', 'series' => [['labels' => [], 'value' => 9.0]]],
      ]);
      $A->merge($B);
      yield assert(
         assertion: $A->metrics['x']['type'] === 'counter'
            && $A->metrics['x']['series'][0]['value'] === 5.0,
         description: 'type mismatch keeps the existing metric, drops the incompatible one'
      );

      // # Histogram with mismatched bucket schemas → not blended (keep existing distribution)
      $H1 = new Snapshot(['h' => ['type' => 'histogram', 'help' => '', 'series' => [
         ['labels' => [], 'buckets' => ['0.5' => 1, '+Inf' => 2], 'sum' => 0.4, 'count' => 2],
      ]]]);
      $H2 = new Snapshot(['h' => ['type' => 'histogram', 'help' => '', 'series' => [
         ['labels' => [], 'buckets' => ['0.1' => 1, '+Inf' => 1], 'sum' => 0.05, 'count' => 1],
      ]]]);
      $H1->merge($H2);
      $series = $H1->metrics['h']['series'][0];
      yield assert(
         assertion: $series['buckets'] === ['0.5' => 1, '+Inf' => 2] && $series['count'] === 2,
         description: 'mismatched histogram bucket schemas are not blended'
      );

      // # Matching schemas DO still combine bucket-wise (sanity)
      $M1 = new Snapshot(['h' => ['type' => 'histogram', 'help' => '', 'series' => [
         ['labels' => [], 'buckets' => ['0.5' => 1, '+Inf' => 2], 'sum' => 0.4, 'count' => 2],
      ]]]);
      $M2 = new Snapshot(['h' => ['type' => 'histogram', 'help' => '', 'series' => [
         ['labels' => [], 'buckets' => ['0.5' => 2, '+Inf' => 3], 'sum' => 0.9, 'count' => 3],
      ]]]);
      $M1->merge($M2);
      yield assert(
         assertion: $M1->metrics['h']['series'][0]['buckets'] === ['0.5' => 3, '+Inf' => 5],
         description: 'matching bucket schemas still combine'
      );
   }
);
