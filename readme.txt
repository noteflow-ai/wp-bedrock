=== AI Chat for Amazon Bedrock ===
Contributors: glayguo
Tags: ai, chatbot, amazon, bedrock, claude
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Integrate Amazon Bedrock AI capabilities into WordPress with streaming chat support.

== Description ==

AI Chat for Amazon Bedrock brings the power of Amazon Bedrock's AI models to your WordPress site, providing an intelligent chatbot with real-time streaming responses.

= Current Features =

* Basic chat interface with Amazon Bedrock AI
* AWS Bedrock integration with Claude model
* Chat history tracking
* Multi-language support
* Configurable AWS settings (region, credentials)
* Customizable chat settings:
  * Model selection
  * Temperature control
  * System prompt customization
  * Initial message configuration
  * Context length adjustment
* Multiple integration options:
  * Admin dashboard interface
  * Shortcode [ai_chat_for_amazon_bedrock] for pages and posts
  * Widget for sidebars and widget areas

= Roadmap =

We're actively working on exciting new features:

* Real-time streaming responses with typewriter effect
* Tool use capabilities for enhanced AI interactions
* Image generation support:
  * Integration with Bedrock image models
  * Multiple model support (Stable Diffusion, DALL-E, etc.)
  * Image customization options
  * Image history management
* Advanced chat features:
  * Context-aware conversations
  * File attachment support
  * Custom function calling
  * Knowledge base integration

= Requirements =

* WordPress 5.0 or higher
* PHP 7.4 or higher
* AWS account with Bedrock access
* Composer for dependency management

== Installation ==

1. Upload the `ai-chat-for-amazon-bedrock` folder to the `/wp-content/plugins/` directory
2. Run `composer install` in the plugin directory to install dependencies
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Go to AI Chat for Amazon Bedrock > Settings to configure your AWS credentials:
   * Enter your AWS Access Key and Secret
   * Select your preferred AI model
   * Save settings

= Using the Shortcode =

Add the chatbot to any post or page using the shortcode:

[ai_chat_for_amazon_bedrock]

Optional parameters:
* height: Set the chat window height (default: 500px)
* width: Set the chat window width (default: 100%)

Example:
[ai_chat_for_amazon_bedrock height="600px" width="800px"]

= Using the Widget =

1. Go to Appearance > Widgets
2. Find the "AI Chat Widget" in the available widgets list
3. Drag it to your desired widget area
4. Configure the widget title and height
5. Save the widget settings

== Frequently Asked Questions ==

= Do I need an AWS account? =

Yes, you need an AWS account with access to Amazon Bedrock service.

= How do I get AWS credentials? =

You can create AWS credentials in your AWS Console under IAM (Identity and Access Management).

= Is chat history stored locally? =

Yes, all chat conversations are stored in your WordPress database.

= What AI models are supported? =

Currently, we support Claude through Amazon Bedrock. More models will be added in future updates.

= Can I customize the chat interface? =

Yes, you can customize various aspects including the initial message, system prompt, temperature settings, and context length.

= Can I add the chatbot to my sidebar? =

Yes, you can use the AI Chat Widget to add the chatbot to any widget area in your theme.

= How do I add the chatbot to a specific page? =

Use the [ai_chat_for_amazon_bedrock] shortcode in your page or post content where you want the chatbot to appear.

== Screenshots ==

1. Chatbot interface with streaming responses
2. Settings page for AWS configuration
3. Chat history view
4. Widget configuration
5. Shortcode implementation

== Changelog ==

= 1.0.3 =
* Updated plugin version for WordPress.org submission
* Fixed text domain to match plugin slug
* Added new shortcode [ai_chat_for_amazon_bedrock] (old shortcode [bedrock_chat] still works)
* Renamed main plugin file to ai-chat-for-amazon-bedrock.php (backward compatibility maintained)
* Fixed escaping issues for better security
* Replaced deprecated functions with WordPress recommended alternatives
* Minor bug fixes and improvements

= 1.0.1 =
* Added shortcode support [ai_chat_for_amazon_bedrock]

You can also use the old shortcode for backward compatibility:
[bedrock_chat]
* Added widget support for sidebar integration
* Updated plugin name to comply with WordPress.org trademark guidelines

= 1.0.0 =
* Initial release
* Basic chat interface with Amazon Bedrock AI
* AWS Bedrock integration with Claude model
* Chat history tracking
* Multi-language support
* Configurable AWS and chat settings

== Upgrade Notice ==

= 1.0.3 =
Updated version with improved WordPress.org compatibility, security fixes, and code improvements.

= 1.0.1 =
Added shortcode and widget support for flexible chatbot integration.

= 1.0.0 =
Initial release of AI Chat for Amazon Bedrock plugin.

== Privacy Policy ==

This plugin stores chat conversations in your WordPress database. No data is sent to external services except for the chat messages that are processed by Amazon Bedrock AI service.
