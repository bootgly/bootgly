<?php

use function is_array;
use function is_int;
use function sort;

use Bootgly\ABI\Resources\Storage;
use Bootgly\ACI\Tests\Suite\Test\Specification;

require_once __DIR__ . '/disk.php';


return new Specification(
   description: 'Storage(Memory): full in-process round-trip for every operation',
   test: function () {
      $Storage = new Storage(['default' => 'memory']);

      $Storage->write('a.txt', source('AAA'));
      $Storage->write('dir/b.txt', source('BB'));

      yield assert(
         assertion: grab($Storage, 'a.txt') === 'AAA',
         description: 'read() round-trips the stored contents'
      );
      yield assert(
         assertion: grab($Storage, 'nope') === false,
         description: 'read() of a missing key returns false'
      );
      yield assert(
         assertion: $Storage->check('a.txt') === true && $Storage->check('dir') === true,
         description: 'check() sees files and implicit directories'
      );
      yield assert(
         assertion: $Storage->measure('a.txt') === 3,
         description: 'measure() returns the byte length'
      );
      $info = $Storage->inspect('a.txt');
      yield assert(
         assertion: is_array($info) === true
            && $info['size'] === 3
            && is_int($info['modified']) === true,
         description: 'inspect() returns size and a write timestamp'
      );

      $top = $Storage->list();
      sort($top);
      yield assert(
         assertion: $top === ['a.txt'],
         description: 'list() returns only immediate keys'
      );

      $all = $Storage->list('', true);
      sort($all);
      yield assert(
         assertion: $all === ['a.txt', 'dir/b.txt'],
         description: 'recursive list() includes nested keys'
      );

      yield assert(
         assertion: $Storage->copy('a.txt', 'c.txt') === true && grab($Storage, 'c.txt') === 'AAA',
         description: 'copy() duplicates a key'
      );
      yield assert(
         assertion: $Storage->move('c.txt', 'd.txt') === true
            && $Storage->check('c.txt') === false
            && grab($Storage, 'd.txt') === 'AAA',
         description: 'move() relocates and removes the source key'
      );
      yield assert(
         assertion: $Storage->delete('a.txt') === true && $Storage->check('a.txt') === false,
         description: 'delete() removes a key'
      );
      yield assert(
         assertion: $Storage->clear() === true && $Storage->list('', true) === [],
         description: 'clear() empties the disk'
      );
   }
);
