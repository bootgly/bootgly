<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * L2 remediation regression — Auto-TLS swap ownership must begin only after
 * the privileged launcher has changed to its runtime identity.
 *
 * The attack/control leg runs as mapped UID 0 inside an unprivileged user
 * namespace and refuses host-root execution. Runtime UID 1 owns the Auto-TLS
 * path and a `swaps` symlink aimed at a root-only canary. Real
 * HTTP_Server_CLI::configure() and a direct root publication must perform no
 * swap-path mutation. After replacing the link with a real UID-1 directory and
 * dropping effective credentials, the same Swaps object must create a private,
 * locked lease bound to its marker and request as UID 1.
 *
 * A second isolated child exercises the complete production startup ordering:
 * root configure, pre-bind and worker fork; irreversible master/worker
 * demotion; deferred publication in ready(); worker wire()/ACK; and an actual
 * TLS response. It uses kernel-selected unprivileged ports and returns control
 * only after the worker/helper are stopped and both ports can be rebound.
 */
$probe = [
   'error' => '',
   'reason' => '',
   'executed' => false,
   'uid' => -1,
   'runtime_uid' => 1,
   'runtime_denied' => false,
   'configured' => false,
   'configure_clean' => false,
   'root_request_null' => false,
   'root_request_clean' => false,
   'canary_unchanged' => false,
   'runtime_euid' => -1,
   'runtime_egid' => -1,
   'runtime_published' => false,
   'runtime_owned' => false,
   'lease_locked' => false,
   'token_bound' => false,
   'round_trip' => false,
   'same_reconfigure_lease_retained' => false,
   'clone_reconfigure_lease_retained' => false,
   'replacement_old_lease_released' => false,
   'replacement_new_lease_locked' => false,
   'rejected_old_lease_retained' => false,
   'rejected_candidate_lease_released' => false,
   'reconfigure_lease_released' => false,
   'start_error' => '',
   'start_executed' => false,
   'start_configured_euid' => -1,
   'start_deferred' => false,
   'start_master_euid' => -1,
   'start_master_egid' => -1,
   'start_worker_count' => 0,
   'start_workers_demoted' => false,
   'start_worker_ack' => false,
   'start_helper_ready' => false,
   'start_runtime_owned' => false,
   'start_lease_locked' => false,
   'start_tls_response' => false,
   'start_stopped' => false,
   'start_stop_lease_released' => false,
   'start_ports_released' => false,
];

return new Test(
   description: 'Auto-TLS swap publication is deferred across the root-to-runtime boundary',

   request: static function (string $hostPort, int $testIndex) use (&$probe): string {
      $directory = sys_get_temp_dir()
         . '/bootgly-security-l2-privilege-' . bin2hex(random_bytes(8));
      $fixture = "{$directory}/fixture";
      $result = "{$directory}/result.json";
      $victim = "{$directory}/victim.php";
      $runner = "{$directory}/run.sh";
      $Process = null;
      $Pipes = [];

      $Clean = static function (string $path) use (&$Clean): void {
         if (is_link($path) || is_dir($path) === false) {
            @unlink($path);

            return;
         }
         @chmod($path, 0700);
         foreach (@scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
               $Clean("{$path}/{$name}");
            }
         }
         @rmdir($path);
      };

      try {
         if (function_exists('proc_open') === false || mkdir($directory, 0700, true) === false) {
            throw new RuntimeException('L2 privilege fixture could not create its process boundary.');
         }

         $victimSource = <<<'VICTIM'
<?php

$root = $argv[1];
$base = $argv[2];
$result = $argv[3];

define('BOOTGLY_STORAGE_BASE', $base . '/framework-storage');
define('BOOTGLY_STORAGE_DIR', $base . '/framework-storage/');
putenv('BOOTGLY_ENVIRONMENT=production');
$_SERVER['SCRIPT_FILENAME'] = '';
require $root . '/autoboot.php';

use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\ACI\Logs\Data\Display;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\AutoTLS;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;

$data = [
   'error' => '',
   'reason' => '',
   'uid' => function_exists('posix_getuid') ? posix_getuid() : -1,
   'runtime_uid' => 1,
   'runtime_denied' => false,
   'configured' => false,
   'configure_clean' => false,
   'root_request_null' => false,
   'root_request_clean' => false,
   'canary_unchanged' => false,
   'runtime_euid' => -1,
   'runtime_egid' => -1,
   'runtime_published' => false,
   'runtime_owned' => false,
   'lease_locked' => false,
   'token_bound' => false,
   'round_trip' => false,
   'same_reconfigure_lease_retained' => false,
   'clone_reconfigure_lease_retained' => false,
   'replacement_old_lease_released' => false,
   'replacement_new_lease_locked' => false,
   'rejected_old_lease_retained' => false,
   'rejected_candidate_lease_released' => false,
   'reconfigure_lease_released' => false,
   'start_error' => '',
   'start_executed' => false,
   'start_configured_euid' => -1,
   'start_deferred' => false,
   'start_master_euid' => -1,
   'start_master_egid' => -1,
   'start_worker_count' => 0,
   'start_workers_demoted' => false,
   'start_worker_ack' => false,
   'start_helper_ready' => false,
   'start_runtime_owned' => false,
   'start_lease_locked' => false,
   'start_tls_response' => false,
   'start_stopped' => false,
   'start_stop_lease_released' => false,
   'start_ports_released' => false,
];

$Restore = static function (): bool {
   return (posix_geteuid() === 0 || posix_seteuid(0))
      && (posix_getegid() === 0 || posix_setegid(0));
};
$Drop = static function (): bool {
   return posix_setegid(1) && posix_seteuid(1);
};
$Identity = static function (array|false $status): array {
   if (is_array($status) === false) {
      return [];
   }

   return array_intersect_key($status, array_flip([
      'dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime'
   ]));
};
$Names = static function (string $path): array {
   $names = @scandir($path);
   if (is_array($names) === false) {
      return [];
   }
   $names = array_values(array_diff($names, ['.', '..']));
   sort($names);

   return $names;
};
$Own = static function (string $path, bool $directory): bool {
   clearstatcache(true, $path);
   $status = @lstat($path);
   if (
      is_array($status) === false
      || $status['uid'] !== 1
      || $status['gid'] !== 1
   ) {
      return false;
   }

   return $directory
      ? ($status['mode'] & 0170000) === 0040000 && ($status['mode'] & 0777) === 0700
      : ($status['mode'] & 0170000) === 0100000
         && ($status['mode'] & 0777) === 0600
         && $status['nlink'] === 1;
};
$Clean = static function (string $path) use (&$Clean): void {
   if (is_link($path) || is_dir($path) === false) {
      @unlink($path);

      return;
   }
   @chmod($path, 0700);
   foreach (@scandir($path) ?: [] as $name) {
      if ($name !== '.' && $name !== '..') {
         $Clean("{$path}/{$name}");
      }
   }
   @rmdir($path);
};

$Server = null;
$Secure = null;
$Swaps = null;
$Replacement = null;

try {
   if (
      function_exists('posix_seteuid') === false
      || function_exists('posix_setegid') === false
      || function_exists('lchown') === false
      || posix_getuid() !== 0
      || posix_geteuid() !== 0
   ) {
      throw new RuntimeException('L2 privilege leg did not enter mapped UID 0.');
   }
   if (
      ($daemon = posix_getpwnam('daemon')) === false
      || ($group = posix_getgrnam('daemon')) === false
      || $daemon['uid'] !== 1
      || $group['gid'] !== 1
   ) {
      throw new RuntimeException('L2 privilege leg requires daemon UID/GID 1.');
   }

   $runtimeUID = 1;
   $runtimeGID = 1;
   $TLSPath = "{$base}/autotls";
   $swaps = "{$TLSPath}/swaps";
   $canary = "{$base}/root-canary";
   $sentinel = "{$canary}/sentinel";

   if (
      @mkdir($TLSPath, 0700, true) === false
      || @mkdir($canary, 0700, true) === false
      || file_put_contents($sentinel, 'ROOT-ONLY-L2-CANARY') !== 19
      || chmod($canary, 0700) === false
      || chmod($sentinel, 0600) === false
      || lchown($TLSPath, $runtimeUID) === false
      || lchgrp($TLSPath, $runtimeGID) === false
      || chmod($TLSPath, 0700) === false
      || symlink($canary, $swaps) === false
      || lchown($swaps, $runtimeUID) === false
      || lchgrp($swaps, $runtimeGID) === false
   ) {
      throw new RuntimeException('L2 privilege leg could not install its ownership fixture.');
   }

   $sentinelBefore = $Identity(@lstat($sentinel));
   $sentinelHash = hash_file('sha256', $sentinel);
   $linkBefore = $Identity(@lstat($swaps));

   if ($Drop() === false) {
      throw new RuntimeException('L2 privilege leg could not enter runtime UID 1 for its denial control.');
   }
   $data['runtime_denied'] = @file_get_contents($sentinel) === false;
   if ($Restore() === false) {
      throw new RuntimeException('L2 privilege leg could not restore mapped root.');
   }

   $Secure = new AutoTLS(
      domains: ['localhost'],
      email: 'l2-privilege@example.test',
      staging: true,
      path: $TLSPath,
      challenges: "{$base}/challenges",
      port: 18080,
   );
   $Server = new HTTP_Server_CLI(Modes::Foreground);
   $Server->configure(
      host: '127.0.0.1',
      port: 18443,
      workers: 1,
      secure: $Secure,
      user: 'daemon',
      group: 'daemon',
   );
   $data['configured'] = true;

   $instance = $Secure->instance;
   $leaseInCanary = "{$canary}/{$instance}.owner.lock";
   $namespaceInCanary = "{$canary}/{$instance}";
   clearstatcache();
   $data['configure_clean'] = is_link($swaps)
      && readlink($swaps) === $canary
      && $Identity(@lstat($swaps)) === $linkBefore
      && $Names($canary) === ['sentinel']
      && @lstat($leaseInCanary) === false
      && @lstat($namespaceInCanary) === false
      && $Identity(@lstat($sentinel)) === $sentinelBefore
      && hash_file('sha256', $sentinel) === $sentinelHash;

   $Snapshot = $Secure->snapshot();
   if ($Snapshot === null) {
      throw new RuntimeException('L2 privilege leg did not retain a validated bootstrap snapshot.');
   }
   $Swaps = $Secure->Swaps;
   $rootAttempt = $Swaps->request($Snapshot);
   clearstatcache();
   $data['root_request_null'] = $rootAttempt === null;
   $data['root_request_clean'] = is_link($swaps)
      && readlink($swaps) === $canary
      && $Identity(@lstat($swaps)) === $linkBefore
      && $Names($canary) === ['sentinel']
      && @lstat($leaseInCanary) === false
      && @lstat($namespaceInCanary) === false
      && $Identity(@lstat($sentinel)) === $sentinelBefore
      && hash_file('sha256', $sentinel) === $sentinelHash;
   $data['canary_unchanged'] = $Identity(@lstat($sentinel)) === $sentinelBefore
      && hash_file('sha256', $sentinel) === $sentinelHash
      && file_get_contents($sentinel) === 'ROOT-ONLY-L2-CANARY';

   if (
      unlink($swaps) === false
      || mkdir($swaps, 0700) === false
      || lchown($swaps, $runtimeUID) === false
      || lchgrp($swaps, $runtimeGID) === false
      || chmod($swaps, 0700) === false
      || $Drop() === false
   ) {
      throw new RuntimeException('L2 privilege leg could not install or enter its real runtime swap directory.');
   }

   $data['runtime_euid'] = posix_geteuid();
   $data['runtime_egid'] = posix_getegid();
   $attempt = $Swaps->request($Snapshot);
   $desired = $Swaps->fetch();
   $lease = "{$swaps}/{$instance}.owner.lock";
   $namespace = "{$swaps}/{$instance}";
   $marker = "{$namespace}/owner.token";
   $request = "{$namespace}/request.json";
   $LeaseProbe = @fopen($lease, 'rb');
   $data['runtime_published'] = is_string($attempt);
   $data['runtime_owned'] = $Own($swaps, true)
      && $Own($lease, false)
      && $Own($namespace, true)
      && $Own($marker, false)
      && $Own($request, false);
   $data['lease_locked'] = is_resource($LeaseProbe)
      && flock($LeaseProbe, LOCK_EX | LOCK_NB) === false;
   is_resource($LeaseProbe) && fclose($LeaseProbe);
   $leaseToken = @file_get_contents($lease);
   $markerToken = @file_get_contents($marker);
   $data['token_bound'] = is_string($leaseToken)
      && preg_match('/^[a-f0-9]{64}$/D', $leaseToken) === 1
      && hash_equals($leaseToken, (string) $markerToken);
   $data['round_trip'] = is_string($attempt)
      && is_array($desired)
      && $desired['attempt'] === $attempt
      && $desired['generation'] === $Snapshot->generation;

   if ($Restore() === false) {
      throw new RuntimeException('L2 privilege leg could not restore mapped root after publication.');
   }
   $data['canary_unchanged'] = $data['canary_unchanged']
      && $Identity(@lstat($sentinel)) === $sentinelBefore
      && hash_file('sha256', $sentinel) === $sentinelHash;

   // A pre-start refresh may reuse the exact AutoTLS object; that must not
   // terminally release its Swaps copy. Replacing it with a different object
   // has the opposite contract: release only the retired namespace while the
   // replacement's independently committed lease remains live.
   $Server->configure(
      host: '127.0.0.1',
      port: 18443,
      workers: 1,
      secure: $Secure,
      user: 'daemon',
      group: 'daemon',
   );
   $SameLeaseProbe = @fopen($lease, 'r+b');
   $data['same_reconfigure_lease_retained'] = $Server->AutoTLS === $Secure
      && is_resource($SameLeaseProbe)
      && flock($SameLeaseProbe, LOCK_EX | LOCK_NB) === false;
   is_resource($SameLeaseProbe) && fclose($SameLeaseProbe);

   // A shallow AutoTLS clone is a distinct configuration object but shares the
   // already-instantiated Swaps owner. Replacement decisions must use lease
   // identity, or retiring the old AutoTLS would terminally release the clone's
   // installed rendezvous.
   $Clone = clone $Secure;
   $Server->configure(
      host: '127.0.0.1',
      port: 18443,
      workers: 1,
      secure: $Clone,
      user: 'daemon',
      group: 'daemon',
   );
   if ($Drop() === false) {
      throw new RuntimeException('L2 clone replacement control could not enter runtime identity.');
   }
   $cloneDesired = $Clone->Swaps->fetch();
   $CloneLeaseProbe = @fopen($lease, 'r+b');
   $data['clone_reconfigure_lease_retained'] = $Server->AutoTLS === $Clone
      && $Clone->Swaps === $Secure->Swaps
      && $cloneDesired !== null
      && is_resource($CloneLeaseProbe)
      && flock($CloneLeaseProbe, LOCK_EX | LOCK_NB) === false;
   is_resource($CloneLeaseProbe) && fclose($CloneLeaseProbe);
   if ($Restore() === false) {
      throw new RuntimeException('L2 clone replacement control could not restore mapped root.');
   }
   $Secure = $Clone;

   $Replacement = new AutoTLS(
      domains: ['localhost'],
      email: 'l2-privilege@example.test',
      staging: true,
      path: $TLSPath,
      challenges: "{$base}/challenges",
      port: 18080,
   );
   $Server->configure(
      host: '127.0.0.1',
      port: 18443,
      workers: 1,
      secure: $Replacement,
      user: 'daemon',
      group: 'daemon',
   );
   $replacementLease = "{$swaps}/{$Replacement->instance}.owner.lock";
   $OldLeaseProbe = @fopen($lease, 'r+b');
   $data['replacement_old_lease_released'] = $Server->AutoTLS === $Replacement
      && is_resource($OldLeaseProbe)
      && flock($OldLeaseProbe, LOCK_EX | LOCK_NB);
   is_resource($OldLeaseProbe) && fclose($OldLeaseProbe);

   $ReplacementSnapshot = $Replacement->snapshot();
   if ($ReplacementSnapshot === null || $Drop() === false) {
      throw new RuntimeException('L2 replacement control could not enter runtime publication.');
   }
   $replacementAttempt = $Replacement->Swaps->request($ReplacementSnapshot);
   $NewLeaseProbe = @fopen($replacementLease, 'r+b');
   $data['replacement_new_lease_locked'] = is_resource($NewLeaseProbe)
      && is_string($replacementAttempt)
      && flock($NewLeaseProbe, LOCK_EX | LOCK_NB) === false;
   is_resource($NewLeaseProbe) && fclose($NewLeaseProbe);
   if ($Restore() === false) {
      throw new RuntimeException('L2 replacement control could not restore mapped root.');
   }

   // Rejecting a different, already-published candidate must not leak its
   // lease. Force halt() to fail closed without signaling anything by naming
   // this non-child as the current helper; the active replacement remains the
   // server's configuration and must keep its own lease.
   $Rejected = new AutoTLS(
      domains: ['localhost'],
      email: 'l2-privilege@example.test',
      staging: true,
      path: $TLSPath,
      challenges: "{$base}/challenges",
      port: 18080,
   );
   $RejectedSnapshot = $Rejected->snapshot();
   if ($RejectedSnapshot === null || $Drop() === false) {
      throw new RuntimeException('L2 rejected-candidate control could not enter runtime publication.');
   }
   $rejectedAttempt = $Rejected->Swaps->request($RejectedSnapshot);
   $rejectedLease = "{$swaps}/{$Rejected->instance}.owner.lock";
   if ($Restore() === false || is_string($rejectedAttempt) === false) {
      throw new RuntimeException('L2 rejected-candidate control could not restore mapped root.');
   }
   new ReflectionProperty(HTTP_Server_CLI::class, 'helper')->setValue($Server, posix_getpid());
   new ReflectionProperty(HTTP_Server_CLI::class, 'helperReady')->setValue($Server, true);
   $rejectedFailure = '';
   try {
      $Server->configure(
         host: '127.0.0.1',
         port: 18443,
         workers: 1,
         secure: $Rejected,
         user: 'daemon',
         group: 'daemon',
      );
   }
   catch (RuntimeException $Exception) {
      $rejectedFailure = $Exception->getMessage();
   }
   $ActiveLeaseProbe = @fopen($replacementLease, 'r+b');
   $RejectedLeaseProbe = @fopen($rejectedLease, 'r+b');
   $data['rejected_old_lease_retained'] = $Server->AutoTLS === $Replacement
      && str_contains($rejectedFailure, 'previous auxiliary children')
      && is_resource($ActiveLeaseProbe)
      && flock($ActiveLeaseProbe, LOCK_EX | LOCK_NB) === false;
   $data['rejected_candidate_lease_released'] = is_resource($RejectedLeaseProbe)
      && flock($RejectedLeaseProbe, LOCK_EX | LOCK_NB);
   is_resource($ActiveLeaseProbe) && fclose($ActiveLeaseProbe);
   is_resource($RejectedLeaseProbe) && fclose($RejectedLeaseProbe);
   new ReflectionProperty(HTTP_Server_CLI::class, 'helper')->setValue($Server, 0);
   new ReflectionProperty(HTTP_Server_CLI::class, 'helperReady')->setValue($Server, false);
   unset($Rejected);

   // Retire the control object's challenge charter before forking an isolated
   // real server. The parent remains mapped root so it can clean both the
   // root-only canary and every UID-1 artifact after the child exits.
   $Server->configure(
      host: '127.0.0.1',
      port: 18443,
      workers: 1,
      secure: null,
      user: 'daemon',
      group: 'daemon',
   );
   $ReconfiguredLeaseProbe = @fopen($replacementLease, 'r+b');
   $data['reconfigure_lease_released'] = $Server->AutoTLS === null
      && is_resource($ReconfiguredLeaseProbe)
      && flock($ReconfiguredLeaseProbe, LOCK_EX | LOCK_NB);
   is_resource($ReconfiguredLeaseProbe) && fclose($ReconfiguredLeaseProbe);
   unset($Server, $Secure, $Swaps, $Replacement);

   $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
   if ($Pair === false) {
      throw new RuntimeException('L2 real-start leg could not create its result channel.');
   }
   [$Parent, $Child] = $Pair;
   $PID = pcntl_fork();
   if ($PID < 0) {
      fclose($Parent);
      fclose($Child);
      throw new RuntimeException('L2 real-start leg could not fork its isolated server.');
   }

   if ($PID === 0) {
      fclose($Parent);
      Display::show(Display::NONE);

      $start = [
         'start_error' => '',
         'start_executed' => true,
         'start_configured_euid' => -1,
         'start_deferred' => false,
         'start_master_euid' => -1,
         'start_master_egid' => -1,
         'start_worker_count' => 0,
         'start_workers_demoted' => false,
         'start_worker_ack' => false,
         'start_helper_ready' => false,
         'start_runtime_owned' => false,
         'start_lease_locked' => false,
         'start_tls_response' => false,
         'start_stopped' => false,
         'start_stop_lease_released' => false,
         'start_ports_released' => false,
      ];
      $StartServer = null;
      $StartSecure = null;
      $TLSReservation = null;
      $GateReservation = null;

      try {
         if (posix_getuid() !== 0 || posix_geteuid() !== 0) {
            throw new RuntimeException('L2 real-start child did not retain mapped UID 0.');
         }

         $Reserve = static function (): array {
            $code = 0;
            $message = '';
            $Socket = @stream_socket_server(
               'tcp://127.0.0.1:0',
               $code,
               $message
            );
            $name = is_resource($Socket)
               ? stream_socket_get_name($Socket, false)
               : false;
            $separator = is_string($name) ? strrpos($name, ':') : false;
            $port = $separator !== false
               ? (int) substr($name, $separator + 1)
               : 0;
            if (is_resource($Socket) === false || $port < 1024) {
               is_resource($Socket) && fclose($Socket);
               throw new RuntimeException(
                  "L2 real-start child could not reserve an unprivileged port: {$message} ({$code})."
               );
            }

            return [$Socket, $port];
         };

         [$TLSReservation, $TLSPort] = $Reserve();
         [$GateReservation, $gatePort] = $Reserve();
         $startPath = "{$base}/start-autotls";
         $StartSecure = new AutoTLS(
            domains: ['localhost'],
            email: 'l2-real-start@example.test',
            staging: true,
            path: $startPath,
            challenges: "{$startPath}/challenges",
            port: $gatePort,
            options: ['verify_peer' => false],
         );
         $StartServer = new HTTP_Server_CLI(Modes::Test);
         $StartServer->configure(
            host: '127.0.0.1',
            port: $TLSPort,
            workers: 1,
            secure: $StartSecure,
            user: 'daemon',
            group: 'daemon',
         );
         $StartServer->on(
            Events::RequestReceived,
            static function ($Request, Response $Response): Response {
               return $Response->send('L2 real-start worker response');
            }
         );
         // This isolated child supplies its own handler; do not replace it by
         // booting the repository's E2E application during start().
         HTTP_Server_CLI::$Application = null;
         HTTP_Server_CLI::$Encoder = new Encoder_;

         $start['start_configured_euid'] = posix_geteuid();
         $start['start_deferred'] = @lstat("{$startPath}/swaps") === false;

         fclose($TLSReservation);
         fclose($GateReservation);
         $TLSReservation = null;
         $GateReservation = null;

         if ($StartServer->start() !== true) {
            throw new RuntimeException('L2 real-start child did not return from the startup barrier.');
         }

         $start['start_master_euid'] = posix_geteuid();
         $start['start_master_egid'] = posix_getegid();
         $Workers = $StartServer->Process->Children->PIDs;
         $start['start_worker_count'] = count($Workers);
         $start['start_workers_demoted'] = count($Workers) === 1;
         foreach ($Workers as $WorkerPID) {
            $status = @file_get_contents("/proc/{$WorkerPID}/status");
            $matched = is_string($status)
               && preg_match('/^Uid:\\s+1\\s+1\\s+1\\s+1$/m', $status) === 1
               && preg_match('/^Gid:\\s+1\\s+1\\s+1\\s+1$/m', $status) === 1;
            $start['start_workers_demoted'] = $start['start_workers_demoted'] && $matched;
         }

         $StartSnapshot = $StartSecure->snapshot();
         $desired = $StartSecure->Swaps->fetch();
         $ACKs = is_array($desired) && $StartSnapshot !== null
            ? $StartSecure->Swaps->collect(
               $StartSnapshot->generation,
               $desired['attempt']
            )
            : [];
         $start['start_worker_ack'] = count($ACKs) === count($Workers)
            && count($Workers) === 1;
         foreach ($Workers as $WorkerPID) {
            $ACK = $ACKs[$WorkerPID] ?? null;
            $start['start_worker_ack'] = $start['start_worker_ack']
               && is_array($ACK)
               && $ACK['success'] === true
               && $StartSnapshot !== null
               && $ACK['generation'] === $StartSnapshot->generation
               && $ACK['certificateHash'] === $StartSnapshot->certificateHash
               && $ACK['keyHash'] === $StartSnapshot->keyHash;
         }
         $start['start_helper_ready'] = $StartServer->helperReady
            && $StartServer->helper > 1;

         $startInstance = $StartSecure->instance;
         $startSwaps = "{$startPath}/swaps";
         $startLease = "{$startSwaps}/{$startInstance}.owner.lock";
         $startNamespace = "{$startSwaps}/{$startInstance}";
         $ownership = [
            $startSwaps => $Own($startSwaps, true),
            $startLease => $Own($startLease, false),
            $startNamespace => $Own($startNamespace, true),
            "{$startNamespace}/owner.token" => $Own("{$startNamespace}/owner.token", false),
            "{$startNamespace}/request.json" => $Own("{$startNamespace}/request.json", false),
         ];
         foreach ($Workers as $WorkerPID) {
            $ACKFile = is_array($desired)
               ? "{$startNamespace}/{$desired['generation']}/{$desired['attempt']}/{$WorkerPID}.json"
               : '';
            $ownership[$ACKFile] = $ACKFile !== '' && $Own($ACKFile, false);
         }
         $start['start_runtime_owned'] = in_array(false, $ownership, true) === false;
         if ($start['start_runtime_owned'] === false) {
            $failedOwnership = [];
            foreach ($ownership as $path => $owned) {
               if ($owned === false) {
                  $failedOwnership[$path] = @lstat($path);
               }
            }
            throw new RuntimeException(
               'L2 real-start runtime ownership mismatch: '
               . json_encode($failedOwnership, JSON_UNESCAPED_SLASHES)
            );
         }
         $LeaseProbe = @fopen($startLease, 'rb');
         $start['start_lease_locked'] = is_resource($LeaseProbe)
            && flock($LeaseProbe, LOCK_EX | LOCK_NB) === false;
         is_resource($LeaseProbe) && fclose($LeaseProbe);

         $Context = stream_context_create([
            'ssl' => [
               'verify_peer' => false,
               'verify_peer_name' => false,
               'allow_self_signed' => true,
            ],
         ]);
         $Client = @stream_socket_client(
            "ssl://127.0.0.1:{$TLSPort}",
            $code,
            $message,
            5,
            STREAM_CLIENT_CONNECT,
            $Context
         );
         $HTTPResponse = '';
         if (is_resource($Client)) {
            stream_set_timeout($Client, 5);
            fwrite(
               $Client,
               "GET /l2-real-start HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n"
            );
            while (feof($Client) === false) {
               $chunk = fread($Client, 8192);
               if ($chunk === false || $chunk === '') {
                  break;
               }
               $HTTPResponse .= $chunk;
            }
            fclose($Client);
         }
         $start['start_tls_response'] = str_contains($HTTPResponse, 'HTTP/1.1 200 OK')
            && str_contains($HTTPResponse, 'L2 real-start worker response');

         $StartServer->stop();
         // stop() returns to this Test-mode process. It must itself close the
         // master's lease after terminating workers/helper; a manual release
         // here would mask the production lifecycle regression being tested.
         $StoppedLeaseProbe = @fopen($startLease, 'r+b');
         $start['start_stop_lease_released'] = is_resource($StoppedLeaseProbe)
            && flock($StoppedLeaseProbe, LOCK_EX | LOCK_NB);
         is_resource($StoppedLeaseProbe) && fclose($StoppedLeaseProbe);
         $StartSecure->Swaps->release();
         $start['start_stopped'] = $StartServer->Process->Children->PIDs === []
            && $StartServer->helper === 0;

         $TLSProbe = @stream_socket_server("tcp://127.0.0.1:{$TLSPort}");
         $GateProbe = @stream_socket_server("tcp://127.0.0.1:{$gatePort}");
         $start['start_ports_released'] = is_resource($TLSProbe)
            && is_resource($GateProbe);
         is_resource($TLSProbe) && fclose($TLSProbe);
         is_resource($GateProbe) && fclose($GateProbe);
      }
      catch (Throwable $Throwable) {
         $start['start_error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         is_resource($TLSReservation) && fclose($TLSReservation);
         is_resource($GateReservation) && fclose($GateReservation);
         if (
            $StartServer instanceof HTTP_Server_CLI
            && $StartServer->Process->stopping === false
         ) {
            try {
               $StartServer->stop();
            }
            catch (Throwable) {
               $StartServer->Process->Children->terminate();
               $StartServer->Process->State->clean();
            }
         }
         if ($StartSecure instanceof AutoTLS) {
            $StartSecure->Swaps->release();
         }
         $JSON = json_encode($start, JSON_UNESCAPED_SLASHES);
         if (is_string($JSON)) {
            @fwrite($Child, $JSON);
         }
         fclose($Child);
      }

      exit($start['start_error'] === '' ? 0 : 1);
   }

   fclose($Child);
   stream_set_blocking($Parent, false);
   $payload = '';
   $status = 0;
   $reaped = 0;
   $processError = '';
   $deadline = microtime(true) + 30.0;
   do {
      $chunk = stream_get_contents($Parent);
      if ($chunk !== false) {
         $payload .= $chunk;
      }
      $reaped = pcntl_waitpid($PID, $status, WNOHANG);
      if ($reaped === $PID || $reaped === -1) {
         break;
      }
      usleep(10000);
   }
   while (microtime(true) < $deadline);

   if ($reaped === 0) {
      $processError = 'L2 real-start child exceeded its bounded deadline.';
      posix_kill($PID, SIGTERM);
      $stopDeadline = microtime(true) + 2.0;
      do {
         $reaped = pcntl_waitpid($PID, $status, WNOHANG);
         if ($reaped !== 0) {
            break;
         }
         usleep(10000);
      }
      while (microtime(true) < $stopDeadline);
   }
   if ($reaped === 0) {
      posix_kill($PID, SIGKILL);
      pcntl_waitpid($PID, $status);
   }
   $chunk = stream_get_contents($Parent);
   if ($chunk !== false) {
      $payload .= $chunk;
   }
   fclose($Parent);

   $decoded = json_decode($payload, true);
   if (is_array($decoded)) {
      foreach ($decoded as $key => $value) {
         if (array_key_exists($key, $data)) {
            $data[$key] = $value;
         }
      }
   }
   else if ($data['start_error'] === '') {
      $data['start_error'] = 'L2 real-start child returned no valid result.';
   }
   if (
      $processError === ''
      && (
         $reaped !== $PID
         || pcntl_wifexited($status) === false
         || pcntl_wexitstatus($status) !== 0
      )
   ) {
      $processError = 'L2 real-start child did not exit cleanly.';
   }
   if ($processError !== '' && $data['start_error'] === '') {
      $data['start_error'] = $processError;
   }
}
catch (Throwable $Throwable) {
   $data['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
}
finally {
   if ($Restore() === false && $data['error'] === '') {
      $data['error'] = 'L2 privilege leg could not restore mapped root in cleanup.';
   }
   $JSON = json_encode($data, JSON_UNESCAPED_SLASHES);
   if (is_string($JSON)) {
      @file_put_contents($result, $JSON);
   }
   unset($Server, $Secure, $Swaps, $Replacement);
   $Clean($base);
}

exit($data['error'] === '' ? 0 : 1);
VICTIM;

         $runnerSource = <<<'RUNNER'
#!/usr/bin/env bash
set -u

ROOT=$1
BASE=$2
RESULT=$3
VICTIM=$4
NSPID=''

cleanup() {
   if [ -n "$NSPID" ]; then
      kill "$NSPID" 2>/dev/null || true
      wait "$NSPID" 2>/dev/null || true
   fi
}
trap cleanup EXIT HUP INT TERM

if [ "$(id -u)" = "0" ]; then
   echo "host-root-refused"
   exit 3
fi
for command in unshare newuidmap newgidmap; do
   command -v "$command" >/dev/null 2>&1 || { echo "missing-$command"; exit 3; }
done
grep -q "^$(id -un):" /etc/subuid 2>/dev/null || { echo "no-subuid"; exit 3; }
grep -q "^$(id -un):" /etc/subgid 2>/dev/null || { echo "no-subgid"; exit 3; }

SUB_UID=$(grep "^$(id -un):" /etc/subuid | head -1 | cut -d: -f2)
SUB_UID_N=$(grep "^$(id -un):" /etc/subuid | head -1 | cut -d: -f3)
SUB_GID=$(grep "^$(id -un):" /etc/subgid | head -1 | cut -d: -f2)
SUB_GID_N=$(grep "^$(id -un):" /etc/subgid | head -1 | cut -d: -f3)

mkdir -p "$BASE"
FIFO="$BASE/.gate"
mkfifo "$FIFO" || exit 3
unshare --user bash -c "read gate < '$FIFO'; exec php '$VICTIM' '$ROOT' '$BASE' '$RESULT'" &
NSPID=$!
for _ in $(seq 1 100); do
   [ -e "/proc/$NSPID/uid_map" ] && break
   sleep 0.05
done
newuidmap "$NSPID" 0 "$(id -u)" 1 1 "$SUB_UID" "$SUB_UID_N" || exit 3
newgidmap "$NSPID" 0 "$(id -g)" 1 1 "$SUB_GID" "$SUB_GID_N" || exit 3
echo gate > "$FIFO"
wait "$NSPID"
STATUS=$?
NSPID=''
exit "$STATUS"
RUNNER;

         if (
            file_put_contents($victim, $victimSource) !== strlen($victimSource)
            || file_put_contents($runner, $runnerSource) !== strlen($runnerSource)
            || chmod($runner, 0700) === false
            || chmod($directory, 0711) === false
         ) {
            throw new RuntimeException('L2 privilege fixture could not install its child programs.');
         }

         $Descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
         ];
         $Process = proc_open(
            ['/bin/bash', $runner, BOOTGLY_ROOT_DIR, $fixture, $result, $victim],
            $Descriptors,
            $Pipes,
            $directory,
         );
         if (is_resource($Process) === false) {
            throw new RuntimeException('L2 privilege fixture could not launch its mapped-root leg.');
         }

         stream_set_blocking($Pipes[1], false);
         stream_set_blocking($Pipes[2], false);
         $output = '';
         $status = ['running' => true];
         $deadline = microtime(true) + 60.0;
         do {
            foreach ([1, 2] as $index) {
               $chunk = stream_get_contents($Pipes[$index]);
               if ($chunk !== false) {
                  $output .= $chunk;
               }
            }
            $status = proc_get_status($Process);
            if (($status['running'] ?? false) === false) {
               break;
            }
            usleep(50000);
         }
         while (microtime(true) < $deadline);

         if (($status['running'] ?? false) === true) {
            proc_terminate($Process);
            usleep(200000);
         }
         foreach ([1, 2] as $index) {
            $chunk = stream_get_contents($Pipes[$index]);
            if ($chunk !== false) {
               $output .= $chunk;
            }
            fclose($Pipes[$index]);
            unset($Pipes[$index]);
         }
         proc_close($Process);
         $Process = null;

         if (is_file($result) === false) {
            $probe['reason'] = trim($output) !== ''
               ? trim($output)
               : 'mapped-root leg produced no result';
         }
         else {
            $decoded = json_decode((string) file_get_contents($result), true);
            if (is_array($decoded) === false) {
               throw new RuntimeException('L2 privilege fixture returned invalid JSON.');
            }
            foreach ($decoded as $key => $value) {
               if (array_key_exists($key, $probe)) {
                  $probe[$key] = $value;
               }
            }
            $probe['executed'] = ($decoded['error'] ?? '') === '';
         }
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         foreach ($Pipes as $Pipe) {
            is_resource($Pipe) && fclose($Pipe);
         }
         if (is_resource($Process)) {
            proc_terminate($Process);
            proc_close($Process);
         }
         $Clean($directory);
      }

      return "GET /l2-acme-swap-privilege HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response): Response {
      return $Response(body: 'L2 privilege handler control');
   },

   test: static function (string $response) use (&$probe): bool|string {
      if (
         str_contains($response, 'HTTP/1.1 200 OK') === false
         || str_contains($response, 'L2 privilege handler control') === false
      ) {
         return 'L2 privilege fixture did not traverse the selected native HTTP handler.';
      }
      if ($probe['error'] !== '') {
         return 'L2 privilege fixture error: ' . $probe['error'];
      }
      if ($probe['executed'] !== true) {
         return 'L2 privilege mapped-root leg did not execute (' . $probe['reason'] . ').';
      }
      if (
         $probe['uid'] !== 0
         || $probe['runtime_uid'] !== 1
         || $probe['runtime_denied'] !== true
         || $probe['configured'] !== true
      ) {
         return 'L2 privilege-boundary controls failed: ' . json_encode($probe);
      }
      if (
         $probe['configure_clean'] !== true
         || $probe['root_request_null'] !== true
         || $probe['root_request_clean'] !== true
         || $probe['canary_unchanged'] !== true
      ) {
         return 'L2 privileged configure/request touched runtime-owned swap state: '
            . json_encode($probe);
      }
      if (
         $probe['runtime_euid'] !== 1
         || $probe['runtime_egid'] !== 1
         || $probe['runtime_published'] !== true
         || $probe['runtime_owned'] !== true
         || $probe['lease_locked'] !== true
         || $probe['token_bound'] !== true
         || $probe['round_trip'] !== true
         || $probe['same_reconfigure_lease_retained'] !== true
         || $probe['clone_reconfigure_lease_retained'] !== true
         || $probe['replacement_old_lease_released'] !== true
         || $probe['replacement_new_lease_locked'] !== true
         || $probe['rejected_old_lease_retained'] !== true
         || $probe['rejected_candidate_lease_released'] !== true
         || $probe['reconfigure_lease_released'] !== true
      ) {
         return 'L2 runtime publication controls failed: ' . json_encode($probe);
      }
      if ($probe['start_error'] !== '') {
         return 'L2 mapped-root real-start fixture error: ' . $probe['start_error'];
      }
      if (
         $probe['start_executed'] !== true
         || $probe['start_configured_euid'] !== 0
         || $probe['start_deferred'] !== true
         || $probe['start_master_euid'] !== 1
         || $probe['start_master_egid'] !== 1
         || $probe['start_worker_count'] !== 1
         || $probe['start_workers_demoted'] !== true
         || $probe['start_worker_ack'] !== true
         || $probe['start_helper_ready'] !== true
         || $probe['start_runtime_owned'] !== true
         || $probe['start_lease_locked'] !== true
         || $probe['start_tls_response'] !== true
         || $probe['start_stopped'] !== true
         || $probe['start_stop_lease_released'] !== true
         || $probe['start_ports_released'] !== true
      ) {
         return 'L2 real start/demotion/worker-ACK controls failed: '
            . json_encode($probe);
      }

      return true;
   },
);
