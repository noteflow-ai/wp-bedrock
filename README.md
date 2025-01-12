# AI Chat for Amazon Bedrock AI Plugin

A WordPress plugin that integrates Amazon Bedrock AI capabilities into your WordPress site, providing an intelligent chatbot with streaming responses.

## Features

- Real-time AI Chat with streaming responses
- Typewriter-style response display
- Chat history tracking
- Multi-language support
- Powered by Amazon Bedrock AI models (Claude)

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- AWS account with Bedrock access
- Composer for dependency management

## Installation

1. Clone this repository to your WordPress plugins directory:
```bash
cd wp-content/plugins/
git clone https://github.com/noteflow-ai/wp-bedrock.git
```

2. Install dependencies:
```bash
cd wp-bedrock
composer install
```

3. Activate the plugin through the WordPress admin interface.

4. Configure your AWS credentials in the plugin settings:
   - Go to AI Chat for Amazon Bedrock > Settings
   - Enter your AWS Access Key and Secret
   - Select your preferred AI model
   - Save settings

## Usage

1. Navigate to AI Chat for Amazon Bedrock > Chatbot in your WordPress admin panel
2. Start chatting with the AI assistant
3. Messages will appear in real-time with a typewriter effect
4. View chat history in the table below the chat interface

## Configuration

The following settings can be configured:

- AWS Access Key and Secret
- AWS Region
- AI Model Selection
- Temperature (response creativity)
- Max Tokens (response length)
- Initial Message
- Placeholder Text

## Development

To contribute to this plugin:

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Submit a pull request

## License

This project is licensed under the GPL v2 or later.

## Support

For support and bug reports, please use the GitHub issues page.
