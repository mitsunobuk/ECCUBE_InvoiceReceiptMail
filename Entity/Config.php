<?php

namespace Plugin\InvoiceReceiptMail\Entity;

use Doctrine\ORM\Mapping as ORM;
use Eccube\Entity\AbstractEntity;

/**
 * 請求書・領収書メールの設定（1 行のみ）
 *
 * @ORM\Table(name="plg_invoice_receipt_mail_config")
 * @ORM\Entity(repositoryClass="Plugin\InvoiceReceiptMail\Repository\ConfigRepository")
 */
class Config extends AbstractEntity
{
    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer", options={"unsigned":true})
     * @ORM\Id
     */
    private $id = 1;

    /**
     * 請求書を注文メールに添付する支払方法 ID（カンマ区切り）
     *
     * @var string|null
     *
     * @ORM\Column(name="invoice_payment_ids", type="string", length=255, nullable=true)
     */
    private $invoice_payment_ids;

    /**
     * 入金済みになったら領収書を送る支払方法 ID（カンマ区切り）
     *
     * @var string|null
     *
     * @ORM\Column(name="receipt_payment_ids", type="string", length=255, nullable=true)
     */
    private $receipt_payment_ids;

    /**
     * 注文時に支払いが完了する支払方法 ID（カンマ区切り）。請求書に「お支払済み」と表示する
     *
     * @var string|null
     *
     * @ORM\Column(name="prepaid_payment_ids", type="string", length=255, nullable=true)
     */
    private $prepaid_payment_ids;

    /**
     * 請求書の備考（振込先・お支払期限など）
     *
     * @var string|null
     *
     * @ORM\Column(name="invoice_note", type="text", nullable=true)
     */
    private $invoice_note;

    /**
     * 領収書の但し書き
     *
     * @var string
     *
     * @ORM\Column(name="receipt_proviso", type="string", length=255)
     */
    private $receipt_proviso = 'お品代として';

    /**
     * 領収書メールの件名
     *
     * @var string
     *
     * @ORM\Column(name="receipt_mail_subject", type="string", length=255)
     */
    private $receipt_mail_subject = 'ご入金確認・領収書送付のお知らせ';

    /**
     * 領収書メールの本文（冒頭）
     *
     * @var string|null
     *
     * @ORM\Column(name="receipt_mail_header", type="text", nullable=true)
     */
    private $receipt_mail_header;

    /**
     * 請求書を再送するメールの件名
     *
     * @var string
     *
     * @ORM\Column(name="invoice_mail_subject", type="string", length=255)
     */
    private $invoice_mail_subject = '請求書送付のお知らせ';

    /**
     * マイページの注文履歴詳細から領収書をダウンロードできるようにする
     *
     * @var bool
     *
     * @ORM\Column(name="mypage_receipt", type="boolean", options={"default":true})
     */
    private $mypage_receipt = true;

    /**
     * マイページでのダウンロード時に、お客様が宛名・但し書きを変更できる
     *
     * @var bool
     *
     * @ORM\Column(name="mypage_receipt_editable", type="boolean", options={"default":true})
     */
    private $mypage_receipt_editable = true;

    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return int[]
     */
    public function getInvoicePaymentIds(): array
    {
        return self::toIds($this->invoice_payment_ids);
    }

    /**
     * @param int[] $ids
     */
    public function setInvoicePaymentIds(array $ids): self
    {
        $this->invoice_payment_ids = self::fromIds($ids);

        return $this;
    }

    /**
     * @return int[]
     */
    public function getReceiptPaymentIds(): array
    {
        return self::toIds($this->receipt_payment_ids);
    }

    /**
     * @param int[] $ids
     */
    public function setReceiptPaymentIds(array $ids): self
    {
        $this->receipt_payment_ids = self::fromIds($ids);

        return $this;
    }

    /**
     * @return int[]
     */
    public function getPrepaidPaymentIds(): array
    {
        return self::toIds($this->prepaid_payment_ids);
    }

    /**
     * @param int[] $ids
     */
    public function setPrepaidPaymentIds(array $ids): self
    {
        $this->prepaid_payment_ids = self::fromIds($ids);

        return $this;
    }

    public function getInvoiceNote(): ?string
    {
        return $this->invoice_note;
    }

    public function setInvoiceNote(?string $invoice_note): self
    {
        $this->invoice_note = $invoice_note;

        return $this;
    }

    public function getReceiptProviso(): string
    {
        return $this->receipt_proviso;
    }

    public function setReceiptProviso(?string $receipt_proviso): self
    {
        $this->receipt_proviso = (string) $receipt_proviso;

        return $this;
    }

    public function getReceiptMailSubject(): string
    {
        return $this->receipt_mail_subject;
    }

    public function setReceiptMailSubject(?string $receipt_mail_subject): self
    {
        $this->receipt_mail_subject = (string) $receipt_mail_subject;

        return $this;
    }

    public function getReceiptMailHeader(): ?string
    {
        return $this->receipt_mail_header;
    }

    public function setReceiptMailHeader(?string $receipt_mail_header): self
    {
        $this->receipt_mail_header = $receipt_mail_header;

        return $this;
    }

    public function getInvoiceMailSubject(): string
    {
        return $this->invoice_mail_subject;
    }

    public function setInvoiceMailSubject(?string $invoice_mail_subject): self
    {
        $this->invoice_mail_subject = (string) $invoice_mail_subject;

        return $this;
    }

    private static function toIds(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return array_values(array_map('intval', explode(',', $value)));
    }

    private static function fromIds(array $ids): ?string
    {
        $ids = array_unique(array_map('intval', $ids));
        sort($ids);

        return $ids ? implode(',', $ids) : null;
    }

    public function isMypageReceipt(): bool
    {
        return (bool) $this->mypage_receipt;
    }

    public function setMypageReceipt(bool $mypage_receipt): self
    {
        $this->mypage_receipt = $mypage_receipt;

        return $this;
    }

    public function isMypageReceiptEditable(): bool
    {
        return (bool) $this->mypage_receipt_editable;
    }

    public function setMypageReceiptEditable(bool $mypage_receipt_editable): self
    {
        $this->mypage_receipt_editable = $mypage_receipt_editable;

        return $this;
    }
}
