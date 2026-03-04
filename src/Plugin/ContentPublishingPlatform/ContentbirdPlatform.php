<?php

declare(strict_types=1);

namespace Drupal\iq_content_publishing_contentbird\Plugin\ContentPublishingPlatform;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\iq_content_publishing\Attribute\ContentPublishingPlatform;
use Drupal\iq_content_publishing\Plugin\ContentPublishingPlatformBase;
use Drupal\iq_content_publishing\Plugin\MultiToolPlatformInterface;
use Drupal\iq_content_publishing\Plugin\PublishingResult;
use Drupal\iq_contentbird_api\Service\ContentbirdApiClientInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Contentbird publishing platform plugin.
 *
 * Syncs Drupal content with the contentbird platform: updates content statuses,
 * sends published URLs, and pushes content data back to contentbird when
 * publishing from Drupal.
 *
 * API token authentication is managed centrally by the iq_contentbird_api
 * module.
 */
#[ContentPublishingPlatform(
  id: 'contentbird',
  label: new TranslatableMarkup('Contentbird'),
  description: new TranslatableMarkup('Sync content with the contentbird platform — update statuses, send published URLs, and push content data.'),
)]
final class ContentbirdPlatform extends ContentPublishingPlatformBase implements MultiToolPlatformInterface {

  /**
   * The Contentbird API client (from iq_contentbird_api module).
   */
  protected ContentbirdApiClientInterface $apiClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->apiClient = $container->get('iq_contentbird_api.client');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getOutputSchema(): array {
    return [
      'title' => [
        'type' => 'textfield',
        'label' => (string) $this->t('Title'),
        'description' => (string) $this->t('The content title for contentbird.'),
        'required' => TRUE,
        'ai_generated' => TRUE,
      ],
      'summary' => [
        'type' => 'textarea',
        'label' => (string) $this->t('Summary / Meta description'),
        'description' => (string) $this->t('A short summary or meta description. Keep under 160 characters for SEO best practices.'),
        'required' => FALSE,
        'max_length' => 160,
        'ai_generated' => TRUE,
      ],
      'content' => [
        'type' => 'textarea',
        'label' => (string) $this->t('Content body'),
        'description' => (string) $this->t('The main HTML content body to sync with contentbird.'),
        'required' => TRUE,
        'ai_generated' => TRUE,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultAiInstructions(): string {
    return <<<'INSTRUCTIONS'
Transform the following Drupal content for the contentbird platform.

Guidelines:
- Provide a clear, SEO-friendly title.
- Write a concise summary suitable for a meta description (under 160 characters).
- Produce clean HTML content suitable for a CMS integration.
- Maintain the original meaning and key information.
- Use proper heading hierarchy (h2, h3) within the content body.
- Do NOT include the title in the content body.

Available tokens:
- [node:title] — The content title.
- [node:url] — The full URL to the content.
- [node:summary] — The content summary.
- [node:content_type] — The content type label.
INSTRUCTIONS;
  }

  /**
   * {@inheritdoc}
   */
  public function getAvailableTools(): array {
    try {
      $types = $this->apiClient->getTypes();
      $tools = [];
      foreach ($types as $type) {
        $toolId = (string) $type['id'];
        $tools[$toolId] = [
          'id' => $toolId,
          'name' => $type['name'] ?? $type['id'],
          'description' => $type['description'] ?? '',
        ];
      }
      return $tools;
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getOutputSchemaForTool(string|int $toolId): array {
    // All contentbird tools currently use the same schema.
    // Override this in the future if specific tools need different fields.
    return $this->getOutputSchema();
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultAiInstructionsForTool(string|int $toolId): string {
    // Resolve the tool name for a more tailored prompt.
    $toolName = 'content';
    try {
      $tools = $this->getAvailableTools();
      if (isset($tools[(string) $toolId])) {
        $toolName = $tools[(string) $toolId]['name'];
      }
    }
    catch (\Exception) {
      // Use generic name.
    }

    return <<<INSTRUCTIONS
Transform the following Drupal content for the contentbird platform as a "{$toolName}" content type.

Guidelines:
- Provide a clear, SEO-friendly title appropriate for a {$toolName}.
- Write a concise summary suitable for a meta description (under 160 characters).
- Produce clean HTML content suitable for a CMS integration.
- Adapt the tone and style to match a {$toolName} format.
- Maintain the original meaning and key information.
- Use proper heading hierarchy (h2, h3) within the content body.
- Do NOT include the title in the content body.

Available tokens:
- [node:title] — The content title.
- [node:url] — The full URL to the content.
- [node:summary] — The content summary.
- [node:content_type] — The content type label.
INSTRUCTIONS;
  }

  /**
   * {@inheritdoc}
   */
  public function buildCredentialsForm(array $form, array $credentials): array {
    // Authentication is managed centrally by the iq_contentbird_api module.
    $connected = FALSE;

    try {
      $list_ids = $this->apiClient->getListOfIds();
      if ($list_ids !== FALSE) {
        $connected = TRUE;
      }
    }
    catch (\Exception $e) {
      // Not connected or API error.
    }

    $settingsUrl = Url::fromRoute('iq_contentbird_api.settings')->toString();

    if ($connected) {
      $form['connection_status'] = [
        '#type' => 'item',
        '#title' => $this->t('Connection Status'),
        '#markup' => '<span style="color: green;">&#10003; Connected to contentbird API</span>',
      ];
    }
    else {
      $form['connection_status'] = [
        '#type' => 'item',
        '#title' => $this->t('Connection Status'),
        '#markup' => '<span style="color: red;">&#10007; Not connected to contentbird.</span><br>'
          . $this->t('Please <a href="@url">configure the contentbird API token</a> in the Contentbird API settings.', [
            '@url' => $settingsUrl,
          ]),
      ];
    }

    $form['api_settings_link'] = [
      '#type' => 'item',
      '#title' => $this->t('Contentbird API Settings'),
      '#markup' => $this->t('<a href="@url">Contentbird API configuration</a> — manage the API token and connection.', [
        '@url' => $settingsUrl,
      ]),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildSettingsForm(array $form, array $settings): array {
    // Fetch projects and statuses from the API for the select options.
    $projectOptions = ['' => $this->t('- Select a project -')];
    $statusOptions = ['' => $this->t('- Select a status -')];

    try {
      $projects = $this->apiClient->getProjects();
      foreach ($projects as $project) {
        $projectOptions[$project['id']] = $project['url_plain'] . ' (ID: ' . $project['id'] . ')';
      }

      $statuses = $this->apiClient->getStatuses();
      foreach ($statuses as $status) {
        $statusOptions[$status['id']] = $status['name'] . ' (ID: ' . $status['id'] . ')';
      }
    }
    catch (\Exception $e) {
      // API not reachable — keep empty options.
    }

    $form['project_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Contentbird Project'),
      '#description' => $this->t('Select the contentbird project to publish content to.'),
      '#options' => $projectOptions,
      '#default_value' => $settings['project_id'] ?? '',
      '#required' => TRUE,
    ];

    $form['status_on_publish'] = [
      '#type' => 'select',
      '#title' => $this->t('Status: Published'),
      '#description' => $this->t('The contentbird content status to set when content is published in Drupal.'),
      '#options' => $statusOptions,
      '#default_value' => $settings['status_on_publish'] ?? '',
    ];

    $form['status_on_import'] = [
      '#type' => 'select',
      '#title' => $this->t('Status: Imported'),
      '#description' => $this->t('The contentbird content status to set when content is imported as a draft in Drupal.'),
      '#options' => $statusOptions,
      '#default_value' => $settings['status_on_import'] ?? '',
    ];

    $form['status_on_failure'] = [
      '#type' => 'select',
      '#title' => $this->t('Status: Failed'),
      '#description' => $this->t('The contentbird content status to set when publishing fails.'),
      '#options' => $statusOptions,
      '#default_value' => $settings['status_on_failure'] ?? '',
    ];

    $form['send_published_url'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send published URL to contentbird'),
      '#description' => $this->t('When checked, the published URL of the Drupal node will be sent to contentbird along with the status update.'),
      '#default_value' => $settings['send_published_url'] ?? TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateCredentials(array $credentials): bool {
    // Authentication is managed by iq_contentbird_api.
    // Validate by checking if we can reach the API.
    try {
      $listOfIds = $this->apiClient->getListOfIds();
      return $listOfIds !== FALSE;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function publish(NodeInterface $node, array $fields, array $credentials, array $settings, string|int|null $toolId = NULL): PublishingResult {
    // If no project is configured, we can't publish.
    $projectId = (int) ($settings['project_id'] ?? 0);
    if ($projectId <= 0) {
      return PublishingResult::failure(
        'No contentbird project configured. Please select a project in the platform settings.',
        ['error' => 'no_project_configured']
      );
    }
    // Extract contentbird content ID from the node.
    // This should have been stored when the content was imported from
    // contentbird (e.g., via a webhook or manual import).
    $contentbirdId = $this->getContentbirdId($node);

    $title = $fields['title'] ?? $node->getTitle();
    $content = $fields['content'] ?? '';
    $summary = $fields['summary'] ?? '';

    // Build the published URL.
    $publishedUrl = '';
    $sendUrl = $settings['send_published_url'] ?? TRUE;
    if ($sendUrl) {
      try {
        $publishedUrl = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
      }
      catch (\Exception $e) {
        // URL generation failed — continue without it.
      }
    }

    // Get the publish timestamp.
    $publishedAt = (new \DateTimeImmutable())->format('c');
    if ($node->isPublished()) {
      $timestamp = $node->getChangedTime();
      $publishedAt = (new \DateTimeImmutable('@' . $timestamp))->format('c');
    }

    // Determine the status ID to set.
    $statusId = (int) ($settings['status_on_publish'] ?? 0);
    $failureStatusId = (int) ($settings['status_on_failure'] ?? 0);

    if ($contentbirdId) {
      // Update the content body/title in contentbird.
      $updateData = [
        'title' => $title,
        'content' => $content,
      ];

      if (!empty($summary)) {
        $updateData['customElements'] = [
          'meta_description' => $summary,
        ];
      }

      $updateResult = $this->apiClient->updateContent($contentbirdId, $updateData);

      if ($updateResult === FALSE) {
        // On failure, try to set the "failed" status if configured.
        if ($failureStatusId > 0) {
          $this->apiClient->updateStatusUnpublishedContent($contentbirdId, $failureStatusId);
        }

        return PublishingResult::failure(
          "Failed to update content #{$contentbirdId} in contentbird. Check the API logs for details.",
          [
            'error' => 'update_failed',
            'contentbird_id' => $contentbirdId,
          ]
        );
      }

      // Mark the content as published via the dedicated endpoint.
      $publishResult = $this->apiClient->updateStatusPublishedContent(
        $contentbirdId,
        $publishedUrl,
        $publishedAt,
        NULL,
        $statusId > 0 ? $statusId : NULL,
      );

      if ($publishResult !== FALSE) {
        return PublishingResult::success(
          "Successfully updated and published content #{$contentbirdId} in contentbird.",
          [
            'contentbird_id' => $contentbirdId,
            'published_url' => $publishedUrl,
            'status_id' => $statusId,
            'api_response' => $publishResult,
          ]
        );
      }

      // Content was updated but publish-status call failed.
      if ($failureStatusId > 0) {
        $this->apiClient->updateStatusUnpublishedContent($contentbirdId, $failureStatusId);
      }

      return PublishingResult::failure(
        "Content #{$contentbirdId} was updated but the publish status could not be set. Check the API logs for details.",
        [
          'error' => 'publish_status_failed',
          'contentbird_id' => $contentbirdId,
        ]
      );
    }

    // No contentbird ID — create new content.
    $createData = [
      'type_id' => $toolId !== NULL ? (int) $toolId : 1,
      'language' => 'en',
      'title' => $title,
      'content' => $content,
    ];

    // Associate with the configured project.
    $projectId = (int) ($settings['project_id'] ?? 0);
    if ($projectId > 0) {
      $createData['project_id'] = $projectId;
    }

    if (!empty($summary)) {
      $createData['customElements'] = [
        'meta_description' => $summary,
      ];
    }

    $result = $this->apiClient->createContent($createData);

    if ($result !== FALSE) {
      // Store the contentbird ID on the node for future updates.
      $newId = $result['id'] ?? $result['data']['id'] ?? NULL;
      if ($newId !== NULL) {
        $this->setContentbirdId($node, (int) $newId);

        // Mark the newly created content as published.
        $this->apiClient->updateStatusPublishedContent(
          (int) $newId,
          $publishedUrl,
          $publishedAt,
          NULL,
          $statusId > 0 ? $statusId : NULL,
        );
      }

      return PublishingResult::success(
        'Successfully created content in contentbird.' . ($newId ? " ID: {$newId}" : ''),
        [
          'contentbird_id' => $newId,
          'published_url' => $publishedUrl,
          'status_id' => $statusId,
          'api_response' => $result,
        ]
      );
    }

    return PublishingResult::failure(
      'Failed to create content in contentbird. Check the API logs for details.',
      [
        'error' => 'create_failed',
      ]
    );
  }

  /**
   * Gets the contentbird content ID stored on a node.
   *
   * Looks for the ID in the node's "field_contentbird_id" field or in
   * Drupal's key-value storage as a fallback.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return int|null
   *   The contentbird content ID, or NULL if not set.
   */
  protected function getContentbirdId(NodeInterface $node): ?int {
    // Check for a dedicated field on the node.
    if ($node->hasField('field_contentbird_id') && !$node->get('field_contentbird_id')->isEmpty()) {
      return (int) $node->get('field_contentbird_id')->value;
    }

    // Fallback: check key-value storage.
    $keyValue = \Drupal::keyValue('iq_content_publishing_contentbird');
    $id = $keyValue->get('node_' . $node->id());
    return $id !== NULL ? (int) $id : NULL;
  }

  /**
   * Stores the contentbird content ID for a node.
   *
   * Stores in the node's "field_contentbird_id" field if available,
   * otherwise uses Drupal's key-value storage.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param int $contentbird_id
   *   The contentbird content ID.
   */
  protected function setContentbirdId(NodeInterface $node, int $contentbird_id): void {
    // Try to store in a dedicated field.
    if ($node->hasField('field_contentbird_id')) {
      $node->set('field_contentbird_id', $contentbird_id);
      $node->save();
      return;
    }

    // Fallback: use key-value storage.
    $keyValue = \Drupal::keyValue('iq_content_publishing_contentbird');
    $keyValue->set('node_' . $node->id(), $contentbird_id);
  }

}
