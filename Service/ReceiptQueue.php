<?php

namespace Plugin\InvoiceReceiptMail\Service;

use Eccube\Entity\Order;

/**
 * このリクエストの中で「入金済み」に変わった受注を覚えておく。
 * 送信は flush の外（kernel.terminate）で行う。
 */
class ReceiptQueue
{
    /** @var Order[] spl_object_hash => Order（新規作成された受注は flush 後まで ID が無いのでオブジェクトで持つ） */
    private $orders = [];

    public function add(Order $Order): void
    {
        $this->orders[spl_object_hash($Order)] = $Order;
    }

    /**
     * @return Order[]
     */
    public function takeAll(): array
    {
        $orders = array_values($this->orders);
        $this->orders = [];

        return $orders;
    }
}
