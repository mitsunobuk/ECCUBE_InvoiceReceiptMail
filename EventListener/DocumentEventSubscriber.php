<?php

namespace Plugin\InvoiceReceiptMail\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Entity\Order;
use Eccube\Event\EccubeEvents;
use Eccube\Event\EventArgs;
use Eccube\Event\TemplateEvent;
use Plugin\InvoiceReceiptMail\Form\Type\MypageReceiptType;
use Plugin\InvoiceReceiptMail\Repository\ConfigRepository;
use Plugin\InvoiceReceiptMail\Repository\DocumentLogRepository;
use Plugin\InvoiceReceiptMail\Service\DocumentMailService;
use Plugin\InvoiceReceiptMail\Service\ReceiptQueue;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class DocumentEventSubscriber implements EventSubscriberInterface
{
    /** @var DocumentMailService */
    private $documentMailService;

    /** @var ReceiptQueue */
    private $receiptQueue;

    /** @var EntityManagerInterface */
    private $entityManager;

    /** @var DocumentLogRepository */
    private $documentLogRepository;

    /** @var ConfigRepository */
    private $configRepository;

    /** @var FormFactoryInterface */
    private $formFactory;

    public function __construct(
        DocumentMailService $documentMailService,
        ReceiptQueue $receiptQueue,
        EntityManagerInterface $entityManager,
        DocumentLogRepository $documentLogRepository,
        ConfigRepository $configRepository,
        FormFactoryInterface $formFactory
    ) {
        $this->documentMailService = $documentMailService;
        $this->receiptQueue = $receiptQueue;
        $this->entityManager = $entityManager;
        $this->documentLogRepository = $documentLogRepository;
        $this->configRepository = $configRepository;
        $this->formFactory = $formFactory;
    }

    public static function getSubscribedEvents()
    {
        return [
            EccubeEvents::MAIL_ORDER => 'onMailOrder',
            // EC-CUBE の TransactionListener（優先度 0）がコミット／ロールバックした後に動かす
            KernelEvents::TERMINATE => ['sendQueuedReceipts', -100],
            ConsoleEvents::TERMINATE => ['sendQueuedReceipts', -100],
            '@admin/Order/edit.twig' => 'onAdminOrderEdit',
            'Mypage/history.twig' => 'onMypageHistory',
        ];
    }

    /**
     * 注文メールに請求書 PDF を添付する。失敗しても注文メール自体は必ず送る。
     */
    public function onMailOrder(EventArgs $event): void
    {
        try {
            $this->documentMailService->attachInvoiceToOrderMail($event->getArgument('message'), $event->getArgument('Order'));
        } catch (\Throwable $e) {
            log_error('[InvoiceReceiptMail] 請求書の添付に失敗', ['message' => $e->getMessage()]);
        }
    }

    /**
     * リクエストの最後に、入金済み（または入金済みを飛ばして発送済み）になった受注へ領収書を送る。
     */
    public function sendQueuedReceipts(): void
    {
        $orders = $this->receiptQueue->takeAll();
        if (!$orders || !$this->entityManager->isOpen()) {
            return;
        }

        // TransactionListener は自動コミットを切ったままにするため、ここで書く送信記録が
        // コミットされずに捨てられてしまう。リクエスト本体の処理は確定済みなので自動コミットに戻す。
        $connection = $this->entityManager->getConnection();
        if (!$connection->isAutoCommit()) {
            $connection->setAutoCommit(true);
        }

        foreach ($orders as $Order) {
            try {
                if (!$Order->getId()) {
                    continue;
                }
                $this->entityManager->refresh($Order);
                // 同じリクエスト内で別の状態に戻された場合は送らない
                if (!in_array((int) $Order->getOrderStatus()->getId(), [OrderStatus::PAID, OrderStatus::DELIVERED], true)) {
                    continue;
                }
                $this->documentMailService->sendReceiptIfNeeded($Order);
            } catch (\Throwable $e) {
                log_error('[InvoiceReceiptMail] 領収書の送信に失敗', ['order_id' => $Order->getId(), 'message' => $e->getMessage()]);
            }
        }
    }

    /**
     * 受注編集画面に「請求書・領収書」欄を出す。
     */
    public function onAdminOrderEdit(TemplateEvent $event): void
    {
        /** @var Order|null $Order */
        $Order = $event->getParameter('Order');
        if (!$Order instanceof Order || !$Order->getId()) {
            return;
        }

        $event->setParameter('InvoiceReceiptMailLogs', $this->documentLogRepository->findByOrderId($Order->getId()));
        $event->setParameter('InvoiceReceiptMailTarget', [
            'invoice' => $this->documentMailService->isInvoiceTarget($Order),
            'receipt' => $this->documentMailService->isReceiptTarget($Order),
        ]);
        $event->addSnippet('@InvoiceReceiptMail/admin/order_edit_documents.twig');
    }

    /**
     * マイページの注文履歴詳細に「領収書」欄を出す。
     */
    public function onMypageHistory(TemplateEvent $event): void
    {
        /** @var Order|null $Order */
        $Order = $event->getParameter('Order');
        if (!$Order instanceof Order || !$this->documentMailService->isMypageReceiptAvailable($Order)) {
            return;
        }

        $Config = $this->configRepository->get();
        $default = $this->documentMailService->defaultAddressee($Order);
        $form = $this->formFactory->create(MypageReceiptType::class, [
            'addressee' => $default['name'],
            'honorific' => $default['honorific'],
            'proviso' => $Config->getReceiptProviso(),
        ]);

        $event->setParameter('InvoiceReceiptMailForm', $form->createView());
        $event->setParameter('InvoiceReceiptMailEditable', $Config->isMypageReceiptEditable());
        $event->addSnippet('@InvoiceReceiptMail/mypage_receipt.twig');
    }
}
