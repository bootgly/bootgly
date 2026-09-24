<?php


use const Bootgly\WPI;
use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\WS_Server_CLI;
use Bootgly\WPI\Nodes\WS_Server_CLI\Decoders;
use Bootgly\WPI\Nodes\WS_Server_CLI\Handshake;
use Bootgly\WPI\Nodes\WS_Server_CLI\Session;


/**
 * Client sockets and the worker's own dependency waits (deferred DB/KV
 * responses, the embedded HTTP client) share one selector: Select::CAPACITY
 * entries per table. The accept gate keeps `TCP_Server_CLI::$headroom` of them
 * free by shedding clients earlier, so an idle-connection flood can no longer
 * leave every dependency wait refused.
 *
 * The reserve is clamped — a negative one means none, an oversized one still
 * lets the worker admit clients — the global connection ceiling still applies
 * when it is lower, and both server Configs carry the knob into the static.
 *
 * `Connections::check()` is the exact predicate `connect()` consults once per
 * accept: driven with a synthetic census, no socket, no race.
 */
return new Test(
   description: 'The accept gate should keep the selector headroom free for dependency waits — clamped, under a lower global ceiling, configurable through the server Configs',
   test: new Assertions(Case: function (): Generator {
      // ! Statics survive the suite: snapshot every one this case writes —
      //   directly, or through a server constructor and configure()
      $Statics = [];
      $Classes = [
         TCP_Server_CLI::class,
         HTTP_Server_CLI::class,
         WS_Server_CLI::class,
         Connections::class,
         Decoders::class,
         Handshake::class,
         Session::class,
         Vars::class,
      ];
      foreach ($Classes as $class) {
         foreach (new ReflectionClass($class)->getProperties(ReflectionProperty::IS_STATIC) as $Property) {
            if ($Property->getDeclaringClass()->name === $class && $Property->isInitialized()) {
               $Statics[] = [$Property, $Property->getValue()];
            }
         }
      }
      $OldServer = isset(WPI->Server) ? WPI->Server : null;

      $capacity = Select::CAPACITY;
      $peer = '192.0.2.1';
      // ! The gate for a census of `$count` live connections
      $gate = static function (int $count) use ($peer): bool {
         Connections::$Connections = $count > 0 ? array_fill(0, $count, true) : [];

         return Connections::check($peer);
      };
      // ! Where the gate starts refusing: the smallest census it sheds at
      $edge = static function () use ($gate, $capacity): null|int {
         for ($count = 0; $count <= $capacity; $count++) {
            if ($gate($count) === false) {
               return $count;
            }
         }

         return null;
      };

      try {
         // ! Only the headroom may refuse: no global or per-IP ceiling
         TCP_Server_CLI::$maxConnections = 0;
         TCP_Server_CLI::$maxConnectionsPerIP = 0;
         Connections::$ipConnections = [];

         // @@ A) The shipped reserve is real
         $default = new ReflectionProperty(TCP_Server_CLI::class, 'headroom')->getDefaultValue();

         yield assert(
            assertion: is_int($default) && $default > 0 && $default < $capacity,
            description: 'a worker ships a positive selector reserve below Select::CAPACITY — observed: '
               . json_encode(['headroom' => $default, 'capacity' => $capacity])
         );

         // @@ B) The reserve is exact: refuse at CAPACITY - headroom, admit
         //    one below
         TCP_Server_CLI::$headroom = 32;
         $observed = [
            'refused' => $gate($capacity - 32),
            'admitted' => $gate($capacity - 33),
            'edge' => $edge(),
         ];

         yield assert(
            assertion: $observed === ['refused' => false, 'admitted' => true, 'edge' => $capacity - 32],
            description: 'headroom 32 sheds at exactly Select::CAPACITY - 32 live connections and admits one below — observed: '
               . json_encode($observed)
         );

         // @@ C) No reserve: only the selector capacity itself refuses
         TCP_Server_CLI::$headroom = 0;
         $observed = [
            'refused' => $gate($capacity),
            'admitted' => $gate($capacity - 1),
            'edge' => $edge(),
         ];

         yield assert(
            assertion: $observed === ['refused' => false, 'admitted' => true, 'edge' => $capacity],
            description: 'headroom 0 sheds only at Select::CAPACITY — observed: ' . json_encode($observed)
         );

         // @@ D) A negative reserve is no reserve — never a larger census
         $observed = [];
         foreach ([-1, -500, PHP_INT_MIN] as $headroom) {
            TCP_Server_CLI::$headroom = $headroom;
            $observed[] = [
               'refused' => $gate($capacity),
               'admitted' => $gate($capacity - 1),
            ];
         }

         yield assert(
            assertion: $observed === array_fill(0, 3, ['refused' => false, 'admitted' => true]),
            description: 'a negative headroom is treated as 0: shed at Select::CAPACITY, never past it — observed: '
               . json_encode($observed)
         );

         // @@ E) An oversized reserve is clamped: the worker still admits
         //    clients (sheds at 2 live connections)
         $observed = [];
         foreach ([$capacity - 2, $capacity, 5000, PHP_INT_MAX] as $headroom) {
            TCP_Server_CLI::$headroom = $headroom;
            $observed[$headroom] = [
               'empty' => $gate(0),
               'one' => $gate(1),
               'two' => $gate(2),
            ];
         }

         yield assert(
            assertion: $observed === array_fill_keys(
               [$capacity - 2, $capacity, 5000, PHP_INT_MAX],
               ['empty' => true, 'one' => true, 'two' => false]
            ),
            description: 'a headroom at or past Select::CAPACITY - 2 is clamped so a worker still admits a client — observed: '
               . json_encode($observed)
         );

         // @@ F) The global ceiling still applies when it is the lower one —
         //    and the headroom still applies under a higher one
         TCP_Server_CLI::$headroom = 32;
         TCP_Server_CLI::$maxConnections = 10;
         $lower = ['refused' => $gate(10), 'admitted' => $gate(9)];
         TCP_Server_CLI::$maxConnections = 10000;
         $higher = ['refused' => $gate($capacity - 32), 'admitted' => $gate($capacity - 33)];

         yield assert(
            assertion: $lower === ['refused' => false, 'admitted' => true]
               && $higher === ['refused' => false, 'admitted' => true],
            description: 'a lower maxConnections sheds first; under a higher one the headroom does — observed: '
               . json_encode(['maxConnections 10' => $lower, 'maxConnections 10000' => $higher])
         );
         TCP_Server_CLI::$maxConnections = 0;

         // @@ G) HTTP_Server_CLI\Configs carries the knob through configure()
         //    into the static the gate reads; omitted, it leaves it alone
         $Omitted = new HTTP_Server_CLI\Configs(host: '127.0.0.1', port: 0, workers: 1);
         $Given = new HTTP_Server_CLI\Configs(host: '127.0.0.1', port: 0, workers: 1, headroom: 7);

         TCP_Server_CLI::$headroom = 32;
         $Server = new HTTP_Server_CLI(Mode: Modes::Test);
         $Server->configure($Given);
         $configured = TCP_Server_CLI::$headroom;
         $gated = ['refused' => $gate($capacity - 7), 'admitted' => $gate($capacity - 8)];
         $Server->configure($Omitted);
         $kept = TCP_Server_CLI::$headroom;

         yield assert(
            assertion: $Omitted->headroom === null
               && $Given->headroom === 7
               && $configured === 7
               && $gated === ['refused' => false, 'admitted' => true]
               && $kept === 7,
            description: 'HTTP_Server_CLI->configure() applies Configs(headroom: 7) to the gate; a Configs without it keeps the current reserve — observed: '
               . json_encode(['omitted' => $Omitted->headroom, 'given' => $Given->headroom, 'configured' => $configured, 'gated' => $gated, 'kept' => $kept])
         );

         // @@ H) WS_Server_CLI\Configs carries the same knob
         $Given = new WS_Server_CLI\Configs(host: '127.0.0.1', port: 0, workers: 1, headroom: 9);

         TCP_Server_CLI::$headroom = 32;
         $WS = new WS_Server_CLI(Mode: Modes::Test);
         $WS->configure($Given);
         $configured = TCP_Server_CLI::$headroom;

         yield assert(
            assertion: $Given->headroom === 9 && $configured === 9,
            description: 'WS_Server_CLI->configure() applies Configs(headroom: 9) — observed: '
               . json_encode(['given' => $Given->headroom, 'configured' => $configured])
         );
      }
      finally {
         foreach ($Statics as [$Property, $value]) {
            $Property->setValue(null, $value);
         }
         $WPI = WPI;
         if ($OldServer !== null) {
            $WPI->Server = $OldServer;
         }
         else {
            unset($WPI->Server);
         }
      }
   })
);
