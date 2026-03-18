# Content Publishing: Contentbird

Provides [contentbird](https://www.contentbird.io/) integration for the [Content Publishing](https://github.com/iqual-ch/iq_content_publishing) framework. Enables publishing Drupal content to contentbird as articles, wiki pages, or social media posts.

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

The credentials form will display the current connection status (connected/not connected) and provide a link to the Contentbird API settings if configuration is needed.

### 3. Configure Platform Settings

In the platform configuration form, set the following:

| Setting | Description |
|---|---|
| **Contentbird Project** | Select the contentbird project to publish content to. This determines which social profiles are available. |
| **Status** | The contentbird content status to set when content is sent (e.g., "Draft", "In Review"). Only unpublished statuses are shown. |
| **Send published URL** | When enabled, the canonical Drupal URL of the node is sent to contentbird along with the content. |

### 4. Fetch Available Content Statuses (Optional)

On the platform configuration list, use the **Fetch Content Statuses** operation to retrieve all available content status IDs from your contentbird account. These IDs are displayed as status messages for reference.

## Features

### Multi-Tool Platform

This module implements the `MultiToolPlatformInterface`, providing multiple publishing tools within a single platform:

#### Content Tools

Dynamically discovered from your contentbird account. Each content type (Wiki, News, Blog, etc.) becomes a separate publishing tool:

- **Format**: `content:{type_id}` (e.g., `content:1` for Wiki)
- **Output schema**: Title, Summary/meta description, HTML content body
- **AI instructions**: Tailored prompts for each content type

#### Social Media Tools

Discovered from the connected social profiles in your selected contentbird project:

- **Format**: `social:{profile_id}`
- **Supported platforms**: Facebook, LinkedIn, X (Twitter), Instagram, and others configured in contentbird
- **Output schema**: Post content (plain text), optional images (up to 3), status (draft/publish/publish_at), scheduled time
- **AI instructions**: Platform-specific prompts for engaging social posts

### Content Publishing Workflow

When content is published through the publishing workflow, the plugin:

1. **Updates existing content** in contentbird if a contentbird ID is associated with the node.
2. **Creates new content** in contentbird if no existing ID is found, and stores the returned contentbird ID for future updates.
3. **Syncs metadata** including the title, summary/meta description, and HTML content body.
4. **Sets the content status** based on the configured status.

### Social Media Publishing

For social media tools, the plugin:

1. **Creates a social post** via the contentbird API, targeting the selected profile/page.
2. **Supports scheduling** with draft, publish immediately, or publish at a specific time.
3. **Attaches images/videos** to the post when provided.
4. **Includes the Drupal URL** as a promotion link (for platforms that support it like Facebook and LinkedIn).

### AI-Assisted Content Transformation

The plugin provides output schemas and AI instructions for the Content Publishing framework's AI integration:

**Content tools output schema:**

- **Title** — SEO-friendly content title
- **Summary** — Meta description (max 160 characters)
- **Content body** — Clean HTML content

**Social tools output schema:**

- **Post content** — Platform-appropriate text (no HTML)
- **Images** — Optional image attachments
- **Status** — Publishing status
- **Scheduled time** — For scheduled posts

**Available tokens in AI instructions:**

- `[node:title]` — The content title
- `[node:url]` — The full URL to the content
- `[node:summary]` — The content summary
- `[node:content_type]` — The content type label

### Contentbird ID Storage

The contentbird content ID is stored on the node using one of two strategies:

1. **Dedicated field** — If the node has a `field_contentbird_id` field, it is used directly.
2. **Key-value storage** — As a fallback, the ID is stored in Drupal's key-value store under the `iq_content_publishing_contentbird` collection.

## API Reference

### Plugin ID

`contentbird`

### Plugin Interfaces

- `ContentPublishingPlatformBase` — Base platform functionality
- `MultiToolPlatformInterface` — Multi-tool support (content types + social profiles)

### Key Methods

| Method | Description |
|---|---|
| `getAvailableTools()` | Returns available content types and social profiles from the API |
| `getOutputSchemaForTool()` | Returns tool-specific output schema (content vs social) |
| `getDefaultAiInstructionsForTool()` | Returns tool-specific AI prompts |
| `publish()` | Routes to content creation/update or social post creation |

## License

GPL-2.0-or-later