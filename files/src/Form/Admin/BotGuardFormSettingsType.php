<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\BotGuard\Form\BotGuardFormShieldTheme;
use App\Entity\BotGuard\BotGuardFormSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BotGuardFormSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enabled', CheckboxType::class, [
                'label' => 'Защита форм включена',
                'required' => false,
            ])
            ->add('protectCheckout', CheckboxType::class, [
                'label' => 'Защищать оформление заказа (checkout)',
                'required' => false,
                'help' => 'По умолчанию защищаются все формы заявок CMS (/{type}/submit). Checkout — отдельная опция.',
            ])
            ->add('minFillSeconds', IntegerType::class, [
                'label' => 'Минимальное время заполнения (сек)',
                'attr' => ['min' => 1, 'max' => 120],
            ])
            ->add('minConfirmDelayMs', IntegerType::class, [
                'label' => 'Задержка после подтверждения (мс)',
                'attr' => ['min' => 200, 'max' => 5000],
            ])
            ->add('rateLimitEnabled', CheckboxType::class, [
                'label' => 'Rate limit отправок форм',
                'required' => false,
            ])
            ->add('rateLimitMaxRequests', IntegerType::class, [
                'label' => 'Лимит отправок с IP',
                'attr' => ['min' => 1, 'max' => 1000],
            ])
            ->add('rateLimitWindowSeconds', IntegerType::class, [
                'label' => 'Окно rate limit (сек)',
                'attr' => ['min' => 60, 'max' => 86400],
            ])
            ->add('blockedNames', TextareaType::class, [
                'label' => 'Блокировать отправителей по имени',
                'required' => false,
                'help' => 'По одному фрагменту в строке. Совпадение по вхождению (без учёта регистра).',
                'attr' => ['rows' => 4, 'placeholder' => "viagra\ntest spam"],
            ])
            ->add('blockedEmails', TextareaType::class, [
                'label' => 'Блокировать отправителей по email',
                'required' => false,
                'help' => 'По одному значению в строке. Точное совпадение или вхождение (от 4 символов).',
                'attr' => ['rows' => 4, 'placeholder' => "spam@\ntest@test.com"],
            ])
            ->add('checkHoneypot', CheckboxType::class, [
                'label' => 'Проверять honeypot-поле CMS (title)',
                'required' => false,
                'help' => 'Стандартное скрытое поле Darvin CMS в формах заявок.',
            ])
            ->add('loggingEnabled', CheckboxType::class, [
                'label' => 'Логировать блокировки форм',
                'required' => false,
            ])
            ->add('shieldLogoCustomUrl', TextType::class, [
                'label' => 'URL логотипа',
                'required' => false,
                'mapped' => false,
                'data' => $this->resolveCustomLogoData($builder),
                'help' => 'По умолчанию — логотип Твердыни. Путь от корня сайта (/assets/...) или полный URL. Размер отображения: до 64×64 px.',
                'attr' => ['placeholder' => '/assets/images/my-logo.svg'],
            ])
            ->add('shieldTheme', ChoiceType::class, [
                'label' => 'Цветовая схема',
                'choices' => BotGuardFormShieldTheme::themeChoices(),
                'help' => 'По умолчанию — синяя (как в оригинальной версии Bot Guard).',
            ])
            ->add('save', SubmitType::class, [
                'label' => 'Сохранить настройки',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BotGuardFormSettings::class,
        ]);
    }

    private function resolveCustomLogoData(FormBuilderInterface $builder): string
    {
        $settings = $builder->getData();
        if (!$settings instanceof BotGuardFormSettings) {
            return '';
        }

        $current = (string) $settings->getShieldLogoUrl();
        if ('' === $current || BotGuardFormShieldTheme::LOGO_TVERDYNYA === $current) {
            return '';
        }

        return $current;
    }
}
