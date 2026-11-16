<?php

namespace QaTracking\CaptureBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'test_invoice_line')]
class InvoiceLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false)]
    public Invoice $invoice;

    #[ORM\Column(length: 200)]
    public string $description;

    public function __construct(Invoice $invoice, string $description)
    {
        $this->invoice = $invoice;
        $this->description = $description;
        $invoice->lines->add($this);
    }
}
