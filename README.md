# AI Chat for Amazon Bedrock

A WordPress plugin that integrates Amazon Bedrock AI capabilities into your WordPress site, providing an intelligent chatbot with real-time streaming responses.

## Features

Current features:
- Basic chat interface with Amazon Bedrock AI
- AWS Bedrock integration with Claude model
- Chat history tracking
- Multi-language support
- Configurable AWS settings (region, credentials)
- Customizable chat settings:
  - Model selection
  - Temperature control
  - System prompt customization
  - Initial message configuration
  - Context length adjustment

## Roadmap

Upcoming features:
- Real-time streaming responses with typewriter effect
- Tool use capabilities for enhanced AI interactions
- Image generation support:
  - Integration with Bedrock image models
  - Multiple model support (Stable Diffusion, DALL-E, etc.)
  - Image customization options
  - Image history management
- Advanced chat features:
  - Context-aware conversations
  - File attachment support
  - Custom function calling
  - Knowledge base integration

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
3. View chat history in the table below the chat interface

## Configuration

The following settings can be configured:

### AWS Settings
- AWS Access Key and Secret
- AWS Region
- AI Model Selection

### Chat Settings
- Temperature (response creativity)
- System Prompt
- Initial Message
- Context Length
- Chat Placeholder Text

## Development

To contribute to this plugin:

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Submit a pull request

### Development Guidelines
- Follow WordPress coding standards
- Write clear commit messages
- Add unit tests for new features
- Update documentation as needed

## License

This project is licensed under the GPL v2 or later.

## Support

For support and bug reports, please use the GitHub issues page.
