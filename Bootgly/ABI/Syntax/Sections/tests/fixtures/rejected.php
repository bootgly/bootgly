<?php

namespace Demo\Sections;


class Server
{
   public string $loose;

   // * Data
   protected int $limit = 0;

   // * Config
   private string $secret;

   // * Metadata
   public int $started = 0;
   public static int $instances = 0;
   public int $cached {
      get => $this->cached ??= 1;
   }
   public int $written {
      set (int $value) {
         $this->written = $value;
      }
   }

   // * Metadata
   protected int $connections = 0;
}

trait Countable
{
   // * Config
   protected int $counted = 0;
}

register(new class {
   public int $argument = 0;
});
