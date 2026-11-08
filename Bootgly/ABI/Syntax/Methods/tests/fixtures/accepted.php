<?php

namespace Demo\Methods;


use ArrayIterator;
use Iterator;
use IteratorAggregate;


class Verbs implements IteratorAggregate
{
   public function __construct () {}
   public function load (): void {}
   public function render (): void {}
   public function reading (): void {}
   public function routing (): void {}
   public function embed (): void {}
   public function feed (): void {}
   public function proceed (): void {}
   public function process (): void {}
   public function access (): void {}
   public function focus (): void {}
   public function implement (): void {}
   public function mention (): void {}
   public function enable (): void {}
   public function alias (): void {}
   public function label (): void {}
   public function index (): void {}
   public function empty (): void {}
   public function getIterator (): Iterator { return new ArrayIterator([]); }
}

class Cursor implements Iterator
{
   public function current (): mixed { return null; }
   public function key (): mixed { return null; }
   public function next (): void {}
   public function rewind (): void {}
   public function valid (): bool { return false; }
}

class Wrapper
{
   public function stream_open (string $path, string $mode, int $options, null|string &$opened): bool { return false; }
   public function stream_read (int $count): string { return ''; }
   public function url_stat (string $path, int $flags): array { return []; }
}

function loaded_helper (): void {}

class Dual
{
   // # Words that name a thing and an action
   public function request (): void {}
   public function type (): void {}
   public function value (): void {}
   public function transition (): void {}
   public function version (): void {}
   public function witness (): void {}
   public function Render (): void {}
}
