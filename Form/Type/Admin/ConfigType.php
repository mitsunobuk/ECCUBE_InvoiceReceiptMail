<?php

namespace Plugin\InvoiceReceiptMail\Form\Type\Admin;

use Eccube\Entity\Payment;
use Eccube\Repository\PaymentRepository;
use Plugin\InvoiceReceiptMail\Repository\ConfigRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

class ConfigType extends AbstractType
{
    /** @var PaymentRepository */
    private $paymentRepository;

    /** @var ConfigRepository */
    private $configRepository;

    public function __construct(PaymentRepository $paymentRepository, ConfigRepository $configRepository)
    {
        $this->paymentRepository = $paymentRepository;
        $this->configRepository = $configRepository;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        // 表示中の支払方法だけを出す。非表示でも、すでに設定で選ばれているものは
        // 保存時に消えないよう「（非表示）」付きで残す。
        $Config = $this->configRepository->get();
        $selectedIds = array_merge($Config->getInvoicePaymentIds(), $Config->getReceiptPaymentIds(), $Config->getPrepaidPaymentIds());
        $payments = [];
        /** @var Payment $Payment */
        foreach ($this->paymentRepository->findBy([], ['sort_no' => 'DESC']) as $Payment) {
            if (!$Payment->isVisible() && !in_array((int) $Payment->getId(), $selectedIds, true)) {
                continue;
            }
            $label = $Payment->getMethod().($Payment->isVisible() ? '' : '（非表示）');
            $payments[$label.' [ID:'.$Payment->getId().']'] = $Payment->getId();
        }

        $builder
            ->add('invoice_payment_ids', ChoiceType::class, [
                'choices' => $payments,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('receipt_payment_ids', ChoiceType::class, [
                'choices' => $payments,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('prepaid_payment_ids', ChoiceType::class, [
                'choices' => $payments,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('invoice_note', TextareaType::class, [
                'required' => false,
                'constraints' => [new Assert\Length(['max' => 2000])],
            ])
            ->add('receipt_proviso', TextType::class, [
                'constraints' => [new Assert\NotBlank(), new Assert\Length(['max' => 100])],
            ])
            ->add('invoice_mail_subject', TextType::class, [
                'constraints' => [new Assert\NotBlank(), new Assert\Length(['max' => 200])],
            ])
            ->add('receipt_mail_subject', TextType::class, [
                'constraints' => [new Assert\NotBlank(), new Assert\Length(['max' => 200])],
            ])
            ->add('receipt_mail_header', TextareaType::class, [
                'required' => false,
                'constraints' => [new Assert\Length(['max' => 4000])],
            ])
            ->add('stamp_image', FileType::class, [
                'required' => false,
                'constraints' => [new Assert\Image([
                    'maxSize' => '2M',
                    'mimeTypes' => ['image/png', 'image/jpeg'],
                    'mimeTypesMessage' => 'PNG または JPEG の画像を選んでください。',
                ])],
            ])
            ->add('mypage_receipt', CheckboxType::class, [
                'required' => false,
                'label' => 'マイページの注文履歴から領収書をダウンロードできるようにする',
            ])
            ->add('mypage_receipt_editable', CheckboxType::class, [
                'required' => false,
                'label' => 'お客様が宛名・但し書きを変更できるようにする',
            ])
            ->add('stamp_delete', CheckboxType::class, [
                'required' => false,
                'label' => '社印を削除する',
            ]);
    }
}
