<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Code\__String\Markdown;


/**
 * Markdown AST node — a tagged union: $type selects which properties carry
 * meaning. Containers (Quote, List, Item, Table, Row) hold blocks or rows in
 * $Children; leaf blocks (Paragraph, Heading, Cell) and emphasis inlines hold
 * inline nodes there instead.
 */
final class Node
{
   // * Data
   /** The node type — a block or an inline */
   public Blocks|Inlines $type;
   /** @var array<int,Node> Child nodes (see the class docblock) */
   public array $Children;
   /** Text payload — Text/Code content, Fence source, Image alt */
   public string $text;
   /** Heading level (1-6) */
   public int $level;
   /** Fence info string first word */
   public string $language;
   /** Link/Image destination */
   public null|string $URL;
   /** Ordered list? */
   public bool $ordered;
   /** Ordered list start number */
   public int $start;
   /** Task item state — null when the item is not a task */
   public null|bool $checked;
   /** @var array<int,string> Table column alignments — left|center|right */
   public array $alignments;


   public function __construct (Blocks|Inlines $type)
   {
      // * Data
      $this->type = $type;
      $this->Children = [];
      $this->text = '';
      $this->level = 0;
      $this->language = '';
      $this->URL = null;
      $this->ordered = false;
      $this->start = 1;
      $this->checked = null;
      $this->alignments = [];
   }
}
