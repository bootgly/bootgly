<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\Demo_Queue_HTTP_Server_CLI\router;


use const GET;
use Generator;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Queues;
use projects\Demo_Queue_HTTP_Server_CLI\SendEmail;


return static function
(Request $Request, Response $Response, Router $Router): Generator
{
   // ? Home — quick help
   yield $Router->route('/', function (Request $Request, Response $Response) {
      return $Response->JSON->send([
         'demo' => 'Queue + HTTP_Server_CLI',
         'try'  => 'GET /email/:to  → enqueues a SendEmail job, responds instantly',
         'then' => 'run `bootgly queue run`, then watch storage/queue-demo.log',
      ]);
   }, GET);

   // @ Enqueue a background job and respond immediately — the slow work never
   //   touches the request: the worker process runs SendEmail later.
   yield $Router->route('/email/:to', function (Request $Request, Response $Response) {
      $Job = Queues::dispatch(SendEmail::class, [
         'to'      => $this->Params->to,
         'subject' => 'Welcome!',
      ], 'emails');

      return $Response->JSON->send([
         'queued' => true,
         'queue'  => 'emails',
         'job'    => $Job->id,
      ]);
   }, GET);

   // ? Inspect the queue depth (ready jobs not yet processed)
   yield $Router->route('/queue', function (Request $Request, Response $Response) {
      $ready = Queues::$Messenger->Queues->fetch('emails')->count();

      return $Response->JSON->send(['queue' => 'emails', 'ready' => $ready]);
   }, GET);
};
