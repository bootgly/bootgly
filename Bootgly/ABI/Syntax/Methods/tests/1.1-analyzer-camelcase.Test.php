<?php

use Bootgly\ABI\Syntax\Methods\Analyzer;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'Analyzer: a camelCase method is reported; magic and PHP-imposed names, functions outside classes, calls and variables are not',
   test: function () {
      // ! Analyzer::analyze() reads a path, so each probe is a scratch file
      $dir = Temporaries::reserve('syntax-methods');

      try {

         $Analyzer = new Analyzer();

         $flag = function (string $body) use ($Analyzer, $dir): array {
            $source = "<?php\n\nnamespace Demo;\n\n{$body}\n";
            $file = $dir . '/probe-' . md5($source) . '.php';
            file_put_contents($file, $source);

            $found = [];
            foreach ($Analyzer->analyze($file)->issues as $Issue) {
               $found[] = $Issue->symbol;
            }

            return $found;
         };

         // @@ Every entity kind and visibility — each with one camelCase method and one control
         $entities = [
            'a class'            => 'class P { public function run (): void {} private function fooBar (): void {} }',
            'an abstract class'  => 'abstract class P { abstract protected function doThing (): void; public function run (): void {} }',
            'an interface'       => 'interface I { public function run (): void; public function fooBar (): void; }',
            'a trait'            => 'trait T { public static function make (): static {} protected function buildRoute (): void {} }',
            'an enum'            => 'enum E: string { case A = "a"; public function label (): string {} public function toLabel (): string {} }',
            'an anonymous class' => '$o = new class(fn () => 1) { public function run () {} public function anonMethod () {} };',
            'an anonymous class with a match in its arguments' => '$o = new class(match (1) { 1 => 2, default => 3 }) { public function run () {} public function anonMethod () {} };',
            'an anonymous class with a closure in its arguments' => '$o = new class(function () { return 1; }) extends \\ArrayObject { public function run () {} public function getIterator (): \\Iterator {} public function anonMethod () {} };',
            'a keyword used as an argument label' => 'class C { public function run () { $x = bar(trait: 1, class: 2); } public function ok () { $y = baz(function () { function deepFoo () {} }); } }',
            'a method named after a keyword' => 'class C { public function class () { function innerFooBar () {} } public function fooBar () {} }',
            'a by-ref method'    => 'class P { public function &byRef (): array {} public function &run (): array {} }',
            'a final method'     => 'final class P { final public function renderHTML (): void {} }',
         ];
         $expected = [
            'a class' => ['fooBar'], 'an abstract class' => ['doThing'], 'an interface' => ['fooBar'],
            'a trait' => ['buildRoute'], 'an enum' => ['toLabel'], 'an anonymous class' => ['anonMethod'],
            'a by-ref method' => ['byRef'], 'a final method' => ['renderHTML'],
            'an anonymous class with a match in its arguments' => ['anonMethod'],
            'an anonymous class with a closure in its arguments' => ['anonMethod'],
            'a keyword used as an argument label' => [],
            'a method named after a keyword' => ['fooBar'],
         ];
         foreach ($entities as $label => $body) {
            $found = $flag($body);
            yield assert(
               assertion: $found === $expected[$label],
               description: "The camelCase method of {$label} must be the only report, got: " . json_encode($found)
            );
         }

         // @ An uppercase FIRST letter is not a second word
         yield assert(
            assertion: $flag('class P { public function Render (): void {} }') === [],
            description: 'A capitalized single word is not multi-word'
         );

         // @@ Names imposed on the class from outside
         $imposed = [
            '__construct', '__toString', '__get', '__invoke',
            'getIterator', 'offsetExists', 'offsetGet', 'offsetSet', 'offsetUnset', 'jsonSerialize',
            'getMessage', 'getCode', 'getFile', 'getLine', 'getTrace', 'getTraceAsString', 'getPrevious',
            'getReturn', 'getTimestamp', 'getTimezone', 'getOffset', 'setTimezone', 'setTimestamp',
            'setDate', 'setTime', 'getChildren', 'hasChildren', 'getFlags', 'setFlags', 'getSubIterator',
            'getInnerIterator', 'getArrayCopy', 'getRealPath', 'getPathname', 'getFilename', 'getExtension',
            'isDir', 'isFile', 'isLink', 'getSize', 'getMTime', 'getCTime', 'getATime', 'getPerms',
            'getInode', 'getOwner', 'getGroup', 'getType', 'isReadable', 'isWritable', 'isExecutable',
            'getBasename', 'getPath', 'getPathInfo', 'getFileInfo', 'openFile', 'setFileClass',
            'setInfoClass', 'hasNext',
         ];
         $members = 'public function fooBar () {} ';
         foreach ($imposed as $name) {
            $members .= "public function {$name} () {} ";
         }
         $found = $flag("class P implements \\Countable { {$members}}");
         yield assert(
            assertion: $found === ['fooBar'],
            description: 'Magic and PHP-imposed names must be exempt in a class that implements something (only the control reported), got: ' . json_encode($found)
         );
         $extending = $flag("class P extends \\Exception { {$members}}");
         yield assert(
            assertion: $extending === ['fooBar'],
            description: 'PHP-imposed names must be exempt in a class that extends something too, got: ' . json_encode($extending)
         );
         $orphan = $flag("class P { {$members}}");
         yield assert(
            assertion: count($orphan) === count(array_filter($imposed, static fn (string $name): bool => str_starts_with($name, '__') === false)) + 1
               && $orphan[0] === 'fooBar' && in_array('getSize', $orphan, true) && in_array('__construct', $orphan, true) === false,
            description: 'The same names in a class that inherits nothing are the author\'s choice and must be reported, got: ' . json_encode($orphan)
         );
         yield assert(
            assertion: $flag('class P implements \\IteratorAggregate { public function GETITERATOR () {} public function fooBar () {} }') === ['fooBar'],
            description: 'An imposed name is exempt in any case, as PHP resolves it'
         );
         yield assert(
            assertion: $flag('trait T { public function offsetGet ($k) {} public function fooBar () {} }') === ['fooBar'],
            description: 'A trait inherits nothing itself but is mixed into classes that may — its imposed names stay exempt'
         );

         // @@ Not a method declaration
         $control = 'public function fooBar () {} ';
         $negatives = [
            'a plain function'           => 'function outsideFunc () {}',
            'a closure'                  => 'class P { ' . $control . 'public function run () { $f = function () { return 1; }; } }',
            'an arrow function'          => 'class P { ' . $control . 'public function run () { $f = fn () => 1; } }',
            'a function inside a method' => 'class P { ' . $control . 'public function run () { function innerFunc () {} } }',
            'a method call'              => 'class P { ' . $control . 'public function run () { $this->renderHTML(); self::buildRoute(); } }',
            'a nullsafe call'            => 'class P { ' . $control . 'public function run ($x) { return $x?->getUser(); } }',
            'a plain call'               => 'class P { ' . $control . 'public function run () { return doThing(); } }',
            'a variable'                 => 'class P { ' . $control . 'public function run () { $fooBar = 1; return $fooBar; } }',
            'a property'                 => 'class P { ' . $control . 'public int $fooBaz = 1; public int $hooked { get => 1; } }',
            'a trait alias'              => 'class P { ' . $control . 'use T { T::make as makeIt; } }',
            'a class constant'           => 'class P { ' . $control . 'public function run () { return P::class; } }',
            'a match'                    => 'class P { ' . $control . 'public function run ($x) { return match ($x) { default => 1 }; } }',
            'string interpolation'       => 'class P { ' . $control . 'public function run ($x) { return "{$x->fooBar()} ${x}"; } }',
         ];
         foreach ($negatives as $label => $body) {
            $found = $flag($body);
            $wanted = $label === 'a plain function' ? [] : ['fooBar'];
            yield assert(
               assertion: $found === $wanted,
               description: "{$label} must not be reported, got: " . json_encode($found)
            );
         }

         // @ Line and type
         $source = "<?php\n\nnamespace Demo;\n\nclass P {\n   public function fooBar (): void {}\n}\n";
         $file = $dir . '/probe-position.php';
         file_put_contents($file, $source);
         $Issues = $Analyzer->analyze($file)->issues;
         yield assert(
            assertion: count($Issues) === 1 && $Issues[0]->line === 6 && $Issues[0]->kind === 'method'
               && $Issues[0]->type === 'multiword_method' && $Issues[0]->offset === -1,
            description: 'The issue must carry the method line and be report-only, got: ' . json_encode($Issues)
         );

      }
      finally {
         foreach (glob($dir . '/*.php') ?: [] as $probe) {
            @unlink($probe);
         }
         @rmdir($dir);
      }
   }
);
