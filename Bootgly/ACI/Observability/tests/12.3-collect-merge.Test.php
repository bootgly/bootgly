<?php

use Bootgly\ACI\Observability;
use Bootgly\ACI\Observability\Collector;
use Bootgly\ACI\Observability\Collectors;
use Bootgly\ACI\Observability\Collectors\Process;
use Bootgly\ACI\Observability\Collectors\Runtime;
use Bootgly\ACI\Observability\Data\Types;
use Bootgly\ACI\Observability\Metrics\Counter;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Same-named metrics of the same type merge their series; collectors label the collecting pid',
   test: function () {
      // ! A collector reporting one metric under a given type and labels
      $source = static function (string $name, Types $Type, array $labels, float $value): Collector {
         return new class ($name, $Type, $labels, $value) extends Collector {
            private string $name;
            private Types $Type;
            /** @var array<string,string> */
            private array $labels;
            private float $value;

            /** @param array<string,string> $labels */
            public function __construct (string $name, Types $Type, array $labels, float $value)
            {
               $this->name = $name;
               $this->Type = $Type;
               $this->labels = $labels;
               $this->value = $value;
            }
            public function collect (): array
            {
               return [$this->name => $this->compose($this->Type, 'h', $this->labels, $this->value)];
            }
         };
      };

      // # Collectors::collect()
      $Collectors = new Collectors;
      $Collectors
         ->push($source('m', Types::Counter, ['transport' => 'tcp'], 1.0))
         ->push($source('m', Types::Counter, ['transport' => 'udp'], 2.0))
         ->push($source('m', Types::Gauge, ['transport' => 'x'], 9.0));
      $metrics = $Collectors->collect();
      yield assert(
         assertion: $metrics['m']['type'] === 'counter'
            && count($metrics['m']['series']) === 2
            && $metrics['m']['series'][0]['value'] === 1.0
            && $metrics['m']['series'][1]['value'] === 2.0,
         description: 'collect() appends same-type series and drops a type conflict (first kept)'
      );

      // # Observability::gather()
      $Observability = new Observability(collectors: false);
      $Observability->Metrics->push(new Counter(name: 'm', labels: ['transport' => 'tcp'], observe: static fn (): int => 5));
      $Observability->Collectors
         ->push($source('m', Types::Counter, ['transport' => 'udp'], 7.0))
         ->push($source('n', Types::Gauge, [], 1.0));
      $Snapshot = $Observability->gather();
      yield assert(
         assertion: count($Snapshot->metrics['m']['series']) === 2
            && $Snapshot->metrics['m']['series'][0]['value'] === 5.0
            && $Snapshot->metrics['m']['series'][1]['value'] === 7.0
            && isSet($Snapshot->metrics['n']),
         description: 'gather() keeps both the instrument and the collector series of one name'
      );
      $Conflict = new Observability(collectors: false);
      $Conflict->Metrics->push(new Counter(name: 'm', observe: static fn (): int => 5));
      $Conflict->Collectors->push($source('m', Types::Gauge, [], 7.0));
      $Snapshot = $Conflict->gather();
      yield assert(
         assertion: $Snapshot->metrics['m']['type'] === 'counter'
            && count($Snapshot->metrics['m']['series']) === 1
            && $Snapshot->metrics['m']['series'][0]['value'] === 5.0,
         description: 'gather() keeps the instrument on a type conflict'
      );

      // # One series reported twice in one process (same name, type and labels)
      $Twice = new Collectors;
      $Twice
         ->push($source('d', Types::Gauge, ['a' => '1', 'b' => '2'], 3.0))
         ->push($source('d', Types::Gauge, ['b' => '2', 'a' => '1'], 4.0))
         ->push($source('d', Types::Gauge, ['a' => '9', 'b' => '2'], 5.0));
      $metrics = $Twice->collect();
      yield assert(
         assertion: count($metrics['d']['series']) === 2
            && $metrics['d']['series'][0]['value'] === 3.0
            && $metrics['d']['series'][1]['value'] === 5.0,
         description: 'collect() keeps the first reading of a label set reported twice, in any label order, and adds the other label set'
      );
      $Shadow = new Observability(collectors: false);
      $Shadow->Metrics->push(new Counter(name: 'm', labels: ['transport' => 'tcp'], observe: static fn (): int => 5));
      $Shadow->Collectors->push($source('m', Types::Counter, ['transport' => 'tcp'], 8.0));
      $Snapshot = $Shadow->gather();
      yield assert(
         assertion: count($Snapshot->metrics['m']['series']) === 1
            && $Snapshot->metrics['m']['series'][0]['value'] === 5.0,
         description: 'gather() keeps the instrument series over a collector series of the same labels, never both'
      );
      $folded = Collectors::fold(
         ['f' => ['type' => 'gauge', 'help' => 'h', 'series' => [['labels' => ['x' => '1'], 'value' => 1.0]]]],
         [
            'f' => ['type' => 'gauge', 'help' => 'h', 'series' => [['labels' => ['x' => '1'], 'value' => 2.0], ['labels' => [], 'value' => 3.0]]],
            'g' => ['type' => 'counter', 'help' => 'h', 'series' => [['labels' => [], 'value' => 4.0]]],
         ]
      );
      yield assert(
         assertion: count($folded['f']['series']) === 2
            && $folded['f']['series'][0]['value'] === 1.0
            && $folded['f']['series'][1]['value'] === 3.0
            && $folded['g']['series'][0]['value'] === 4.0,
         description: 'fold() adds new names and unheld label sets, never a second reading of a held one'
      );

      // # Label identity: order-free on both sides, once per incoming set, byte-exact
      $Ordered = new Observability(collectors: false);
      $Ordered->Metrics->push(new Counter(name: 'o', labels: ['b' => '2', 'a' => '1'], observe: static fn (): int => 5));
      $Ordered->Collectors->push($source('o', Types::Counter, ['a' => '1', 'b' => '2'], 8.0));
      $Snapshot = $Ordered->gather();
      yield assert(
         assertion: count($Snapshot->metrics['o']['series']) === 1
            && $Snapshot->metrics['o']['series'][0]['value'] === 5.0,
         description: 'a held series whose labels came in another order still shadows the same label set'
      );
      $repeated = Collectors::fold(
         ['r' => ['type' => 'gauge', 'help' => 'h', 'series' => [['labels' => ['x' => '1'], 'value' => 1.0]]]],
         ['r' => ['type' => 'gauge', 'help' => 'h', 'series' => [
            ['labels' => ['x' => '2'], 'value' => 2.0],
            ['labels' => ['x' => '2'], 'value' => 3.0],
         ]]]
      );
      yield assert(
         assertion: count($repeated['r']['series']) === 2
            && $repeated['r']['series'][1]['value'] === 2.0,
         description: 'an incoming label set repeated in one metric is added once, its first reading'
      );
      $bytes = Collectors::fold(
         ['u' => ['type' => 'gauge', 'help' => 'h', 'series' => [['labels' => ['x' => "\xFF"], 'value' => 1.0]]]],
         ['u' => ['type' => 'gauge', 'help' => 'h', 'series' => [['labels' => ['x' => "\xFE"], 'value' => 2.0]]]]
      );
      yield assert(
         assertion: count($bytes['u']['series']) === 2,
         description: 'two label sets that differ only in bytes that are not UTF-8 stay two series'
      );

      // # Process / Runtime: the pid is the collecting process's, not the building one's
      $Process = new Process;
      $Runtime = new Runtime;
      // ! Collected once in the parent before the fork
      $before = $Process->collect()['process_memory_bytes']['series'][0]['labels']['pid'];
      yield assert(
         assertion: $before === (string) getmypid()
            && $Runtime->collect()['runtime_included_files']['series'][0]['labels']['pid'] === $before,
         description: 'the parent collects under its own pid'
      );
      $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      yield assert(
         assertion: $pair !== false,
         description: 'socket pair for the forked child'
      );
      [$Parent, $Child] = $pair;
      $pid = pcntl_fork();
      if ($pid === 0) {
         fclose($Parent);
         $labels = [
            getmypid(),
            $Process->collect()['process_memory_bytes']['series'][0]['labels']['pid'],
            $Runtime->collect()['runtime_included_files']['series'][0]['labels']['pid'],
         ];
         fwrite($Child, implode(',', $labels));
         fclose($Child);
         // ! Leave without the runner's shutdown
         posix_kill(getmypid(), SIGKILL);
      }
      fclose($Child);
      $read = stream_get_contents($Parent);
      fclose($Parent);
      pcntl_waitpid($pid, $status);
      [$child, $process, $runtime] = explode(',', (string) $read) + ['', '', ''];
      yield assert(
         assertion: $pid > 0 && $child === (string) $pid
            && $process === (string) $pid && $runtime === (string) $pid,
         description: 'a collector built before a fork labels the child with the child pid'
      );
      yield assert(
         assertion: $Process->collect()['process_memory_bytes']['series'][0]['labels']['pid'] === (string) getmypid(),
         description: 'the parent keeps its own pid'
      );
   }
);
