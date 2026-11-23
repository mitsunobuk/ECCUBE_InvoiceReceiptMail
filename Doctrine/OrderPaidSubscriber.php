<?php

namespace Plugin\InvoiceReceiptMail\Doctrine;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Entity\Order;
use Plugin\InvoiceReceiptMail\Service\ReceiptQueue;

/**
 * 受注の状態が「入金済み」または「発送済み」に変わったことを検知する。
 * 発送済みは、入金済みを飛ばして進めた受注で領収書が漏れないようにするための予備
 * （送信済みかどうかは送信時に確認するので、二重には送らない）。
 *
 * 管理画面での変更（OrderStateMachine 経由）だけでなく、決済プラグインが
 * setOrderStatus() で直接書き換える場合（カード決済など）も拾うため、
 * workflow ではなく Doctrine の onFlush で判定する。
 */
class OrderPaidSubscriber implements EventSubscriber
{
    /** @var ReceiptQueue */
    private $queue;

    public function __construct(ReceiptQueue $queue)
    {
        $this->queue = $queue;
    }

    public function getSubscribedEvents()
    {
        return [Events::onFlush];
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Order) {
                continue;
            }
            $changeSet = $uow->getEntityChangeSet($entity);
            if (!isset($changeSet['OrderStatus'])) {
                continue;
            }
            [$before, $after] = $changeSet['OrderStatus'];
            if ($this->isTrigger($after) && !$this->isSameStatus($before, $after)) {
                $this->queue->add($entity);
            }
        }

        // 管理画面で最初から「入金済み」「発送済み」で登録された受注
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Order && $this->isTrigger($entity->getOrderStatus())) {
                $this->queue->add($entity);
            }
        }
    }

    private function isTrigger($OrderStatus): bool
    {
        return $OrderStatus instanceof OrderStatus
            && in_array((int) $OrderStatus->getId(), [OrderStatus::PAID, OrderStatus::DELIVERED], true);
    }

    private function isSameStatus($before, $after): bool
    {
        return $before instanceof OrderStatus && $after instanceof OrderStatus
            && (int) $before->getId() === (int) $after->getId();
    }
}
