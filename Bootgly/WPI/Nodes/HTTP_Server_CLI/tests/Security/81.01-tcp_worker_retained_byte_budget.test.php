<?php

use const Bootgly\WPI;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as TCPServer;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


if (! class_exists('L1WorkerBudgetStream', false)) {
   class L1WorkerBudgetStream
   {
      /** @var array<string,bool> */
      public static array $blocked = [];
      /** @var array<string,int> */
      public static array $calls = [];
      /** @var array<string,string> */
      public static array $written = [];

      public mixed $context;
      private string $key = '';


      public static function reset (): void
      {
         self::$blocked = [];
         self::$calls = [];
         self::$written = [];
      }

      public static function block (string $key, bool $blocked): void
      {
         self::$blocked[$key] = $blocked;
      }

      public function stream_open (
         string $path,
         string $mode,
         int $options,
         null|string &$opened_path
      ): bool {
         $this->key = (string) (parse_url($path, PHP_URL_HOST) ?: 'default');
         self::$blocked[$this->key] ??= false;
         self::$calls[$this->key] ??= 0;
         self::$written[$this->key] ??= '';

         return true;
      }

      public function stream_write (string $data): int
      {
         self::$calls[$this->key]++;

         if (self::$blocked[$this->key]) {
            return 0;
         }

         self::$written[$this->key] .= $data;

         return strlen($data);
      }

      public function stream_eof (): bool
      {
         return false;
      }

      /** @return array<string,mixed> */
      public function stream_stat (): array
      {
         return [];
      }
   }
}

if (! class_exists('L1WorkerBudgetConnection', false)) {
   class L1WorkerBudgetConnection extends Connection
   {
      public bool $closed = false;

      /** @param resource $Socket */
      public function __construct (mixed &$Socket, int $port)
      {
         $this->Socket = $Socket;
         $this->timers = [];
         $this->expiration = 15;
         $this->ip = '127.0.0.1';
         $this->port = $port;
         $this->encrypted = false;
         $this->handshaking = false;
         $this->handshakeTimer = 0;
         $this->status = Connections::STATUS_ESTABLISHED;
         $this->started = time();
         $this->used = time();
         $this->writes = 0;
      }

      public function close (): true
      {
         $this->closed = true;
         $this->status = Connections::STATUS_CLOSED;

         if (is_resource($this->Socket)) {
            @fclose($this->Socket);
         }

         return true;
      }
   }
}

/**
 * Security PoC L1 — the TCP transport needs a worker-wide retained-output
 * budget in addition to its per-connection maxPendingBytes ceiling.
 *
 * Four independent HTTP/1 responses are encoded through the public Response
 * API and handed to the production writing() -> transmit() -> defer() path.
 * Each deterministic stream makes zero progress, so the complete unsent wire
 * becomes pendingBuffer on its own Package. Every response is individually
 * admissible, while their sum exceeds a deliberately small 256 KiB worker
 * probe budget without approaching memory exhaustion.
 */
$probeCap = 256 * 1024;
$bodySize = 96 * 1024;
$peerCount = 4;
$capCandidates = [
   'maxWorkerPendingBytes',
   'maxWorkerRetainedBytes',
   'maxPendingWorkerBytes',
   'maxRetainedWorkerBytes',
];
$counterCandidates = [
   'pendingBytes',
   'retainedBytes',
   'totalPendingBytes',
   'totalRetainedBytes',
];
$probe = [
   'error' => '',
   'probe_cap' => $probeCap,
   'body_size' => $bodySize,
   'peer_count' => $peerCount,
   'per_connection_cap' => null,
   'budget_property' => null,
   'budget_configured' => false,
   'budget_error' => null,
   'counter_property' => null,
   'counter_baseline' => null,
   'counter_after_control' => null,
   'counter_after_attack' => null,
   'counter_after_drain' => null,
   'control' => [],
   'peers' => [],
   'aggregate_pending' => 0,
   'cleanup' => [],
   'fresh' => [],
];

return new Specification(
   description: 'TCP retained output must obey a worker-wide byte budget',
   Separator: new Separator(line: true),

   request: static function () use (
      $probeCap,
      $bodySize,
      $peerCount,
      $capCandidates,
      $counterCandidates,
      &$probe,
   ): string {
      $scheme = 'bootgly-l1-worker-budget';
      if (! in_array($scheme, stream_get_wrappers(), true)) {
         stream_wrapper_register($scheme, L1WorkerBudgetStream::class);
      }

      L1WorkerBudgetStream::reset();

      $WPI = WPI;
      $OldRequest = $WPI->Request;
      $savedPerConnectionCap = TCPServer::$maxPendingBytes;
      $Cap = null;
      $Counter = null;
      $capOriginal = null;
      $Peers = [];
      $Control = null;
      $Fresh = null;

      $Pending = static fn (TCPPackages $Package): int => max(
         0,
         strlen($Package->pendingBuffer) - $Package->pendingOffset,
      );

      $Build = static function (
         string $scheme,
         string $key,
         int $port,
         string $body,
      ) use ($WPI): array {
         $Socket = fopen("{$scheme}://{$key}/probe", 'w+');
         if (! is_resource($Socket)) {
            throw new RuntimeException("Could not allocate transport stream {$key}.");
         }

         $Connection = new L1WorkerBudgetConnection($Socket, $port);
         $Package = new class($Connection) extends TCPPackages {};

         $Request = new Request;
         $Request->method = 'GET';
         $Request->protocol = 'HTTP/1.1';
         $WPI->Request = $Request;

         $Response = new Response;
         $Response->reset($Package, $Request);
         $Response(body: $body);
         $length = null;
         $wire = $Response->encode($Package, $length);
         if ($length !== null && $length !== strlen($wire)) {
            throw new RuntimeException(
               "Production encoder length mismatch for transport stream {$key}."
            );
         }

         return [
            'key' => $key,
            'Socket' => $Socket,
            'Connection' => $Connection,
            'Package' => $Package,
            'wire' => $wire,
         ];
      };

      try {
         // ! Keep the individual control comfortably above every probe wire.
         TCPServer::$maxPendingBytes = 1024 * 1024;
         $probe['per_connection_cap'] = TCPServer::$maxPendingBytes;

         foreach ($capCandidates as $candidate) {
            if (! property_exists(TCPServer::class, $candidate)) {
               continue;
            }

            $Candidate = new ReflectionProperty(TCPServer::class, $candidate);
            if (
               ! $Candidate->isStatic()
               || ! $Candidate->isPublic()
               || $Candidate->isReadOnly()
               || ! is_int($Candidate->getValue())
            ) {
               continue;
            }

            $Cap = $Candidate;
            break;
         }

         // @ Accept a semantically equivalent future public setting without
         //   coupling the retained PoC to one exact spelling.
         if ($Cap === null) {
            $Server = new ReflectionClass(TCPServer::class);
            foreach ($Server->getProperties(ReflectionProperty::IS_PUBLIC) as $Candidate) {
               $name = strtolower($Candidate->getName());
               $worker = str_contains($name, 'worker');
               $owner = str_contains($name, 'pending')
                  || str_contains($name, 'retained');
               $unit = str_contains($name, 'byte')
                  || str_contains($name, 'size');
               if (
                  ! $worker
                  || ! $owner
                  || ! $unit
                  || ! $Candidate->isStatic()
                  || $Candidate->isReadOnly()
                  || ! is_int($Candidate->getValue())
               ) {
                  continue;
               }

               $Cap = $Candidate;
               break;
            }
         }

         if ($Cap !== null) {
            try {
               $capOriginal = $Cap->getValue();
               $Cap->setValue(null, $probeCap);
               $probe['budget_property'] = $Cap->getName();
               $probe['budget_configured'] = $Cap->getValue() === $probeCap;
            }
            catch (Throwable $Throwable) {
               $probe['budget_error'] =
                  $Throwable::class . ': ' . $Throwable->getMessage();
            }
         }

         foreach ($counterCandidates as $candidate) {
            if (! property_exists(TCPServer::class, $candidate)) {
               continue;
            }

            $Candidate = new ReflectionProperty(TCPServer::class, $candidate);
            if (
               $Candidate->isStatic()
               && $Candidate->isPublic()
               && is_int($Candidate->getValue())
            ) {
               $Counter = $Candidate;
               $probe['counter_property'] = $Candidate->getName();
               $probe['counter_baseline'] = $Candidate->getValue();
               break;
            }
         }

         // # No-stall control: the same production encoder and writer emit
         //   exact wire without retaining anything.
         $controlBody = 'L1-CONTROL:' . str_repeat('C', 4096);
         $Control = $Build($scheme, 'control', 18100, $controlBody);
         $controlResult = $Control['Package']->writing(
            $Control['Socket'],
            buffer: $Control['wire'],
         );
         $probe['control'] = [
            'result' => $controlResult,
            'closed' => $Control['Connection']->closed,
            'wire_bytes' => strlen($Control['wire']),
            'pending_bytes' => $Pending($Control['Package']),
            'written_bytes' => strlen(L1WorkerBudgetStream::$written['control']),
            'wire_hash' => hash('sha256', $Control['wire']),
            'written_hash' => hash(
               'sha256',
               L1WorkerBudgetStream::$written['control'],
            ),
         ];
         $Control['Connection']->close();
         if ($Counter !== null) {
            $probe['counter_after_control'] = $Counter->getValue();
         }

         // # Attack: every peer stalls independently below the per-connection
         //   cap. Keep every object strongly referenced, then take one FINAL
         //   live snapshot after every admission attempt. A future controller
         //   may close/evict an earlier peer to admit a later one; historical
         //   per-write snapshots must never be summed as if still resident.
         for ($index = 1; $index <= $peerCount; $index++) {
            $key = "peer-{$index}";
            $body = "L1-PEER-{$index}:" . str_repeat('P', $bodySize);
            $Peer = $Build($scheme, $key, 18100 + $index, $body);
            L1WorkerBudgetStream::block($key, true);

            $result = $Peer['Package']->writing(
               $Peer['Socket'],
               buffer: $Peer['wire'],
            );
            $pending = $Pending($Peer['Package']);
            $probe['peers'][] = [
               'key' => $key,
               'result' => $result,
               'closed_after_write' => $Peer['Connection']->closed,
               'wire_bytes' => strlen($Peer['wire']),
               'pending_after_write' => $pending,
               'calls_after_write' => L1WorkerBudgetStream::$calls[$key],
            ];
            $Peers[] = $Peer;
         }

         $probe['aggregate_pending'] = 0;
         foreach ($Peers as $index => $Peer) {
            $pending = $Pending($Peer['Package']);
            $probe['peers'][$index]['closed'] = $Peer['Connection']->closed;
            $probe['peers'][$index]['pending_bytes'] = $pending;
            $probe['peers'][$index]['calls'] =
               L1WorkerBudgetStream::$calls[$Peer['key']];
            $probe['aggregate_pending'] += $pending;
         }

         if ($Counter !== null) {
            $probe['counter_after_attack'] = $Counter->getValue();
         }

         // # Drain every admitted peer through production writing() so a
         //   future aggregate reservation must be released by reset().
         $cleanup = [
            'drained' => 0,
            'rejected' => 0,
            'failures' => [],
         ];
         foreach ($Peers as $Peer) {
            $key = $Peer['key'];
            $Package = $Peer['Package'];
            $Connection = $Peer['Connection'];
            L1WorkerBudgetStream::block($key, false);

            if ($Connection->closed) {
               if ($Pending($Package) === 0) {
                  $cleanup['rejected']++;
               }
               else {
                  $cleanup['failures'][] = [
                     'key' => $key,
                     'closed_with_pending' => $Pending($Package),
                  ];
               }
               continue;
            }

            $rounds = 0;
            while (
               $Pending($Package) > 0
               && $rounds < 8
            ) {
               $rounds++;
               $Package->writing($Peer['Socket'], buffer: '');
            }

            if ($Connection->closed) {
               $cleanup['failures'][] = [
                  'key' => $key,
                  'closed_during_drain' => true,
                  'pending' => $Pending($Package),
               ];
               continue;
            }

            $exact = $Pending($Package) === 0
               && L1WorkerBudgetStream::$written[$key] === $Peer['wire'];
            if ($exact) {
               $cleanup['drained']++;
            }
            else {
               $cleanup['failures'][] = [
                  'key' => $key,
                  'pending' => $Pending($Package),
                  'wire_bytes' => strlen($Peer['wire']),
                  'written_bytes' => strlen(L1WorkerBudgetStream::$written[$key]),
                  'rounds' => $rounds,
               ];
            }
         }

         if ($Counter !== null) {
            $probe['counter_after_drain'] = $Counter->getValue();
         }

         // # Reservation-release regression for a future implementation:
         //   after all admitted peers drain, one fresh sub-cap response must
         //   be admissible again.
         if ($Cap !== null && $probe['budget_configured']) {
            $freshBody = 'L1-FRESH:' . str_repeat('F', $bodySize);
            $Fresh = $Build($scheme, 'fresh', 18200, $freshBody);
            L1WorkerBudgetStream::block('fresh', true);
            $freshResult = $Fresh['Package']->writing(
               $Fresh['Socket'],
               buffer: $Fresh['wire'],
            );
            $freshPending = $Pending($Fresh['Package']);
            L1WorkerBudgetStream::block('fresh', false);
            if (! $Fresh['Connection']->closed) {
               $Fresh['Package']->writing($Fresh['Socket'], buffer: '');
            }
            $probe['fresh'] = [
               'result' => $freshResult,
               'closed' => $Fresh['Connection']->closed,
               'wire_bytes' => strlen($Fresh['wire']),
               'pending_before_drain' => $freshPending,
               'pending_after_drain' => $Pending($Fresh['Package']),
               'exact_wire' =>
                  L1WorkerBudgetStream::$written['fresh'] === $Fresh['wire'],
            ];
         }

         $probe['cleanup'] = $cleanup;
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         foreach ($Peers as $Peer) {
            L1WorkerBudgetStream::block($Peer['key'], false);
            if (! $Peer['Connection']->closed) {
               try {
                  if ($Pending($Peer['Package']) > 0) {
                     $Peer['Package']->writing($Peer['Socket'], buffer: '');
                  }
               }
               catch (Throwable) {}
               $Peer['Connection']->close();
            }
            if (is_resource($Peer['Socket'])) {
               @fclose($Peer['Socket']);
            }
         }

         foreach ([$Control, $Fresh] as $Peer) {
            if (! is_array($Peer)) {
               continue;
            }
            if (! $Peer['Connection']->closed) {
               $Peer['Connection']->close();
            }
            if (is_resource($Peer['Socket'])) {
               @fclose($Peer['Socket']);
            }
         }

         if ($Cap !== null && is_int($capOriginal)) {
            try {
               $Cap->setValue(null, $capOriginal);
            }
            catch (Throwable) {}
         }
         TCPServer::$maxPendingBytes = $savedPerConnectionCap;
         $WPI->Request = $OldRequest;
      }

      return "GET /l1-worker-budget-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) {
      yield $Router->route(
         '/l1-worker-budget-harness',
         static function (Request $Request, Response $Response): Response {
            return $Response(code: 200, body: 'L1-WORKER-BUDGET-HARNESS-OK');
         },
         GET,
      );

      yield $Router->route(
         '/*',
         static function (Request $Request, Response $Response): Response {
            return $Response(code: 404, body: 'Not Found');
         },
      );
   },

   test: static function (string $response) use (
      $probeCap,
      $peerCount,
      &$probe,
   ): bool|string {
      if ($probe['error'] !== '') {
         Vars::$labels = ['L1 worker-budget PoC state'];
         dump(json_encode($probe));

         return 'L1 PoC harness failed before the retained-output snapshot: '
            . $probe['error'];
      }

      if (! str_contains($response, 'L1-WORKER-BUDGET-HARNESS-OK')) {
         return 'L1 PoC harness request did not reach its control route.';
      }

      $control = $probe['control'];
      if (
         ($control['result'] ?? null) !== true
         || ($control['closed'] ?? null) !== false
         || ($control['pending_bytes'] ?? null) !== 0
         || ($control['written_bytes'] ?? null) !== ($control['wire_bytes'] ?? null)
         || ($control['written_hash'] ?? null) !== ($control['wire_hash'] ?? null)
      ) {
         Vars::$labels = ['L1 no-stall control'];
         dump(json_encode($probe));

         return 'L1 PoC control failed: the production HTTP/1 encoder/writer '
            . 'did not emit exact wire without backpressure.';
      }

      if (
         count($probe['peers']) !== $peerCount
         || ! is_int($probe['per_connection_cap'])
         || $probe['per_connection_cap'] <= 0
      ) {
         Vars::$labels = ['L1 peer fixture'];
         dump(json_encode($probe));

         return 'L1 PoC fixture failed before the aggregate transport comparison.';
      }

      $pending = 0;
      $accepted = 0;
      $rejected = 0;
      foreach ($probe['peers'] as $index => $peer) {
         $wireBytes = $peer['wire_bytes'] ?? null;
         $pendingBytes = $peer['pending_bytes'] ?? null;
         if (
            ! is_int($wireBytes)
            || $wireBytes <= 0
            || $wireBytes >= $probe['per_connection_cap']
            || ! is_int($pendingBytes)
            || $pendingBytes < 0
         ) {
            Vars::$labels = ['L1 per-connection control'];
            dump(json_encode($probe));

            return 'L1 PoC control failed: at least one response was not '
               . 'individually admissible below maxPendingBytes.';
         }

         $pending += $pendingBytes;
         if (
            ($peer['result'] ?? null) === true
            && ($peer['closed'] ?? null) === false
            && $pendingBytes === $wireBytes
         ) {
            $accepted++;
         }
         else if (
            ($peer['result'] ?? null) === false
            || ($peer['closed'] ?? null) === true
         ) {
            $rejected++;
         }
         else {
            Vars::$labels = ['L1 peer admission outcome'];
            dump(json_encode(['index' => $index, 'probe' => $probe]));

            return 'L1 PoC produced a partial peer-admission outcome that '
               . 'cannot support a security verdict.';
         }
      }

      if ($pending !== $probe['aggregate_pending']) {
         Vars::$labels = ['L1 aggregate snapshot'];
         dump(json_encode($probe));

         return 'L1 PoC aggregate snapshot did not equal the exact sum of '
            . 'the four per-connection pending buffers.';
      }

      $cleanup = $probe['cleanup'];
      if (
         ($cleanup['failures'] ?? null) !== []
         || ($cleanup['drained'] ?? -1) !== $accepted
         || ($cleanup['rejected'] ?? -1) !== $rejected
      ) {
         Vars::$labels = ['L1 cleanup evidence'];
         dump(json_encode($probe));

         return 'L1 PoC cleanup failed to drain every admitted response exactly.';
      }

      if ($probe['counter_property'] !== null) {
         $baseline = $probe['counter_baseline'];
         if (
            ! is_int($baseline)
            || $probe['counter_after_control'] !== $baseline
            || ! is_int($probe['counter_after_attack'])
            || $probe['counter_after_attack'] - $baseline !== $pending
            || $probe['counter_after_drain'] !== $baseline
         ) {
            Vars::$labels = ['L1 retained-byte ledger'];
            dump(json_encode($probe));

            return 'L1 ledger fixture mismatch: the exposed counter did not '
               . 'match the baseline-relative live pending output and drain.';
         }
      }

      if ($probe['budget_property'] === null) {
         if (
            $accepted === $peerCount
            && $rejected === 0
            && $pending > $probeCap
         ) {
            Vars::$labels = ['L1 aggregate retained-output evidence'];
            dump(json_encode([
               'peers' => $peerCount,
               'pending_bytes' => $pending,
               'probe_cap' => $probeCap,
               'per_connection_cap' => $probe['per_connection_cap'],
               'budget_property' => null,
            ]));

            return 'CONFIRMED L1: four independently admissible '
               . "backpressured HTTP/1 responses retained {$pending} bytes "
               . 'across four per-connection transport fixtures in one runner '
               . "process, exceeding the {$probeCap}-byte aggregate probe "
               . 'threshold; no worker-wide retained-output cap is exposed.';
         }

         Vars::$labels = ['L1 unexpected unbounded outcome'];
         dump(json_encode($probe));

         return 'L1 PoC found no worker budget but did not reproduce the '
            . 'expected exact aggregate retention.';
      }

      if (
         $probe['budget_configured'] !== true
         || $probe['budget_error'] !== null
      ) {
         Vars::$labels = ['L1 budget configuration'];
         dump(json_encode($probe));

         return 'L1 PoC found a worker-budget setting but could not configure '
            . 'the bounded regression fixture.';
      }

      if ($pending > $probeCap) {
         Vars::$labels = ['L1 configured-cap bypass'];
         dump(json_encode($probe));

         return 'CONFIRMED L1: the configured worker-wide retained-output cap '
            . "was {$probeCap} bytes, but live pending output reached {$pending} "
            . 'bytes.';
      }

      $fresh = $probe['fresh'];
      if (
         $accepted < 1
         || $rejected < 1
         || ($fresh['result'] ?? null) !== true
         || ($fresh['closed'] ?? null) !== false
         || ($fresh['pending_before_drain'] ?? null)
            !== ($fresh['wire_bytes'] ?? null)
         || ($fresh['pending_after_drain'] ?? null) !== 0
         || ($fresh['exact_wire'] ?? null) !== true
      ) {
         Vars::$labels = ['L1 bounded-budget lifecycle'];
         dump(json_encode($probe));

         return 'L1 worker budget bounded the initial aggregate but failed '
            . 'admission, rejection, drain, or reservation-release controls.';
      }

      return true;
   },
);
