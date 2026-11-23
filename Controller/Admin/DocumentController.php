<?php

namespace Plugin\InvoiceReceiptMail\Controller\Admin;

use Eccube\Controller\AbstractController;
use Eccube\Entity\Order;
use Eccube\Repository\OrderRepository;
use Plugin\InvoiceReceiptMail\Entity\DocumentLog;
use Plugin\InvoiceReceiptMail\Service\DocumentMailService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

/**
 * 受注編集画面からの PDF ダウンロード・メール送信
 */
class DocumentController extends AbstractController
{
    /** @var OrderRepository */
    private $orderRepository;

    /** @var DocumentMailService */
    private $documentMailService;

    public function __construct(OrderRepository $orderRepository, DocumentMailService $documentMailService)
    {
        $this->orderRepository = $orderRepository;
        $this->documentMailService = $documentMailService;
    }

    /**
     * @Route("/%eccube_admin_route%/invoice_receipt_mail/{type}/{id}/download",
     *     requirements={"type" = "invoice|receipt", "id" = "\d+"},
     *     name="invoice_receipt_mail_admin_download", methods={"GET"})
     */
    public function download(string $type, int $id): Response
    {
        $Order = $this->findOrder($id);
        $pdf = $type === DocumentLog::TYPE_INVOICE
            ? $this->documentMailService->invoicePdf($Order)
            : $this->documentMailService->receiptPdf($Order);

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->documentMailService->fileName($type, $Order).'"',
        ]);
    }

    /**
     * @Route("/%eccube_admin_route%/invoice_receipt_mail/{type}/{id}/send",
     *     requirements={"type" = "invoice|receipt", "id" = "\d+"},
     *     name="invoice_receipt_mail_admin_send", methods={"POST"})
     */
    public function send(string $type, int $id): Response
    {
        $this->isTokenValid();
        $Order = $this->findOrder($id);

        try {
            if ($type === DocumentLog::TYPE_INVOICE) {
                $this->documentMailService->sendInvoice($Order, DocumentLog::SEND_MANUAL);
                $this->addSuccess('invoice_receipt_mail.admin.sent_invoice', 'admin');
            } else {
                $this->documentMailService->sendReceipt($Order, DocumentLog::SEND_MANUAL);
                $this->addSuccess('invoice_receipt_mail.admin.sent_receipt', 'admin');
            }
        } catch (\Throwable $e) {
            log_error('[InvoiceReceiptMail] 手動送信に失敗', ['order_id' => $id, 'message' => $e->getMessage()]);
            $this->addError('invoice_receipt_mail.admin.send_failed', 'admin');
        }

        return $this->redirectToRoute('admin_order_edit', ['id' => $id]);
    }

    private function findOrder(int $id): Order
    {
        $Order = $this->orderRepository->find($id);
        if (!$Order || !$Order->getOrderNo()) {
            throw new NotFoundHttpException();
        }

        return $Order;
    }
}
