<?php
if (!defined('ABSPATH')) exit;
?>
<div class="wrap">
    <h1>AI Chat for Amazon Bedrock Settings</h1>
    <form method="post" action="options.php">
        <?php
        settings_fields('wp-bedrock_settings');
        do_settings_sections('wp-bedrock_settings');
        ?>

        <div class="wp-bedrock-settings-container">
            <!-- AWS Credentials Section -->
            <div class="settings-section">
                <h2>AWS Credentials</h2>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">AWS Access Key</th>
                        <td>
                            <input type="text" name="wpbedrock_aws_key" value="<?php echo esc_attr(get_option('wpbedrock_aws_key')); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">AWS Secret Key</th>
                        <td>
                            <input type="password" name="wpbedrock_aws_secret" value="<?php echo esc_attr(get_option('wpbedrock_aws_secret')); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">AWS Region</th>
                        <td>
                            <select name="wpbedrock_aws_region">
                                <option value="us-east-1" <?php selected(get_option('wpbedrock_aws_region'), 'us-east-1'); ?>>US East (N. Virginia)</option>
                                <option value="us-west-2" <?php selected(get_option('wpbedrock_aws_region'), 'us-west-2'); ?>>US West (Oregon)</option>
                                <option value="ap-northeast-1" <?php selected(get_option('wpbedrock_aws_region'), 'ap-northeast-1'); ?>>Asia Pacific (Tokyo)</option>
                                <option value="ap-southeast-1" <?php selected(get_option('wpbedrock_aws_region'), 'ap-southeast-1'); ?>>Asia Pacific (Singapore)</option>
                                <option value="eu-central-1" <?php selected(get_option('wpbedrock_aws_region'), 'eu-central-1'); ?>>Europe (Frankfurt)</option>
                            </select>
                            <p class="description">Select the AWS Bedrock region closest to your server</p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Model Settings Section -->
            <div class="settings-section">
                <h2>Model Settings</h2>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Default Model</th>
                        <td>
                            <select name="wpbedrock_model_id" class="widefat">
                                <optgroup label="Claude 3">
                                    <option value="us.anthropic.claude-3-haiku-20240307-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'us.anthropic.claude-3-haiku-20240307-v1:0'); ?>>Claude 3 Haiku - Fast and efficient</option>
                                    <option value="us.anthropic.claude-3-5-haiku-20241022-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'us.anthropic.claude-3-5-haiku-20241022-v1:0'); ?>>Claude 3.5 Haiku - Enhanced efficiency</option>
                                    <option value="us.anthropic.claude-3-sonnet-20240229-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'us.anthropic.claude-3-sonnet-20240229-v1:0'); ?>>Claude 3 Sonnet - Balanced performance</option>
                                    <option value="us.anthropic.claude-3-5-sonnet-20241022-v2:0" <?php selected(get_option('wpbedrock_model_id'), 'us.anthropic.claude-3-5-sonnet-20241022-v2:0'); ?>>Claude 3.5 Sonnet - Enhanced balance</option>
                                    <option value="us.anthropic.claude-3-opus-20240229-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'us.anthropic.claude-3-opus-20240229-v1:0'); ?>>Claude 3 Opus - Most capable</option>
                                </optgroup>
                                <optgroup label="AWS Nova">
                                    <option value="us.amazon.nova-micro-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'us.amazon.nova-micro-v1:0'); ?>>Nova Micro - Most efficient</option>
                                    <option value="us.amazon.nova-lite-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'us.amazon.nova-lite-v1:0'); ?>>Nova Lite - Cost effective</option>
                                    <option value="us.amazon.nova-pro-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'us.amazon.nova-pro-v1:0'); ?>>Nova Pro - Most capable</option>
                                </optgroup>
                                <optgroup label="Meta Llama 3">
                                    <option value="us.meta.llama3-1-8b-instruct-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'us.meta.llama3-1-8b-instruct-v1:0'); ?>>Llama 3 8B - Most efficient</option>
                                    <option value="us.meta.llama3-1-70b-instruct-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'us.meta.llama3-1-70b-instruct-v1:0'); ?>>Llama 3 70B - Most capable</option>
                                    <option value="us.meta.llama3-2-11b-instruct-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'us.meta.llama3-2-11b-instruct-v1:0'); ?>>Llama 3.2 11B - Balanced efficiency</option>
                                    <option value="us.meta.llama3-2-90b-instruct-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'us.meta.llama3-2-90b-instruct-v1:0'); ?>>Llama 3.2 90B - Enhanced capability</option>
                                    <option value="us.meta.llama3-3-70b-instruct-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'us.meta.llama3-3-70b-instruct-v1:0'); ?>>Llama 3.3 70B - Latest version</option>
                                </optgroup>
                                <optgroup label="Mistral">
                                    <option value="mistral.mistral-large-2402-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'mistral.mistral-large-2402-v1:0'); ?>>Mistral Large 2402 - Latest version</option>
                                    <option value="mistral.mistral-large-2407-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'mistral.mistral-large-2407-v1:0'); ?>>Mistral Large 2407 - Previous version</option>
                                </optgroup>
                            </select>
                            <p class="description">Select the default model for new chat sessions</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Default Temperature</th>
                        <td>
                            <div class="temperature-control">
                                <input type="range" name="wpbedrock_temperature" value="<?php echo esc_attr(get_option('wpbedrock_temperature', '0.7')); ?>" min="0" max="1" step="0.1" class="temperature-slider" />
                                <span class="temperature-value"><?php echo esc_attr(get_option('wpbedrock_temperature', '0.7')); ?></span>
                            </div>
                            <p class="description">Controls randomness in responses (0 = focused, 1 = creative)</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Max Tokens</th>
                        <td>
                            <input type="number" name="wpbedrock_max_tokens" value="<?php echo esc_attr(get_option('wpbedrock_max_tokens', '2000')); ?>" class="small-text" min="1" max="4000" />
                            <p class="description">Maximum length of generated responses</p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Chat Settings Section -->
            <div class="settings-section">
                <h2>Chat Settings</h2>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Default System Prompt</th>
                        <td>
                            <textarea name="wpbedrock_system_prompt" rows="4" class="large-text" style="font-family: monospace;"><?php echo esc_textarea(get_option('wpbedrock_system_prompt', 'You are a helpful AI assistant. Respond to user queries in a clear and concise manner.')); ?></textarea>
                            <p class="description">Define the AI's default behavior and role</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Initial Message</th>
                        <td>
                            <textarea name="wpbedrock_chat_initial_message" rows="2" class="large-text"><?php echo esc_textarea(get_option('wpbedrock_chat_initial_message', 'Hello! How can I help you today?')); ?></textarea>
                            <p class="description">First message shown in new chat sessions</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Input Placeholder</th>
                        <td>
                            <input type="text" name="wpbedrock_chat_placeholder" value="<?php echo esc_attr(get_option('wpbedrock_chat_placeholder', 'Type your message here...')); ?>" class="regular-text" />
                            <p class="description">Placeholder text for the chat input field</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Context Length</th>
                        <td>
                            <input type="number" name="wpbedrock_context_length" value="<?php echo esc_attr(get_option('wpbedrock_context_length', '4')); ?>" class="small-text" min="1" max="10" />
                            <p class="description">Number of previous messages to include as context (1-10)</p>
                        </td>
                    </tr>
                    <!-- <tr valign="top">
                        <th scope="row">Enable Streaming</th>
                        <td>
                            <label>
                                <input type="checkbox" name="wpbedrock_enable_stream" value="1" <?php checked(get_option('wpbedrock_enable_stream', '1'), '1'); ?> />
                                Show responses as they are generated
                            </label>
                            <p class="description">Provides a more interactive experience</p>
                        </td>
                    </tr> -->
                </table>
            </div>
        </div>

        <?php submit_button(); ?>
    </form>
</div>

<style>
.wp-bedrock-settings-container {
    max-width: 1200px;
}

.settings-section {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
    margin-bottom: 20px;
}

.settings-section h2 {
    margin-top: 0;
    padding-bottom: 12px;
    border-bottom: 1px solid #eee;
}

.temperature-control {
    display: flex;
    align-items: center;
    gap: 10px;
    max-width: 300px;
}

.temperature-slider {
    flex-grow: 1;
}

.temperature-value {
    min-width: 40px;
    text-align: center;
}

/* Responsive table layout */
@media screen and (max-width: 782px) {
    .form-table td {
        padding: 15px 10px;
    }
    
    .form-table th {
        padding: 15px 10px 5px;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Temperature slider
    $('.temperature-slider').on('input', function() {
        $(this).next('.temperature-value').text(this.value);
    });
});
</script>
