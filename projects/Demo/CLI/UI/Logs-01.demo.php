<?php
namespace Bootgly\CLI;


use const BOOTGLY_TTY;
use const PHP_EOL;
use function json_encode;
use function microtime;
use function sleep;
use function usleep;

use const Bootgly\CLI;
use Bootgly\CLI\UI\Components\Logs;


$Input = CLI->Terminal->Input;
$Output = CLI->Terminal->Output;
$Output->reset();

$Output->render(<<<OUTPUT
/* @*:
 * @#green: Bootgly CLI UI - Logs component @;
 * @#yellow: @@: Demo - Example #1 - Monitor-mode log viewer @;
 * {$location}
 */\n\n
OUTPUT);

// ? The interactive viewer requires an interactive terminal (pipes and CI render a notice only)
if (BOOTGLY_TTY === false) {
   $Output->write('The Logs viewer requires an interactive terminal.' . PHP_EOL);

   return;
}

$Output->render("@#cyan:Streaming records into the viewer...@;\n");
sleep(1);

$Logs = new Logs($Input, $Output);

// ! Simulated worker records (newline-delimited JSON, as streamed over the log pipe)
$records = [
   ['level' => 'info',    'channel' => 'Server', 'message' => 'HTTP Server CLI booted on tcp://0.0.0.0:8080'],
   ['level' => 'info',    'channel' => 'Server', 'message' => 'Worker #1 spawned (pid 4021)'],
   ['level' => 'info',    'channel' => 'Server', 'message' => 'Worker #2 spawned (pid 4022)'],
   ['level' => 'debug',   'channel' => 'Router', 'message' => 'Route table compiled: 24 routes in 1.8 ms'],
   ['level' => 'info',    'channel' => 'Router', 'message' => 'GET / matched route `index` (200) in 0.4 ms'],
   ['level' => 'debug',   'channel' => 'Server', 'message' => 'Connection #17 accepted from 172.18.0.9:51204'],
   ['level' => 'info',    'channel' => 'Queue',  'message' => 'Job #311 `mail:welcome` queued on channel `default`'],
   ['level' => 'info',    'channel' => 'Router', 'message' => 'GET /products matched route `products.list` (200) in 1.2 ms'],
   ['level' => 'notice',  'channel' => 'Queue',  'message' => 'Job #311 `mail:welcome` retried (attempt 2 of 3)'],
   ['level' => 'debug',   'channel' => 'Server', 'message' => 'Connection #17 kept alive (12 requests served)'],
   ['level' => 'warning', 'channel' => 'Router', 'message' => 'GET /reports took 512.7 ms (slow route threshold: 300 ms)', 'context' => ['route' => 'reports.index', 'took' => 512.7]],
   ['level' => 'info',    'channel' => 'Queue',  'message' => 'Job #311 `mail:welcome` done in 84.1 ms'],
   ['level' => 'info',    'channel' => 'Router', 'message' => 'POST /checkout matched route `checkout.pay` (202) in 2.9 ms'],
   ['level' => 'error',   'channel' => 'Queue',  'message' => "Uncaught RuntimeException: payment gateway timed out after 3000 ms\n#0 /app/projects/shop/Gateway.php(88): Gateway->connect()\n#1 /app/projects/shop/Checkout.php(41): Gateway->charge()\n#2 {main}", 'context' => ['job' => 312, 'order' => 4919, 'amount' => '99.90']],
   ['level' => 'notice',  'channel' => 'Queue',  'message' => 'Job #312 `checkout:pay` rescheduled (attempt 2 of 3)'],
   ['level' => 'info',    'channel' => 'Server', 'message' => 'Worker #2 recycled after 10000 requests (pid 4022 -> 4038)'],
   ['level' => 'debug',   'channel' => 'Router', 'message' => 'GET /favicon.ico matched route `static` (200) in 0.1 ms'],
   ['level' => 'info',    'channel' => 'Queue',  'message' => 'Job #312 `checkout:pay` done in 1204.6 ms'],
   ['level' => 'info',    'channel' => 'Router', 'message' => 'GET /orders/4919 matched route `orders.show` (200) in 1.7 ms'],
   ['level' => 'debug',   'channel' => 'Server', 'message' => 'Heartbeat: 2 workers alive, 31 connections open'],
];

CLI->Terminal->clear();

// @ Live tail: the records arrive over time, the newest is kept in view
foreach ($records as $record) {
   $record['timestamp'] = microtime(true);

   $Logs->feed(json_encode($record) . "\n");
   $Logs->render();

   usleep(140000);
}

// @ Interactive: drive the viewer with the footer keys until `q` or Esc
$Input->configure(blocking: true, canonical: false, echo: false);

while (true) {
   $key = $Input->read(16);
   if ($key === false || $key === '') {
      break;
   }

   if ($Logs->control($key) === false) {
      break;
   }

   $Logs->render();
}

$Input->configure(blocking: true, canonical: true, echo: true);

$Output->render("\n\n@#green:Logs viewer closed. Bye!@;\n");
