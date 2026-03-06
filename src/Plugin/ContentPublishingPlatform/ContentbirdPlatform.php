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
   * Cached available tools to avoid redundant API calls within a request.
   *
   * Keyed by a hash of the settings used, so different settings produce
   * different cached results.
   */
  protected array $availableToolsCache = [];

  /**
   * Tool ID prefix for content tools (uses createContent API).
   */
  protected const TOOL_PREFIX_CONTENT = 'content:';

  /**
   * Tool ID prefix for social post tools (uses createSocialPost API).
   */
  protected const TOOL_PREFIX_SOCIAL = 'social:';

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
        'type' => 'text_format',
        'label' => (string) $this->t('Content body'),
        'description' => (string) $this->t('The main HTML content body to sync with contentbird.'),
        'required' => TRUE,
        'ai_generated' => TRUE,
        'format' => 'content_format',
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
  public function getAvailableTools(array $settings = []): array {
    $cacheKey = md5(serialize($settings));
    if (isset($this->availableToolsCache[$cacheKey])) {
      return $this->availableToolsCache[$cacheKey];
    }

    $tools = [];

    // Content tools: one per contentbird type (Wiki, News, etc.).
    // Each uses the createContent API with the corresponding type_id.
    try {
      $types = $this->apiClient->getTypes();
      foreach ($types as $type) {
        $typeId = (string) $type['id'];
        $toolId = static::TOOL_PREFIX_CONTENT . $typeId;
        $tools[$toolId] = [
          'id' => $toolId,
          'name' => $type['name'] ?? $typeId,
          'description' => $type['description'] ?? '',
          'group' => 'content',
          'group_label' => (string) $this->t('Content'),
        ];
      }
    }
    catch (\Exception) {
      // API not reachable — skip content tools.
    }

    // Social tools: one per active social profile on the project.
    // Each uses the createSocialPost API with the corresponding page_id.
    $projectId = (int) ($settings['project_id'] ?? 0);
    if ($projectId > 0) {
      try {
        $profiles = $this->apiClient->getProjectSocialProfiles($projectId);
        $profileData = $profiles['data'] ?? $profiles;
        if (is_array($profileData)) {
          foreach ($profileData as $profile) {
            $id = $profile['id'] ?? '';
            $page_id = $profile['page_id'] ?? '';
            if ($id === '' || $page_id === '') {
              continue;
            }
            if (isset($profile['network']) && isset($profile['page_name'])) {
              $profileName = ucfirst($profile['network']) . ' - ' . $profile['page_name'];
            }
            else {
              $profileName = (string) $this->t('Profile #@id', ['@id' => $id]);
            }
             $toolId = static::TOOL_PREFIX_SOCIAL . $id;
            $tools[$toolId] = [
              'id' => $toolId,
              'page_id' => $page_id,
              'name' => $profileName,
            ];
          }
        }
      }
      catch (\Exception) {
        // API not reachable or no project — skip social tools.
      }
    }

    $this->availableToolsCache[$cacheKey] = $tools;
    return $tools;
  }

  /**
   * {@inheritdoc}
   */
  public function getOutputSchemaForTool(string|int $toolId): array {
    if (str_starts_with((string) $toolId, static::TOOL_PREFIX_SOCIAL)) {
      return [
        'post_content' => [
          'type' => 'textarea',
          'label' => (string) $this->t('Post content'),
          'description' => (string) $this->t('The text content for the social media post.'),
          'required' => TRUE,
          'ai_generated' => TRUE,
        ],
        'image' => [
          'type' => 'image',
          'label' => (string) $this->t('Images'),
          'description' => (string) $this->t('Select images to attach to the post.'),
          'required' => FALSE,
          'max' => 3,
          'ai_generated' => FALSE,
        ],
        'status' => [
          '#type' => 'select',
          '#title' => (string) $this->t('Status'),
          '#options' => $this->apiClient->getSocialPostStatuses(),
          '#description' => (string) $this->t('The publishing status to set on contentbird.'),
          '#required' => TRUE,
        ],
        'scheduled_time' => [
          '#type' => 'datetime',
          '#title' => (string) $this->t('Scheduled time'),
          '#description' => (string) $this->t('The date and time to schedule the post for. Required if status is "Publish At".'),
          '#required' => FALSE,
          '#states' => [
            'required' => [
              ':input[name="fields[status]"]' => ['value' => 'publish_at'],
            ],
          ],
        ],
      ];
    }
    return $this->getOutputSchema();
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultAiInstructionsForTool(string|int $toolId): string {
    $toolIdStr = (string) $toolId;

    // Social tools get a dedicated prompt focused on short-form posts.
    if (str_starts_with($toolIdStr, static::TOOL_PREFIX_SOCIAL)) {
      // Try to resolve the profile name from cached tools for a richer prompt.
      $profileName = 'social media';
      $tools = $this->availableToolsCache;
      foreach ($tools as $cache) {
        if (isset($cache[$toolIdStr])) {
          $profileName = $cache[$toolIdStr]['name'];
          break;
        }
      }

      return <<<INSTRUCTIONS
Create a compelling {$profileName} post based on the following Drupal content.

Guidelines:
- Write engaging, concise copy suitable for {$profileName}.
- Keep the tone professional but conversational.
- Include a clear call to action where appropriate.
- Do NOT use HTML — plain text only.
- Keep the post within the platform's typical character limits.
- Reference the content URL using [node:url] if appropriate.

Available tokens:
- [node:title] — The content title.
- [node:url] — The full URL to the content.
- [node:summary] — The content summary.
- [node:content_type] — The content type label.
INSTRUCTIONS;
    }

    // Content tools: resolve the type name for a tailored prompt.
    $typeName = 'content';
    if (str_starts_with($toolIdStr, static::TOOL_PREFIX_CONTENT)) {
      $typeId = substr($toolIdStr, strlen(static::TOOL_PREFIX_CONTENT));
      try {
        $types = $this->apiClient->getTypes();
        foreach ($types as $type) {
          if ((string) $type['id'] === $typeId) {
            $typeName = $type['name'] ?? $typeId;
            break;
          }
        }
      }
      catch (\Exception) {
        // Use generic name.
      }
    }

    return <<<INSTRUCTIONS
Transform the following Drupal content for the contentbird platform as a "{$typeName}" content type.

Guidelines:
- Provide a clear, SEO-friendly title appropriate for a {$typeName}.
- Write a concise summary suitable for a meta description (under 160 characters).
- Produce clean HTML content suitable for a CMS integration.
- Adapt the tone and style to match a {$typeName} format.
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
        // For now only allow unpublished statuses.
        if (isset($status['isPublished']) && $status['isPublished'] === TRUE) {
          continue;
        }
        $statusOptions[$status['id']] = $status['name'] . ' (' . $status['description'] . ')';
      }
    }
    catch (\Exception $e) {
      // API not reachable — keep empty options.
    }

    $form['project_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Contentbird Project'),
      '#description' => $this->t('Select the contentbird project to publish content to. Changing this refreshes the available social integrations below.'),
      '#options' => $projectOptions,
      '#default_value' => $settings['project_id'] ?? '',
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::pluginSettingsAjax',
        'wrapper' => 'plugin-settings-wrapper',
      ],
    ];

    $form['allowed_status'] = [
      '#type' => 'select',
      '#title' => $this->t('Status'),
      '#description' => $this->t('The contentbird content status to set when content is sent. (Only for the content integration, not social posts.)'),
      '#options' => $statusOptions,
      '#default_value' => $settings['allowed_status'] ?? '',
      '#required' => TRUE,
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
    $statusId = (int) ($settings['allowed_status'] ?? 0);

    // Route based on tool ID prefix.
    $toolIdStr = $toolId !== NULL ? (string) $toolId : '';

    // Social tools — use the createSocialPost API.
    if (str_starts_with($toolIdStr, static::TOOL_PREFIX_SOCIAL)) {
      $pageId = (int) substr($toolIdStr, strlen(static::TOOL_PREFIX_SOCIAL));
      return $this->publishSocialPost($node, $fields, $settings, $pageId, $publishedUrl);
    }

    // Content tools — extract the type_id from the tool ID.
    $typeId = 1;
    if (str_starts_with($toolIdStr, static::TOOL_PREFIX_CONTENT)) {
      $typeId = (int) substr($toolIdStr, strlen(static::TOOL_PREFIX_CONTENT));
    }
    elseif (is_numeric($toolIdStr)) {
      // Legacy fallback: bare numeric tool IDs are treated as type_id.
      $typeId = (int) $toolIdStr;
    }

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

        return PublishingResult::failure(
          "Failed to update content #{$contentbirdId} in contentbird. Check the API logs for details.",
          [
            'error' => 'update_failed',
            'contentbird_id' => $contentbirdId,
          ]
        );
      }

      return PublishingResult::success(
        "Successfully updated content #{$contentbirdId} in contentbird.",
        [
          'contentbird_id' => $contentbirdId,
          'published_url' => $publishedUrl,
          'status_id' => $statusId > 0 ? $statusId : NULL,
          'api_response' => $updateResult,
        ]
      );
    }

    // No contentbird ID — create new content.
    $createData = [
      'type_id' => $typeId,
      'language' => $node->language()->getId() ?: 'en',
      'title' => $title,
      'content' => $content,
      'status_id' => $statusId > 0 ? $statusId : NULL,
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
      }

      return PublishingResult::success(
        'Successfully created content in contentbird.' . ($newId ? " ID: {$newId}" : ''),
        [
          'contentbird_id' => $newId,
          'published_url' => $publishedUrl,
          'status_id' => $statusId > 0 ? $statusId : NULL,
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

  /**
   * Publishes content as a social media post via the contentbird API.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being published.
   * @param array $fields
   *   The AI-generated (or editor-modified) fields.
   * @param array $settings
   *   The platform plugin settings.
   * @param int $pageId
   *   The social profile page ID to post to.
   * @param string $publishedUrl
   *   The published URL of the node (used as promote_url).
   *
   * @return \Drupal\iq_content_publishing\Plugin\PublishingResult
   *   The publishing result.
   */
  protected function publishSocialPost(NodeInterface $node, array $fields, array $settings, int $pageId, string $publishedUrl): PublishingResult {
    $projectId = (int) ($settings['project_id'] ?? 0);

    $postContent = $fields['post_content'] ?? $fields['content'] ?? '';
    if (empty($postContent)) {
      return PublishingResult::failure(
        'No post content provided for the social post.',
        ['error' => 'empty_post_content']
      );
    }

    $type = $fields['status'] ?? 'draft';
    $scheduledTime = $fields['scheduled_time'] ?? NULL;

    $createData = [
      'project_id' => $projectId,
      'page_id' => $pageId,
      'language' => $node->language()->getId() ?: 'en',
      'post_content' => $postContent,
      'type' => $type,
    ];

    if ($scheduledTime) {
      try {
        if ($scheduledTime instanceof \DateTimeInterface || $scheduledTime instanceof \Drupal\Core\Datetime\DrupalDateTime) {
          $createData['publish_at'] = $scheduledTime->getTimestamp();
        }
        elseif (is_string($scheduledTime)) {
          $createData['publish_at'] = (new \DateTimeImmutable($scheduledTime))->getTimestamp();
        }
        elseif (is_numeric($scheduledTime)) {
          $createData['publish_at'] = (int) $scheduledTime;
        }
      }
      catch (\Exception $e) {
        // Invalid datetime format — ignore the scheduled time.
      }
    }

    // Include attachments if provided.
    // Images.
    if (!empty($fields['image']) && is_array($fields['image'])) {
      $attachments = [];
      foreach ($fields['image'] as $image) {
        if (isset($image['url'])) {
          $attachments[] = $image['url'];
        }
      }
      if (!empty($attachments)) {
        $createData['image_attachments'] = $attachments;
      }
    }
    // Videos.
    if (!empty($fields['video']) && is_array($fields['video'])) {
      $attachments = [];
      foreach ($fields['video'] as $video) {
        if (isset($video['url'])) {
          $attachments[] = $video['url'];
        }
      }
      if (!empty($attachments)) {
        $createData['video_attachments'] = $attachments;
      }
    }

    // Include the node URL as promote_url for platforms that support it
    // (Facebook, LinkedIn).
    if (!empty($publishedUrl)) {
      $createData['promote_url'] = $publishedUrl;
    }

    $result = $this->apiClient->createSocialPost($createData);

    if ($result !== FALSE) {
      $postId = $result['id'] ?? $result['data']['id'] ?? NULL;
      return PublishingResult::success(
        'Successfully created social post in contentbird.' . ($postId ? " Post ID: {$postId}" : ''),
        [
          'social_post_id' => $postId,
          'page_id' => $pageId,
          'api_response' => $result,
        ]
      );
    }

    return PublishingResult::failure(
      'Failed to create social post in contentbird. Check the API logs for details.',
      [
        'error' => 'social_post_create_failed',
        'page_id' => $pageId,
      ]
    );
  }

}
