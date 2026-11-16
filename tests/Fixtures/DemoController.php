<?php

namespace QaTracking\CaptureBundle\Tests\Fixtures;

use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use QaTracking\CaptureBundle\Tests\Fixtures\Entity\Customer;
use QaTracking\CaptureBundle\Tests\Fixtures\Entity\Invoice;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;

/** Test application controller: writes logs, uses Doctrine and fails on demand. */
final class DemoController
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $sqlLogger,
        private readonly ?ManagerRegistry $doctrine = null,
    ) {
    }

    public function ok(): Response
    {
        return new Response('ok');
    }

    public function log(): Response
    {
        $this->logger->info('Invoice {number} viewed', ['number' => 'F001-0023', 'password' => 's3creta', 'user' => ['api_token' => 'abc', 'name' => 'Ana']]);
        $this->logger->debug('Debug detail');

        return new Response('logged');
    }

    public function sql(): Response
    {
        // Same format Doctrine DBAL uses when logging a query.
        $this->sqlLogger->debug('Executing statement: {sql} (parameters: {params}, types: {types})', [
            'sql' => 'SELECT * FROM customer WHERE email = ?',
            'params' => [1 => 'ana@example.com'],
            'types' => [1 => 2],
        ]);

        return new Response('sql');
    }

    public function boom(): Response
    {
        $this->logger->warning('The template "invoice_v2" does not exist');

        throw new \RuntimeException('Could not generate the PDF', 42, new \LogicException('Template not found'));
    }

    public function spam(): Response
    {
        for ($i = 1; $i <= 20; ++$i) {
            $this->logger->debug('Debug message '.$i);
        }
        $this->logger->error('Something went wrong');

        return new Response('spam');
    }

    /** Detail screen: reads the invoice, its customer and its lines. */
    public function invoice(#[MapEntity(id: 'id')] Invoice $invoice): Response
    {
        return new Response(\sprintf('%s for %s, %d lines', $invoice->number, $invoice->customer->name, $invoice->lines->count()));
    }

    public function invoices(): Response
    {
        return new Response((string) \count($this->doctrine->getRepository(Invoice::class)->findAll()));
    }

    public function pay(#[MapEntity(id: 'id')] Invoice $invoice): Response
    {
        $invoice->status = 'paid';
        $invoice->customer->apiToken = 'tok_rotated';
        $this->doctrine->getManager()->flush();

        return new Response('paid');
    }

    public function removeLine(#[MapEntity(id: 'id')] Invoice $invoice): Response
    {
        $invoice->lines->remove($invoice->lines->getKeys()[0]);
        $this->doctrine->getManager()->flush();

        return new Response('removed');
    }

    public function newCustomer(): Response
    {
        $em = $this->doctrine->getManager();
        $em->persist(new Customer('New customer', '10999999999'));
        $em->flush();

        return new Response('created');
    }
}
