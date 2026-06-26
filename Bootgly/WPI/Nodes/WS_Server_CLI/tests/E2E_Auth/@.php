<?php

namespace Bootgly\WPI\Nodes\WS_Server_CLI\tests\E2E_Auth;


use const BOOTGLY_ROOT_DIR;
use function define;
use function defined;
use function json_encode;
use function usleep;

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Suite;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Basic;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating\Guard;
use Bootgly\WPI\Nodes\WS_Server_CLI;
use Bootgly\WPI\Nodes\WS_Server_CLI\Events;
use Bootgly\WPI\Nodes\WS_Server_CLI\tests\E2E\Client;

require_once __DIR__ . '/../E2E/Client.php';


return new Suite(
   // * Config
   autoBoot: function (Suite|null $Suite = null): true {
      Display::show(Display::NONE);

      if ( ! defined('BOOTGLY_PROJECT') ) {
         $projectFile = BOOTGLY_ROOT_DIR . 'projects/Demo/WS_Server_CLI/WS_Server_CLI.project.php';
         $TestProject = require $projectFile;
         define('BOOTGLY_PROJECT', $TestProject);
      }

      // @ Bearer guard: token `tok` -> exposes identity + claims; announces a
      //   `WWW-Authenticate: Bearer` challenge on denial.
      $Bearer = new class extends Guard {
         public function authenticate (object $Request): bool
         {
            if ($this->extract($Request) !== 'tok') {
               return false;
            }
            $this->expose($Request, 'identity', 'user-42');
            $this->expose($Request, 'claims', ['role' => 'admin']);

            return true;
         }
         public function challenge (object $Response): object
         {
            return $this->announce($Response, $this->format('Bearer', ['realm' => 'WS']));
         }
      };
      // @ Basic guard: accepts alice:secret.
      $Basic = new Basic(
         Resolver: function (string $user, string $pass): mixed {
            return ($user === 'alice' && $pass === 'secret') ? 'alice' : false;
         },
         realm: 'WS'
      );

      $WS_Server_CLI = new WS_Server_CLI(Mode: Modes::Test);
      $WS_Server_CLI->configure(
         host: '0.0.0.0',
         port: 8086,
         workers: 1,
         heartbeatInterval: 0,
         guards: [$Bearer, $Basic]
      );
      $WS_Server_CLI->on(Events::MessageReceived, function ($Session, $Message) {
         return "id={$Session->identity};claims=" . json_encode($Session->claims);
      });

      $WS_Server_CLI->start();
      usleep(400000);

      Client::$port = 8086;

      try {
         $Suite->autoboot(__DIR__);
         $Suite->autoinstance(true);
         $Suite->summarize();
      }
      finally {
         Client::$port = 8084;

         $WS_Server_CLI->Process->stopping = true;
         $WS_Server_CLI->Process->Children->terminate();
         $WS_Server_CLI->Process->State->clean();
      }

      return true;
   },
   autoReport: true,
   suiteName: __NAMESPACE__,
   exitOnFailure: false,
   // * Data
   tests: [
      '1.1-auth'
   ]
);
