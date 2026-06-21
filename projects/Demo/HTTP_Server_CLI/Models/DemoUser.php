<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\Bootgly\WPI;


use DateTimeImmutable;

use Bootgly\ADI\Databases\SQL\Model\Auxiliaries\Relations;
use Bootgly\ADI\Databases\SQL\Model\Column;
use Bootgly\ADI\Databases\SQL\Model\Key;
use Bootgly\ADI\Databases\SQL\Model\Relation;
use Bootgly\ADI\Databases\SQL\Model\Table;


#[Table('bootgly_orm_users')]
class DemoUser
{
   // * Data
   #[Key]
   public null|int $id = null;

   #[Column]
   public string $name = '';

   #[Column]
   public string $email = '';

   #[Column]
   public bool $active = true;

   #[Column]
   public float $score = 0.0;

   #[Column('created_at', insert: false, update: false, generated: true, nullable: true)]
   public null|DateTimeImmutable $CreatedAt = null;

   /** @var array<int,DemoPost> */
   #[Relation(Relations::HasMany, DemoPost::class, 'id', 'user')]
   public array $posts = [];
}