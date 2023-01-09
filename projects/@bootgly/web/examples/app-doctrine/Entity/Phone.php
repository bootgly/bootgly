<?php
namespace Bootgly\Examples\Doctrine\Entity;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;

#[Entity]
class Phone
{
   #[Id, GeneratedValue, Column]
   public int $id;

   #[ManyToOne(targetEntity: Student::class, inversedBy: "phones")]
   public readonly Student $Student;

   public function __construct (
      #[Column]
      public readonly string $number
   ) {
      
   }

   public function setStudent(Student $Student)
   {
      $this->Student = $Student;
   }
}