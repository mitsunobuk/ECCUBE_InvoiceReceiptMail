<?php

namespace Plugin\InvoiceReceiptMail\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * マイページで領収書をダウンロードするときの宛名・但し書き
 */
class MypageReceiptType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('addressee', TextType::class, [
                'constraints' => [new Assert\NotBlank(), new Assert\Length(['max' => 60])],
                'attr' => ['maxlength' => 60],
            ])
            ->add('honorific', ChoiceType::class, [
                'choices' => ['様' => '様', '御中' => '御中'],
                'expanded' => true,
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('proviso', TextType::class, [
                'constraints' => [new Assert\NotBlank(), new Assert\Length(['max' => 40])],
                'attr' => ['maxlength' => 40],
            ]);
    }

    public function getBlockPrefix()
    {
        return 'invoice_receipt_mail_receipt';
    }
}
