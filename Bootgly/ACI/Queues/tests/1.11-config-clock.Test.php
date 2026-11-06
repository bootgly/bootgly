<?php

use Bootgly\ABI\Clock as SystemClock;
use Bootgly\ACI\Queues;
use Bootgly\ACI\Queues\Config;
use Bootgly\ACI\Queues\Job;
use Bootgly\ACI\Queues\tests\Recorder;
use Bootgly\ACI\Tests\Doubles\Fake\Clock;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;

require_once __DIR__ . '/Recorder.php';


return new Test(
   description: 'Queues(Config): the `clock` option takes a Clock; a legacy closure reads the same',
   test: function () {
      // # Config: what each `clock` option becomes
      $Fake = new Clock(1_000);
      $Closure = static fn (): float => $Fake->now;

      $Config = new Config(['clock' => $Fake]);
      yield assert(
         assertion: $Config->Clock === $Fake && $Config->clock === null,
         description: 'a Clock instance is the Config Clock'
      );
      $Config = new Config(['clock' => $Closure]);
      yield assert(
         assertion: ($Config->Clock instanceof SystemClock && $Config->Clock::class !== SystemClock::class) && $Config->clock === $Closure,
         description: 'a closure (deprecated) is wrapped into an adapted Clock and kept in $clock'
      );
      $Config = new Config();
      yield assert(
         assertion: $Config->Clock::class === SystemClock::class && $Config->clock === null,
         description: 'no clock option reads the system Clock'
      );

      // # Assignments after construction keep working
      $Config->Clock = $Fake;
      yield assert(
         assertion: $Config->Clock === $Fake,
         description: '$Clock can be assigned a Clock'
      );
      $Config->clock = $Closure;
      yield assert(
         assertion: ($Config->Clock instanceof SystemClock && $Config->Clock::class !== SystemClock::class) && $Config->Clock->time === 1_000,
         description: 'assigning a closure to $clock (deprecated) replaces $Clock with its adapted Clock'
      );
      yield assert(
         assertion: $Config->clock === $Closure,
         description: '$clock reads the closure assigned to it'
      );
      $Config->Clock = $Fake;
      yield assert(
         assertion: $Config->Clock === $Fake && $Config->clock === null,
         description: 'assigning a Clock to $Clock clears the legacy $clock'
      );
      $Config->clock = $Closure;
      $Config->clock = null;
      yield assert(
         assertion: $Config->Clock::class === SystemClock::class,
         description: 'assigning null to $clock restores the system Clock, as null always meant'
      );

      // # File driver: one scenario, driven by a Clock and by a closure
      // ! Removes a reserved temporary tree (the queue files under it)
      $purge = static function (string $path) use (&$purge): void {
         if (is_dir($path) === true && is_link($path) === false) {
            foreach (scandir($path) ?: [] as $child) {
               if ($child !== '.' && $child !== '..') {
                  $purge("{$path}/{$child}");
               }
            }
            @rmdir($path);

            return;
         }
         @unlink($path);
      };
      $run = static function (bool $instance) use ($purge): array {
         // ! Fractional start: whole seconds are read truncated, `(int) $now`
         $Clock = new Clock(1_000_000_000.9);
         $directory = Temporaries::reserve('queue-clock');
         try {
            $Queue = new Queues([
               'path' => $directory,
               'clock' => $instance ? $Clock : static fn (): float => $Clock->now,
            ])->fetch('default');

            // @
            $trace = [];
            $Job = new Job(Recorder::class);
            $Queue->enqueue($Job);
            $trace[] = $Job->available;

            $Reserved = $Queue->reserve();
            $trace[] = $Reserved instanceof Job;
            if ($Reserved instanceof Job) {
               $Queue->release($Reserved, 50);
            }
            $Clock->advance(49);
            $trace[] = $Queue->reserve();
            $Clock->advance(1);
            $Again = $Queue->reserve();
            $trace[] = $Again instanceof Job ? $Again->available : null;
         }
         finally {
            // ! Release
            $purge($directory);
         }

         return $trace;
      };

      $Instance = $run(true);
      $Legacy = $run(false);
      yield assert(
         assertion: $Instance === [1_000_000_000, true, null, 1_000_000_050],
         description: 'a Clock instance stamps and schedules jobs in whole seconds'
      );
      yield assert(
         assertion: $Legacy === $Instance,
         description: 'a legacy closure gives identical results'
      );

      // # Redis driver: availability is stamped on the Clock before the server
      //   is asked, so the seam is visible without a server — the port below
      //   was just released, and the connection is refused
      $Listener = stream_socket_server('tcp://127.0.0.1:0');
      $address = $Listener !== false ? (string) stream_socket_get_name($Listener, false) : '127.0.0.1:1';
      if ($Listener !== false) {
         fclose($Listener);
      }
      $port = (int) substr($address, (int) strrpos($address, ':') + 1);
      $Clock = new Clock(1_500_000_000.9);
      $Queue = new Queues([
         'driver' => 'redis',
         'host' => '127.0.0.1',
         'port' => $port,
         'timeout' => 0.5,
         'clock' => $Clock,
      ])->fetch('default');
      $Job = new Job(Recorder::class);
      $refused = false;
      try {
         $Queue->enqueue($Job);
      }
      catch (Throwable) {
         $refused = true;
      }
      yield assert(
         assertion: $refused === true && $Job->available === 1_500_000_000,
         description: 'the Redis driver stamps a job\'s availability on the Config Clock, in whole seconds'
      );
   }
);
