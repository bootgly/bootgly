<?php


use Bootgly\ABI\Data\__String\Path;


return [
   // @ configure
   'describe' => 'It should cut path nodes',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @
      // Valid
      // Negative (absolute dir)
      $cutted10 = Path::cut(path: '/var/www/html/test/', nodes: -2);
      yield assert($cutted10 === 'html/test/', "Cut #1.0 wrong: @; $cutted10");
      // Negative (relative dir)
      $cutted11 = Path::cut(path: 'var/www/html/test/', nodes: -2);
      yield assert($cutted11 === 'html/test/', "Cut #1.1 wrong: @; $cutted11");
      // Negative (absolute file)
      $cutted12 = Path::cut(path: '/var/www/html/test.php', nodes: -2);
      yield assert($cutted12 === 'html/test.php', "Cut #1.2 wrong: @; $cutted12");
      // Negative (relative file)
      $cutted13 = Path::cut(path: 'var/www/html/test.php', nodes: -2);
      yield assert($cutted13 === 'html/test.php', "Cut #1.3 wrong: @; $cutted13");

      // Positive (absolute dir)
      $cutted20 = Path::cut(path: '/var/www/html/test/', nodes: 1);
      yield assert($cutted20 === '/var/www/html/', "Cut #2.0 wrong: @; $cutted20");
      // Positive (relative dir)
      $cutted21 = Path::cut(path: 'var/www/html/test/', nodes: 1);
      yield assert($cutted21 === 'var/www/html/', "Cut #2.1 wrong: @; $cutted21");
      // Positive (absolute file)
      $cutted22 = Path::cut(path: '/var/www/html/test.php', nodes: 1);
      yield assert($cutted22 === '/var/www/html/', "Cut #2.2 wrong: @; $cutted22");
      // Positive (relative file)
      $cutted23 = Path::cut(path: 'var/www/html/test.php', nodes: 1);
      yield assert($cutted23 === 'var/www/html/', "Cut #2.3 wrong: @; $cutted23");

      // (absolute dir)
      $cutted30 = Path::cut('/var/www/html/test/', -1, 1);
      yield assert($cutted30 === 'www/html/', "Cut #3.0 wrong: @; $cutted30");
      // (relative dir)
      $cutted31 = Path::cut('var/www/html/test/', -2, 1);
      yield assert($cutted31 === 'html/', "Cut #3.1 wrong: @; $cutted31");
      // (absolute file)
      $cutted32 = Path::cut('/var/www/html/test.php', -1, 1);
      yield assert($cutted32 === 'www/html/', "Cut #3.2 wrong: @; $cutted32");
      // (relative file)
      $cutted33 = Path::cut('var/www/html/test.php', -2, 1);
      yield assert($cutted33 === 'html/', "Cut #3.3 wrong: @; $cutted33");
      // Invalid
      // nodes: 0
      $cutted01 = Path::cut(path: '/var/www/html/test/', nodes: 0);
      yield assert($cutted01 === '/var/www/html/test/', "Cut #0.1 wrong: @; $cutted01");
      // +nodes >= count($paths)
      $cutted02 = Path::cut(path: '/var/www/html/test/', nodes: 4);
      yield assert($cutted02 === '', "Cut #0.2 wrong: @; $cutted02");
      // -nodes >= count($paths)
      $cutted03 = Path::cut(path: '/var/www/html/test/', nodes: -4);
      yield assert($cutted03 === '', "Cut #0.3 wrong: @; $cutted03");
      // -nodes - +nodes > count($paths)
      $cutted04 = Path::cut('/var/www/html/test/', -3, 1);
      yield assert($cutted04 === '', "Cut #0.4 wrong: @; $cutted04");
   }
];
