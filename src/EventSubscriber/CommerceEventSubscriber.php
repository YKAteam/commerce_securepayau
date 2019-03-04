<?php

namespace Drupal\commerce_securepayau\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\commerce_checkout\Event\CheckoutEvents;
use Drupal\commerce_securepayau\Plugin\Commerce\PaymentGateway\SecurePay;

/**
 * Event Subscriber for Commerce events.
 */
class CommerceEventSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public function onCheckout(CheckoutCompleteEvent $event) {
    // Forget the payment details after checking out.
    SecurePay::destroyPaymentDetails();
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[CheckoutEvents::CHECKOUT_COMPLETE][] = ['onCheckout'];

    return $events;
  }

}
