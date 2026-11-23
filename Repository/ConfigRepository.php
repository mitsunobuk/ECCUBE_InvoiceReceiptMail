<?php

namespace Plugin\InvoiceReceiptMail\Repository;

use Doctrine\Persistence\ManagerRegistry;
use Eccube\Repository\AbstractRepository;
use Plugin\InvoiceReceiptMail\Entity\Config;

class ConfigRepository extends AbstractRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Config::class);
    }

    /**
     * 設定を返す。まだ保存されていなければ初期値の（未保存の）設定を返す。
     */
    public function get(): Config
    {
        return $this->find(1) ?? new Config();
    }
}
