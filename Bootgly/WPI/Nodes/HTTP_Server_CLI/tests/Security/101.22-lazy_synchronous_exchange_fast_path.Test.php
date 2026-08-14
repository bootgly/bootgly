<?php

use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Exchange;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Telemetry\Admissions;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * L4 lazy synchronous lifecycle regression.
 *
 * A plain synchronous request on a fresh emitter has no lifecycle consumer
 * and must not allocate either Exchange registry. A separate observed request
 * proves the same production Decoder_ -> Encoder_ fixture still opens, binds
 * and terminalizes one Exchange when the lifecycle is actually requested.
 */
$Probe = new class {
   public string $error = '';
   /** @var array<string,mixed> */
   public array $fast = [];
   /** @var array<string,mixed> */
   public array $observed = [];
};

return new Test(
   description: 'A synchronous request should allocate an Exchange only when observed',
   Separator: new Separator(line: true),

   request: static function () use ($Probe): string {
      /** @var Closure $Fixture */
      $Fixture = require __DIR__ . '/Support/Telemetry_Encoder.Fixture.php';

      $ExchangeReflection = new ReflectionClass(Exchange::class);
      $AdmissionsReflection = new ReflectionClass(Admissions::class);
      $Properties = [
         'Owners' => $ExchangeReflection->getProperty('Owners'),
         'Snapshots' => $ExchangeReflection->getProperty('Snapshots'),
         'Context' => $ExchangeReflection->getProperty('Context'),
         'Package' => $ExchangeReflection->getProperty('Package'),
         'Request' => $ExchangeReflection->getProperty('Request'),
         'Exchange' => $ExchangeReflection->getProperty('Exchange'),
         'Emitters' => $AdmissionsReflection->getProperty('Emitters'),
      ];
      $RegistryState = [];
      foreach ($Properties as $name => $Property) {
         $RegistryState[$name] = $Property->getValue();
         $Property->setValue(null, null);
      }

      try {
         $Fixture(static function (
            Closure $Encode,
            Closure $Run,
            Closure $Configure,
         ) use ($Probe, $Properties): void {
            $FastEmitter = new Emitter;
            $Configure($FastEmitter);

            $fastHandlers = 0;
            SAPI::$Handler = static function (
               Request $Request,
               Response $Response,
               Router $Router,
            ) use (&$fastHandlers): Generator {
               yield $Router->route('/l4/lazy-sync/fast', static function (
                  Request $Request,
                  Response $Response,
               ) use (&$fastHandlers): Response {
                  $fastHandlers++;

                  return $Response(
                     code: 200,
                     body: 'L4-LAZY-SYNC-FAST',
                  );
               }, GET);
            };

            $fastOwnersBefore = $Properties['Owners']->getValue() === null;
            $fastSnapshotsBefore = $Properties['Snapshots']->getValue() === null;
            $fastContextBefore = $Properties['Context']->getValue() === null
               && $Properties['Package']->getValue() === null
               && $Properties['Request']->getValue() === null
               && $Properties['Exchange']->getValue() === null;
            $fast = $Run(static fn (): string => $Encode(
               "GET /l4/lazy-sync/fast HTTP/1.1\r\nHost: localhost\r\n\r\n"
            ));
            $Probe->fast = [
               ...$fast,
               'handlers' => $fastHandlers,
               'owners_before' => $fastOwnersBefore,
               'snapshots_before' => $fastSnapshotsBefore,
               'context_before' => $fastContextBefore,
               'owners_after' => $Properties['Owners']->getValue() === null,
               'snapshots_after' => $Properties['Snapshots']->getValue() === null,
               'context_after' => $Properties['Context']->getValue() === null
                  && $Properties['Package']->getValue() === null
                  && $Properties['Request']->getValue() === null
                  && $Properties['Exchange']->getValue() === null,
            ];

            $ObservedEmitter = new Emitter;
            $ObservedExchange = null;
            $admissions = 0;
            $terminals = [];
            Admissions::listen(
               $ObservedEmitter,
               static function (Exchange $Exchange) use (
                  &$ObservedExchange,
                  &$admissions,
                  &$terminals,
               ): void {
                  $ObservedExchange = $Exchange;
                  $admissions++;
                  $Exchange->observe(
                     static function (
                        Exchange $Terminal,
                        null|int $code,
                     ) use (&$terminals, $Exchange): void {
                        $terminals[] = [
                           'exchange' => $Terminal === $Exchange,
                           'code' => $code,
                        ];
                     },
                  );
               },
            );
            $Configure($ObservedEmitter);

            $observedHandlers = 0;
            $handlerExchange = false;
            SAPI::$Handler = static function (
               Request $Request,
               Response $Response,
               Router $Router,
            ) use (
               &$ObservedExchange,
               &$handlerExchange,
               &$observedHandlers,
            ): Generator {
               yield $Router->route('/l4/lazy-sync/observed', static function (
                  Request $Request,
                  Response $Response,
               ) use (
                  &$ObservedExchange,
                  &$handlerExchange,
                  &$observedHandlers,
               ): Response {
                  $observedHandlers++;
                  $HandlerExchange = Exchange::fetch($Request);
                  $handlerExchange = $HandlerExchange instanceof Exchange
                     && $HandlerExchange === $ObservedExchange
                     && $HandlerExchange->check() === false;

                  return $Response(
                     code: 201,
                     body: 'L4-LAZY-SYNC-OBSERVED',
                  );
               }, GET);
            };

            $observed = $Run(static fn (): string => $Encode(
               "GET /l4/lazy-sync/observed HTTP/1.1\r\nHost: localhost\r\n\r\n"
            ));
            $Probe->observed = [
               ...$observed,
               'handlers' => $observedHandlers,
               'admissions' => $admissions,
               'handler_exchange' => $handlerExchange,
               'terminal' => $ObservedExchange?->check() ?? false,
               'owner_cleared' => Exchange::fetch(Server::$Request) === null,
               'terminals' => $terminals,
            ];
         });
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         foreach ($Properties as $name => $Property) {
            $Property->setValue(null, $RegistryState[$name]);
         }
      }

      return "GET /l4/lazy-sync/harness HTTP/1.1\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ): Generator {
      yield $Router->route('/l4/lazy-sync/harness', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(body: 'L4-LAZY-SYNC-HARNESS-OK');
      }, GET);
   },

   test: static function (string $response) use ($Probe): bool|string {
      if ($Probe->error !== '') {
         return 'L4 lazy synchronous fixture failed: ' . $Probe->error;
      }
      if (
         substr_count($response, 'HTTP/1.1 ') !== 1
         || str_contains($response, 'HTTP/1.1 200 OK') === false
         || str_contains($response, 'L4-LAZY-SYNC-HARNESS-OK') === false
      ) {
         return 'L4 lazy synchronous native harness control failed.';
      }

      $fast = $Probe->fast;
      if (
         ($fast['throwable_class'] ?? null) !== null
         || is_string($fast['wire'] ?? null) === false
         || substr_count($fast['wire'], 'HTTP/1.1 ') !== 1
         || str_contains($fast['wire'], 'HTTP/1.1 200 OK') === false
         || str_contains($fast['wire'], 'L4-LAZY-SYNC-FAST') === false
         || ($fast['handlers'] ?? null) !== 1
         || ($fast['owners_before'] ?? null) !== true
         || ($fast['snapshots_before'] ?? null) !== true
         || ($fast['context_before'] ?? null) !== true
         || ($fast['owners_after'] ?? null) !== true
         || ($fast['snapshots_after'] ?? null) !== true
         || ($fast['context_after'] ?? null) !== true
      ) {
         return 'L4 performance regression: a plain synchronous request '
            . 'materialized Exchange ownership or emitted an invalid wire. Evidence: '
            . json_encode($fast);
      }

      $observed = $Probe->observed;
      if (
         ($observed['throwable_class'] ?? null) !== null
         || is_string($observed['wire'] ?? null) === false
         || substr_count($observed['wire'], 'HTTP/1.1 ') !== 1
         || str_contains($observed['wire'], 'HTTP/1.1 201 Created') === false
         || str_contains($observed['wire'], 'L4-LAZY-SYNC-OBSERVED') === false
         || ($observed['handlers'] ?? null) !== 1
         || ($observed['admissions'] ?? null) !== 1
         || ($observed['handler_exchange'] ?? null) !== true
         || ($observed['terminal'] ?? null) !== true
         || ($observed['owner_cleared'] ?? null) !== true
         || ($observed['terminals'] ?? null) !== [[
            'exchange' => true,
            'code' => 201,
         ]]
      ) {
         return 'L4 security regression: pay-for-use skipped or corrupted the '
            . 'observed synchronous lifecycle. Evidence: ' . json_encode($observed);
      }

      return true;
   },
);
