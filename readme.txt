=== AI Chat for Amazon Bedrock ===
Contributors: glay
Tags: ai, chatbot, amazon, bedrock, claude, streaming
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Integrate Amazon Bedrock AI capabilities into WordPress with streaming chat support.

== Description ==

Bedrock AI Chat brings the power of Amazon Bedrock's AI models to your WordPress site, providing an intelligent chatbot with real-time streaming responses.

Features:

* Real-time AI Chat with streaming responses
* Typewriter-style response display
* Chat history tracking
* Multi-language support
* Powered by Amazon Bedrock AI models (Claude)

Requirements:

* WordPress 5.0 or higher
* PHP 7.4 or higher
* AWS account with Bedrock access
* Composer for dependency management

== Installation ==

1. Upload the `wp-bedrock` folder to the `/wp-content/plugins/` directory
2. Run `composer install` in the plugin directory to install dependencies
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Go to Bedrock AI Chat > Settings to configure your AWS credentials:
   * Enter your AWS Access Key and Secret
   * Select your preferred AI model
   * Save settings

== Frequently Asked Questions ==

= Do I need an AWS account? =

Yes, you need an AWS account with access to Amazon Bedrock service.

= How do I get AWS credentials? =

You can create AWS credentials in your AWS Console under IAM (Identity and Access Management).

= Is chat history stored locally? =

Yes, all chat conversations are stored in your WordPress database.

== Screenshots ==

1. Chatbot interface with streaming responses
2. Settings page for AWS configuration
3. Chat history view

== Changelog ==

= 1.0.0 =
* Initial release
* Real-time chat with streaming responses
* AWS Bedrock integration
* Multi-language support
* Chat history tracking

== Upgrade Notice ==

= 1.0.0 =
Initial release of Bedrock AI Chat plugin.

== Privacy Policy ==

This plugin stores chat conversations in your WordPress database. No data is sent to external services except for the chat messages that are processed by Amazon Bedrock AI service.
