<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\AutoTLS;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;

$root = getenv('BOOTGLY_STARTUP_ROOT');
$storage = getenv('BOOTGLY_STARTUP_STORAGE');
$port = (int) getenv('BOOTGLY_STARTUP_PORT');
$gate = (int) getenv('BOOTGLY_STARTUP_GATE');
$phase = getenv('BOOTGLY_STARTUP_PHASE') ?: 'readiness';
if ($root === false || $storage === false || $port < 1 || $gate < 1) {
   exit(2);
}

define('BOOTGLY_STORAGE_BASE', $storage);
$_SERVER['SCRIPT_FILENAME'] = '';
require rtrim($root, '/') . '/autoboot.php';

final class FailingServer extends HTTP_Server_CLI
{
   public static string $phase = 'readiness';

   protected function booting (): void
   {
      if (self::$phase === 'setup') {
         throw new RuntimeException('injected Auto-TLS pre-readiness setup failure');
      }

      parent::booting();
   }

   /** @param array<string,mixed> $secure */
   public function swap (array $secure, null|array $hashes = null): bool
   {
      if (self::$phase === 'readiness' && $this->Process->level === 'child') {
         return false;
      }

      return parent::swap($secure, $hashes);
   }
}

FailingServer::$phase = $phase;
$Server = new FailingServer;
$Server->configure(
   host: '127.0.0.1',
   port: $port,
   workers: 2,
   secure: new AutoTLS(
      domains: ['localhost'],
      email: 'startup@bootgly.test',
      path: "{$storage}/autotls/",
      port: $gate,
      options: ['verify_peer' => false]
   )
);
$Server->on(
   Events::RequestReceived,
   static function ($Request, Response $Response): Response {
      return $Response->send('must-not-start');
   }
);
if ($phase === 'post-ready') {
   $Server->on(
      Events::ServerStarted,
      static function (): never {
         throw new RuntimeException('injected Auto-TLS post-readiness callback failure');
      }
   );
}
$failure = '';
try {
   $Server->start();
}
catch (RuntimeException $Exception) {
   $failure = $Exception->getMessage();
}

$instance = $Server->AutoTLS?->instance ?? '';
$lease = "{$storage}/autotls/swaps/{$instance}.owner.lock";
$LeaseProbe = $instance !== '' ? @fopen($lease, 'r+b') : false;
$exclusive = is_resource($LeaseProbe)
   && flock($LeaseProbe, LOCK_EX | LOCK_NB);
is_resource($LeaseProbe) && fclose($LeaseProbe);

$postReady = false;
if ($phase === 'post-ready') {
   $desired = $Server->AutoTLS?->Swaps->fetch();
   $postReady = str_contains($failure, 'post-readiness callback failure')
      && is_array($desired)
      && $exclusive === false
      && $Server->Process->Children->PIDs !== [];
   $Server->stop();
   $StoppedLeaseProbe = @fopen($lease, 'r+b');
   $postReady = $postReady
      && is_resource($StoppedLeaseProbe)
      && flock($StoppedLeaseProbe, LOCK_EX | LOCK_NB);
   is_resource($StoppedLeaseProbe) && fclose($StoppedLeaseProbe);
}

exit(
   (($phase === 'post-ready' && $postReady)
      || ($phase === 'setup'
         && str_contains($failure, 'pre-readiness setup failure')
         && $exclusive)
      || ($phase === 'readiness'
         && str_contains($failure, 'startup credential')
         && str_contains($failure, 'readiness barrier')
         && $exclusive))
      ? 1
      : 0
);
