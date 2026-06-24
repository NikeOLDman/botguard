<?php
/**
 * @author    Nickolay Mavrin <mavrinnick@gmail.com>
 * @copyright Copyright (c) 2021
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\EventSubscriber;

use App\Entity\ECommerce\Order\AppOrder;
use App\Entity\ECommerce\Order\Model\OrderAcceptEvent;
use App\Entity\ECommerce\Order\Model\StatusChangeEvent;
use App\Yandex\Handlers\ResponseHandler;
use Darvin\AdminBundle\Event\Crud\Controller\ControllerEvent;
use Darvin\AdminBundle\Event\Crud\Controller\CrudControllerEvents;
use Darvin\AdminBundle\Event\Crud\CrudEvents;
use Darvin\AdminBundle\Event\Crud\UpdatedEvent;
use Darvin\MailerBundle\Factory\Exception\CantCreateEmailException;
use Darvin\MailerBundle\Mailer\MailerInterface;
use Darvin\OrderBundle\Config\OrderConfigInterface;
use Darvin\OrderBundle\Mailer\Factory\OrderEmailFactoryInterface;
use Darvin\OrderBundle\Notifier\Telegram\TelegramNotifier;
use Darvin\OrderBundle\Type\Registry\OrderTypeRegistryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Notifier\NotifierInterface;

/**
 * Order status change event subscriber
 */
class OrderStatusChangeEventSubscriber implements EventSubscriberInterface
{
    /**
     * @var \Darvin\OrderBundle\Config\OrderConfig
     */
    protected $orderConfig;

    /**
     * @var \Darvin\OrderBundle\Type\Registry\OrderTypeRegistry
     */
    protected $orderTypeRegistry;

    /**
     * @var \Darvin\MailerBundle\Mailer\MailerInterface
     */
    private $mailer;

    /**
     * @var \Darvin\OrderBundle\Mailer\Factory\OrderEmailFactoryInterface
     */
    private $emailFactory;

    /**
     * @var \Darvin\OrderBundle\Notifier\Telegram\TelegramNotifier
     */
    private $telegramNotifier;

    /** @var \App\Yandex\Handlers\ResponseHandler */
    private $responseHandler;

    /**
     * OrderStatusChangeEventSubscriber constructor.
     *
     * @param \Darvin\OrderBundle\Config\OrderConfigInterface               $orderConfig
     * @param \Darvin\MailerBundle\Mailer\MailerInterface                   $mailer
     * @param \Darvin\OrderBundle\Type\Registry\OrderTypeRegistryInterface  $orderTypeRegistry
     * @param \Darvin\OrderBundle\Mailer\Factory\OrderEmailFactoryInterface $orderEmailFactory
     * @param \Darvin\OrderBundle\Notifier\Telegram\TelegramNotifier        $telegramNotifier
     * @param \App\Yandex\Handlers\ResponseHandler                          $responseHandler
     */
    public function __construct(
        OrderConfigInterface $orderConfig,
        MailerInterface $mailer,
        OrderTypeRegistryInterface $orderTypeRegistry,
        OrderEmailFactoryInterface $orderEmailFactory,
        TelegramNotifier $telegramNotifier,
        ResponseHandler $responseHandler
    ) {
        $this->orderConfig       = $orderConfig;
        $this->mailer            = $mailer;
        $this->orderTypeRegistry = $orderTypeRegistry;
        $this->emailFactory      = $orderEmailFactory;
        $this->telegramNotifier  = $telegramNotifier;
        $this->responseHandler   = $responseHandler;
    }

    /**
     * @return string[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            StatusChangeEvent::class => 'updateOrderStatus',
            OrderAcceptEvent::class  => 'notifyOrderYandexAccept',
            CrudEvents::UPDATED      => 'informYandex',
        ];
    }

    /**
     * @param \App\Entity\ECommerce\Order\Model\StatusChangeEvent $event
     *
     * @throws \Symfony\Component\Notifier\Exception\TransportExceptionInterface
     */
    public function updateOrderStatus(StatusChangeEvent $event)
    {
        $orderType = $this->orderTypeRegistry->getType('ecommerce_order');

        if ($orderType->getEmail()->isServiceEmailEnabled()) {
            try {
                $serviceEmail = $this->emailFactory->createServiceSubmittedEmail($event->getOrder(), $orderType);
                $serviceEmail->setSubject(sprintf('Изменен статус заказа %s', $event->getOrder()->getOrderIdApi()));
            } catch (CantCreateEmailException $ex) {
                $serviceEmail = null;
            }
            if (null !== $serviceEmail) {
                $this->mailer->send($serviceEmail);
                $this->telegramNotifier->sendMessage($event->getOrder());
            }
        }
    }

    /**
     * @param \App\Entity\ECommerce\Order\Model\OrderAcceptEvent $orderAcceptEvent
     */
    public function notifyOrderYandexAccept(OrderAcceptEvent $orderAcceptEvent)
    {
        $orderType = $this->orderTypeRegistry->getType('ecommerce_order');

        if ($orderType->getEmail()->isServiceEmailEnabled()) {
            try {
                $order      = $orderAcceptEvent->getOrder();
                $orderIdApi = $orderAcceptEvent->getOrder()->getOrderIdApi();

                $serviceEmail = $this->emailFactory->createServiceSubmittedEmail($order, $orderType);
                $serviceEmail->setSubject(sprintf('Новый заказ с Яндекса %s', $orderIdApi));
            } catch (CantCreateEmailException $ex) {
                $serviceEmail = null;
            }
            if (null !== $serviceEmail) {
                $this->mailer->send($serviceEmail);
            }
        }
    }

    /**
     * @param \Darvin\AdminBundle\Event\Crud\UpdatedEvent $event
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Yandex\Common\Exception\ForbiddenException
     * @throws \Yandex\Common\Exception\UnauthorizedException
     * @throws \Yandex\Market\Partner\Exception\PartnerRequestException
     */
    public function informYandex(UpdatedEvent $event)
    {
        /** @var AppOrder $entityBefore */
        $entityBefore = $event->getEntityBefore();
        /** @var AppOrder $entityAfter */
        $entityAfter = $event->getEntityAfter();

        if (!$entityBefore instanceof AppOrder ||
            $entityAfter->getOrderChannel() !== AppOrder::CHANNEL_YANDEX_MARKET ||
            $entityBefore->getState() === $entityAfter->getState() ||
            !in_array($entityAfter->getState(),array_keys(AppOrder::getStatusesMap()))) {
            return;
        }

        $this->responseHandler->processStatus($entityAfter);
    }
}
