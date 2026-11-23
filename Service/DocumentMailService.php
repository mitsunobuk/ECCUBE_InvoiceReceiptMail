<?php

namespace Plugin\InvoiceReceiptMail\Service;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Entity\BaseInfo;
use Eccube\Entity\MailHistory;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Entity\Order;
use Eccube\Repository\BaseInfoRepository;
use Eccube\Service\MailService;
use Plugin\InvoiceReceiptMail\Entity\Config;
use Plugin\InvoiceReceiptMail\Entity\DocumentLog;
use Plugin\InvoiceReceiptMail\Repository\ConfigRepository;
use Plugin\InvoiceReceiptMail\Repository\DocumentLogRepository;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class DocumentMailService
{
    /** @var EntityManagerInterface */
    private $entityManager;

    /** @var MailerInterface */
    private $mailer;

    /** @var Environment */
    private $twig;

    /** @var MailService */
    private $mailService;

    /** @var BaseInfo */
    private $BaseInfo;

    /** @var ConfigRepository */
    private $configRepository;

    /** @var DocumentLogRepository */
    private $documentLogRepository;

    /** @var DocumentPdfService */
    private $pdfService;

    public function __construct(
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        Environment $twig,
        MailService $mailService,
        BaseInfoRepository $baseInfoRepository,
        ConfigRepository $configRepository,
        DocumentLogRepository $documentLogRepository,
        DocumentPdfService $pdfService
    ) {
        $this->entityManager = $entityManager;
        $this->mailer = $mailer;
        $this->twig = $twig;
        $this->mailService = $mailService;
        $this->BaseInfo = $baseInfoRepository->get();
        $this->configRepository = $configRepository;
        $this->documentLogRepository = $documentLogRepository;
        $this->pdfService = $pdfService;
    }

    public function isInvoiceTarget(Order $Order): bool
    {
        return $this->isTarget($Order, $this->configRepository->get()->getInvoicePaymentIds());
    }

    public function isReceiptTarget(Order $Order): bool
    {
        return $this->isTarget($Order, $this->configRepository->get()->getReceiptPaymentIds());
    }

    /**
     * 注文メール（mail.order イベント）に請求書を添付する。
     * 送信記録は persist だけ行い、直後の MailHistory 保存の flush で一緒に保存される。
     */
    public function attachInvoiceToOrderMail(Email $message, Order $Order): void
    {
        if (!$this->isInvoiceTarget($Order)) {
            return;
        }

        $message->attach($this->invoicePdf($Order), $this->fileName('invoice', $Order), 'application/pdf');
        $this->entityManager->persist(new DocumentLog(
            $Order->getId(),
            DocumentLog::TYPE_INVOICE,
            DocumentPdfService::invoiceNo($Order),
            DocumentLog::SEND_AUTO,
            $Order->getEmail()
        ));

        log_info('[InvoiceReceiptMail] 注文メールに請求書を添付', ['order_no' => $Order->getOrderNo()]);
    }

    /**
     * 入金済みになった受注に領収書を送る（自動送信は 1 受注 1 回だけ）。
     *
     * @return bool 送信したら true
     */
    public function sendReceiptIfNeeded(Order $Order): bool
    {
        if (!$this->isReceiptTarget($Order)) {
            return false;
        }
        if ($this->documentLogRepository->hasSent($Order->getId(), DocumentLog::TYPE_RECEIPT)) {
            log_info('[InvoiceReceiptMail] 領収書は送信済みのため自動送信しない', ['order_no' => $Order->getOrderNo()]);

            return false;
        }

        $this->sendReceipt($Order, DocumentLog::SEND_AUTO);

        return true;
    }

    public function sendReceipt(Order $Order, string $sendType): void
    {
        $Config = $this->configRepository->get();
        $reissue = $this->documentLogRepository->hasSent($Order->getId(), DocumentLog::TYPE_RECEIPT);

        $this->send(
            $Order,
            $Config->getReceiptMailSubject(),
            '@InvoiceReceiptMail/Mail/receipt.twig',
            ['header' => $Config->getReceiptMailHeader(), 'reissue' => $reissue],
            $this->pdfService->createReceipt($Order, $Config, $reissue),
            $this->fileName('receipt', $Order),
            DocumentLog::TYPE_RECEIPT,
            DocumentPdfService::receiptNo($Order),
            $sendType
        );
    }

    /**
     * 請求書を単独のメールで送る（管理画面からの再送）
     */
    public function sendInvoice(Order $Order, string $sendType): void
    {
        $this->send(
            $Order,
            $this->configRepository->get()->getInvoiceMailSubject(),
            '@InvoiceReceiptMail/Mail/invoice.twig',
            [],
            $this->invoicePdf($Order),
            $this->fileName('invoice', $Order),
            DocumentLog::TYPE_INVOICE,
            DocumentPdfService::invoiceNo($Order),
            $sendType
        );
    }

    public function invoicePdf(Order $Order): string
    {
        return $this->pdfService->createInvoice($Order, $this->configRepository->get());
    }

    /**
     * 管理画面でのダウンロード用（送信記録は残さず、領収書は送信済みなら「再発行」表示）
     */
    public function receiptPdf(Order $Order): string
    {
        $reissue = $this->documentLogRepository->hasSent($Order->getId(), DocumentLog::TYPE_RECEIPT);

        return $this->pdfService->createReceipt($Order, $this->configRepository->get(), $reissue);
    }

    /**
     * マイページで領収書をダウンロードできる受注か
     * （設定で有効・領収書の対象の支払方法・入金済みか発送済み）
     */
    public function isMypageReceiptAvailable(Order $Order): bool
    {
        $OrderStatus = $Order->getOrderStatus();

        return $this->configRepository->get()->isMypageReceipt()
            && $this->isReceiptTarget($Order)
            && $OrderStatus
            && in_array((int) $OrderStatus->getId(), [OrderStatus::PAID, OrderStatus::DELIVERED], true);
    }

    /**
     * 宛名の初期値。会社名があれば「会社名 御中」、なければ「お名前 様」
     *
     * @return array{name: string, honorific: string}
     */
    public function defaultAddressee(Order $Order): array
    {
        if ($Order->getCompanyName()) {
            return ['name' => $Order->getCompanyName(), 'honorific' => '御中'];
        }

        return ['name' => trim($Order->getName01().' '.$Order->getName02()), 'honorific' => '様'];
    }

    /**
     * マイページからのダウンロード用。発行記録を残し、2 回目以降は「再発行」と表示する。
     */
    public function mypageReceiptPdf(Order $Order, string $addressee, string $proviso): string
    {
        $reissue = $this->documentLogRepository->hasSent($Order->getId(), DocumentLog::TYPE_RECEIPT);
        $pdf = $this->pdfService->createReceipt($Order, $this->configRepository->get(), $reissue, $addressee, $proviso);

        $this->entityManager->persist(new DocumentLog(
            $Order->getId(),
            DocumentLog::TYPE_RECEIPT,
            DocumentPdfService::receiptNo($Order),
            DocumentLog::SEND_MYPAGE,
            null,
            $addressee,
            $proviso
        ));
        $this->entityManager->flush();

        log_info('[InvoiceReceiptMail] マイページで領収書をダウンロード', ['order_no' => $Order->getOrderNo(), 'reissue' => $reissue]);

        return $pdf;
    }

    public function fileName(string $type, Order $Order): string
    {
        return ($type === 'invoice' ? 'invoice_' : 'receipt_').$Order->getOrderNo().'.pdf';
    }

    private function send(
        Order $Order,
        string $subject,
        string $template,
        array $params,
        string $pdf,
        string $fileName,
        string $documentType,
        string $documentNo,
        string $sendType
    ): void {
        $body = $this->twig->render($template, array_merge($params, [
            'Order' => $Order,
            'BaseInfo' => $this->BaseInfo,
        ]));

        $message = (new Email())
            ->subject('['.$this->BaseInfo->getShopName().'] '.$subject)
            ->from(new Address($this->BaseInfo->getEmail01(), $this->BaseInfo->getShopName()))
            ->to($this->mailService->convertRFCViolatingEmail($Order->getEmail()))
            ->bcc($this->BaseInfo->getEmail01())
            ->replyTo($this->BaseInfo->getEmail03())
            ->returnPath($this->BaseInfo->getEmail04())
            ->text($body)
            ->attach($pdf, $fileName, 'application/pdf');

        $this->mailer->send($message);

        // 管理画面の受注編集「メール配信履歴」にも残す
        $MailHistory = new MailHistory();
        $MailHistory->setMailSubject($message->getSubject())
            ->setMailBody($body)
            ->setOrder($Order)
            ->setSendDate(new \DateTime());
        $this->entityManager->persist($MailHistory);
        $this->entityManager->persist(new DocumentLog($Order->getId(), $documentType, $documentNo, $sendType, $Order->getEmail()));
        $this->entityManager->flush();

        log_info('[InvoiceReceiptMail] '.$documentType.' メール送信', ['order_no' => $Order->getOrderNo(), 'send_type' => $sendType]);
    }

    /**
     * @param int[] $paymentIds
     */
    private function isTarget(Order $Order, array $paymentIds): bool
    {
        $Payment = $Order->getPayment();

        return $Payment !== null && in_array((int) $Payment->getId(), $paymentIds, true) && $Order->getEmail();
    }
}
