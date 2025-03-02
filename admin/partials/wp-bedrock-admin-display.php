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
        <h2><?php esc_html_e('About AI Chat for Amazon Bedrock', 'wp-bedrock'); ?></h2>
        <p>
            <?php esc_html_e('AI Chat for Amazon Bedrock is a powerful WordPress plugin that integrates Amazon Bedrock AI capabilities into your WordPress site. It provides an intelligent chatbot that can assist your visitors with questions and information.', 'wp-bedrock'); ?>
        </p>
    </div>

    <div class="card">
        <h2><?php esc_html_e('Key Features', 'wp-bedrock'); ?></h2>
        <ul class="feature-list">
            <li><?php esc_html_e('Real-time AI Chat: Engage with visitors through a responsive chatbot interface', 'wp-bedrock'); ?></li>
            <li><?php esc_html_e('Streaming Responses: See AI responses appear in real-time with typewriter effect', 'wp-bedrock'); ?></li>
            <li><?php esc_html_e('Amazon Bedrock Integration: Powered by advanced AI models like Claude', 'wp-bedrock'); ?></li>
            <li><?php esc_html_e('Chat History: Keep track of all conversations for future reference', 'wp-bedrock'); ?></li>
            <li><?php esc_html_e('Customizable Settings: Configure the chatbot behavior to suit your needs', 'wp-bedrock'); ?></li>
        </ul>
    </div>

    <div class="card">
        <h2><?php esc_html_e('Quick Start Guide', 'wp-bedrock'); ?></h2>
        <ol class="guide-list">
            <li>
                <strong><?php esc_html_e('Configure AWS Credentials', 'wp-bedrock'); ?></strong><br>
                <?php esc_html_e('Enter your AWS access key and secret in the Settings page', 'wp-bedrock'); ?>
            </li>
            <li>
                <strong><?php esc_html_e('Choose AI Model', 'wp-bedrock'); ?></strong><br>
                <?php esc_html_e('Select your preferred Bedrock AI model from the available options', 'wp-bedrock'); ?>
            </li>
            <li>
                <strong><?php esc_html_e('Test the Chatbot', 'wp-bedrock'); ?></strong><br>
                <?php esc_html_e('Use the Chatbot page to test the functionality', 'wp-bedrock'); ?>
            </li>
        </ol>
    </div>

    <div class="card">
        <h2><?php esc_html_e('Navigation', 'wp-bedrock'); ?></h2>
        <ul class="nav-list">
            <li><strong><?php esc_html_e('Settings', 'wp-bedrock'); ?></strong>: <?php esc_html_e('Configure AWS credentials and AI model settings', 'wp-bedrock'); ?></li>
            <li><strong><?php esc_html_e('Chatbot', 'wp-bedrock'); ?></strong>: <?php esc_html_e('Access the chat interface for testing and monitoring', 'wp-bedrock'); ?></li>
        </ul>
    </div>

    <div class="card">
        <h2><?php esc_html_e('Support & Documentation', 'wp-bedrock'); ?></h2>
        <p>
            <?php 
            printf(
                /* translators: %s: Version number */
                __('Current Version: %s', 'wp-bedrock'), 
                '<strong>' . WPBEDROCK_VERSION . '</strong>'
            ); 
            ?>
        </p>
        <p>
            <?php esc_html_e('For support and detailed documentation, please visit:', 'wp-bedrock'); ?>
            <ul class="support-list">
                <li><a href="https://github.com/noteflow-ai/wp-bedrock" target="_blank"><?php esc_html_e('GitHub Repository', 'wp-bedrock'); ?></a></li>
                <li><a href="https://wordpress.org/plugins/ai-chat-for-amazon-bedrock" target="_blank"><?php esc_html_e('WordPress Plugin Page', 'wp-bedrock'); ?></a></li>
            </ul>
        </p>
    </div>

</div>
