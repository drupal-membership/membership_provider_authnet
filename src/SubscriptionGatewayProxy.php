<?php

declare(strict_types=1);

namespace Drupal\membership_provider_authnet;

use CommerceGuys\AuthNet\ARBCreateSubscriptionRequest;
use CommerceGuys\AuthNet\Configuration;
use CommerceGuys\AuthNet\DataTypes\Subscription;
use Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway\OnsiteBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Use a payment gateway for subscription operations.
 */
final class SubscriptionGatewayProxy implements ContainerInjectionInterface {

  /**
   * The proxied plugin.
   *
   * @var \Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway\OnsiteBase
   */
  protected $plugin;

  /**
   * HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The Authorize.net API configuration.
   *
   * @var \CommerceGuys\AuthNet\Configuration
   */
  protected $authnetConfiguration;

  /**
   * Set the proxied plugin.
   *
   * @param \Drupal\commerce_authnet\Plugin\Commerce\PaymentGateway\OnsiteBase $plugin
   */
  public function setProxiedPlugin(OnsiteBase $plugin) {
    if ($this->plugin) {
      throw new \RuntimeException('Proxied plugin already set.');
    }
    $this->plugin = $plugin;
    $configuration = $plugin->getConfiguration();
    $this->authnetConfiguration = new Configuration([
      'sandbox' => ($plugin->getMode() == 'test'),
      'api_login' => $configuration['api_login'],
      'transaction_key' => $configuration['transaction_key'],
      'client_key' => $configuration['client_key'],
    ]);
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('http_client')
    );
  }

  /**
   * {@inheritDoc}
   */
  public function __construct(ClientInterface $client) {
    $this->httpClient = $client;
  }

  public function createSubscription(Subscription $subscription) {
    $request = new ARBCreateSubscriptionRequest($this->authnetConfiguration, $this->httpClient, $subscription);
    $request->execute();
  }

}
