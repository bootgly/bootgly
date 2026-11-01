<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Storage;


use function assert;
use function file_get_contents;
use function file_put_contents;
use function fopen;
use function is_file;
use function mkdir;
use function source;
use function str_contains;
use function wipe;

use Bootgly\ABI\Resources\Storage\Drivers\Local;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;

require_once __DIR__ . '/disk.php';


/**
 * A NUL byte names no file, so the Local driver refuses a path carrying one the way its
 * contract reports every failure — `false` (`[]` for `list()`) with the reason on `$error`
 * — instead of letting PHP throw a `ValueError` (write, copy, move, make, check) or
 * answering a vacuous `true` (delete, clear) (STORE-14). The refusal reads the raw path:
 * `Path::normalize()` would drop the component carrying the NUL from `x\0/../y.txt`.
 */
return new Test(
   description: 'Storage(Local): a path with a NUL byte is refused with false and a reason, never a ValueError',
   test: function () {
      $base = Temporaries::reserve('storage-nul-path');

      try {
         $root = "{$base}/root";
         mkdir($root, 0775, true);
         file_put_contents("{$root}/keep.txt", 'KEEP');

         $Disk = new Local($root);
         $Sink = fopen('php://temp', 'r+');
         $calls = [
            'write()' => static fn () => $Disk->write("a\0b.txt", source('n')),
            'write() in a directory component' => static fn () => $Disk->write("d\0/f.txt", source('n')),
            'read()' => static fn () => $Disk->read("keep.txt\0", $Sink),
            'delete()' => static fn () => $Disk->delete("keep.txt\0"),
            'check()' => static fn () => $Disk->check("keep.txt\0"),
            'list()' => static fn () => $Disk->list("\0"),
            'copy() from' => static fn () => $Disk->copy("keep.txt\0", 'c.txt'),
            'copy() to' => static fn () => $Disk->copy('keep.txt', "c\0.txt"),
            'move() from' => static fn () => $Disk->move("keep.txt\0", 'm.txt'),
            'move() to' => static fn () => $Disk->move('keep.txt', "m\0.txt"),
            'measure()' => static fn () => $Disk->measure("keep.txt\0"),
            'inspect()' => static fn () => $Disk->inspect("keep.txt\0"),
            'make()' => static fn () => $Disk->make("k\0"),
            'clear()' => static fn () => $Disk->clear("\0"),
         ];

         // @@ Every operation: false (or []), a reason, and nothing touched
         foreach ($calls as $operation => $call) {
            $expected = $operation === 'list()' ? [] : false;
            yield assert(
               assertion: $call() === $expected
                  && str_contains($Disk->error, 'NUL byte')
                  && file_get_contents("{$root}/keep.txt") === 'KEEP',
               description: "{$operation} refuses a NUL byte with false and a reason"
            );
         }

         // @ The raw path is judged, before normalization can drop the NUL component
         yield assert(
            assertion: $Disk->write("x\0/../y.txt", source('n')) === false
               && is_file("{$root}/y.txt") === false,
            description: 'a NUL byte in a component that `..` removes is still refused'
         );

         // @ Control: the same names without the NUL work
         yield assert(
            assertion: $Disk->write('a.txt', source('n')) === true
               && $Disk->check('keep.txt') === true
               && $Disk->error === '',
            description: 'paths without a NUL byte are unaffected'
         );
      }
      finally {
         // ! Cleanup
         wipe($base);
      }
   }
);
