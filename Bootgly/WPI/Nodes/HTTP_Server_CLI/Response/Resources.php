<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


use function is_string;
use function property_exists;
use function spl_object_id;
use Closure;
use InvalidArgumentException;
use ReflectionProperty;
use RuntimeException;
use WeakReference;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response as ServerResponse;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Database;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\JSON;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\JSONP;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Negotiation;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Plaintext;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Pre;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\SSE;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\View;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\XML;


/**
 * Mounted HTTP response resources.
 */
class Resources
{
   // * Config
   /** @var null|Closure(Resource):mixed */
   private null|Closure $Attach;
   private null|object $Context;

   // * Data
   /** @var array<string,Resource> */
   public private(set) array $resources = [];
   // ! Factories are documented as Closure(object):Resource, but the array
   //   stays loosely typed: load() cannot verify closure return types, so
   //   fetch() keeps a runtime instanceof guard on what factories return
   /** @var array<string,Closure> */
   public private(set) array $definitions = [];

   // * Metadata
   // ! True only while at least one NON-persistent resource is mounted —
   //   reset() then scans; persistent-only mounts (the hot routes) skip it.
   private bool $ephemeral = false;
   // ! Persistent resources carrying request-scoped state. Empty on every
   //   route that mounts none, so reset() stays free on the hot path.
   /** @var array<int,Resource> */
   private array $Scoped = [];
   // ! Base reset hot-path gate. True only when reset() has actual ephemeral
   //   mounts to drop or scoped instances to clean.
   // ! Default true keeps subtype overrides fail-safe even when a subtype
   //   constructor deliberately skips parent::__construct(). The exact base
   //   constructor resolves its idle state once, outside the request loop.
   public private(set) bool $resettable = true;
   // ! The response context once release() ended its deferred job, held
   //   weakly: that response owns this registry, so the strong edge was a
   //   cycle only the collector could break. Null while the context is held
   //   strongly — or not at all — and every context read falls back to it
   //   (see resolve()).
   /** @var null|WeakReference<ServerResponse> */
   private null|WeakReference $Reference = null;
   // ! The Response's private stand-in builder, bound to its scope on the
   //   first stand-in a registry answers for (see resolve())
   /** @var null|Closure():ServerResponse */
   private static null|Closure $Substitute = null;
   // ! The framework built-ins whose clean() is what lets their response go
   //   (see fetch()). Exact classes: a subclass keeps the strong edge it
   //   always had, and its own clean() never runs at build time. Kept in
   //   step by Resources/tests/1.18-releasable_builtins.Test.php, which
   //   derives it from the definitions the constructor below registers
   /** @var array<class-string<Resource>,true> */
   private const array RELEASABLE = [
      Database::class => true,
      JSON::class => true,
      JSONP::class => true,
      Negotiation::class => true,
      Plaintext::class => true,
      Pre::class => true,
      SSE::class => true,
      View::class => true,
      XML::class => true,
   ];


   /**
    * Construct the response resources registry.
    *
    * @param null|Closure(Resource):mixed $Attach
    */
   public function __construct (null|Closure $Attach = null, null|object $Context = null)
   {
      // * Config
      $this->Attach = $Attach;
      $this->Context = $Context;
      $this->resettable = $this::class !== self::class;

      if ($Context instanceof ServerResponse) {
         // * Data
         $this->register('JSON', static fn (object $Context): JSON => new JSON(self::guard($Context)));
         $this->register('JSONP', static fn (object $Context): JSONP => new JSONP(self::guard($Context)));
         $this->register('Negotiation', static fn (object $Context): Negotiation => new Negotiation(self::guard($Context)));
         $this->register('Plaintext', static fn (object $Context): Plaintext => new Plaintext(self::guard($Context)));
         $this->register('Pre', static fn (object $Context): Pre => new Pre(self::guard($Context)));
         $this->register('SSE', static fn (object $Context): SSE => new SSE(self::guard($Context)));
         $this->register('View', static fn (object $Context): View => new View(self::guard($Context)));
         $this->register('XML', static fn (object $Context): XML => new XML(self::guard($Context)));
      }
   }

   /**
    * Fork definitions into a new response context.
    *
    * @param Closure(Resource):mixed $Attach
    */
   public function fork (Closure $Attach, object $Context): self
   {
      // ! Clone instead of construct — the constructor would re-define every
      //   built-in factory (five closure allocations) only for the definitions
      //   copy to replace them; cloning carries definitions by copy-on-write
      //   (hot path: one fork per deferred request)
      $Resources = clone $this;
      $Resources->Attach = $Attach;
      $Resources->Context = $Context;
      $Resources->Reference = null;

      // @ Carry user-mounted instances (those without a definition) into the
      //   fork, re-attaching their scheduler bridge to the new context.
      //   Definition-backed names (built-ins, configured resources) are
      //   dropped to rebuild lazily so they bind to the forked response, not
      //   the old.
      foreach ($Resources->resources as $name => $Resource) {
         if (isset($Resources->definitions[$name])) {
            $Resources->remove($name, false);

            continue;
         }

         $Resources->replace($name, $Resource, false);
      }

      return $Resources;
   }

   /**
    * Define one lazy response resource factory.
    *
    * @param Closure(object):Resource $Factory
    */
   public function define (string $name, Closure $Factory): static
   {
      $Context = $this->Context ?? $this->resolve();

      if ($Context === null) {
         throw new RuntimeException('Response resource definitions require a context.');
      }
      self::reserve($Context, $name);

      $this->register($name, $Factory);

      return $this;
   }

   /**
    * Write one factory into the registry.
    *
    * The framework's own built-ins take this path around the reserved-name
    * guard: a subclass that declares a public property named after a built-in
    * chooses to shadow that resource and must still construct.
    */
   private function register (string $name, Closure $Factory): void
   {
      $this->definitions[$name] = $Factory;
      $this->remove($name);
   }

   /**
    * Load lazy response resource factories.
    *
    * @param array<array-key,mixed> $definitions
    */
   public function load (array $definitions): static
   {
      $Context = $this->Context ?? $this->resolve();

      if ($Context === null) {
         throw new RuntimeException('Response resource definitions require a context.');
      }

      // ? Validate the whole batch first — a name refused halfway through
      //   would leave the definitions it preceded already registered
      foreach ($definitions as $name => $Factory) {
         if (is_string($name) === false) {
            throw new InvalidArgumentException('Response resource definitions must be keyed by name.');
         }

         if ($Factory instanceof Closure === false) {
            throw new InvalidArgumentException('Response resource definition must be a Closure factory.');
         }

         self::reserve($Context, $name);
      }

      // @ Register — the batch proved legal
      foreach ($definitions as $name => $Factory) {
         /** @var Closure(object):Resource $Factory */
         $this->register($name, $Factory);
      }

      return $this;
   }

   /**
    * Set one mounted resource by name.
    *
    * @template T of Resource
    * @param T $Resource
    * @return T
    */
   public function set (string $name, Resource $Resource): Resource
   {
      $Context = $this->Context ?? $this->resolve();

      if ($Context !== null) {
         self::reserve($Context, $name);
      }

      return $this->replace($name, $Resource, true);
   }

   /**
    * Refuse a resource name a publicly readable instance property of the
    * context already owns: the property wins every read, so the resource
    * could never be reached. A private/protected property and a static one
    * never shadow `__get`, so their names stay free. Registration/mount time
    * only — `fetch()` is untouched.
    */
   private static function reserve (object $Context, string $name): void
   {
      // ? Only a declared property can win a read
      if (property_exists($Context, $name) === false) {
         return;
      }
      // ! A static property never answers instance access — `__get` still
      //   reaches the resource — so only a public instance property reserves
      $Property = new ReflectionProperty($Context, $name);
      if ($Property->isPublic() && $Property->isStatic() === false) {
         throw new InvalidArgumentException(
            "Response resource name `{$name}` is reserved by a declared response property."
         );
      }
   }

   /**
    * Replace one mounted name after its attach hook succeeds.
    *
    * @template T of Resource
    * @param T $Resource
    * @return T
    */
   private function replace (
      string $name,
      Resource $Resource,
      bool $clean,
   ): Resource {
      $Previous = $this->resources[$name] ?? null;
      $Attached = $this->attach($Resource);
      $this->resources[$name] = $Attached;

      if ($Previous !== null && $Previous !== $Attached) {
         try {
            $this->detach($Previous, $clean);
         }
         finally {
            $this->refresh();
         }
      }

      return $Attached;
   }

   /**
    * Fetch one mounted resource by name.
    */
   public function fetch (string $name): null|Resource
   {
      $Resource = $this->resources[$name] ?? null;

      if ($Resource !== null) {
         return $Resource;
      }

      $Factory = $this->definitions[$name] ?? null;

      if ($Factory === null) {
         return null;
      }

      // ! Hot path: first resource read of a request — construct from the
      //   factory and mount inline, without create()/set() call hops. A
      //   strong context is the only read it pays.
      $Context = $this->Context;
      $released = $Context === null;
      if ($released) {
         $Context = $this->resolve();
      }

      if ($Context === null) {
         throw new RuntimeException('Response resource factory expects a context, but none was configured.');
      }

      $Resource = $Factory($Context);

      if ($Resource instanceof Resource === false) {
         throw new RuntimeException('Response resource factory must return a Resource.');
      }

      $Resource = $this->resources[$name] = $this->attach($Resource);

      // ? Built after release(): a framework built-in whose clean() is what
      //   lets its response go is cleaned as release() cleaned every
      //   instance built before it, so it holds its response weakly too —
      //   and nothing at all of a stand-in (see resolve()). Any other
      //   resource is handed over as built, as before: its clean() may wipe
      //   what its construction set up
      if ($released && isSet(self::RELEASABLE[$Resource::class])) {
         $Resource->clean();
      }

      // :
      return $Resource;
   }

   /**
    * Reset per-request resource instances while keeping boot definitions.
    */
   public function reset (): void
   {
      // ? Exact idle base registry: retain the unconditional virtual call in
      //   Response::reset() so subtype overrides always dispatch, then leave
      //   before scanning either lifecycle collection.
      if ($this->resettable === false) {
         return;
      }

      // @ A persistent instance may still hold request-scoped data; drop it
      //   without dropping the instance (audit 2026-07-27 M2). The list is
      //   empty unless a scoped resource was actually mounted.
      foreach ($this->Scoped as $Resource) {
         $Resource->clean();
      }

      // ? Persistent-only mounts — nothing to unset, skip the scan entirely.
      if ($this->ephemeral === false) {
         return;
      }

      foreach ($this->resources as $name => $Resource) {
         if ($Resource->persistent) {
            continue;
         }

         unset($this->resources[$name]);

         if ($Resource->scoped) {
            unset($this->Scoped[spl_object_id($Resource)]);
         }
      }

      $this->refresh();
   }

   /**
    * Clean every instance this registry built from a definition — a forked
    * response's own, per-generation instances — when its deferred job ends,
    * keeping them mounted (a stream the job opened, such as SSE, may still be
    * reached through the response). User-mounted instances, which other
    * contexts share, are left alone.
    *
    * A resource that holds work in flight (a KV command nobody awaited) hands
    * it back here instead of when its response is destroyed. From here on
    * this registry holds its response weakly, and so does every built-in
    * that binds it — built before this call, or after it (see fetch()):
    * then only what the application keeps bound to that response (an
    * instance it mounted itself, a subclass of a built-in, a resource of its
    * own that holds its context, a capture) still leads back to it, and
    * without such an edge refcounting — not the cycle collector — frees it
    * once nothing else holds it. While something does, every read reaches it
    * as before (see resolve()).
    */
   public function release (): void
   {
      // ! Before any cleanup can throw. Only the framework's own Response
      //   is held weakly: once it is gone, resolve() stands a detached one in
      //   for it — which it could not build for any other context.
      $Context = $this->Context;
      if ($Context !== null && $Context::class === ServerResponse::class) {
         $this->Reference = WeakReference::create($Context);
         $this->Context = null;
      }

      foreach ($this->resources as $name => $Resource) {
         if (isset($this->definitions[$name])) {
            $Resource->clean();
         }
      }
   }

   /**
    * Resolve the context release() holds weakly.
    *
    * The response itself while it lives — read through the weak edge, never
    * held again. Once it is gone, this registry outlived it (it was kept
    * without its response): every call that needs a context gets a fresh
    * detached stand-in — the one `Resource\Releasable` describes, never
    * serialized and never adopted as the worker's Response, built by the
    * Response's own builder, so refcounting frees it once the call lets go
    * — and what is still built, mounted or defined here lands on a response
    * as it did before, and no call shares anything with another. The attach
    * hook does the same for what it attaches. Null only for a registry that
    * never had a context.
    */
   private function resolve (): null|object
   {
      $Reference = $this->Reference;

      // ? Held strongly, or never configured
      if ($Reference === null) {
         return $this->Context;
      }

      // ?: Still alive
      $Context = $Reference->get();
      if ($Context !== null) {
         return $Context;
      }

      // ! Gone: a fresh stand-in for this call alone, from the Response's
      //   own builder — bound once, never re-declared here
      self::$Substitute ??= Closure::bind(
         static function (): ServerResponse {
            return ServerResponse::substitute();
         },
         null,
         ServerResponse::class,
      );

      // :
      return (self::$Substitute)();
   }

   /**
    * Stop tracking one resource after its final mounted name is removed.
    */
   private function detach (Resource $Resource, bool $clean): void
   {
      if ($Resource->scoped === false) {
         return;
      }

      foreach ($this->resources as $Mounted) {
         if ($Mounted === $Resource) {
            return;
         }
      }

      unset($this->Scoped[spl_object_id($Resource)]);

      if ($clean) {
         $Resource->clean();
      }
   }

   /**
    * Remove one mounted name and synchronize its lifecycle metadata.
    */
   private function remove (string $name, bool $clean = true): void
   {
      $Resource = $this->resources[$name] ?? null;

      if ($Resource === null) {
         return;
      }

      unset($this->resources[$name]);
      try {
         $this->detach($Resource, $clean);
      }
      finally {
         $this->refresh();
      }
   }

   /**
    * Refresh lifecycle gates after a mounted resource is displaced.
    */
   private function refresh (): void
   {
      $this->ephemeral = false;

      foreach ($this->resources as $Resource) {
         if ($Resource->persistent === false) {
            $this->ephemeral = true;

            break;
         }
      }

      $this->resettable = $this::class !== self::class
         || $this->ephemeral
         || $this->Scoped !== [];
   }

   /**
    * Attach one resource to the current response lifecycle.
    *
    * @template T of Resource
    * @param T $Resource
    * @return T
    */
   private function attach (Resource $Resource): Resource
   {
      $Attach = $this->Attach;

      if ($Attach === null) {
         if ($Resource->persistent === false) {
            $this->ephemeral = true;
         }
         if ($Resource->scoped) {
            $this->Scoped[spl_object_id($Resource)] = $Resource;
         }
         if ($Resource->persistent === false || $Resource->scoped) {
            $this->resettable = true;
         }

         return $Resource;
      }

      $Attached = $Attach($Resource);

      if ($Attached instanceof Resource === false) {
         throw new RuntimeException('Response resource attach hook must return a Resource.');
      }

      if ($Attached->persistent === false) {
         $this->ephemeral = true;
      }
      if ($Attached->scoped) {
         $this->Scoped[spl_object_id($Attached)] = $Attached;
      }
      if ($Attached->persistent === false || $Attached->scoped) {
         $this->resettable = true;
      }

      /** @var T $Attached */
      return $Attached;
   }

   /**
    * Validate one built-in factory context.
    */
   private static function guard (object $Context): ServerResponse
   {
      if ($Context instanceof ServerResponse === false) {
         throw new RuntimeException('Built-in response resource factory expects a Response context.');
      }

      return $Context;
   }
}
