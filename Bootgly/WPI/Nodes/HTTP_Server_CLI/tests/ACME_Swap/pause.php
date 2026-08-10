<?php

use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;

$root = getenv('BOOTGLY_PAUSE_ROOT');
$storage = getenv('BOOTGLY_PAUSE_STORAGE');
$counter = getenv('BOOTGLY_PAUSE_COUNTER');
$journal = getenv('BOOTGLY_PAUSE_JOURNAL');
$port = (int) getenv('BOOTGLY_PAUSE_PORT');
if ($root === false || $storage === false || $counter === false || $journal === false || $port < 1) {
   exit(2);
}

define('BOOTGLY_STORAGE_BASE', $storage);
$_SERVER['SCRIPT_FILENAME'] = '';
require rtrim($root, '/') . '/autoboot.php';

final class PausableServer extends HTTP_Server_CLI
{
   protected function tick (): void
   {
      $counter = (string) getenv('BOOTGLY_PAUSE_COUNTER');
      $current = (int) @file_get_contents($counter);
      file_put_contents($counter, (string) ($current + 1), LOCK_EX);

      parent::tick();
   }

   public function pause (): bool
   {
      $this->record('pause');

      return parent::pause();
   }

   public function resume (): bool
   {
      $this->record('resume');

      return parent::resume();
   }

   /** Journal the MASTER's lifecycle transitions — workers keep their own copy quiet. */
   private function record (string $event): void
   {
      if ($this->Process->level !== 'master') {
         return;
      }

      file_put_contents(
         (string) getenv('BOOTGLY_PAUSE_JOURNAL'),
         "{$event}\n",
         FILE_APPEND | LOCK_EX
      );
   }
}

$Server = new PausableServer(Modes::Interactive);
$Server->configure(host: '127.0.0.1', port: $port, workers: 1);
$Server->on(
   Events::RequestReceived,
   static function ($Request, Response $Response): Response {
      return $Response->send('pausable');
   }
);
$Server->start();
