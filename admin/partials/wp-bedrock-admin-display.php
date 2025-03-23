<?php
/**
 * Provide a admin area view for the plugin
 *
 * This file is used to markup the admin-facing aspects of the plugin.
 *
 * @since      1.0.0
 * @package    WP_Bedrock
 * @subpackage WP_Bedrock/admin/partials
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
?>

<div class="wrap">
    <h1>AI Chat for Amazon Bedrock</h1>
    
    <div class="card">
        <h2><?php esc_html_e('About AI Chat for Amazon Bedrock', 'ai-chat-for-amazon-bedrock'); ?></h2>
        <p>
            <?php esc_html_e('AI Chat for Amazon Bedrock is a powerful WordPress plugin that integrates Amazon Bedrock AI capabilities into your WordPress site. It provides an intelligent chatbot that can assist your visitors with questions and information.', 'ai-chat-for-amazon-bedrock'); ?>
        </p>
    </div>

    <div class="card">
        <h2><?php esc_html_e('Key Features', 'ai-chat-for-amazon-bedrock'); ?></h2>
        <ul class="feature-list">
            <li><?php esc_html_e('Real-time AI Chat: Engage with visitors through a responsive chatbot interface', 'ai-chat-for-amazon-bedrock'); ?></li>
            <li><?php esc_html_e('Streaming Responses: See AI responses appear in real-time with typewriter effect', 'ai-chat-for-amazon-bedrock'); ?></li>
            <li><?php esc_html_e('Amazon Bedrock Integration: Powered by advanced AI models like Claude', 'ai-chat-for-amazon-bedrock'); ?></li>
            <li><?php esc_html_e('Chat History: Keep track of all conversations for future reference', 'ai-chat-for-amazon-bedrock'); ?></li>
            <li><?php esc_html_e('Customizable Settings: Configure the chatbot behavior to suit your needs', 'ai-chat-for-amazon-bedrock'); ?></li>
        </ul>
    </div>

    <div class="card">
        <h2><?php esc_html_e('Quick Start Guide', 'ai-chat-for-amazon-bedrock'); ?></h2>
        <ol class="guide-list">
            <li>
                <strong><?php esc_html_e('Configure AWS Credentials', 'ai-chat-for-amazon-bedrock'); ?></strong><br>
                <?php esc_html_e('Enter your AWS access key and secret in the Settings page', 'ai-chat-for-amazon-bedrock'); ?>
            </li>
            <li>
                <strong><?php esc_html_e('Choose AI Model', 'ai-chat-for-amazon-bedrock'); ?></strong><br>
                <?php esc_html_e('Select your preferred Bedrock AI model from the available options', 'ai-chat-for-amazon-bedrock'); ?>
            </li>
            <li>
                <strong><?php esc_html_e('Test the Chatbot', 'ai-chat-for-amazon-bedrock'); ?></strong><br>
                <?php esc_html_e('Use the Chatbot page to test the functionality', 'ai-chat-for-amazon-bedrock'); ?>
            </li>
        </ol>
    </div>

    <div class="card">
        <h2><?php esc_html_e('Navigation', 'ai-chat-for-amazon-bedrock'); ?></h2>
        <ul class="nav-list">
            <li><strong><?php esc_html_e('Settings', 'ai-chat-for-amazon-bedrock'); ?></strong>: <?php esc_html_e('Configure AWS credentials and AI model settings', 'ai-chat-for-amazon-bedrock'); ?></li>
            <li><strong><?php esc_html_e('Chatbot', 'ai-chat-for-amazon-bedrock'); ?></strong>: <?php esc_html_e('Access the chat interface for testing and monitoring', 'ai-chat-for-amazon-bedrock'); ?></li>
        </ul>
    </div>

    <div class="card">
        <h2><?php esc_html_e('Support & Documentation', 'ai-chat-for-amazon-bedrock'); ?></h2>
        <p>
            <?php 
            printf(
                /* translators: %s: Version number */
                esc_html__('Current Version: %s', 'ai-chat-for-amazon-bedrock'), 
                '<strong>' . esc_html(AICHAT_BEDROCK_VERSION) . '</strong>'
            ); 
            ?>
        </p>
        <p>
            <?php esc_html_e('For support and detailed documentation, please visit:', 'ai-chat-for-amazon-bedrock'); ?>
            <ul class="support-list">
                <li><a href="https://github.com/noteflow-ai/wp-bedrock" target="_blank"><?php esc_html_e('GitHub Repository', 'ai-chat-for-amazon-bedrock'); ?></a></li>
                <li><a href="https://wordpress.org/plugins/ai-chat-for-amazon-bedrock" target="_blank"><?php esc_html_e('WordPress Plugin Page', 'ai-chat-for-amazon-bedrock'); ?></a></li>
            </ul>
        </p>
    </div>

</div>
