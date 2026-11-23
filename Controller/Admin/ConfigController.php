<?php

namespace Plugin\InvoiceReceiptMail\Controller\Admin;

use Eccube\Controller\AbstractController;
use Plugin\InvoiceReceiptMail\Form\Type\Admin\ConfigType;
use Plugin\InvoiceReceiptMail\Repository\ConfigRepository;
use Plugin\InvoiceReceiptMail\Service\StampStorage;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class ConfigController extends AbstractController
{
    /** @var ConfigRepository */
    private $configRepository;

    /** @var StampStorage */
    private $stampStorage;

    public function __construct(ConfigRepository $configRepository, StampStorage $stampStorage)
    {
        $this->configRepository = $configRepository;
        $this->stampStorage = $stampStorage;
    }

    /**
     * @Route("/%eccube_admin_route%/invoice_receipt_mail/config", name="invoice_receipt_mail_admin_config")
     * @Template("@InvoiceReceiptMail/admin/config.twig")
     */
    public function index(Request $request)
    {
        $Config = $this->configRepository->get();
        $form = $this->createForm(ConfigType::class, [
            'invoice_payment_ids' => $Config->getInvoicePaymentIds(),
            'receipt_payment_ids' => $Config->getReceiptPaymentIds(),
            'prepaid_payment_ids' => $Config->getPrepaidPaymentIds(),
            'invoice_note' => $Config->getInvoiceNote(),
            'receipt_proviso' => $Config->getReceiptProviso(),
            'invoice_mail_subject' => $Config->getInvoiceMailSubject(),
            'receipt_mail_subject' => $Config->getReceiptMailSubject(),
            'receipt_mail_header' => $Config->getReceiptMailHeader(),
            'mypage_receipt' => $Config->isMypageReceipt(),
            'mypage_receipt_editable' => $Config->isMypageReceiptEditable(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $Config
                ->setInvoicePaymentIds($data['invoice_payment_ids'] ?? [])
                ->setReceiptPaymentIds($data['receipt_payment_ids'] ?? [])
                ->setPrepaidPaymentIds($data['prepaid_payment_ids'] ?? [])
                ->setInvoiceNote($data['invoice_note'])
                ->setReceiptProviso($data['receipt_proviso'])
                ->setInvoiceMailSubject($data['invoice_mail_subject'])
                ->setReceiptMailSubject($data['receipt_mail_subject'])
                ->setReceiptMailHeader($data['receipt_mail_header'])
                ->setMypageReceipt((bool) $data['mypage_receipt'])
                ->setMypageReceiptEditable((bool) $data['mypage_receipt_editable']);
            if ($data['stamp_image']) {
                $this->stampStorage->save($data['stamp_image']);
            } elseif ($data['stamp_delete']) {
                $this->stampStorage->delete();
            }
            $this->entityManager->persist($Config);
            $this->entityManager->flush();
            $this->addSuccess('admin.common.save_complete', 'admin');

            return $this->redirectToRoute('invoice_receipt_mail_admin_config');
        }

        return [
            'form' => $form->createView(),
            'stamp' => $this->stampStorage->dataUri(),
        ];
    }
}
