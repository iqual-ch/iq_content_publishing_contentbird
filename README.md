# Content Publishing: Contentbird

Provides [contentbird](https://www.contentbird.io/) integration for the [Content Publishing](https://github.com/iqual-ch/iq_content_publishing) framework. Enables syncing Drupal content statuses and publishing workflows with the contentbird platform.

## Requirements

- **Drupal** ^11
- **[iq_content_publishing](https://github.com/iqual-ch/iq_content_publishing)** ^1 — The base Content Publishing framework
- **[iq_contentbird_api](https://github.com/iqual-ch/iq_contentbird_api)** dev-main — Provides the contentbird API client and centralized API token management

## Installation

Install via Composer:

```bash
composer require iqual/iq_content_publishing_contentbird
```

Enable the module:

```bash
drush en iq_content_publishing_contentbird
```

## Configuration

### 1. Configure the Contentbird API Token

API token authentication is managed centrally by the **iq_contentbird_api** module. Navigate to:

> **Administration » Configuration » Contentbird API settings**
> (`/admin/config/services/contentbird-api`)

Enter your contentbird API token and save.

### 2. Create a Publishing Platform Configuration

Navigate to:

> **Administration » Configuration » Content Publishing » Platforms**

Add a new platform configuration and select **Contentbird** as the platform type.

### 3. Fetch Available Content Statuses

On the platform configuration list, use the **Fetch Content Statuses** operation to retrieve all available content status IDs from your contentbird account. These IDs are displayed as status messages and are needed for the settings below.

### 4. Configure Platform Settings

In the platform configuration form, set the following:

| Setting | Description |
|---|---|
| **Status ID: Published** | The contentbird status ID to set when content is published in Drupal. |
| **Status ID: Imported** | The contentbird status ID to set when content is imported as a draft (e.g., "CMS imported"). |
| **Status ID: Failed** | The contentbird status ID to set when a publishing action fails (e.g., "CMS failed"). |
| **Send published URL** | When enabled, the canonical Drupal URL of the node is sent to contentbird along with the status update. |

## How It Works

This module registers a `contentbird` plugin for the Content Publishing framework. When content is published through the publishing workflow, the plugin:

1. **Updates existing content** in contentbird if a contentbird ID is associated with the node (via a `field_contentbird_id` field or key-value storage fallback).
2. **Creates new content** in contentbird if no existing ID is found, and stores the returned contentbird ID for future updates.
3. **Syncs metadata** including the title, summary/meta description, HTML content body, published URL, and publish timestamp.
4. **Sets content statuses** in contentbird based on the configured status IDs (published, imported, or failed).

### AI-Assisted Content Transformation

The plugin provides an output schema and default AI instructions for the Content Publishing framework's AI integration. The schema includes:

- **Title** — SEO-friendly content title
- **Summary** — Meta description (max 160 characters)
- **Content body** — Clean HTML content

### Contentbird ID Storage

The contentbird content ID is stored on the node using one of two strategies:

1. **Dedicated field** — If the node has a `field_contentbird_id` field, it is used directly.
2. **Key-value storage** — As a fallback, the ID is stored in Drupal's key-value store under the `iq_content_publishing_contentbird` collection.

## Module Structure

```
├── composer.json
├── iq_content_publishing_contentbird.info.yml
├── iq_content_publishing_contentbird.module
├── iq_content_publishing_contentbird.routing.yml
├── config/
│   └── schema/
│       └── iq_content_publishing_contentbird.schema.yml
└── src/
    ├── Controller/
    │   └── ContentbirdController.php        # Fetch Content Statuses action
    └── Plugin/
        └── ContentPublishingPlatform/
            └── ContentbirdPlatform.php      # Main platform plugin
```

## License

GPL-2.0-or-later