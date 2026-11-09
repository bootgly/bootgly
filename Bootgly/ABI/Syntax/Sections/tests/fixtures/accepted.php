<?php

namespace Demo\Sections;


use function count;


class Server
{
   // * Config
   public string $host;
   public private(set) int $port;
   public readonly string $name;
   readonly int $backlog;

   // * Data
   /** @var array<string,string> */
   protected array $routes = [];
   public protected(set) int $limit = 0;

   // * Metadata
   public private(set) int $started = 0;
   protected int $connections = 0;
   protected Server $Peer;
   public bool $running {
      get => $this->started > 0;
   }
   public static int $instances {
      get => 1;
   }
   public int $port_ {
      get => $this->Peer->port_;
   }

   private const array DEFAULTS = [];


   public function __construct (string $host, int $port, string $name, int $backlog)
   {
      // * Config
      $this->host = $host;
      $this->port = $port;
      $this->name = $name;
      $this->backlog = $backlog;

      // * Metadata
      $object = new class {
         // * Data
         public int $count = 0;
      };
      $this->started = $object->count + count(self::DEFAULTS);
   }
}

trait Countable
{
   // * Metadata
   protected int $counted = 0;
}

interface Contract
{
   public string $value { get; }
}

enum Modes
{
   case Strict;
}
