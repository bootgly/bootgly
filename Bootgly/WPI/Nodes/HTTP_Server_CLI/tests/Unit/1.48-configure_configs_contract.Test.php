<?php

use Bootgly\ABI\Configs as Configuring;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Client_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\WS_Client_CLI;
use Bootgly\WPI\Nodes\WS_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


/**
 * `configure()` takes one Configs per concern, in any order, and every Configs
 * is named-arguments-only: the `$Named` guard slot occupies the first
 * positional position with a type no user value can satisfy, so a positional
 * construction dies before it can bind `host` to `port`.
 *
 * The contract also rejects what it cannot honor: the same Configs twice, a
 * Configs the node does not accept, a first configuration without a transport,
 * and a server Configs claiming both a manual `secure` context and Auto-TLS.
 */
return new Test(
   description: 'configure() enforces the Configs contract and named-only construction',
   test: new Assertions(Case: function (): Generator {
      // ! Statics survive the suite: snapshot every one configure() writes
      $oldDeferred = Response::$deferredTimeout;
      $oldHealth = HTTP_Server_CLI::$health;
      $oldHTTP2 = HTTP_Server_CLI::$enableHTTP2;
      $oldCaps = TCP_Server_CLI::$maxConnections;
      $OldProtocols = TCP_Server_CLI::$Protocols;

      try {
         // @@ A) Positional construction never binds — the guard slot rejects it
         $thrown = null;
         try {
            // @phpstan-ignore-next-line — the point of the assertion
            new HTTP_Server_CLI\Configs('127.0.0.1', 8080, 1);
         }
         catch (TypeError $Thrown) {
            $thrown = $Thrown->getMessage();
         }

         yield assert(
            assertion: $thrown !== null && str_contains($thrown, '$Named'),
            description: 'a positional Configs dies on the guard slot — '
               . var_export($thrown, true)
         );

         // @@ B) A missing mandatory input names the class actually constructed
         $thrown = null;
         try {
            new HTTP_Server_CLI\Configs(host: '127.0.0.1', port: 8080);
         }
         catch (ArgumentCountError $Thrown) {
            $thrown = $Thrown->getMessage();
         }

         yield assert(
            assertion: $thrown !== null
               && str_contains($thrown, 'HTTP_Server_CLI\Configs')
               && str_contains($thrown, 'workers'),
            description: 'the missing-argument error names the concrete Configs — '
               . var_export($thrown, true)
         );

         // @@ C) One TLS source only
         $thrown = null;
         try {
            new HTTP_Server_CLI\Configs(
               host: '127.0.0.1',
               port: 8080,
               workers: 1,
               secure: ['local_cert' => '/dev/null'],
               AutoTLS: new HTTP_Server_CLI\AutoTLS(domains: ['bootgly.test'], email: 'a@bootgly.test')
            );
         }
         catch (InvalidArgumentException $Thrown) {
            $thrown = $Thrown->getMessage();
         }

         yield assert(
            assertion: $thrown !== null && str_contains($thrown, 'never both'),
            description: 'a manual context and Auto-TLS cannot both own the transport — '
               . var_export($thrown, true)
         );

         // @@ D) Order is irrelevant — the Response Configs may come first
         $Server = new HTTP_Server_CLI(Mode: Modes::Test);
         $Server->configure(
            new HTTP_Server_CLI\Response\Configs(deferredTimeout: 3.5),
            new HTTP_Server_CLI\Configs(host: '127.0.0.1', port: 0, workers: 1)
         );

         yield assert(
            assertion: Response::$deferredTimeout === 3.5 && $Server->port === 0,
            description: 'both Configs are applied regardless of order — '
               . var_export(Response::$deferredTimeout, true)
         );

         // @@ E) The same Configs class twice is a configuration mistake
         $thrown = null;
         try {
            $Server->configure(
               new HTTP_Server_CLI\Configs(host: '127.0.0.1', port: 0, workers: 1),
               new HTTP_Server_CLI\Configs(host: '127.0.0.2', port: 1, workers: 2)
            );
         }
         catch (InvalidArgumentException $Thrown) {
            $thrown = $Thrown->getMessage();
         }

         yield assert(
            assertion: $thrown !== null
               && str_contains($thrown, 'received two')
               && $Server->port === 0,
            description: 'a repeated Configs class is rejected before anything is applied — '
               . var_export($thrown, true)
         );

         // @@ F) A Configs the node does not accept is rejected
         $Foreign = new class implements Configuring {};

         $thrown = null;
         try {
            $Server->configure($Foreign);
         }
         catch (InvalidArgumentException $Thrown) {
            $thrown = $Thrown->getMessage();
         }

         yield assert(
            assertion: $thrown !== null && str_contains($thrown, 'does not accept'),
            description: 'an unsupported Configs is rejected — ' . var_export($thrown, true)
         );

         // @@ F2) The PARENT transport Configs would configure only half of an
         //       HTTP server (no scheme, no ALPN, no safe TLS defaults), so it
         //       is rejected too — `instanceof` alone would have accepted it.
         //       The legal Configs travelling with it must not be applied
         //       either: the set is validated as a whole, before anything.
         $thrown = null;
         try {
            $Server->configure(
               new HTTP_Server_CLI\Response\Configs(deferredTimeout: 9.5),
               new TCP_Server_CLI\Configs(host: '127.0.0.2', port: 1, workers: 2)
            );
         }
         catch (InvalidArgumentException $Thrown) {
            $thrown = $Thrown->getMessage();
         }

         yield assert(
            assertion: $thrown !== null
               && str_contains($thrown, 'does not accept')
               && $Server->host === '127.0.0.1'
               && Response::$deferredTimeout === 3.5,
            description: 'the parent transport Configs is rejected and its batch never applied — '
               . var_export([$thrown, Response::$deferredTimeout], true)
         );

         // @@ F3) And the reverse direction: a richer Configs on a base node
         //        carries fields it cannot read — dropping a TLS context or a
         //        connection cap in silence is the same defect, mirrored
         $Plain = new TCP_Server_CLI(Mode: Modes::Test);

         $thrown = null;
         try {
            $Plain->configure(
               new HTTP_Server_CLI\Configs(host: '127.0.0.1', port: 0, workers: 1, maxConnections: 5)
            );
         }
         catch (InvalidArgumentException $Thrown) {
            $thrown = $Thrown->getMessage();
         }

         yield assert(
            assertion: $thrown !== null
               && str_contains($thrown, 'does not accept')
               && TCP_Server_CLI::$maxConnections !== 5,
            description: 'a node refuses a Configs richer than itself — '
               . var_export($thrown, true)
         );

         // @@ F4) Once the transport is configured, a later pre-start call may
         //        refine one concern on its own
         $Server->configure(new HTTP_Server_CLI\Response\Configs(deferredTimeout: 4.5));

         yield assert(
            assertion: Response::$deferredTimeout === 4.5 && $Server->port === 0,
            description: 'a transport-less follow-up is accepted once the socket is set — '
               . var_export(Response::$deferredTimeout, true)
         );

         // @@ F5) A Configs that can only fail against live state (a resource
         //        name a response property already owns) throws AFTER the
         //        transport applied — the socket is real from that moment, so
         //        the follow-up that refines one concern must be accepted
         $Live = new HTTP_Server_CLI(Mode: Modes::Test);

         $thrown = null;
         try {
            $Live->configure(
               new HTTP_Server_CLI\Configs(host: '127.0.0.1', port: 0, workers: 1),
               new HTTP_Server_CLI\Response\Configs(
                  Resources: ['Header' => static fn (object $Response): object => $Response]
               )
            );
         }
         catch (InvalidArgumentException $Thrown) {
            $thrown = $Thrown->getMessage();
         }

         $accepted = true;
         try {
            $Live->configure(new HTTP_Server_CLI\Response\Configs(deferredTimeout: 6.5));
         }
         catch (ArgumentCountError) {
            $accepted = false;
         }

         yield assert(
            assertion: $thrown !== null
               && str_contains($thrown, 'reserved')
               && $accepted === true
               && Response::$deferredTimeout === 6.5,
            description: 'the transport counts as applied even when a later Configs throws — '
               . var_export([$thrown, $accepted, Response::$deferredTimeout], true)
         );

         // @@ G) The first configuration must carry the transport
         $Fresh = new HTTP_Server_CLI(Mode: Modes::Test);

         $deferred = Response::$deferredTimeout;

         $thrown = null;
         try {
            $Fresh->configure(new HTTP_Server_CLI\Response\Configs(deferredTimeout: 1));
         }
         catch (ArgumentCountError $Thrown) {
            $thrown = $Thrown->getMessage();
         }

         yield assert(
            assertion: $thrown !== null
               && str_contains($thrown, 'requires a ' . HTTP_Server_CLI\Configs::class),
            description: 'a first configuration without a transport is rejected — '
               . var_export($thrown, true)
         );

         // @@ H) A rejected configure() applies NOTHING — request limits,
         //       response budgets and caps are process-global statics
         yield assert(
            assertion: Response::$deferredTimeout === $deferred,
            description: 'the rejected call left the global budget untouched — '
               . var_export(Response::$deferredTimeout, true)
         );

         // @@ I) A call that configures nothing is a mistake, not a no-op
         $thrown = null;
         try {
            $Server->configure();
         }
         catch (ArgumentCountError $Thrown) {
            $thrown = $Thrown->getMessage();
         }

         yield assert(
            assertion: $thrown !== null && str_contains($thrown, 'at least one Configs'),
            description: 'an empty configure() is rejected — ' . var_export($thrown, true)
         );

         // @@ J) A Configs whose payload cannot be applied is refused by its
         //       own constructor, so it never reaches a half-applied call
         $thrown = null;
         try {
            new HTTP_Server_CLI\Response\Configs(
               Resources: ['bad' => 'not-a-closure'],
               deferredTimeout: 42
            );
         }
         catch (InvalidArgumentException $Thrown) {
            $thrown = $Thrown->getMessage();
         }

         yield assert(
            assertion: $thrown !== null && str_contains($thrown, 'Closure factory'),
            description: 'an invalid resource factory is rejected at construction — '
               . var_export($thrown, true)
         );

         // @@ K) Every node names its own transport Configs — a node that
         //       inherited its parent's constants would half-configure itself
         $Contracts = [
            TCP_Server_CLI::class => TCP_Server_CLI\Configs::class,
            UDP_Server_CLI::class => UDP_Server_CLI\Configs::class,
            HTTP_Server_CLI::class => HTTP_Server_CLI\Configs::class,
            WS_Server_CLI::class => WS_Server_CLI\Configs::class,
            TCP_Client_CLI::class => TCP_Client_CLI\Configs::class,
            UDP_Client_CLI::class => UDP_Client_CLI\Configs::class,
            HTTP_Client_CLI::class => HTTP_Client_CLI\Configs::class,
            WS_Client_CLI::class => WS_Client_CLI\Configs::class,
         ];
         $drifted = [];
         foreach ($Contracts as $node => $Transport) {
            $Reflection = new ReflectionClass($node);

            if (
               $Reflection->getConstant('TRANSPORT') !== $Transport
               || in_array($Transport, $Reflection->getConstant('CONFIGS'), true) === false
            ) {
               $drifted[] = $node;
            }
         }

         yield assert(
            assertion: $drifted === [],
            description: 'every node declares its own TRANSPORT, listed in CONFIGS — drifted: '
               . var_export($drifted, true)
         );
      }
      finally {
         Response::$deferredTimeout = $oldDeferred;
         HTTP_Server_CLI::$health = $oldHealth;
         HTTP_Server_CLI::$enableHTTP2 = $oldHTTP2;
         TCP_Server_CLI::$maxConnections = $oldCaps;
         TCP_Server_CLI::$Protocols = $OldProtocols;
      }
   })
);
