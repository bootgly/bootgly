<?php

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Handlers;
use Bootgly\ACI\Logs\Handlers\File as FileHandler;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as TCPServer;


if (! class_exists('TCPServerCLIDefaultSinkProbe', false)) {
   class TCPServerCLIDefaultSinkProbe extends TCPServer
   {
      public function store (): void
      {
         parent::store();
      }
   }
}


/**
 * IMP-8: Daemon must not be a silent black hole. With no sinks configured, the
 * server installs a default File sink at storage/logs/{channel}.log pre-fork and
 * notices where records land; a configured project is never touched, non-Daemon
 * modes never install, and the server channels opt into the sinks by default.
 */
return new Test(
   description: 'Daemon installs a default log sink when none is configured (IMP-8)',
   test: new Assertions(Case: function (): Generator {
      // ! Statics survive the suite: snapshot everything store() touches
      $OldSinks = Logger::$Sinks;
      $oldSegments = Display::$segments;
      $sinkPath = BOOTGLY_STORAGE_DIR . 'logs/{channel}.log';
      $noticeFile = BOOTGLY_STORAGE_DIR . 'logs/TCP.Server.CLI.log';
      $noticeExisted = is_file($noticeFile);
      $noticeOffset = $noticeExisted ? filesize($noticeFile) : 0;

      try {
         // ? Silence local (terminal) handlers — only the sink route is under test
         Display::show(Display::NONE);

         $Probe = new TCPServerCLIDefaultSinkProbe(Modes::Daemon);

         // @@ A) Server channels opt into the sinks by default; plain loggers do not
         yield assert(
            assertion: $Probe->Logger->global === true,
            description: 'a server logger is global by default (opt-out stays possible)'
         );
         yield assert(
            assertion: (new Logger('x'))->global === false,
            description: 'a plain logger keeps the opt-in default'
         );

         // @@ B) Daemon + no sinks → the default File sink is installed
         Logger::$Sinks = null;
         $Probe->store();

         $Handler = null;
         if (Logger::$Sinks instanceof Handlers) {
            $Reflection = new ReflectionObject(Logger::$Sinks);
            foreach ($Reflection->getProperties() as $Property) {
               $value = $Property->getValue(Logger::$Sinks);
               if (is_array($value) && $value !== []) {
                  $Handler = reset($value);
                  break;
               }
            }
         }
         yield assert(
            assertion: $Handler instanceof FileHandler && $Handler->path === $sinkPath,
            description: 'store() installs one File sink at storage/logs/{channel}.log'
         );

         // @@ C) The NOTICE reached the sink itself (the sink is installed first)
         $appended = is_file($noticeFile)
            ? (string) file_get_contents($noticeFile, offset: $noticeOffset)
            : '';
         $lines = array_values(array_filter(explode("\n", trim($appended))));
         $decoded = json_decode((string) end($lines), true);
         yield assert(
            assertion: is_array($decoded)
               && $decoded['channel'] === 'TCP.Server.CLI'
               && isSet($decoded['project'])
               && str_contains((string) $decoded['message'], '{channel}'),
            description: 'the notice lands in the sink as a JSON record with provenance, '
               . 'the literal {channel} surviving templating'
         );

         // @@ D) A configured project is never touched (??= semantics)
         $Custom = new Handlers;
         Logger::$Sinks = $Custom;
         $Probe->store();
         yield assert(
            assertion: Logger::$Sinks === $Custom,
            description: 'pre-configured sinks are left exactly as the project set them'
         );

         // @@ E) Non-Daemon modes never install
         $Probe->Mode = Modes::Foreground;
         Logger::$Sinks = null;
         $Probe->store();
         yield assert(
            assertion: Logger::$Sinks === null,
            description: 'Foreground keeps sinks unset — the fallback is Daemon-only'
         );
      }
      finally {
         Logger::$Sinks = $OldSinks;
         Display::show($oldSegments);

         // @ File hygiene: drop only what this test created/appended
         if ($noticeExisted === false) {
            @unlink($noticeFile);
         }
         else if (is_file($noticeFile) && $noticeOffset > 0) {
            $Handle = fopen($noticeFile, 'r+b');
            if ($Handle !== false) {
               ftruncate($Handle, $noticeOffset);
               fclose($Handle);
            }
         }
      }
   })
);
