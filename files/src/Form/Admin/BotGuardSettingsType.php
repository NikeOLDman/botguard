<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\BotGuard\BotGuardSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BotGuardSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enabled', CheckboxType::class, [
                'label' => 'Защита включена',
                'required' => false,
            ])
            ->add('underAttack', CheckboxType::class, [
                'label' => 'Режим «Под атакой»',
                'required' => false,
            ])
            ->add('blockEmptyUserAgent', CheckboxType::class, [
                'label' => 'Блокировать пустой User-Agent',
                'required' => false,
            ])
            ->add('loggingEnabled', CheckboxType::class, [
                'label' => 'Вести журнал блокировок',
                'required' => false,
            ])
            ->add('trustReferrer', CheckboxType::class, [
                'label' => 'Доверять внешнему referrer (мягкая cookie-проверка)',
                'required' => false,
                'help' => 'Пропускает только переходы с доменов из списка ниже. Работает для мягкой cookie-проверки, включая страницы фильтров каталога.',
            ])
            ->add('trustedReferrerDomains', TextareaType::class, [
                'label' => 'Домены доверенного referrer',
                'required' => false,
                'help' => 'По одному домену в строке. Пусто — встроенный список (yandex.ru, google.com, vk.com и др.).',
                'attr' => [
                    'rows' => 4,
                    'placeholder' => "yandex.ru\nya.ru\ngoogle.com\nvk.com",
                ],
            ])
            ->add('blockStatusCode', IntegerType::class, [
                'label' => 'HTTP-код при блокировке',
                'attr' => ['min' => 400, 'max' => 599],
            ])
            ->add('retentionDays', IntegerType::class, [
                'label' => 'Хранить логи (дней)',
                'attr' => ['min' => 1, 'max' => 3650],
            ])
            ->add('cookieWhitelistUserAgents', TextareaType::class, [
                'label' => 'Белый список User-Agent',
                'required' => false,
                'help' => 'По одному значению в строке или через запятую. Действует всегда, включая режим «Под атакой».',
                'attr' => [
                    'rows' => 5,
                    'placeholder' => "YandexBot\nGooglebot\nbingbot",
                ],
            ])
            ->add('jsChallengeMinDelayMs', IntegerType::class, [
                'label' => 'Минимальная задержка JS-проверки (мс)',
                'attr' => ['min' => 500, 'max' => 10000],
                'help' => 'Сервер отклонит verify-запрос, если он пришёл раньше этого интервала после выдачи nonce.',
            ])
            ->add('catalogFilterPagesSoftCheck', CheckboxType::class, [
                'label' => 'Мягкая проверка для страниц фильтров каталога',
                'required' => false,
                'help' => 'Страницы фильтров, созданные в каталоге в админке, проходят мягкую cookie-проверку вместо строгой JS для /filtered. Переходы из поиска на эти страницы пропускаются без челленджа.',
            ])
            ->add('pathRateLimitEnabled', CheckboxType::class, [
                'label' => 'Rate limit для защищённых путей',
                'required' => false,
                'help' => 'Лимит по подсети /24, работает без режима «Под атакой».',
            ])
            ->add('pathRateLimitUriPattern', TextareaType::class, [
                'label' => 'URI для path rate limit',
                'required' => false,
                'attr' => [
                    'rows' => 2,
                    'placeholder' => '/filtered',
                ],
            ])
            ->add('pathRateLimitMaxRequests', IntegerType::class, [
                'label' => 'Лимит запросов (path rate limit)',
                'attr' => ['min' => 1, 'max' => 10000],
            ])
            ->add('pathRateLimitWindowSeconds', IntegerType::class, [
                'label' => 'Окно path rate limit (сек)',
                'attr' => ['min' => 10, 'max' => 3600],
            ])
            ->add('rateLimitEnabled', CheckboxType::class, [
                'label' => 'Rate limit в режиме «Под атакой»',
                'required' => false,
            ])
            ->add('rateLimitMaxRequests', IntegerType::class, [
                'label' => 'Лимит запросов с IP за окно',
                'attr' => ['min' => 1, 'max' => 10000],
            ])
            ->add('rateLimitWindowSeconds', IntegerType::class, [
                'label' => 'Окно rate limit (сек)',
                'attr' => ['min' => 10, 'max' => 3600],
            ])
            ->add('reduceLoggingUnderAttack', CheckboxType::class, [
                'label' => 'Снизить логирование в режиме «Под атакой»',
                'required' => false,
            ])
            ->add('autoUnderAttackEnabled', CheckboxType::class, [
                'label' => 'Автовключение «Под атакой» по нагрузке',
                'required' => false,
            ])
            ->add('autoUnderAttackCpuPercent', IntegerType::class, [
                'label' => 'Порог CPU (%), включение',
                'attr' => ['min' => 50, 'max' => 100],
            ])
            ->add('autoUnderAttackMemPercent', IntegerType::class, [
                'label' => 'Порог RAM (%), включение',
                'attr' => ['min' => 50, 'max' => 100],
            ])
            ->add('autoUnderAttackDurationMinutes', IntegerType::class, [
                'label' => 'Длительность повышенной нагрузки (мин)',
                'help' => 'Сколько минут подряд нагрузка должна быть выше порога (шаг cron × 1 мин).',
                'attr' => ['min' => 1, 'max' => 120],
            ])
            ->add('autoUnderAttackReleasePercent', IntegerType::class, [
                'label' => 'Порог снижения нагрузки (%), выключение',
                'attr' => ['min' => 40, 'max' => 99],
            ])
            ->add('save', SubmitType::class, [
                'label' => 'Сохранить настройки',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BotGuardSettings::class,
        ]);
    }
}
