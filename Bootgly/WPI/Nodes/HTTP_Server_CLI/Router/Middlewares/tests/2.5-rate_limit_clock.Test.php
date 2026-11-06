<?php


use Bootgly\ABI\Clock as SystemClock;
use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Doubles\Fake\Clock;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\RateLimit;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\RateLimit\Algorithms;


return new Test(
   description: 'RateLimit: the `clock` argument takes a Clock; a legacy closure counts the same',
   test: function () {
      // !
      $createMocks = require __DIR__ . '/0.mock.php';
      $passthrough = static fn (object $Request, object $Response): object => $Response;
      $read = static function (RateLimit $RateLimit): Cache {
         $Property = new ReflectionProperty(RateLimit::class, 'Cache');

         $Cache = $Property->getValue($RateLimit);

         return $Cache instanceof Cache
            ? $Cache
            : throw new RuntimeException('RateLimit holds no Cache.');
      };

      // # What each `clock` argument becomes
      $Fake = new Clock(100);
      $RateLimit = new RateLimit(clock: $Fake, scope: 'clock-instance');
      yield assert(
         assertion: $RateLimit->Clock === $Fake && $RateLimit->clock === null,
         description: 'a Clock instance is the RateLimit Clock'
      );
      yield assert(
         assertion: $read($RateLimit)->Config->Clock === $Fake,
         description: 'the default counter Cache reads the same Clock'
      );

      $Closure = static fn (): float => $Fake->now;
      $RateLimit = new RateLimit(clock: $Closure, scope: 'clock-closure');
      yield assert(
         assertion: ($RateLimit->Clock instanceof SystemClock && $RateLimit->Clock::class !== SystemClock::class)
            && $RateLimit->clock === $Closure
            && $read($RateLimit)->Config->Clock === $RateLimit->Clock,
         description: 'a closure (deprecated) is wrapped into one adapted Clock, shared with the default Cache'
      );

      $RateLimit = new RateLimit(scope: 'clock-system');
      yield assert(
         assertion: $RateLimit->Clock::class === SystemClock::class
            && $RateLimit->clock === null
            && $read($RateLimit)->Config->Clock === $RateLimit->Clock,
         description: 'no clock reads the system Clock'
      );

      // # One scenario, driven by a Clock and by a closure
      // ! Removes a reserved temporary tree (the counter files under it)
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
      $run = static function (bool $instance) use ($createMocks, $passthrough, $purge): array {
         // ! Fractional start: whole seconds are read truncated, `(int) $now`
         $Clock = new Clock(100.9);
         $directory = Temporaries::reserve('ratelimit-clock');
         $clock = $instance ? $Clock : static fn (): float => $Clock->now;
         $Cache = new Cache([
            'driver' => 'file',
            'path' => $directory,
            'prefix' => 'ratelimit:',
            'clock' => $clock,
         ]);
         try {
            $Cache->clear();
            $RateLimit = new RateLimit(
               limit: 2,
               window: 60,
               algorithm: Algorithms::Fixed,
               clock: $clock,
               Cache: $Cache,
               scope: 'clock-scenario'
            );

            // @
            $trace = [];
            $request = static function () use ($createMocks, $passthrough, $RateLimit, &$trace): void {
               [$Request, $Response] = $createMocks(requestProps: ['peer' => '198.51.100.7']);
               $Result = $RateLimit->process($Request, $Response, $passthrough);
               $trace[] = [
                  $Result->code,
                  $Result->Header->get('X-RateLimit-Remaining'),
                  $Result->Header->get('X-RateLimit-Reset'),
                  $Result->Header->get('Retry-After'),
               ];
            };
            $request();
            $request();
            $request();
            $Clock->advance(59);
            $request();
            $Clock->advance(1);
            $request();
         }
         finally {
            // ! Release
            $Cache->clear();
            $purge($directory);
         }

         return $trace;
      };

      $Instance = $run(true);
      $Legacy = $run(false);
      yield assert(
         assertion: $Instance[0][0] === 200 && $Instance[0][2] === '160'
            && $Instance[1][0] === 200
            && $Instance[2][0] === 429 && $Instance[2][3] === '60'
            && $Instance[3][0] === 429 && $Instance[3][3] === '1'
            && $Instance[4][0] === 200 && $Instance[4][1] === '1',
         description: 'a Clock instance counts the window in whole seconds'
      );
      yield assert(
         assertion: $Legacy === $Instance,
         description: 'a legacy closure gives identical results'
      );
   }
);
