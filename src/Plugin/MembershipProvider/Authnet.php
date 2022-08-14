<?php

declare(strict_types=1);

namespace Drupal\membership_provider_authnet\Plugin\MembershipProvider;

use CommerceGuys\AuthNet\DataTypes\CustomerProfileId;
use CommerceGuys\AuthNet\DataTypes\PaymentSchedule;
use CommerceGuys\AuthNet\DataTypes\Subscription;
use CommerceGuys\AuthNet\Exception\AuthNetException;
use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway\OnsiteBase;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\membership\Annotation\MembershipProvider;
use Drupal\membership\Entity\MembershipInterface;
use Drupal\membership\Plugin\MembershipProviderBase;
use Drupal\membership_offer\MembershipOfferInterface;
use Drupal\membership_provider_authnet\SubscriptionGatewayProxy;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Authorize.Net membership provider.
 *
 * @MembershipProvider(
 *   id = "authnet",
 *   label = "Authorize.net",
 * )
 */
final class Authnet extends MembershipProviderBase {

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Class resolver.
   *
   * @var \Drupal\Core\DependencyInjection\ClassResolverInterface
   */
  protected $classResolver;

  /**
   * Currency formatter.
   *
   * @var \CommerceGuys\Intl\Formatter\CurrencyFormatterInterface
   */
  protected $currencyFormatter;

  /**
   * Class resolver.
   *
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $classResolver
   */
  public function setClassResolver(ClassResolverInterface $classResolver) {
    $this->classResolver = $classResolver;
  }

  /**
   * Currency formatter.
   *
   * @param \CommerceGuys\Intl\Formatter\CurrencyFormatterInterface $currencyFormatter
   */
  public function setCurrencyFormatter(CurrencyFormatterInterface $currencyFormatter) {
    $this->currencyFormatter = $currencyFormatter;
  }

  /**
   * Setter for the entity type manager.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   */
  public function setEntityTypeManager(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * @inheritDoc
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $plugin = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $plugin->setClassResolver($container->get('class_resolver'));
    $plugin->setCurrencyFormatter($container->get('commerce_price.currency_formatter'));
    $plugin->setEntityTypeManager($container->get('entity_type.manager'));
    return $plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'gateway' => '',
      ] + parent::defaultConfiguration();
  }

  /**
   * Lazy instantiation of the entity type manager.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected function entityTypeManager(): EntityTypeManagerInterface {
    if (!$this->entityTypeManager) {
      $this->entityTypeManager = $this->classResolver
        ->getInstanceFromDefinition('entity_type.manager');
    }
    return $this->entityTypeManager;
  }

  /**
   * {@inheritDoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface[] $gateways */
    $gateways = $this->entityTypeManager()->getStorage('commerce_payment_gateway')
      ->loadMultiple();
    $authnetGateways = [];
    foreach ($gateways as $gateway) {
      if ($gateway->getPlugin() instanceof OnsiteBase) {
        $authnetGateways[$gateway->id()] = $gateway->label();
      }
    }
    $form['gateway'] = [
      '#type' => 'select',
      '#options' => $authnetGateways,
      '#required' => TRUE,
      '#title' => $this->t('Gateway'),
      '#default_value' => $this->configuration['gateway'] ?? NULL,
    ];
    if (empty($authnetGateways)) {
      $form['warning']['#markup'] = $this->t('No Authorize.Net gateways are configured.');
      return $form;
    }
    return $form;
  }

  /**
   * {@inheritDoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    // This should be caught by client-side validation, but just in case.
    $values = $form_state->getValue($form['#parents']);
    if (!$values['gateway']) {
      $form_state->setError($form['gateway'], $this->t('An Authorize.Net gateway is required.'));
    }
  }

  /**
   * {@inheritDoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['gateway'] = $values['gateway'];
    }
  }

  /**
   * {@inheritDoc}
   */
  public function postCreateMembership(MembershipInterface $membership, array $pluginValues = []): void {
    assert($pluginValues['membership_offer'] instanceof MembershipOfferInterface);
    assert($pluginValues['payment_method'] instanceof PaymentMethodInterface);
    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $paymentMethod */
    $paymentMethod = $pluginValues['payment_method'];
    /** @var \Drupal\membership_offer\MembershipOfferInterface $offer */
    $offer = $pluginValues['membership_offer'];
    try {
      $this->makeInitialPayment($membership, $offer, $paymentMethod);
      $this->createAuthnetSubscription($membership, $offer, $paymentMethod);
    }
    catch (PaymentGatewayException $e) {
      // First-class Drupal Commerce payment exceptions.
    }
    catch (AuthNetException $e) {
      // Lower-level Authnet exception, from subscription failures.
    }
  }

  /**
   * Create subscription at Authnet.
   *
   * @param \Drupal\membership\Entity\MembershipInterface $membership
   * @param \Drupal\membership_offer\MembershipOfferInterface $offer
   * @param \Drupal\commerce_payment\Entity\PaymentMethodInterface $paymentMethod
   */
  protected function createAuthnetSubscription(MembershipInterface $membership, MembershipOfferInterface $offer, PaymentMethodInterface $paymentMethod) {
    $subscription = new Subscription([
      'name' => $offer->label(),
      'amount' => $this->currencyFormatter->format(
        $offer->getPrice()->getNumber(),
        $offer->getPrice()->getCurrencyCode()
      ),
    ]);
    $subscription->addPaymentSchedule(new PaymentSchedule([
      // @todo Make this configurable.
      'interval' => [
        'length' => 1,
        'unit' => 'months',
      ],
      // Start one month from now; we want to immediately process the first
      // payment, otherwise Authnet waits until 2 am the following day.
      // @see https://developer.authorize.net/api/reference/features/recurring_billing.html#Subscriptions
      'startDate' => (new DrupalDateTime())
        ->add(new \DateInterval('P1M'))->format('Y-m-d'),
    ]));
    $subscription->addProfile(new CustomerProfileId([
      'customerProfileId' => $this->getGatewayPlugin()
        ->getRemoteCustomerId($paymentMethod->getOwner()),
      'customerPaymentProfileId' => $paymentMethod->getRemoteId(),
    ]));
    /** @var \Drupal\membership_provider_authnet\SubscriptionGatewayProxy $gateway */
    $gateway = $this->classResolver->getInstanceFromDefinition(SubscriptionGatewayProxy::class);
    $gateway->setProxiedPlugin($this->getGatewayPlugin());
    $gateway->createSubscription($subscription);
  }

  /**
   * Make an initial payment for a subscription, immediately.
   *
   * @param \Drupal\membership\Entity\MembershipInterface $membership
   *   The membership.
   * @param \Drupal\membership_offer\MembershipOfferInterface $offer
   *   Membership offer.
   * @param \Drupal\commerce_payment\Entity\PaymentMethodInterface $paymentMethod
   *   Payment method.
   */
  protected function makeInitialPayment(MembershipInterface $membership, MembershipOfferInterface $offer, PaymentMethodInterface $paymentMethod) {
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entityTypeManager->getStorage('commerce_payment')
      ->create([
        'type' => 'authnet_subscription',
        'amount' => $offer->getPrice(),
        'payment_method' => $paymentMethod,
        'payment_gateway' => $paymentMethod->getPaymentGatewayId(),
      ]);
    $this->getGatewayPlugin()->createPayment($payment);
    $payment->set('membership', $membership->id());
    $payment->save();
  }

  /**
   * Get the gateway plugin.
   *
   * @return \Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway\OnsiteBase
   *   Configured gateway plugin.
   */
  protected function getGatewayPlugin(): OnsiteBase {
    /** @var \Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway\OnsiteBase $gateway */
    $gateway = $this->entityTypeManager->getStorage('commerce_payment_gateway')
      ->load($this->configuration['gateway'])->getPlugin();
    return $gateway;
  }

}
