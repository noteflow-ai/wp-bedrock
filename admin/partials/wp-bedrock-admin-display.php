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

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}
?>

<div class="wrap">
    <h1>AI Chat for Amazon Bedrock</h1>
    
    <div class="card">
        <h2><?php _e('About AI Chat for Amazon Bedrock', 'wp-bedrock'); ?></h2>
        <p>
            <?php _e('AI Chat for Amazon Bedrock is a powerful WordPress plugin that integrates Amazon Bedrock AI capabilities into your WordPress site. It provides an intelligent chatbot that can assist your visitors with questions and information.', 'wp-bedrock'); ?>
        </p>
    </div>

    <div class="card">
        <h2><?php _e('Key Features', 'wp-bedrock'); ?></h2>
        <ul style="list-style: disc; margin-left: 20px;">
            <li><?php _e('Real-time AI Chat: Engage with visitors through a responsive chatbot interface', 'wp-bedrock'); ?></li>
            <li><?php _e('Streaming Responses: See AI responses appear in real-time with typewriter effect', 'wp-bedrock'); ?></li>
            <li><?php _e('Amazon Bedrock Integration: Powered by advanced AI models like Claude', 'wp-bedrock'); ?></li>
            <li><?php _e('Chat History: Keep track of all conversations for future reference', 'wp-bedrock'); ?></li>
            <li><?php _e('Customizable Settings: Configure the chatbot behavior to suit your needs', 'wp-bedrock'); ?></li>
        </ul>
    </div>

    <div class="card">
        <h2><?php _e('Quick Start Guide', 'wp-bedrock'); ?></h2>
        <ol style="list-style: decimal; margin-left: 20px;">
            <li>
                <strong><?php _e('Configure AWS Credentials', 'wp-bedrock'); ?></strong><br>
                <?php _e('Enter your AWS access key and secret in the Settings page', 'wp-bedrock'); ?>
            </li>
            <li>
                <strong><?php _e('Choose AI Model', 'wp-bedrock'); ?></strong><br>
                <?php _e('Select your preferred Bedrock AI model from the available options', 'wp-bedrock'); ?>
            </li>
            <li>
                <strong><?php _e('Test the Chatbot', 'wp-bedrock'); ?></strong><br>
                <?php _e('Use the Chatbot page to test the functionality', 'wp-bedrock'); ?>
            </li>
        </ol>
    </div>

    <div class="card">
        <h2><?php _e('Navigation', 'wp-bedrock'); ?></h2>
        <ul style="list-style: disc; margin-left: 20px;">
            <li><strong><?php _e('Settings', 'wp-bedrock'); ?></strong>: <?php _e('Configure AWS credentials and AI model settings', 'wp-bedrock'); ?></li>
            <li><strong><?php _e('Chatbot', 'wp-bedrock'); ?></strong>: <?php _e('Access the chat interface for testing and monitoring', 'wp-bedrock'); ?></li>
        </ul>
    </div>

    <div class="card">
        <h2><?php _e('Support & Documentation', 'wp-bedrock'); ?></h2>
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
            <?php _e('For support and detailed documentation, please visit:', 'wp-bedrock'); ?>
            <ul style="list-style: disc; margin-left: 20px;">
                <li><a href="https://github.com/noteflow-ai/wp-bedrock" target="_blank"><?php _e('GitHub Repository', 'wp-bedrock'); ?></a></li>
                <li><a href="https://wordpress.org/plugins/ai-chat-for-amazon-bedrock" target="_blank"><?php _e('WordPress Plugin Page', 'wp-bedrock'); ?></a></li>
            </ul>
        </p>
    </div>

    <style>
        .wrap .card {
            max-width: 800px;
            margin-top: 20px;
            padding: 20px;
        }
        .wrap .card h2 {
            margin-top: 0;
            color: #1d2327;
            font-size: 1.4em;
            margin-bottom: 1em;
        }
        .wrap .card p {
            font-size: 14px;
            margin: 1em 0;
            line-height: 1.6;
        }
        .wrap .card ul, .wrap .card ol {
            margin: 1em 0;
        }
        .wrap .card li {
            margin-bottom: 10px;
            line-height: 1.4;
        }
    </style>
</div>
