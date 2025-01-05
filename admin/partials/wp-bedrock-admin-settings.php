<?php
if (!defined('ABSPATH')) exit;
?>
<div class="wrap">
    <h1>WP Bedrock Settings</h1>
    <form method="post" action="options.php">
        <?php
        settings_fields('wp-bedrock_settings');
        do_settings_sections('wp-bedrock_settings');
        ?>
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
            <tr valign="top">
                <th scope="row">Model ID</th>
                <td>
                    <select name="wpbedrock_model_id">
                        <!-- Claude 3 系列 -->
                        <option value="anthropic.claude-3-opus-20240229-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'anthropic.claude-3-opus-20240229-v1:0'); ?>>Claude 3 Opus</option>
                        <option value="anthropic.claude-3-sonnet-20240229-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'anthropic.claude-3-sonnet-20240229-v1:0'); ?>>Claude 3 Sonnet</option>
                        <option value="anthropic.claude-3-haiku-20240307-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'anthropic.claude-3-haiku-20240307-v1:0'); ?>>Claude 3 Haiku</option>
                        <option value="us.anthropic.claude-3-5-haiku-20241022-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'us.anthropic.claude-3-5-haiku-20241022-v1:0'); ?>>Claude 3.5 Haiku</option>
                        <option value="us.anthropic.claude-3-5-sonnet-20241022-v2:0" <?php selected(get_option('wpbedrock_model_id'), 'us.anthropic.claude-3-5-sonnet-20241022-v2:0'); ?>>Claude 3.5 Sonnet</option>

                        <!-- AWS Nova 系列 -->
                        <option value="us.amazon.nova-micro-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'us.amazon.nova-micro-v1:0'); ?>>Nova Micro</option>
                        <option value="us.amazon.nova-lite-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'us.amazon.nova-lite-v1:0'); ?>>Nova Lite</option>
                        <option value="us.amazon.nova-pro-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'us.amazon.nova-pro-v1:0'); ?>>Nova Pro</option>

                        <!-- Llama 3 系列 -->
                        <option value="us.meta.llama3-1-8b-instruct-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'us.meta.llama3-1-8b-instruct-v1:0'); ?>>Llama 3 1.8B Instruct</option>
                        <option value="us.meta.llama3-1-70b-instruct-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'us.meta.llama3-1-70b-instruct-v1:0'); ?>>Llama 3 1.70B Instruct</option>
                        <option value="us.meta.llama3-2-11b-instruct-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'us.meta.llama3-2-11b-instruct-v1:0'); ?>>Llama 3 2.11B Instruct</option>
                        <option value="us.meta.llama3-2-90b-instruct-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'us.meta.llama3-2-90b-instruct-v1:0'); ?>>Llama 3 2.90B Instruct</option>
                        <option value="us.meta.llama3-3-70b-instruct-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'us.meta.llama3-3-70b-instruct-v1:0'); ?>>Llama 3 3.70B Instruct</option>

                        <!-- Mistral 系列 -->
                        <option value="mistral.mistral-large-2402-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'mistral.mistral-large-2402-v1:0'); ?>>Mistral Large 2402</option>
                        <option value="mistral.mistral-large-2407-v1:0" <?php selected(get_option('wpbedrock_model_id'), 'mistral.mistral-large-2407-v1:0'); ?>>Mistral Large 2407</option>
                    </select>
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Enable Streaming</th>
                <td>
                    <label>
                        <input type="checkbox" name="wpbedrock_enable_stream" value="1" <?php checked(get_option('wpbedrock_enable_stream', '1'), '1'); ?> />
                        Enable streaming responses (real-time text generation)
                    </label>
                    <p class="description">Show responses as they are generated, word by word</p>
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Temperature</th>
                <td>
                    <input type="number" name="wpbedrock_temperature" value="<?php echo esc_attr(get_option('wpbedrock_temperature', '0.7')); ?>" class="small-text" step="0.1" min="0" max="1" />
                    <p class="description">Controls randomness in the output (0.0 to 1.0)</p>
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Max Tokens</th>
                <td>
                    <input type="number" name="wpbedrock_max_tokens" value="<?php echo esc_attr(get_option('wpbedrock_max_tokens', '2000')); ?>" class="small-text" min="1" max="4000" />
                    <p class="description">Maximum length of the generated response</p>
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Chat Initial Message</th>
                <td>
                    <textarea name="wpbedrock_chat_initial_message" rows="3" class="large-text"><?php echo esc_textarea(get_option('wpbedrock_chat_initial_message', 'Hello! How can I help you today?')); ?></textarea>
                    <p class="description">Initial message shown in the chat window</p>
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Chat Input Placeholder</th>
                <td>
                    <input type="text" name="wpbedrock_chat_placeholder" value="<?php echo esc_attr(get_option('wpbedrock_chat_placeholder', 'Type your message here...')); ?>" class="regular-text" />
                    <p class="description">Placeholder text for the chat input field</p>
                </td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>
</div>
