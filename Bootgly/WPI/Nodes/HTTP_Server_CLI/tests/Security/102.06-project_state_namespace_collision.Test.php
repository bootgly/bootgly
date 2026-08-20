<?php


use const Bootgly\CLI;
use Bootgly\ACI\Process\State;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\API\Projects;
use Bootgly\CLI\Terminal\Output;
use Bootgly\commands\ProjectCommand;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Isolated source-to-sink probe. The controlled process owns the exact kernel
 * lock advertised for project A/B, blocks SIGUSR2, and then invokes the real
 * ProjectCommand reload path for a distinct project, A/B itself, and the
 * registered colliding project A~B.
 */
if (
   realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)
   && ($_SERVER['argv'][1] ?? null) === '--m2-probe'
) {
   $root = (string) ($_SERVER['argv'][2] ?? '');
   $base = (string) ($_SERVER['argv'][3] ?? '');
   $result = [
      'error' => '',
      'nested' => 'A/B',
      'collision' => 'A~B',
      'distinct' => 'Control/Distinct',
      'validated' => [],
      'encoded' => [],
      'state_paths_equal' => false,
      'state_round_trip' => false,
      'locked' => false,
      'authenticated' => false,
      'negative_accepted' => null,
      'negative_delivered' => null,
      'positive_accepted' => null,
      'positive_delivered' => null,
      'attack_accepted' => null,
      'attack_delivered' => null,
      'PID' => posix_getpid(),
      'instance' => '',
   ];

   $State = null;
   $Terminal = null;
   $OriginalOutput = null;
   $maskSaved = false;
   $savedSignals = [];

   try {
      if (
         $root === ''
         || is_file("{$root}/autoboot.php") === false
         || $base === ''
         || is_dir($base) === false
      ) {
         throw new RuntimeException('M2 probe received an invalid root or fixture directory.');
      }

      $projects = "{$base}/projects";
      $storage = "{$base}/storage";
      foreach ([
         $projects,
         "{$projects}/A/B",
         "{$projects}/A~B",
         "{$projects}/Control/Distinct",
         $storage,
      ] as $directory) {
         if (is_dir($directory) === false && mkdir($directory, 0700, true) === false) {
            throw new RuntimeException("M2 probe could not create `{$directory}`.");
         }
      }

      $Registry = [
         'A/B' => ['interfaces' => ['WPI']],
         'A~B' => ['interfaces' => ['WPI']],
         'Control/Distinct' => ['interfaces' => ['WPI']],
      ];
      $registrySource = "<?php\nreturn " . var_export($Registry, true) . ";\n";
      if (
         file_put_contents(
            "{$projects}/Bootgly.projects.php",
            $registrySource,
         ) !== strlen($registrySource)
      ) {
         throw new RuntimeException('M2 probe could not publish its isolated project registry.');
      }

      define('BOOTGLY_WORKING_BASE', $base);
      define('BOOTGLY_WORKING_DIR', $base . DIRECTORY_SEPARATOR);
      define('BOOTGLY_STORAGE_BASE', $storage);
      define('BOOTGLY_STORAGE_DIR', $storage . DIRECTORY_SEPARATOR);
      putenv('BOOTGLY_ENVIRONMENT=production');
      $_SERVER['SCRIPT_FILENAME'] = '';
      require "{$root}/autoboot.php";

      $Nested = 'A/B';
      $Collision = 'A~B';
      $Distinct = 'Control/Distinct';
      $encodedNested = Projects::encode($Nested);
      $encodedCollision = Projects::encode($Collision);
      $encodedDistinct = Projects::encode($Distinct);
      $instance = (string) (45000 + (posix_getpid() % 10000));
      $result['instance'] = $instance;
      $result['validated'] = [
         'nested' => Projects::validate($Nested),
         'collision' => Projects::validate($Collision),
         'distinct' => Projects::validate($Distinct),
      ];
      $result['encoded'] = [
         'nested' => $encodedNested,
         'collision' => $encodedCollision,
         'distinct' => $encodedDistinct,
      ];

      $State = new State($encodedNested, $instance);
      $CollisionState = new State($encodedCollision, $instance);
      $result['state_paths_equal'] = $State->pidFile === $CollisionState->pidFile
         && $State->pidLockFile === $CollisionState->pidLockFile
         && $State->commandFile === $CollisionState->commandFile;

      $result['locked'] = $State->lock(LOCK_EX | LOCK_NB);
      if ($result['locked'] !== true) {
         throw new RuntimeException('M2 controlled master could not acquire its project lock.');
      }

      $PID = posix_getpid();
      $topology = [
         'master' => $PID,
         'workers' => [],
         'host' => '127.0.0.1',
         'port' => (int) $instance,
         'started' => time(),
         'status' => 'Running',
         'type' => 'WPI',
      ];
      $State->save($topology);
      $result['state_round_trip'] = $State->read() === $topology;
      $result['authenticated'] = $State->authenticate($PID);

      if (pcntl_sigprocmask(SIG_BLOCK, [SIGUSR2], $savedSignals) === false) {
         throw new RuntimeException('M2 could not block SIGUSR2 around its controlled target.');
      }
      $maskSaved = true;

      // ProjectCommand renders status cards; isolate them from the JSON evidence
      // channel without replacing any command or state implementation.
      $Terminal = CLI->Terminal;
      $OriginalOutput = $Terminal->Output;
      $CapturedOutput = new Output('php://temp');
      $Terminal->Output = $CapturedOutput;

      $Command = new ProjectCommand;

      // Negative control: a registered but non-colliding project must neither
      // discover this state nor signal its owner.
      $result['negative_accepted'] = $Command->run([
         'reload',
         $Distinct,
         $instance,
      ]);
      $negativeSignal = pcntl_sigtimedwait(
         [SIGUSR2],
         $negativeInfo,
         0,
         100_000_000,
      );
      $result['negative_delivered'] = $negativeSignal === SIGUSR2;

      // Positive control: the canonical owning project must authenticate the
      // exact State lock and deliver the reload edge.
      $result['positive_accepted'] = $Command->run([
         'reload',
         $Nested,
         $instance,
      ]);
      $positiveSignal = pcntl_sigtimedwait([SIGUSR2], $positiveInfo, 1, 0);
      $result['positive_delivered'] = $positiveSignal === SIGUSR2;

      // Attack: a different registered project name reaches the same state
      // namespace solely because slash is encoded as a literal permitted tilde.
      $result['attack_accepted'] = $Command->run([
         'reload',
         $Collision,
         $instance,
      ]);
      $attackSignal = pcntl_sigtimedwait([SIGUSR2], $attackInfo, 1, 0);
      $result['attack_delivered'] = $attackSignal === SIGUSR2;
   }
   catch (Throwable $Throwable) {
      $result['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
   }
   finally {
      if ($Terminal !== null && $OriginalOutput !== null) {
         $Terminal->Output = $OriginalOutput;
      }

      if ($maskSaved) {
         do {
            $pending = pcntl_sigtimedwait([SIGUSR2], $pendingInfo, 0, 1);
         }
         while ($pending === SIGUSR2);
      }

      if ($State instanceof State) {
         $State->clean();
      }

      if ($maskSaved) {
         pcntl_sigprocmask(SIG_SETMASK, $savedSignals);
      }
   }

   $JSON = json_encode($result, JSON_UNESCAPED_SLASHES);
   fwrite(STDOUT, 'M2_JSON:' . (is_string($JSON) ? $JSON : '{}') . PHP_EOL);
   exit($result['error'] === '' ? 0 : 1);
}


/**
 * Security PoC M2 — project state namespaces must be collision-free.
 *
 * Projects::validate() accepts both `A/B` and `A~B`, while Projects::encode()
 * maps the slash in the first name to the literal tilde in the second. The
 * real ProjectCommand reload path therefore resolves `A~B`, locates the State
 * and kernel lock owned by `A/B`, authenticates that unrelated master, and
 * sends it SIGUSR2.
 */
$probe = [];
$probeError = '';

return new Test(
   description: 'Project controls must not alias nested and literal-tilde state namespaces',
   Separator: new Separator(line: true),

   request: static function (string $hostPort, int $testIndex) use (
      &$probe,
      &$probeError,
   ): string {
      $fixture = sys_get_temp_dir()
         . '/bootgly-security-m2-project-state-'
         . bin2hex(random_bytes(8));
      $ownedFixture = false;

      $Remove = static function (string $path) use (&$Remove): void {
         if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
         }
         if (is_dir($path) === false) {
            return;
         }

         foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
               $Remove("{$path}/{$entry}");
            }
         }
         @rmdir($path);
      };

      $Process = null;
      $Pipes = [];
      try {
         if (
            function_exists('pcntl_sigprocmask') === false
            || function_exists('pcntl_sigtimedwait') === false
            || function_exists('proc_open') === false
         ) {
            throw new RuntimeException('M2 requires pcntl and proc_open.');
         }
         if (is_dir($fixture) || mkdir($fixture, 0700, true) === false) {
            throw new RuntimeException('M2 could not exclusively create its isolated fixture directory.');
         }
         $ownedFixture = true;

         $Process = proc_open(
            [
               PHP_BINARY,
               __FILE__,
               '--m2-probe',
               BOOTGLY_ROOT_DIR,
               $fixture,
            ],
            [
               0 => ['pipe', 'r'],
               1 => ['pipe', 'w'],
               2 => ['pipe', 'w'],
            ],
            $Pipes,
            $fixture,
         );
         if (is_resource($Process) === false) {
            throw new RuntimeException('M2 could not start its isolated control process.');
         }

         fclose($Pipes[0]);
         stream_set_blocking($Pipes[1], false);
         stream_set_blocking($Pipes[2], false);
         $STDOUT = '';
         $STDERR = '';
         $exitCode = -1;
         $timedOut = false;
         $deadline = microtime(true) + 12.0;
         do {
            $STDOUT .= (string) stream_get_contents($Pipes[1]);
            $STDERR .= (string) stream_get_contents($Pipes[2]);
            $status = proc_get_status($Process);
            if (($status['running'] ?? false) === false) {
               $exitCode = (int) ($status['exitcode'] ?? -1);
               break;
            }
            usleep(10_000);
         }
         while (microtime(true) < $deadline);

         if (($status['running'] ?? false) === true) {
            $timedOut = true;
            proc_terminate($Process);
            usleep(100_000);
            $status = proc_get_status($Process);
            if (($status['running'] ?? false) === true) {
               proc_terminate($Process, SIGKILL);
            }
         }

         stream_set_blocking($Pipes[1], true);
         stream_set_blocking($Pipes[2], true);
         $STDOUT .= (string) stream_get_contents($Pipes[1]);
         $STDERR .= (string) stream_get_contents($Pipes[2]);
         fclose($Pipes[1]);
         fclose($Pipes[2]);
         $closed = proc_close($Process);
         $Process = null;
         if ($exitCode < 0 && $closed >= 0) {
            $exitCode = $closed;
         }

         $record = null;
         foreach (array_reverse(preg_split('/\r?\n/', $STDOUT) ?: []) as $line) {
            if (str_starts_with($line, 'M2_JSON:')) {
               $record = json_decode(substr($line, 8), true);
               break;
            }
         }

         if ($timedOut) {
            throw new RuntimeException('M2 isolated control process exceeded 12 seconds.');
         }
         if ($exitCode !== 0 || is_array($record) === false) {
            throw new RuntimeException(
               'M2 isolated control process failed: exit=' . $exitCode
               . '; stdout=' . substr($STDOUT, 0, 1000)
               . '; stderr=' . substr($STDERR, 0, 1000)
            );
         }

         $probe = $record;
      }
      catch (Throwable $Throwable) {
         $probeError = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         if (is_resource($Process)) {
            @proc_terminate($Process, SIGKILL);
            @proc_close($Process);
         }
         foreach ($Pipes as $Pipe) {
            if (is_resource($Pipe)) {
               @fclose($Pipe);
            }
         }
         if ($ownedFixture) {
            $Remove($fixture);
         }
      }

      return "GET /m2-project-state-harness HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/m2-project-state-harness', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response->JSON->send(['phase' => 'harness']);
      }, GET);
   },

   test: static function (string $response) use (&$probe, &$probeError): bool|string {
      if ($probeError !== '') {
         return 'M2 fixture error: ' . $probeError;
      }

      $separator = strpos($response, "\r\n\r\n");
      $harness = $separator === false
         ? null
         : json_decode(substr($response, $separator + 4), true);
      if (! is_array($harness) || ($harness['phase'] ?? null) !== 'harness') {
         return 'M2 harness control did not complete: ' . json_encode([
            'probe' => $probe,
            'response' => substr($response, 0, 500),
         ]);
      }

      if (($probe['error'] ?? '') !== '') {
         return 'M2 isolated probe error: ' . (string) $probe['error'];
      }

      $controls = ($probe['validated']['nested'] ?? null) === true
         && ($probe['validated']['distinct'] ?? null) === true
         && ($probe['state_round_trip'] ?? null) === true
         && ($probe['locked'] ?? null) === true
         && ($probe['authenticated'] ?? null) === true
         && ($probe['negative_accepted'] ?? null) === false
         && ($probe['negative_delivered'] ?? null) === false
         && ($probe['positive_accepted'] ?? null) === true
         && ($probe['positive_delivered'] ?? null) === true;
      if ($controls === false) {
         return 'M2 source, negative, or positive controls failed: ' . json_encode($probe);
      }

      if (
         ($probe['attack_accepted'] ?? null) === true
         && ($probe['attack_delivered'] ?? null) === true
      ) {
         if (
            ($probe['validated']['collision'] ?? null) !== true
            || ($probe['encoded']['nested'] ?? null) !== ($probe['encoded']['collision'] ?? null)
            || ($probe['state_paths_equal'] ?? null) !== true
         ) {
            return 'M2 command reached the target without proving the namespace collision: '
               . json_encode($probe);
         }

         return 'CONFIRMED M2: registered project A~B resolved the state namespace '
            . 'owned and kernel-authenticated for A/B, and ProjectCommand delivered '
            . 'SIGUSR2 to that wrong master. Evidence: ' . json_encode($probe);
      }

      if (
         ($probe['attack_accepted'] ?? null) === false
         && ($probe['attack_delivered'] ?? null) === false
      ) {
         if (
            ($probe['validated']['collision'] ?? null) !== true
            || ($probe['encoded']['nested'] ?? null) === ($probe['encoded']['collision'] ?? null)
            || ($probe['state_paths_equal'] ?? null) !== false
         ) {
            return 'M2 command rejected the attack without proving collision-free '
               . 'registered project state identities: ' . json_encode($probe);
         }

         return true;
      }

      return 'M2 collision path produced an inconsistent command/signal result: '
         . json_encode($probe);
   },
);
