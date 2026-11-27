<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Observability;
use Bootgly\ACI\Observability\Metrics\Counter;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


/**
 * The Demo's `/health` + `/metrics` route set reuses a registry built before it
 * (by `collectors: true` or by the app) and adds its HTTP bridge to it once,
 * without a second HTTP telemetry when the app booted its own.
 */
return new Test(
   description: 'Demo observability routes join an existing registry once, keeping the app telemetry',
   test: function () {
      // ! State the route set touches, restored at the end
      $Instance = Observability::$Instance;
      $stats = Connections::$stats;
      $Tasks = new ReflectionProperty(Timer::class, 'tasks');
      $before = $Tasks->getValue();
      $IDs = static function (array $tasks): array {
         $IDs = [];
         foreach ($tasks as $Runtime) {
            foreach (array_keys($Runtime) as $ID) {
               $IDs[] = $ID;
            }
         }
         return $IDs;
      };

      try {
         // ! A registry built before the route set: the server's transport
         //   series and an app-booted HTTP telemetry (its first counter)
         $Prebuilt = new Observability(collectors: false);
         $Prebuilt->Metrics
            ->push(new Counter(name: 'server_connections_accepted_total', observe: static fn (): int => 0))
            ->push(new Counter(name: 'http_requests_total', observe: static fn (): int => 0));
         Observability::$Instance = $Prebuilt;

         // ! The route set, with a Router that only records the handlers
         $Router = new class extends Router {
            /** @var array<string,callable> */
            public array $Handlers = [];

            public function route (
               string $route,
               callable $handler,
               null|string|array $methods = null,
               array $middlewares = [],
               null|array $cache = null
            ): false
            {
               $this->Handlers[$route] = $handler;
               return false;
            }
         };
         $Routes = require BOOTGLY_ROOT_DIR . 'projects/Demo/HTTP_Server_CLI/router/routes/Observability.routes.php';
         $Request = new ReflectionClass(Request::class)->newInstanceWithoutConstructor();
         $Response = new ReflectionClass(Response::class)->newInstanceWithoutConstructor();
         foreach ($Routes($Request, $Response, $Router) as $_) {
            // @@ Register every route
         }
         $ensure = new ReflectionFunction($Router->Handlers['/health'])->getStaticVariables()['ensure'] ?? null;

         yield assert(
            assertion: $ensure instanceof Closure,
            description: 'the /health handler carries the lazy registry builder'
         );

         // @ Two scrapes
         $First = $ensure();
         $Second = $ensure();
         $count = static function (string $name): int {
            $count = 0;
            foreach (Observability::$Instance->Metrics->Metrics as $Metric) {
               $count += (int) ($Metric->name === $name);
            }
            return $count;
         };
         $census = [
            $count('server_connections_accepted_total'),
            $count('http_socket_reads_total'),
            $count('http_requests_total'),
         ];
         $evidence = json_encode($census);

         yield assert(
            assertion: $First === $Prebuilt && $Second === $Prebuilt && Observability::$Instance === $Prebuilt,
            description: 'the route set reuses the registry built before it'
         );
         yield assert(
            assertion: $census === [1, 1, 1],
            description: "the HTTP bridge joins it once and no second telemetry is booted — got {$evidence}"
         );
         yield assert(
            assertion: count($IDs($Tasks->getValue())) === count($IDs($before)) + 1,
            description: 'one periodic dump timer is armed, once'
         );
      }
      finally {
         // @ Drop the dump timer the route set armed; with no other task, its alarm too
         foreach (array_diff($IDs($Tasks->getValue()), $IDs($before)) as $ID) {
            Timer::del($ID);
         }
         if ($before === []) {
            pcntl_alarm(0);
         }
         Connections::$stats = $stats;
         Observability::$Instance = $Instance;
      }
   }
);
