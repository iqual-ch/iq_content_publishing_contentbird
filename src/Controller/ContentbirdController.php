<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing_contentbird\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface;
use Drupal\iq_contentbird_api\Service\ContentbirdApiClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for contentbird-specific platform operations.
 *
 * API authentication is handled by the iq_contentbird_api module.
 * This controller provides the "Fetch Content Statuses" action so
 * administrators can discover available status IDs for configuration.
 */
final class ContentbirdController extends ControllerBase {

  /**
   * The Contentbird API client (from iq_contentbird_api module).
   */
  protected ContentbirdApiClientInterface $apiClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->apiClient = $container->get('iq_contentbird_api.client');
    return $instance;
  }

  /**
   * Fetches and displays available content statuses from contentbird.
   *
   * @param \Drupal\iq_content_publishing\Entity\PublishingPlatformConfigInterface $platform_config
   *   The platform config entity.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect back to the platform edit form with statuses shown as messages.
   */
  public function fetchStatuses(PublishingPlatformConfigInterface $platform_config): RedirectResponse {
    $result = $this->apiClient->getContentStatuses();

    $editUrl = Url::fromRoute('entity.publishing_platform.edit_form', [
      'publishing_platform' => $platform_config->id(),
    ])->toString();

    if ($result === FALSE) {
      $this->messenger()->addWarning($this->t('Could not fetch content statuses. Please ensure the <a href="@url">Contentbird API is configured</a>.', [
        '@url' => Url::fromRoute('iq_contentbird_api.settings')->toString(),
      ]));

      return new RedirectResponse($editUrl);
    }

    // The API may return statuses as a direct array or nested in 'data'.
    $statuses = $result['data'] ?? $result;

    if (empty($statuses) || !is_array($statuses)) {
      $this->messenger()->addWarning($this->t('No content statuses found in contentbird.'));
      return new RedirectResponse($editUrl);
    }

    $this->messenger()->addStatus($this->t('Found @count content status(es):', [
      '@count' => count($statuses),
    ]));

    foreach ($statuses as $status) {
      $id = $status['id'] ?? 'N/A';
      $name = $status['name'] ?? $status['label'] ?? 'Unknown';
      $color = $status['color'] ?? '';

      $message = $this->t('ID: @id — @name', [
        '@id' => $id,
        '@name' => $name,
      ]);

      if (!empty($color)) {
        $message = $this->t('ID: @id — @name (color: @color)', [
          '@id' => $id,
          '@name' => $name,
          '@color' => $color,
        ]);
      }

      $this->messenger()->addStatus($message);
    }

    $this->messenger()->addStatus($this->t('Use the status IDs above to configure the "Status ID" fields in the platform settings.'));

    return new RedirectResponse($editUrl);
  }

}
