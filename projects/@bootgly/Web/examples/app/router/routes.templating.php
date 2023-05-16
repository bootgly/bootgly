<?php
$Router('/templating-raw', function () use ($Bootgly) {
   $raw = <<<'HTML'
   <h1>Testing raw templating!</h1>

   <p>1 in line -> [title]: @>> $title1;</p>
   <p>1+ in line -> [title]: @>> $title1; @>> $title2;</p>
   HTML;

   $Bootgly->Template->render($raw, [
      'title1' => 'Bootgly Testing templating',
      'title2' => '| Using Bootgly templating'
   ]);
});
$Router('/templating-file', function () use ($Bootgly) {
   $Bootgly->Template->render('index', [
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

$Router('/*', function () {
   echo 'Bootgly 404 Not found!';
   exit;
});
