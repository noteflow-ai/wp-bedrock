<?php
/**
 * Provide a public-facing view for the plugin
 *
 * This file is used to markup the public-facing aspects of the plugin.
 *
 * @since      1.0.0
 * @package    WP_Bedrock
 * @subpackage WP_Bedrock/public/partials
 */
?>

<div class="wp-bedrock-container">
    <h2><?php echo esc_html($atts['title']); ?></h2>
    <div class="wp-bedrock-content">
        <!-- 在这里添加你的前端HTML结构 -->
        <div class="wp-bedrock-widget">
            <div class="wp-bedrock-widget-header">
                <h3>示例小部件</h3>
            </div>
            <div class="wp-bedrock-widget-content">
                <p>这是一个示例小部件内容。你可以在这里添加任何HTML内容。</p>
                <button class="wp-bedrock-button" data-action="example">点击我</button>
            </div>
            <div class="wp-bedrock-widget-footer">
                <small>Powered by AI Chat for Amazon Bedrock</small>
            </div>
        </div>
    </div>
</div>
