<?php

use Generator;

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Chart;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should render an SVG line chart',
   test: new Assertions(Case: function (): Generator
   {
      $Chart = new Chart(
         title: 'Throughput',
         xLabel: 'server-workers',
         yLabel: 'req/s',
      );

      $x = [1, 2, 4, 8];
      $svg = $Chart->render($x, [
         'Plaintext' => [
            'Bootgly' => [100000.0, 200000.0, 400000.0, 800000.0],
            'Swoole' => [80000.0, null, 300000.0, 600000.0],
         ],
         'JSON' => [
            'Bootgly' => [90000.0, 180000.0, 350000.0, 700000.0],
            'Swoole' => [70000.0, 140000.0, 280000.0, 550000.0],
         ],
      ]);

      yield new Assertion(
         description: 'Document is a complete SVG',
         fallback: 'SVG envelope missing!'
      )
         ->expect(
            str_starts_with($svg, '<svg xmlns="http://www.w3.org/2000/svg"')
               && str_ends_with(rtrim($svg), '</svg>'),
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'Both panels and the legend are rendered',
         fallback: 'Panels/legend missing!'
      )
         ->expect(
            str_contains($svg, '>Plaintext</text>')
               && str_contains($svg, '>JSON</text>')
               && str_contains($svg, '>Bootgly</text>')
               && str_contains($svg, '>Swoole</text>'),
            Op::Identical,
            true
         )
         ->assert();

      yield new Assertion(
         description: 'A null value breaks the series into segments',
         fallback: 'Null gap not segmented!'
      )
         // Swoole/Plaintext has a gap at x=2: an isolated first point (circle)
         // plus a 2-point polyline — so the SVG carries at least one circle.
         ->expect(str_contains($svg, '<circle'), Op::Identical, true)
         ->assert();

      yield new Assertion(
         description: 'Series are drawn as polylines',
         fallback: 'Polylines missing!'
      )
         ->expect(substr_count($svg, '<polyline') >= 3, Op::Identical, true)
         ->assert();

      // # Log scale
      $Log = new Chart(
         title: 'Latency',
         xLabel: 'server-workers',
         yLabel: 'ms',
         yscale: 'log',
      );
      $logSvg = $Log->render([1, 2], [
         'Plaintext' => ['Bootgly' => [0.5, 900.0]],
      ]);

      yield new Assertion(
         description: 'Log scale renders without errors and stays SVG',
         fallback: 'Log scale broken!'
      )
         ->expect(str_contains($logSvg, '<polyline'), Op::Identical, true)
         ->assert();
   })
);
