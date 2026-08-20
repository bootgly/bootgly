<?php


use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ABI\Resources\Cache as CacheResource;
use Bootgly\ABI\Resources\Cache\Config as CacheConfig;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Committing as SessionCommitting;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handler as SessionHandler;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handlers\Cache as SessionCache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


if (! class_exists('HTTPServerCLISessionCapacityProbe', false)) {
   class HTTPServerCLISessionCapacityProbe
   {
      public string $error = '';
      public int $segment = 0;
      public int $prePID = 0;
      public int $postPID = 0;
      public int $successfulFills = 0;
      public int $trigger = 0;
      public int $triggerBytes = 0;
      public string $triggerStatus = '';
      public bool $workerTerminated = false;
      public string $workerState = '';
      public int $workerExitCode = -1;
      public bool $control = false;
      public bool $capacityResponse = false;
      public bool $capacityCookie = false;
      public bool $capacityMarker = false;
      public string $capacityMessage = '';
      public int $capacityPID = 0;
      public bool $workerRestored = false;
      public bool $privateIPCAttested = false;
      public bool $hostIPCMounted = false;
      public int $hostIPCInode = 0;
      public int $currentIPCInode = 0;
      public bool $skip = false;
      /** @var array<int,int> */
      public array $fillSizes = [];
   }
}

if (! class_exists('HTTPServerCLISessionCapacityWorkerState', false)) {
   class HTTPServerCLISessionCapacityWorkerState
   {
      public static bool $captured = false;
      public static mixed $Handler = null;
      public static bool $autoUpdateTimestamp = false;
      /** @var array{int,int} */
      public static array $gcProbability = [0, 1];
   }
}

if (! class_exists('HTTPServerCLISessionCapacityHandler', false)) {
   class HTTPServerCLISessionCapacityHandler implements SessionCommitting
   {
      public function __construct (
         private SessionCache $Handler,
         private string $marker,
      ) {}

      public function read (string $sessionID): string|false
      {
         return $this->Handler->read($sessionID);
      }

      public function write (string $sessionID, string $sessionData): bool
      {
         return $this->Handler->write($sessionID, $sessionData);
      }

      public function touch (string $sessionID): bool
      {
         return $this->Handler->touch($sessionID);
      }

      public function destroy (string $sessionID): bool
      {
         return $this->Handler->destroy($sessionID);
      }

      public function purge (int $maxLifetime): bool
      {
         return $this->Handler->purge($maxLifetime);
      }

      public function fetch (
         string $sessionID,
         null|string &$revision = null,
      ): string|false {
         return $this->Handler->fetch($sessionID, $revision);
      }

      public function commit (
         string $sessionID,
         string $sessionData,
         null|string &$revision,
      ): bool {
         try {
            return $this->Handler->commit($sessionID, $sessionData, $revision);
         }
         catch (Throwable $Throwable) {
            @file_put_contents($this->marker, (string) json_encode([
               'class' => $Throwable::class,
               'message' => $Throwable->getMessage(),
               'origin' => 'session-commit',
               'pid' => getmypid(),
            ]));

            throw $Throwable;
         }
      }

      public function revoke (string $sessionID, string $revision): bool
      {
         return $this->Handler->revoke($sessionID, $revision);
      }
   }
}


/**
 * Security PoC H1 — exhaustion of the default Shared session cache must not
 * let Session::save() throw beyond the request catcher and terminate a worker.
 *
 * The production default is a 16 MiB fixed-size SysV segment. This test uses
 * that exact size and stores attacker-supplied request bodies below the default
 * 10 MiB request limit through the real encrypted Session handler.
 */
$Probe = new HTTPServerCLISessionCapacityProbe;

return new Test(
   description: 'Shared Session capacity exhaustion must not terminate the serving worker',
   Separator: new Separator(line: true),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      $Segment = false;
      $Semaphore = false;
      $ownedSHM = false;
      $ownedSEM = false;
      $token = bin2hex(random_bytes(12));
      $marker = sys_get_temp_dir() . "/bootgly-security-h1-session-{$token}.json";

      $Read = static function ($Socket, float $timeout = 2.0): string {
         stream_set_blocking($Socket, false);
         $wire = '';
         $expected = null;
         $deadline = microtime(true) + $timeout;

         while (microtime(true) < $deadline) {
            $chunk = @fread($Socket, 65535);
            if ($chunk !== false && $chunk !== '') {
               $wire .= $chunk;
               $separator = strpos($wire, "\r\n\r\n");
               if ($separator !== false && $expected === null) {
                  $head = substr($wire, 0, $separator + 2);
                  if (preg_match('#\r\nContent-Length: ([0-9]+)\r\n#i', $head, $matches) === 1) {
                     $expected = $separator + 4 + (int) $matches[1];
                  }
               }
               if ($expected !== null && strlen($wire) >= $expected) {
                  return substr($wire, 0, $expected);
               }
            }

            if (@feof($Socket)) {
               break;
            }
            usleep(5_000);
         }

         return $wire;
      };

      $Send = static function (string $request, float $timeout = 2.0) use ($hostPort, $Read): string {
         $Socket = @stream_socket_client(
            "tcp://{$hostPort}",
            $errorNumber,
            $errorMessage,
            timeout: 2,
         );
         if (! is_resource($Socket)) {
            return '';
         }
         stream_set_blocking($Socket, true);
         $length = strlen($request);
         $offset = 0;
         while ($offset < $length) {
            $written = @fwrite($Socket, substr($request, $offset));
            if (! is_int($written) || $written < 1) {
               fclose($Socket);
               return '';
            }
            $offset += $written;
         }

         $wire = $Read($Socket, $timeout);
         fclose($Socket);

         return $wire;
      };

      $Decode = static function (string $wire): null|array {
         $separator = strpos($wire, "\r\n\r\n");
         if ($separator === false) {
            return null;
         }

         $decoded = json_decode(substr($wire, $separator + 4), true);

         return is_array($decoded) ? $decoded : null;
      };

      try {
         $hostIPC = @stat('/run/bootgly-host-ipc');
         $currentIPC = @stat('/proc/self/ns/ipc');
         $Probe->hostIPCInode = is_array($hostIPC)
            ? (int) ($hostIPC['ino'] ?? 0)
            : 0;
         $Probe->currentIPCInode = is_array($currentIPC)
            ? (int) ($currentIPC['ino'] ?? 0)
            : 0;
         $mountInfo = @file_get_contents('/proc/self/mountinfo');
         $Probe->hostIPCMounted = is_string($mountInfo)
            && preg_match(
               '#^[0-9]+ [0-9]+ [^ ]+ ipc:\\[' . $Probe->hostIPCInode
                  . '\\] /run/bootgly-host-ipc ro(?:,[^ ]*)? - nsfs nsfs #m',
               $mountInfo,
            ) === 1;
         $Probe->privateIPCAttested = is_file('/.dockerenv')
            && getenv('BOOTGLY_PRIVATE_IPC_AUDIT') === '1'
            && $Probe->hostIPCMounted
            && $Probe->hostIPCInode > 0
            && $Probe->currentIPCInode > 0
            && $Probe->currentIPCInode !== $Probe->hostIPCInode;
         if ($Probe->privateIPCAttested === false) {
            $Probe->skip = true;

            return "GET /h1-session-harness HTTP/1.1\r\n"
               . "Host: localhost\r\nConnection: close\r\n\r\n";
         }
         if (
            ! extension_loaded('sysvshm')
            || ! extension_loaded('sysvsem')
            || ! function_exists('posix_kill')
         ) {
            throw new RuntimeException('H1 requires sysvshm, sysvsem and POSIX.');
         }

         // A fresh IPC key prevents this destructive capacity probe from ever
         // attaching to an unrelated segment. Both /proc tables expose keys in
         // their first column on Linux, the platform this driver hardens.
         $used = [];
         foreach (['shm', 'sem'] as $table) {
            foreach (@file("/proc/sysvipc/{$table}", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
               $fields = preg_split('/\s+/', trim($line));
               if (is_array($fields) && isset($fields[0]) && is_numeric($fields[0])) {
                  $used[(int) $fields[0]] = true;
               }
            }
         }

         for ($attempt = 0; $attempt < 64; $attempt++) {
            $candidate = random_int(0x10000000, 0x6fffffff);
            if (! isset($used[$candidate])) {
               $Probe->segment = $candidate;
               break;
            }
         }
         if ($Probe->segment === 0) {
            throw new RuntimeException('Could not allocate an unused H1 SysV key.');
         }

         // Prove that this process created the exact objects before granting
         // cleanup authority. A scan-only random key is insufficient on a host
         // IPC namespace because another process can win the attach race.
         $FindIPC = static function (string $table, int $key): null|array {
            foreach (@file(
               "/proc/sysvipc/{$table}",
               FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES,
            ) ?: [] as $line) {
               $fields = preg_split('/\s+/', trim($line));
               if (is_array($fields) && isset($fields[0]) && (int) $fields[0] === $key) {
                  return $fields;
               }
            }

            return null;
         };

         $EUID = posix_geteuid();
         $Segment = @shm_attach($Probe->segment, CacheConfig::DEFAULT_SIZE, 0600);
         if ($Segment === false) {
            throw new RuntimeException('Could not create the isolated H1 shared-memory segment.');
         }
         $SHM = $FindIPC('shm', $Probe->segment);
         $ownedSHM = is_array($SHM)
            && (int) ($SHM[2] ?? -1) === 600
            && (int) ($SHM[3] ?? -1) === CacheConfig::DEFAULT_SIZE
            && (int) ($SHM[4] ?? -1) === posix_getpid()
            && (int) ($SHM[7] ?? -1) === $EUID
            && (int) ($SHM[9] ?? -1) === $EUID;
         if ($ownedSHM === false) {
            throw new RuntimeException(
               'H1 refused a shared-memory segment whose creator, owner, size or mode was not the fixture.'
            );
         }

         $Semaphore = @sem_get($Probe->segment, 1, 0600, true);
         if ($Semaphore === false) {
            throw new RuntimeException('Could not create the isolated H1 semaphore.');
         }
         $SEM = $FindIPC('sem', $Probe->segment);
         $ownedSEM = is_array($SEM)
            && (int) ($SEM[2] ?? -1) === 600
            && (int) ($SEM[4] ?? -1) === $EUID
            && (int) ($SEM[6] ?? -1) === $EUID;
         if ($ownedSEM === false) {
            throw new RuntimeException(
               'H1 refused a semaphore whose owner or mode was not the fixture.'
            );
         }

         // Positive control: the worker is healthy before any Session pressure.
         $controlWire = $Send(
            "GET /h1-session-control HTTP/1.1\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "Host: localhost\r\nConnection: close\r\n\r\n"
         );
         $control = $Decode($controlWire);
         $controlPID = $control['pid'] ?? null;
         if (
            ! is_array($control)
            || ($control['phase'] ?? null) !== 'control'
            || ! is_int($controlPID)
            || $controlPID < 1
         ) {
            throw new RuntimeException('H1 pre-pressure control did not reach the worker.');
         }
         $Probe->control = true;
         $Probe->prePID = $controlPID;

         // Each request discards its cookie and therefore creates a unique,
         // live encrypted Session record. A modest fixed body keeps peak heap
         // far from memory_limit, so worker death cannot be misattributed to
         // transient encryption/base64 copies instead of Shared capacity.
         $fillSize = 256 * 1024;
         for ($index = 1; $index <= 128; $index++) {
            $body = str_repeat('S', $fillSize);
            $Probe->fillSizes[] = $fillSize;
            $fillWire = $Send(
               "POST /h1-session-fill HTTP/1.1\r\n"
               . "X-Bootgly-Test: {$testIndex}\r\n"
               . "X-H1-Segment: {$Probe->segment}\r\n"
               . "X-H1-Token: {$token}\r\n"
               . "Host: localhost\r\n"
               . "Content-Type: application/octet-stream\r\n"
               . "Content-Length: {$fillSize}\r\n"
               . "Connection: close\r\n\r\n"
               . $body,
               timeout: 8.0,
            );
            $fill = $Decode($fillWire);
            if (
               is_array($fill)
               && ($fill['phase'] ?? null) === 'fill'
               && ($fill['pid'] ?? null) === $Probe->prePID
            ) {
               $Probe->successfulFills++;
               continue;
            }

            $Probe->trigger = $index;
            $Probe->triggerBytes = strlen($fillWire);
            if (preg_match('#^HTTP/1\.1 ([0-9]{3})#', $fillWire, $matches) === 1) {
               $Probe->triggerStatus = $matches[1];
               $Probe->capacityResponse = in_array($matches[1], ['500', '503'], true);
            }
            $headerEnd = strpos($fillWire, "\r\n\r\n");
            $Probe->capacityCookie = $headerEnd !== false
               && stripos(substr($fillWire, 0, $headerEnd), "\r\nSet-Cookie:") !== false;
            break;
         }

         if ($Probe->trigger === 0) {
            throw new RuntimeException('H1 did not reach the default Shared capacity in 128 Sessions.');
         }
         if ($Probe->successfulFills < 1) {
            throw new RuntimeException('H1 reached failure before proving a successful Session commit.');
         }

         $capacity = is_file($marker)
            ? json_decode((string) @file_get_contents($marker), true)
            : null;
         if (is_array($capacity)) {
            $Probe->capacityMessage = is_string($capacity['message'] ?? null)
               ? $capacity['message']
               : '';
            $Probe->capacityPID = is_int($capacity['pid'] ?? null)
               ? $capacity['pid']
               : 0;
            $Probe->capacityMarker = ($capacity['class'] ?? null) === RuntimeException::class
               && ($capacity['origin'] ?? null) === 'session-commit'
               && str_starts_with(
                  $Probe->capacityMessage,
                  'Shared-memory capacity exhausted while writing variable ',
               )
               && $Probe->capacityPID === $Probe->prePID;
         }

         $deathDeadline = microtime(true) + 2.0;
         do {
            $status = @file_get_contents('/proc/' . $Probe->prePID . '/status');
            if ($status === false) {
               $Probe->workerState = 'absent';
               $Probe->workerTerminated = true;
               break;
            }
            if (preg_match('/^State:\s+([A-Z])/m', $status, $matches) === 1) {
               $Probe->workerState = $matches[1];
               if ($matches[1] === 'Z' || $matches[1] === 'X') {
                  $Probe->workerTerminated = true;
                  $stat = @file_get_contents('/proc/' . $Probe->prePID . '/stat');
                  $close = is_string($stat) ? strrpos($stat, ')') : false;
                  $fields = $close === false
                     ? []
                     : preg_split('/\s+/', trim(substr($stat, $close + 1)));
                  $exitCode = is_array($fields) ? array_pop($fields) : null;
                  $Probe->workerExitCode = is_numeric($exitCode) ? (int) $exitCode : -1;
                  break;
               }
            }
            if (@posix_kill($Probe->prePID, 0) === false) {
               $Probe->workerState = 'unreachable';
               $Probe->workerTerminated = true;
               break;
            }
            usleep(10_000);
         }
         while (microtime(true) < $deathDeadline);

         // On remediated code the worker survives. Restore every worker-global
         // Session/handler value before the parent removes the private SysV
         // resources, so later cases can never inherit a handler aimed at a
         // removed segment.
         if ($Probe->workerTerminated === false) {
            $restoreWire = $Send(
               "GET /h1-session-restore HTTP/1.1\r\n"
               . "X-Bootgly-Test: {$testIndex}\r\n"
               . "Host: localhost\r\nConnection: close\r\n\r\n"
            );
            $restore = $Decode($restoreWire);
            $Probe->workerRestored = is_array($restore)
               && ($restore['phase'] ?? null) === 'restore'
               && ($restore['pid'] ?? null) === $Probe->prePID
               && ($restore['restored'] ?? null) === true;
         }
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         if ($Segment !== false) {
            if ($ownedSHM) {
               @shm_remove($Segment);
            }
            @shm_detach($Segment);
         }
         if ($Semaphore !== false && $ownedSEM) {
            @sem_remove($Semaphore);
         }
         @unlink($marker);
      }

      return "GET /h1-session-harness HTTP/1.1\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/h1-session-control', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response->JSON->send([
            'phase' => 'control',
            'pid' => getmypid(),
         ]);
      }, GET);

      yield $Router->route('/h1-session-fill', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $segment = $Request->Header->get('X-H1-Segment') ?? '';
         $token = $Request->Header->get('X-H1-Token') ?? '';
         if (
            ! ctype_digit($segment)
            || (int) $segment < 1
            || preg_match('/^[a-f0-9]{24}$/D', $token) !== 1
         ) {
            return $Response->code(400)->JSON->send([
               'phase' => 'fill',
               'error' => 'segment',
            ]);
         }

         if (HTTPServerCLISessionCapacityWorkerState::$captured === false) {
            HTTPServerCLISessionCapacityWorkerState::$captured = true;
            HTTPServerCLISessionCapacityWorkerState::$Handler = SessionHandler::$instance;
            HTTPServerCLISessionCapacityWorkerState::$autoUpdateTimestamp = Session::$autoUpdateTimestamp;
            HTTPServerCLISessionCapacityWorkerState::$gcProbability = Session::$gcProbability;
         }

         $Cache = new CacheResource([
            'driver' => 'shared',
            'segment' => (int) $segment,
            'size' => CacheConfig::DEFAULT_SIZE,
            'permissions' => 0600,
            'prefix' => 'h2:',
         ]);
         $SessionCache = new SessionCache($Cache);
         SessionHandler::$instance = new HTTPServerCLISessionCapacityHandler(
            $SessionCache,
            sys_get_temp_dir() . "/bootgly-security-h1-session-{$token}.json",
         );
         Session::$autoUpdateTimestamp = false;
         Session::$gcProbability = [0, 1];

         $Session = $Request->Session;
         if ($Session === null) {
            throw new RuntimeException('H1 could not construct the request Session.');
         }
         $Session->set('payload', $Request->Body->raw);

         return $Response->JSON->send([
            'phase' => 'fill',
            'pid' => getmypid(),
         ]);
      }, POST);

      yield $Router->route('/h1-session-restore', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $restored = false;
         if (HTTPServerCLISessionCapacityWorkerState::$captured) {
            SessionHandler::$instance = HTTPServerCLISessionCapacityWorkerState::$Handler;
            Session::$autoUpdateTimestamp = HTTPServerCLISessionCapacityWorkerState::$autoUpdateTimestamp;
            Session::$gcProbability = HTTPServerCLISessionCapacityWorkerState::$gcProbability;
            HTTPServerCLISessionCapacityWorkerState::$captured = false;
            HTTPServerCLISessionCapacityWorkerState::$Handler = null;
            $restored = true;
         }

         return $Response->JSON->send([
            'phase' => 'restore',
            'pid' => getmypid(),
            'restored' => $restored,
         ]);
      }, GET);

      yield $Router->route('/h1-session-harness', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $restored = false;
         if (HTTPServerCLISessionCapacityWorkerState::$captured) {
            SessionHandler::$instance = HTTPServerCLISessionCapacityWorkerState::$Handler;
            Session::$autoUpdateTimestamp = HTTPServerCLISessionCapacityWorkerState::$autoUpdateTimestamp;
            Session::$gcProbability = HTTPServerCLISessionCapacityWorkerState::$gcProbability;
            HTTPServerCLISessionCapacityWorkerState::$captured = false;
            HTTPServerCLISessionCapacityWorkerState::$Handler = null;
            $restored = true;
         }

         return $Response->JSON->send([
            'phase' => 'harness',
            'pid' => getmypid(),
            'restored' => $restored,
         ]);
      }, GET);
   },

   test: static function (string $response) use ($Probe): bool|string {
      $separator = strpos($response, "\r\n\r\n");
      $harness = $separator === false
         ? null
         : json_decode(substr($response, $separator + 4), true);
      $harnessPID = is_array($harness) ? ($harness['pid'] ?? null) : null;
      if (is_int($harnessPID)) {
         $Probe->postPID = $harnessPID;
      }
      $Probe->workerRestored = $Probe->workerRestored || (
         is_array($harness)
         && ($harness['restored'] ?? false) === true
      );

      if ($Probe->skip) {
         return true;
      }

      if ($Probe->error !== '') {
         Vars::$labels = ['H1 fixture evidence'];
         dump(json_encode($Probe));

         return 'H1 fixture error: ' . $Probe->error;
      }
      if (
         $Probe->control !== true
         || $Probe->prePID < 1
         || $Probe->successfulFills < 1
         || $Probe->trigger < 1
         || $Probe->capacityMarker !== true
      ) {
         return 'H1 controls did not prove healthy Session commits and the exact capacity exception: '
            . json_encode($Probe);
      }

      if ($Probe->workerTerminated) {
         Vars::$labels = ['H1 confirmed evidence'];
         dump(json_encode([
            'probe' => $Probe,
            'harness' => $harness,
         ]));

         return 'CONFIRMED H1: remote creation of unique encrypted Sessions exhausted the '
            . 'fixed Shared cache; Session::save() escaped the request boundary and terminated '
            . "worker PID {$Probe->prePID} after {$Probe->successfulFills} successful commits. "
            . 'Evidence: ' . json_encode($Probe);
      }

      if (
         $Probe->capacityResponse !== true
         || $Probe->capacityCookie
         || ! is_array($harness)
         || ($harness['phase'] ?? null) !== 'harness'
         || $Probe->postPID !== $Probe->prePID
         || $Probe->workerRestored !== true
      ) {
         return 'H1 worker survived but did not return a cookie-free bounded failure, restore globals, and keep the same-worker control: '
            . json_encode([
               'probe' => $Probe,
               'harness' => $harness,
            ]);
      }

      return true;
   },
);
