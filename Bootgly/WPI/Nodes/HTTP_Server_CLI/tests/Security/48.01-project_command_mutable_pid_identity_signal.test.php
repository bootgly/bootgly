<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Process\State;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\API\Projects;
use Bootgly\commands\ProjectCommand;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC M14 — mutable PID state must not select the process signaled
 * by an operator project command without authenticating its process identity.
 *
 * The setup creates one unique, benign, current-schema state document and a
 * controlled child holding that qualifier's exact lock. The attacker phase
 * rewrites the existing regular PID inode with the complete live port-8081
 * topology, changing only its port/qualifier. The target test runner is the
 * real 8081 master and does not hold the fake lock. ProjectCommand traverses
 * its real run(reload) -> scan -> locate -> authenticate -> posix_kill(SIGUSR2)
 * path. The test blocks/consumes that signal synchronously and restores the
 * mask; no ambient PID is targeted and no production handler executes.
 */
$evidence = [];
$probeError = '';

return new Specification(
   description: 'Project controls must authenticate mutable PID state before signaling a process',
   Separator: new Separator(line: true),

   request: static function (string $hostPort, int $testIndex) use (
      &$evidence,
      &$probeError,
   ): string {
      $projectName = 'Demo/HTTP_Server_CLI';
      $encoded = Projects::encode($projectName);
      $pidsDir = BOOTGLY_STORAGE_DIR . 'pids/';
      $instance = null;

      // @ Select a numeric, port-shaped qualifier whose three State paths do
      //   not pre-exist. Setup may create it; attacker simulation later only
      //   rewrites the already-created PID inode.
      $base = 60000 + (getmypid() % 4000);
      for ($offset = 0; $offset < 1000; $offset++) {
         $candidate = (string) (60000 + (($base - 60000 + $offset) % 5000));
         $prefix = $pidsDir . $encoded . '.' . $candidate;
         $paths = [
            $prefix . '.json',
            $prefix . '.lock',
            $prefix . '.command',
         ];
         $available = true;
         foreach ($paths as $path) {
            if (file_exists($path) || is_link($path)) {
               $available = false;
               break;
            }
         }
         if ($available) {
            $instance = $candidate;
            break;
         }
      }

      if ($instance === null) {
         $probeError = 'No unused numeric State qualifier was available for M14.';

         return "GET /m14/harness HTTP/1.1\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "Host: localhost\r\nConnection: close\r\n\r\n";
      }

      $State = null;
      $HolderPID = 0;
      $Pair = null;
      $savedSignals = [];
      $signalMaskSaved = false;

      try {
         if (
            ! function_exists('pcntl_sigprocmask')
            || ! function_exists('pcntl_sigtimedwait')
            || ! function_exists('posix_kill')
         ) {
            throw new RuntimeException('M14 requires pcntl and posix signal support.');
         }

         $State = new State($encoded, $instance);
         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
         if ($Pair === false) {
            throw new RuntimeException('M14 could not create its lock-holder control pair.');
         }
         $HolderPID = pcntl_fork();
         if ($HolderPID === -1) {
            throw new RuntimeException('M14 could not fork its controlled lock holder.');
         }
         if ($HolderPID === 0) {
            fclose($Pair[0]);
            $Holder = new State($encoded, $instance);
            $held = $Holder->lock(LOCK_EX | LOCK_NB);
            fwrite($Pair[1], $held ? 'ready' : 'fail!');
            if ($held) {
               fread($Pair[1], 1);
               $Holder->detach();
            }
            fclose($Pair[1]);
            exit($held ? 0 : 1);
         }
         fclose($Pair[1]);
         stream_set_timeout($Pair[0], 2);
         $lockHeld = fread($Pair[0], 5) === 'ready';

         $benign = [
            'master' => 0,
            'workers' => [],
            'host' => '127.0.0.1',
            'port' => (int) $instance,
            'started' => time(),
            'type' => 'WPI',
         ];
         $State->save($benign);

         $before = @lstat($State->pidFile);
         $lockBefore = @lstat($State->pidLockFile);
         $initial = $State->read();

         $targetPID = posix_getpid();
         $activeFile = $pidsDir . $encoded . '.8081.json';
         $active = json_decode((string) @file_get_contents($activeFile), true);
         $targetDifferentInstance = is_array($active)
            && ($active['master'] ?? null) === $targetPID
            && ($active['port'] ?? null) === 8081
            && $instance !== '8081';

         $signalBlocked = pcntl_sigprocmask(
            SIG_BLOCK,
            [SIGCHLD, SIGUSR2],
            $savedSignals,
         );
         if ($signalBlocked === false) {
            throw new RuntimeException('M14 could not preserve the process signal mask.');
         }
         $signalMaskSaved = true;

         $ProjectCommand = new ProjectCommand;
         $authenticAccepted = $ProjectCommand->run([
            'reload',
            $projectName,
            '8081',
         ]);
         $authenticSignal = pcntl_sigtimedwait([SIGUSR2], $authenticInfo, 1, 0);

         $negativeAccepted = $ProjectCommand->run([
            'reload',
            $projectName,
            $instance,
         ]);
         $negativeSignal = pcntl_sigtimedwait([SIGUSR2], $negativeInfo, 0, 1000000);

         if (is_array($active) === false) {
            throw new RuntimeException('M14 could not read the legitimate instance topology.');
         }
         $forged = $active;
         $forged['port'] = (int) $instance;
         $JSON = json_encode($forged);
         if (! is_string($JSON)) {
            throw new RuntimeException('M14 could not encode its complete forged PID state.');
         }
         $written = @file_put_contents($State->pidFile, $JSON, LOCK_EX);
         clearstatcache(true, $State->pidFile);
         $after = @lstat($State->pidFile);
         $lockAfter = @lstat($State->pidLockFile);
         $decoded = json_decode((string) @file_get_contents($State->pidFile), true);

         $sameInode = is_array($before) && is_array($after)
            && $before['dev'] === $after['dev']
            && $before['ino'] === $after['ino'];
         $targetHasLock = false;
         foreach (glob("/proc/{$targetPID}/fd/*") ?: [] as $FD) {
            $opened = @stat($FD);
            if (
               is_array($opened)
               && is_array($lockBefore)
               && $opened['dev'] === $lockBefore['dev']
               && $opened['ino'] === $lockBefore['ino']
            ) {
               $targetHasLock = true;
               break;
            }
         }
         $sameLock = $lockHeld
            && is_array($lockBefore)
            && is_array($lockAfter)
            && $lockBefore['dev'] === $lockAfter['dev']
            && $lockBefore['ino'] === $lockAfter['ino'];
         $stateOwned = is_array($after)
            && (int) $after['uid'] === posix_geteuid();
         $stateForged = $written === strlen($JSON)
            && is_array($decoded)
            && $decoded === $forged
            && ($decoded['master'] ?? null) === $targetPID
            && ($decoded['port'] ?? null) === (int) $instance;

         $attackAccepted = $ProjectCommand->run([
            'reload',
            $projectName,
            $instance,
         ]);
         $attackSignal = pcntl_sigtimedwait([SIGUSR2], $attackInfo, 1, 0);

         $controlSent = posix_kill($targetPID, SIGUSR2);
         $controlSignal = pcntl_sigtimedwait([SIGUSR2], $controlInfo, 1, 0);

         $evidence = [
            'project_valid' => Projects::validate($projectName),
            'numeric_instance' => ctype_digit($instance)
               && (int) $instance === ($benign['port'] ?? -1),
            'initial_schema' => $initial === $benign,
            'state_owned' => $stateOwned,
            'same_inode' => $sameInode,
            'same_lock' => $sameLock,
            'target_lock_absent' => $targetHasLock === false,
            'state_forged' => $stateForged,
            'target_pid_current' => $targetPID === getmypid(),
            'target_different_instance' => $targetDifferentInstance,
            'signal_blocked' => $signalBlocked,
            'control_sent' => $controlSent,
            'control_signal' => $controlSignal === SIGUSR2,
            'control_sender' => ($controlInfo['pid'] ?? null) === $targetPID
               && ($controlInfo['uid'] ?? null) === posix_geteuid(),
            'authentic_accepted' => $authenticAccepted,
            'authentic_signal' => $authenticSignal === SIGUSR2,
            'authentic_sender' => ($authenticInfo['pid'] ?? null) === $targetPID
               && ($authenticInfo['uid'] ?? null) === posix_geteuid(),
            'negative_rejected' => $negativeAccepted === false,
            'negative_signal' => $negativeSignal === SIGUSR2,
            'attack_accepted' => $attackAccepted,
            'attack_signal' => $attackSignal === SIGUSR2,
            'attack_sender' => ($attackInfo['pid'] ?? null) === $targetPID
               && ($attackInfo['uid'] ?? null) === posix_geteuid(),
         ];
      }
      catch (Throwable $Throwable) {
         $probeError = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         if ($signalMaskSaved) {
            do {
               $pendingSignal = pcntl_sigtimedwait(
                  [SIGUSR2],
                  $pendingInfo,
                  0,
                  1000000,
               );
            }
            while ($pendingSignal === SIGUSR2);

            pcntl_sigprocmask(SIG_SETMASK, $savedSignals);

            $restoredSignals = [];
            pcntl_sigprocmask(SIG_BLOCK, [SIGCHLD, SIGUSR2], $restoredSignals);
            pcntl_sigprocmask(SIG_SETMASK, $savedSignals);
            sort($savedSignals);
            sort($restoredSignals);
            $evidence['mask_restored'] = $restoredSignals === $savedSignals;
         }
         if ($State instanceof State) {
            if (is_array($Pair) && is_resource($Pair[0] ?? null)) {
               @fwrite($Pair[0], 'x');
               @fclose($Pair[0]);
            }
            if ($HolderPID > 0) {
               pcntl_waitpid($HolderPID, $holderStatus);
            }
            @unlink($State->pidFile);
            @unlink($State->commandFile);
            @unlink($State->pidLockFile);
         }
      }

      return "GET /m14/harness HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/m14/harness', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(body: 'M14-HARNESS-OK');
      }, GET);
   },

   test: static function (string $response) use (&$evidence, &$probeError): bool|string {
      if (! str_contains($response, 'M14-HARNESS-OK')) {
         return 'M14 HTTP harness did not complete after the process-signal probe.';
      }
      if ($probeError !== '') {
         return 'M14 fixture failed: ' . $probeError;
      }

      $controls = ($evidence['project_valid'] ?? false) === true
         && ($evidence['numeric_instance'] ?? false) === true
         && ($evidence['initial_schema'] ?? false) === true
         && ($evidence['state_owned'] ?? false) === true
         && ($evidence['same_inode'] ?? false) === true
         && ($evidence['same_lock'] ?? false) === true
         && ($evidence['target_lock_absent'] ?? false) === true
         && ($evidence['state_forged'] ?? false) === true
         && ($evidence['target_pid_current'] ?? false) === true
         && ($evidence['target_different_instance'] ?? false) === true
         && ($evidence['signal_blocked'] ?? false) === true
         && ($evidence['control_sent'] ?? false) === true
         && ($evidence['control_signal'] ?? false) === true
         && ($evidence['control_sender'] ?? false) === true
         && ($evidence['authentic_accepted'] ?? false) === true
         && ($evidence['authentic_signal'] ?? false) === true
         && ($evidence['authentic_sender'] ?? false) === true
         && ($evidence['negative_rejected'] ?? false) === true
         && ($evidence['negative_signal'] ?? true) === false
         && ($evidence['mask_restored'] ?? false) === true;
      if ($controls === false) {
         Vars::$labels = ['M14 safe fixture/source controls'];
         dump(json_encode($evidence));

         return 'M14 fixture did not prove the mutable-state source, cross-instance target, signal handler, and negative path. Evidence: '
            . json_encode($evidence);
      }

      $vulnerable = ($evidence['attack_accepted'] ?? false) === true
         && ($evidence['attack_signal'] ?? false) === true
         && ($evidence['attack_sender'] ?? false) === true;
      if ($vulnerable) {
         Vars::$labels = ['M14 safe vulnerability evidence'];
         dump(json_encode($evidence));

         return 'CONFIRMED M14: an in-place rewrite of runtime-owned PID state made the real project reload command send SIGUSR2 to a process belonging to a different instance.';
      }

      $secure = ($evidence['attack_accepted'] ?? true) === false
         && ($evidence['attack_signal'] ?? true) === false;
      if ($secure === false) {
         Vars::$labels = ['M14 incomplete security evidence'];
         dump(json_encode($evidence));

         return 'M14 probe produced neither the complete vulnerable path nor authenticated PID-state rejection.';
      }

      return true;
   },
);
