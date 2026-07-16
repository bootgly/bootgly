<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Components\Tree;


use Closure;
use Throwable;


/**
 * One tree node — a label, an optional payload and its children.
 * Children are built eagerly with `add()` or lazily through a `$resolver`
 * Closure, which runs once on the first `expand()`.
 */
class Node
{
   // * Config
   /** Label — Template markup allowed (per-node color goes here) */
   public string $label;
   /** Consumer payload */
   public mixed $value;
   /** Marker glyph override — empty uses the Tree state glyphs */
   public string $glyph;
   /** Enter confirms this node — gates only the plain, action-less confirm */
   public bool $selectable;
   /**
    * Enter action — runs with this Node instead of confirming it.
    * Return `false` to confirm and finish; any other return keeps navigating
    * (the action may mutate the tree — rows re-flatten). The false return
    * confirms even when `$selectable` is false: the explicit runtime decision
    * wins over the static flag.
    */
   public null|Closure $action;
   /** Lazy children provider — called once with this Node on the first expand */
   public null|Closure $resolver;

   // * Data
   /** @var array<int,Node> */
   public private(set) array $Nodes;
   public private(set) bool $expanded;

   // * Metadata
   public private(set) null|Node $Parent;
   public private(set) int $depth;
   /** Lazy resolver already ran? */
   public private(set) bool $resolved;
   /** No children and no pending resolver */
   public bool $leaf {
      get => $this->Nodes === [] && ($this->resolver === null || $this->resolved === true);
   }
   /** Fully open — expanded, with children and no pending resolver */
   public bool $open {
      get => $this->expanded === true && $this->Nodes !== []
          && ($this->resolver === null || $this->resolved === true);
   }


   public function __construct (string $label, mixed $value = null)
   {
      // * Config
      $this->label = $label;
      $this->value = $value;
      $this->glyph = '';
      $this->selectable = true;
      $this->action = null;
      $this->resolver = null;

      // * Data
      $this->Nodes = [];
      $this->expanded = true;

      // * Metadata
      $this->Parent = null;
      $this->depth = 0;
      $this->resolved = false;
   }

   /**
    * Adds a child node.
    *
    * @param string $label The child label — Template markup allowed.
    * @param mixed $value The consumer payload carried by the child.
    * @param null|Closure $resolver A lazy children provider for the child.
    *
    * @return Node The new child node.
    */
   public function add (string $label, mixed $value = null, null|Closure $resolver = null): Node
   {
      $Child = new Node($label, $value);
      $Child->resolver = $resolver;
      $Child->Parent = $this;
      $Child->depth = $this->depth + 1;

      $this->Nodes[] = $Child;

      // :
      return $Child;
   }

   /**
    * Expands this node — the first expand of a lazy node runs its resolver.
    *
    * @return self
    */
   public function expand (): self
   {
      // ? Leaves have nothing to expand
      if ($this->leaf === true) {
         // :
         return $this;
      }

      // ! First expand of a lazy node resolves its children — the latch flips
      //   before the call (re-entrancy guard) and rolls back on failure, so a
      //   throwing resolver stays retryable
      if ($this->resolver !== null && $this->resolved === false) {
         $this->resolved = true;

         try {
            ($this->resolver)($this);
         }
         catch (Throwable $Throwable) {
            $this->resolved = false;

            throw $Throwable;
         }
      }

      $this->expanded = true;

      // :
      return $this;
   }

   /**
    * Collapses this node — its children leave the visible tree.
    *
    * @return self
    */
   public function collapse (): self
   {
      $this->expanded = false;

      // :
      return $this;
   }

   /**
    * Toggles this node between its open and folded states — an unresolved
    * lazy node counts as folded, so toggling it resolves and opens it.
    *
    * @return self
    */
   public function toggle (): self
   {
      // :
      return $this->open === true ? $this->collapse() : $this->expand();
   }
}
