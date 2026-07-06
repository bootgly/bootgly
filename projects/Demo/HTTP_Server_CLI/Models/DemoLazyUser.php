<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
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
use Bootgly\ADI\Databases\SQL\Repository\LazyCollection;


#[Table('bootgly_orm_users')]
class DemoLazyUser
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

   /** @var LazyCollection<DemoPost> */
   #[Relation(Relations::HasMany, DemoPost::class, 'id', 'user', lazy: true)]
   public LazyCollection $posts;
}