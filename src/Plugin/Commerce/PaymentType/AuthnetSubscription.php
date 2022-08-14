<?php

namespace Drupal\membership_provider_authnet\Plugin\Commerce\PaymentType;

use Drupal\commerce_authnet\Plugin\Commerce\PaymentType\AcceptJs as UpstreamAcceptJs;
use Drupal\entity\BundleFieldDefinition;

/**
 * Provides the payment type for Subscription/Recurring Payments.
 *
 * @CommercePaymentType(
 *   id = "authnet_subscription",
 *   label = @Translation("Authorize.net Subscription Payment (Accept.js)"),
 *   workflow = "payment_acceptjs"
 * )
 */
class AuthnetSubscription extends UpstreamAcceptJs {

  /**
   * {@inheritdoc}
   */
  public function buildFieldDefinitions() {
    return [
      'membership' => BundleFieldDefinition::create('entity_reference')
        ->setLabel((string) t('Membership'))
        ->setDescription((string) t('The related membership.'))
        ->setSetting('target_type', 'membership')
        ->setRequired(TRUE)
        ->setReadOnly(TRUE),
    ];
  }

}
