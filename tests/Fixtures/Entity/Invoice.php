<?php

namespace QaTracking\CaptureBundle\Tests\Fixtures\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'test_invoice')]
class Invoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 20)]
    public string $number;

    #[ORM\Column(length: 16)]
    public string $status = 'issued';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    public Customer $customer;

    /** @var Collection<int, InvoiceLine> */
    #[ORM\OneToMany(mappedBy: 'invoice', targetEntity: InvoiceLine::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    public Collection $lines;

    public function __construct(string $number, Customer $customer)
    {
        $this->number = $number;
        $this->customer = $customer;
        $this->lines = new ArrayCollection();
    }
}
