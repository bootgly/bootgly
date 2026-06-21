<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

/**
 * Templating routes example.
 *
 * Demonstrates the Bootgly template engine: raw template strings and file views.
 */

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


return static function (Request $Request, Response $Response, Router $Router): Generator
{
   yield $Router->route('/templating-raw', function (Request $Request, Response $Response)
   {
      $raw = <<<'HTML'
      <h1>Testing raw templating!</h1>

      <p>1 in line -> [title]: @>> $title1;</p>
      <p>1+ in line -> [title]: @>> $title1; @>> $title2;</p>
      HTML;

      return $Response->View->render($raw, [
         'title1' => 'Bootgly Testing templating',
         'title2' => '| Using Bootgly templating'
      ])->send();
   }, GET);
   yield $Router->route('/templating-file', function (Request $Request, Response $Response)
   {
      return $Response->View->render('index', [
         'title' => 'Bootgly Testing templating',
         'description' => 'Using Bootgly Template engine!',

         'testA' => 'Test A',
         'test1' => 'Test 1',
         'testI' => 'Test I',

         'items' => [
            'itemA',
            'itemB',
            'itemC',
         ],

         'tenth' => 10
      ])->send();
   }, GET);

   yield $Router->route('/*', function (Request $Request, Response $Response) {
      return $Response(code: 404, body: 'Bootgly 404 Not found!');
   });
};
