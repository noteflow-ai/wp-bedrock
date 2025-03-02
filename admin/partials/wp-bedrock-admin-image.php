<?php
/**
 * Admin image generation page template
 *
 * @since      1.0.0
 * @package    WP_Bedrock
 * @subpackage WP_Bedrock/admin/partials
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

?><div class="wrap">
    <h1><?php esc_html_e('Image Generation', 'wp-bedrock'); ?></h1>

    <div class="wp-bedrock-tabs">
        <button class="tab-button active" data-tab="bedrock-nova"><?php esc_html_e('Bedrock Nova', 'wp-bedrock'); ?></button>
        <button class="tab-button" data-tab="bedrock-sd"><?php esc_html_e('Bedrock SD', 'wp-bedrock'); ?></button>
        <button class="tab-button" data-tab="bedrock-titan"><?php esc_html_e('Bedrock Titan', 'wp-bedrock'); ?></button>
    </div>
    
    <div class="wp-bedrock-image-container">
        <div class="wp-bedrock-image-input">
            <div class="prompt-section">
                <label for="wp-bedrock-image-prompt"><?php esc_html_e('Prompt:', 'wp-bedrock'); ?></label>
                <textarea id="wp-bedrock-image-prompt" placeholder="<?php esc_attr_e('Describe the image you want to generate...', 'wp-bedrock'); ?>" rows="4"></textarea>
                <div class="button-group">
                    <button id="wp-bedrock-surprise-me" class="button"><?php esc_html_e('Surprise Me', 'wp-bedrock'); ?></button>
                    <button id="wp-bedrock-generate-image" class="button button-primary"><?php esc_html_e('Generate', 'wp-bedrock'); ?></button>
                </div>
            </div>

            <div class="wp-bedrock-image-controls">
                <!-- Bedrock Nova Tab Content -->
                <div class="tab-content active" id="bedrock-nova-content">
                    <div class="settings-panel">
                        <h3><?php esc_html_e('Model Settings', 'wp-bedrock'); ?></h3>
                        <div class="setting-group">
                            <label for="wp-bedrock-nova-model"><?php esc_html_e('Model:', 'wp-bedrock'); ?></label>
                            <select id="wp-bedrock-nova-model">
                                <?php foreach ($image_models as $model): ?>
                                    <?php if ($model['type'] === 'bedrock-nova'): ?>
                                        <option value="<?php echo esc_attr($model['id']); ?>"><?php echo esc_html($model['name']); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="setting-group">
                            <label for="wp-bedrock-nova-quality"><?php esc_html_e('Quality:', 'wp-bedrock'); ?></label>
                            <select id="wp-bedrock-nova-quality">
                                <option value="standard"><?php esc_html_e('Standard', 'wp-bedrock'); ?></option>
                                <option value="premium"><?php esc_html_e('Premium', 'wp-bedrock'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Premium quality takes longer but produces better results', 'wp-bedrock'); ?></p>
                        </div>
                    </div>

                    <div class="settings-panel">
                        <h3><?php esc_html_e('Generation Settings', 'wp-bedrock'); ?></h3>
                        
                        <div class="setting-group dimensions">
                            <label><?php esc_html_e('Dimensions:', 'wp-bedrock'); ?></label>
                            <div class="dimension-inputs">
                                <input type="number" id="wp-bedrock-nova-width" min="512" max="1792" step="8" value="1024">
                                <span>×</span>
                                <input type="number" id="wp-bedrock-nova-height" min="512" max="1792" step="8" value="1024">
                            </div>
                            <select id="wp-bedrock-nova-size">
                                <option value="1024x1024">1024x1024</option>
                                <option value="1024x1792">1024x1792</option>
                                <option value="1792x1024">1792x1024</option>
                            </select>
                        </div>

                        <div class="setting-group">
                            <label for="wp-bedrock-nova-seed"><?php esc_html_e('Seed:', 'wp-bedrock'); ?></label>
                            <input type="number" id="wp-bedrock-nova-seed" min="0" max="4294967295" value="-1" placeholder="<?php esc_attr_e('Random', 'wp-bedrock'); ?>">
                        </div>

                        <div class="setting-group">
                            <label for="wp-bedrock-nova-negative-prompt"><?php esc_html_e('Negative Prompt:', 'wp-bedrock'); ?></label>
                            <textarea id="wp-bedrock-nova-negative-prompt" placeholder="<?php esc_attr_e('Describe what you don\'t want in the image...', 'wp-bedrock'); ?>" rows="2"></textarea>
                            <p class="description"><?php esc_html_e('Separate by commas', 'wp-bedrock'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Bedrock SD Tab Content -->
                <div class="tab-content" id="bedrock-sd-content">
                    <div class="settings-panel">
                        <h3><?php esc_html_e('Model Settings', 'wp-bedrock'); ?></h3>
                        <div class="setting-group">
                            <label for="wp-bedrock-sd-model"><?php esc_html_e('Model:', 'wp-bedrock'); ?></label>
                            <select id="wp-bedrock-sd-model">
                                <?php foreach ($image_models as $model): ?>
                                    <?php if ($model['type'] === 'bedrock-sd'): ?>
                                        <option value="<?php echo esc_attr($model['id']); ?>"><?php echo esc_html($model['name']); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>

                            <div class="wp-bedrock-image-model-info">
                                <p class="description"></p>
                            </div>
                        </div>

                        <div class="setting-group">
                            <label for="wp-bedrock-sd-style"><?php esc_html_e('Style Preset:', 'wp-bedrock'); ?></label>
                            <select id="wp-bedrock-sd-style">
                                <?php foreach ($style_presets as $value => $label): ?>
                                    <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="settings-panel">
                        <h3><?php esc_html_e('Generation Settings', 'wp-bedrock'); ?></h3>
                        
                        <div class="setting-group dimensions">
                            <label><?php esc_html_e('Dimensions:', 'wp-bedrock'); ?></label>
                            <div class="dimension-inputs">
                                <input type="number" id="wp-bedrock-sd-width" min="512" max="1024" step="8" value="1024">
                                <span>×</span>
                                <input type="number" id="wp-bedrock-sd-height" min="512" max="1024" step="8" value="1024">
                            </div>
                            <select id="wp-bedrock-sd-aspect-ratio">
                                <option value="1:1"><?php esc_html_e('Square (1:1)', 'wp-bedrock'); ?></option>
                                <option value="16:9"><?php esc_html_e('Widescreen (16:9)', 'wp-bedrock'); ?></option>
                                <option value="21:9"><?php esc_html_e('Ultrawide (21:9)', 'wp-bedrock'); ?></option>
                                <option value="2:3"><?php esc_html_e('Portrait (2:3)', 'wp-bedrock'); ?></option>
                                <option value="3:2"><?php esc_html_e('Landscape (3:2)', 'wp-bedrock'); ?></option>
                                <option value="4:5"><?php esc_html_e('Portrait (4:5)', 'wp-bedrock'); ?></option>
                                <option value="5:4"><?php esc_html_e('Landscape (5:4)', 'wp-bedrock'); ?></option>
                                <option value="9:16"><?php esc_html_e('Vertical (9:16)', 'wp-bedrock'); ?></option>
                                <option value="9:21"><?php esc_html_e('Vertical Ultrawide (9:21)', 'wp-bedrock'); ?></option>
                            </select>
                        </div>

                        <div class="setting-group">
                            <label for="wp-bedrock-sd-steps"><?php esc_html_e('Inference Steps:', 'wp-bedrock'); ?></label>
                            <input type="number" id="wp-bedrock-sd-steps" min="10" max="150" value="50">
                        </div>

                        <div class="setting-group">
                            <label for="wp-bedrock-sd-cfg-scale"><?php esc_html_e('Prompt Strength:', 'wp-bedrock'); ?></label>
                            <input type="number" id="wp-bedrock-sd-cfg-scale" min="0" max="35" step="0.1" value="7.0">
                        </div>

                        <div class="setting-group">
                            <label for="wp-bedrock-sd-seed"><?php esc_html_e('Seed:', 'wp-bedrock'); ?></label>
                            <input type="number" id="wp-bedrock-sd-seed" min="0" max="4294967295" value="-1" placeholder="<?php esc_attr_e('Random', 'wp-bedrock'); ?>">
                        </div>

                        <div class="setting-group">
                            <label for="wp-bedrock-sd-negative-prompt"><?php esc_html_e('Negative Prompt:', 'wp-bedrock'); ?></label>
                            <textarea id="wp-bedrock-sd-negative-prompt" placeholder="<?php esc_attr_e('Describe what you don\'t want in the image...', 'wp-bedrock'); ?>" rows="2"></textarea>
                            <p class="description"><?php esc_html_e('Separate by commas', 'wp-bedrock'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Bedrock Titan Tab Content -->
                <div class="tab-content" id="bedrock-titan-content">
                    <div class="settings-panel">
                        <h3><?php esc_html_e('Model Settings', 'wp-bedrock'); ?></h3>
                        <div class="setting-group">
                            <label for="wp-bedrock-titan-model"><?php esc_html_e('Model:', 'wp-bedrock'); ?></label>
                            <select id="wp-bedrock-titan-model">
                                <?php foreach ($image_models as $model): ?>
                                    <?php if ($model['type'] === 'bedrock-titan'): ?>
                                        <option value="<?php echo esc_attr($model['id']); ?>"><?php echo esc_html($model['name']); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="setting-group">
                            <label for="wp-bedrock-titan-quality"><?php esc_html_e('Quality:', 'wp-bedrock'); ?></label>
                            <select id="wp-bedrock-titan-quality">
                                <option value="standard"><?php esc_html_e('Standard', 'wp-bedrock'); ?></option>
                                <option value="premium"><?php esc_html_e('Premium', 'wp-bedrock'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Premium quality takes longer but produces better results', 'wp-bedrock'); ?></p>
                        </div>
                    </div>

                    <div class="settings-panel">
                        <h3><?php esc_html_e('Generation Settings', 'wp-bedrock'); ?></h3>
                        
                        <div class="setting-group dimensions">
                            <label><?php esc_html_e('Dimensions:', 'wp-bedrock'); ?></label>
                            <div class="dimension-inputs">
                                <input type="number" id="wp-bedrock-titan-width" min="512" max="1792" step="8" value="1024">
                                <span>×</span>
                                <input type="number" id="wp-bedrock-titan-height" min="512" max="1792" step="8" value="1024">
                            </div>
                            <select id="wp-bedrock-titan-size">
                                <option value="1024x1024">1024x1024</option>
                                <option value="1024x1792">1024x1792</option>
                                <option value="1792x1024">1792x1024</option>
                            </select>
                        </div>

                        <div class="setting-group">
                            <label for="wp-bedrock-titan-cfg-scale"><?php esc_html_e('CFG Scale:', 'wp-bedrock'); ?></label>
                            <input type="number" id="wp-bedrock-titan-cfg-scale" min="1.1" max="10.0" step="0.1" value="7.5">
                            <p class="description"><?php esc_html_e('Controls how closely to follow the prompt', 'wp-bedrock'); ?></p>
                        </div>

                        <div class="setting-group">
                            <label for="wp-bedrock-titan-seed"><?php esc_html_e('Seed:', 'wp-bedrock'); ?></label>
                            <input type="number" id="wp-bedrock-titan-seed" min="0" max="4294967295" value="-1" placeholder="<?php esc_attr_e('Random', 'wp-bedrock'); ?>">
                        </div>

                        <div class="setting-group">
                            <label for="wp-bedrock-titan-negative-prompt"><?php esc_html_e('Negative Prompt:', 'wp-bedrock'); ?></label>
                            <textarea id="wp-bedrock-titan-negative-prompt" placeholder="<?php esc_attr_e('Describe what you don\'t want in the image...', 'wp-bedrock'); ?>" rows="2"></textarea>
                            <p class="description"><?php esc_html_e('Separate by commas', 'wp-bedrock'); ?></p>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <div class="wp-bedrock-image-output">
            <div id="wp-bedrock-image-result"></div>
            <div id="wp-bedrock-image-loading" style="display: none;">
                <span class="spinner is-active"></span>
                <span><?php esc_html_e('Generating image...', 'wp-bedrock'); ?></span>
            </div>
        </div>
    </div>
</div>
