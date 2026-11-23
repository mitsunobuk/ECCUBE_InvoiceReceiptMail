<?php

namespace Plugin\InvoiceReceiptMail\Controller;

use Eccube\Controller\AbstractController;
use Eccube\Entity\Order;
use Eccube\Repository\OrderRepository;
use Plugin\InvoiceReceiptMail\Form\Type\MypageReceiptType;
use Plugin\InvoiceReceiptMail\Repository\ConfigRepository;
use Plugin\InvoiceReceiptMail\Service\DocumentMailService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

/**
 * マイページ（注文履歴詳細）からの領収書ダウンロード
 *
 * /mypage/ 配下なのでログイン必須。さらに自分の受注かどうかを確認する。
 */
class MypageReceiptController extends AbstractController
{
    /** @var OrderRepository */
    private $orderRepository;

    /** @var ConfigRepository */
    private $configRepository;

    /** @var DocumentMailService */
    private $documentMailService;

    public function __construct(
        OrderRepository $orderRepository,
        ConfigRepository $configRepository,
        DocumentMailService $documentMailService
    ) {
        $this->orderRepository = $orderRepository;
        $this->configRepository = $configRepository;
        $this->documentMailService = $documentMailService;
    }

    /**
     * @Route("/mypage/invoice_receipt_mail/receipt/{order_no}", name="invoice_receipt_mail_mypage_receipt", methods={"POST"})
     */
    public function receipt(Request $request, string $order_no): Response
    {
        /** @var Order|null $Order */
        $Order = $this->orderRepository->findOneBy(['order_no' => $order_no, 'Customer' => $this->getUser()]);
        if (!$Order || !$this->documentMailService->isMypageReceiptAvailable($Order)) {
            throw new NotFoundHttpException();
        }

        $Config = $this->configRepository->get();
        $default = $this->documentMailService->defaultAddressee($Order);
        $form = $this->createForm(MypageReceiptType::class, [
            'addressee' => $default['name'],
            'honorific' => $default['honorific'],
            'proviso' => $Config->getReceiptProviso(),
        ]);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addError('invoice_receipt_mail.mypage.invalid');

            return $this->redirectToRoute('mypage_history', ['order_no' => $Order->getOrderNo()]);
        }

        $data = $form->getData();
        if (!$Config->isMypageReceiptEditable()) {
            // 変更を許可していなければ、送られてきた値は使わない
            $data = ['addressee' => $default['name'], 'honorific' => $default['honorific'], 'proviso' => $Config->getReceiptProviso()];
        }

        $pdf = $this->documentMailService->mypageReceiptPdf(
            $Order,
            trim($data['addressee']).'　'.$data['honorific'],
            trim($data['proviso'])
        );

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->documentMailService->fileName('receipt', $Order).'"',
        ]);
    }
}
