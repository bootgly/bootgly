<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Routes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * A budgeted deferral arms exactly one reactor deadline while it parks and
 * disarms it when it settles — a completed deferral leaves no timer behind
 * to fire on a pooled Fiber later.
 */
$Probe = new class {
   /** @var array<string,int> */
   public array $before = [];
   /** @var array<string,int> */
   public array $during = [];
   /** @var array<string,int> */
   public array $after = [];
   /** @var array<string,mixed> */
   public array $budgeted = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      $timers = static function ($Socket) use ($testIndex): array {
         return (array) json_decode(Client::send($Socket, Client::request('/idle/timers', $testIndex), 2.0)['body'] ?? '', true);
      };
      try {
         $Watcher = Client::open($hostPort);
         $Probe->before = $timers($Watcher);

         $Parker = Client::open($hostPort);
         @fwrite($Parker, Client::request('/idle/budgeted?seconds=1', $testIndex));
         usleep(300_000);
         $Probe->during = $timers($Watcher);

         $Probe->budgeted = Client::send($Parker, '', 3.0);
         Client::close($Parker);
         $Probe->after = $timers($Watcher);
         Client::close($Watcher);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /idle/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router): Generator {
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode(['error' => $Probe->error, 'before' => $Probe->before, 'during' => $Probe->during, 'after' => $Probe->after, 'budgeted' => $Probe->budgeted]);

      yield new Assertion(
         description: 'The budgeted deferral answered in time',
         fallback: "The budgeted deferral did not answer 200: {$evidence}"
      )
         ->expect($Probe->budgeted['code'] ?? 0)
         ->to->be(200)
         ->assert();

      yield new Assertion(
         description: 'Exactly one monotonic deadline is armed while the budgeted deferral parks',
         fallback: "The deadline was not armed while parked: {$evidence}"
      )
         ->expect(($Probe->during['monotonic'] ?? -1) - ($Probe->before['monotonic'] ?? 0))
         ->to->be(1)
         ->assert();

      yield new Assertion(
         description: 'The deadline is disarmed once the deferral settled',
         fallback: "A stale deadline survived the completed deferral: {$evidence}"
      )
         ->expect(($Probe->after['monotonic'] ?? -1) - ($Probe->before['monotonic'] ?? 0))
         ->to->be(0)
         ->assert();
   })
);
