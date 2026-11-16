<?php

namespace QaTracking\CaptureBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'test_customer')]
class Customer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 120)]
    public string $name;

    #[ORM\Column(length: 20)]
    public string $taxId;

    #[ORM\Column(length: 64)]
    public string $apiToken = 'tok_secret';

    public function __construct(string $name, string $taxId)
    {
        $this->name = $name;
        $this->taxId = $taxId;
    }
}
