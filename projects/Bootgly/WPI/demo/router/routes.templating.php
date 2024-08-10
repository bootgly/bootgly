<?php
use Bootgly\WPI\Modules\HTTP\Server\Router;

use Bootgly\WPI\Nodes\HTTP_Server_\Request;
use Bootgly\WPI\Nodes\HTTP_Server_\Response;

/** @var Router $Router */

$Router->route('/templating-raw', function (Request $Request, Response $Response)
{
   $raw = <<<'HTML'
   <h1>Testing raw templating!</h1>

   <p>1 in line -> [title]: @>> $title1;</p>
   <p>1+ in line -> [title]: @>> $title1; @>> $title2;</p>
   HTML;

   $Response->render($raw, [
      'title1' => 'Bootgly Testing templating',
      'title2' => '| Using Bootgly templating'
   ]);
});
$Router->route('/templating-file', function (Request $Request, Response $Response)
{
   $Response->render('index', [
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
   ]);
});

$Router->route('/*', function () {
   echo 'Bootgly 404 Not found!';
   exit;
});
