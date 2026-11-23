<?php

namespace Plugin\InvoiceReceiptMail\Entity;

use Doctrine\ORM\Mapping as ORM;
use Eccube\Entity\AbstractEntity;

/**
 * 請求書・領収書の送信記録
 *
 * order_id は外部キーにしない（受注データ一括削除などで受注を消せなくならないように）。
 *
 * @ORM\Table(name="plg_invoice_receipt_mail_log", indexes={
 *     @ORM\Index(name="plg_invoice_receipt_mail_log_order_idx", columns={"order_id", "document_type"})
 * })
 * @ORM\Entity(repositoryClass="Plugin\InvoiceReceiptMail\Repository\DocumentLogRepository")
 */
class DocumentLog extends AbstractEntity
{
    public const TYPE_INVOICE = 'invoice';
    public const TYPE_RECEIPT = 'receipt';

    /** 注文メール・入金済み時に自動で送ったもの */
    public const SEND_AUTO = 'auto';
    /** 管理画面から手動で送ったもの */
    public const SEND_MANUAL = 'manual';
    /** お客様がマイページからダウンロードしたもの */
    public const SEND_MYPAGE = 'mypage';

    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer", options={"unsigned":true})
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @var int
     *
     * @ORM\Column(name="order_id", type="integer", options={"unsigned":true})
     */
    private $order_id;

    /**
     * @var string
     *
     * @ORM\Column(name="document_type", type="string", length=20)
     */
    private $document_type;

    /**
     * @var string
     *
     * @ORM\Column(name="document_no", type="string", length=50)
     */
    private $document_no;

    /**
     * @var string
     *
     * @ORM\Column(name="send_type", type="string", length=20)
     */
    private $send_type;

    /**
     * @var string|null
     *
     * @ORM\Column(name="sent_to", type="string", length=255, nullable=true)
     */
    private $sent_to;

    /**
     * 領収書の宛名（マイページで指定されたもの。それ以外は null）
     *
     * @var string|null
     *
     * @ORM\Column(name="addressee", type="string", length=255, nullable=true)
     */
    private $addressee;

    /**
     * 領収書の但し書き（マイページで指定されたもの。それ以外は null）
     *
     * @var string|null
     *
     * @ORM\Column(name="proviso", type="string", length=255, nullable=true)
     */
    private $proviso;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="create_date", type="datetimetz")
     */
    private $create_date;

    public function __construct(
        int $orderId,
        string $documentType,
        string $documentNo,
        string $sendType,
        ?string $sentTo,
        ?string $addressee = null,
        ?string $proviso = null
    ) {
        $this->order_id = $orderId;
        $this->document_type = $documentType;
        $this->document_no = $documentNo;
        $this->send_type = $sendType;
        $this->sent_to = $sentTo;
        $this->addressee = $addressee;
        $this->proviso = $proviso;
        $this->create_date = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrderId(): int
    {
        return $this->order_id;
    }

    public function getDocumentType(): string
    {
        return $this->document_type;
    }

    public function getDocumentNo(): string
    {
        return $this->document_no;
    }

    public function getSendType(): string
    {
        return $this->send_type;
    }

    public function getSentTo(): ?string
    {
        return $this->sent_to;
    }

    public function getAddressee(): ?string
    {
        return $this->addressee;
    }

    public function getProviso(): ?string
    {
        return $this->proviso;
    }

    public function getCreateDate(): \DateTime
    {
        return $this->create_date;
    }
}
