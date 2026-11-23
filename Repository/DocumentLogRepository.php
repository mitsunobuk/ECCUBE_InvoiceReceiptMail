<?php

namespace Plugin\InvoiceReceiptMail\Repository;

use Doctrine\Persistence\ManagerRegistry;
use Eccube\Repository\AbstractRepository;
use Plugin\InvoiceReceiptMail\Entity\DocumentLog;

class DocumentLogRepository extends AbstractRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentLog::class);
    }

    public function hasSent(int $orderId, string $documentType): bool
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.order_id = :order_id')
            ->andWhere('l.document_type = :type')
            ->setParameter('order_id', $orderId)
            ->setParameter('type', $documentType)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * @return DocumentLog[]
     */
    public function findByOrderId(int $orderId): array
    {
        return $this->findBy(['order_id' => $orderId], ['id' => 'DESC']);
    }
}
