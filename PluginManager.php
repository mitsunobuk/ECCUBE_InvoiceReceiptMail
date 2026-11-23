<?php

namespace Plugin\InvoiceReceiptMail;

use Eccube\Entity\Payment;
use Eccube\Plugin\AbstractPluginManager;
use Plugin\InvoiceReceiptMail\Entity\Config;
use Psr\Container\ContainerInterface;

class PluginManager extends AbstractPluginManager
{
    /**
     * 初回の有効化で設定を作る。
     * 初期値：銀行振込とクレジットカード決済を請求書・領収書の両方の対象にし、
     * クレジットカード決済は注文時に支払い済み（請求書に「お支払済み」と表示）とする。
     * クレジットカード決済は決済会社を問わず、支払方法名で判定する。設定画面で変更できる。
     */
    public function enable(array $meta, ContainerInterface $container)
    {
        $em = $container->get('doctrine')->getManager();
        if ($em->find(Config::class, 1)) {
            return;
        }

        $ids = [];
        $prepaidIds = [];
        /** @var Payment $Payment */
        foreach ($em->getRepository(Payment::class)->findAll() as $Payment) {
            if (!$Payment->isVisible()) {
                continue;
            }
            $method = (string) $Payment->getMethod();
            $isBankTransfer = mb_strpos($method, '銀行振込') !== false;
            $isCreditCard = mb_strpos($method, 'クレジット') !== false;
            if ($isBankTransfer || $isCreditCard) {
                $ids[] = $Payment->getId();
            }
            if ($isCreditCard) {
                $prepaidIds[] = $Payment->getId();
            }
        }

        $Config = (new Config())
            ->setInvoicePaymentIds($ids)
            ->setReceiptPaymentIds($ids)
            ->setPrepaidPaymentIds($prepaidIds);
        $em->persist($Config);
        $em->flush();
    }
}
