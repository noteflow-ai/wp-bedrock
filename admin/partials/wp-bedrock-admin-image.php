<div class="wrap">
    <h1>Image Generation</h1>

    <div class="wp-bedrock-tabs">
        <button class="tab-button active" data-tab="bedrock-nova">Bedrock Nova</button>
        <button class="tab-button" data-tab="bedrock-sd">Bedrock SD</button>
        <button class="tab-button" data-tab="bedrock-titan">Bedrock Titan</button>
    </div>
    
    <div class="wp-bedrock-image-container">
        <div class="wp-bedrock-image-input">
            <div class="prompt-section">
                <label for="wp-bedrock-image-prompt">Prompt:</label>
                <textarea id="wp-bedrock-image-prompt" placeholder="Describe the image you want to generate..." rows="4"></textarea>
                <div class="button-group">
                    <button id="wp-bedrock-surprise-me" class="button">Surprise Me</button>
                    <button id="wp-bedrock-generate-image" class="button button-primary">Generate</button>
                </div>
            </div>

            <div class="wp-bedrock-image-controls">
                <!-- Bedrock Nova Tab Content -->
                <div class="tab-content active" id="bedrock-nova-content">
                    <div class="settings-panel">
                        <h3>Model Settings</h3>
                        <div class="setting-group">
                            <label for="wp-bedrock-nova-model">Model:</label>
                            <select id="wp-bedrock-nova-model">
                                <?php foreach ($image_models as $model): ?>
                                    <?php if ($model['type'] === 'bedrock-nova'): ?>
                                        <option value="<?php echo esc_attr($model['id']); ?>"><?php echo esc_html($model['name']); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="setting-group">
                            <label for="wp-bedrock-nova-quality">Quality:</label>
                            <select id="wp-bedrock-nova-quality">
                                <option value="standard">Standard</option>
                                <option value="premium">Premium</option>
                            </select>
                            <p class="description">Premium quality takes longer but produces better results</p>
                        </div>
                    </div>

                    <div class="settings-panel">
                        <h3>Generation Settings</h3>
                        
                        <div class="setting-group dimensions">
                            <label>Dimensions:</label>
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
                            <label for="wp-bedrock-nova-seed">Seed:</label>
                            <input type="number" id="wp-bedrock-nova-seed" min="0" max="4294967295" value="-1" placeholder="Random">
                        </div>

                        <div class="setting-group">
                            <label for="wp-bedrock-nova-negative-prompt">Negative Prompt:</label>
                            <textarea id="wp-bedrock-nova-negative-prompt" placeholder="Describe what you don't want in the image..." rows="2"></textarea>
                            <p class="description">Separate by commas</p>
                        </div>
                    </div>
                </div>

                <!-- Bedrock SD Tab Content -->
                <div class="tab-content" id="bedrock-sd-content">
                    <div class="settings-panel">
                        <h3>Model Settings</h3>
                        <div class="setting-group">
                            <label for="wp-bedrock-sd-model">Model:</label>
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
                            <label for="wp-bedrock-sd-style">Style Preset:</label>
                            <select id="wp-bedrock-sd-style">
                                <?php foreach ($style_presets as $value => $label): ?>
                                    <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="settings-panel">
                        <h3>Generation Settings</h3>
                        
                        <div class="setting-group dimensions">
                            <label>Dimensions:</label>
                            <div class="dimension-inputs">
                                <input type="number" id="wp-bedrock-sd-width" min="512" max="1024" step="8" value="1024">
                                <span>×</span>
                                <input type="number" id="wp-bedrock-sd-height" min="512" max="1024" step="8" value="1024">
                            </div>
                            <select id="wp-bedrock-sd-aspect-ratio">
                                <option value="1:1">Square (1:1)</option>
                                <option value="16:9">Widescreen (16:9)</option>
                                <option value="21:9">Ultrawide (21:9)</option>
                                <option value="2:3">Portrait (2:3)</option>
                                <option value="3:2">Landscape (3:2)</option>
                                <option value="4:5">Portrait (4:5)</option>
                                <option value="5:4">Landscape (5:4)</option>
                                <option value="9:16">Vertical (9:16)</option>
                                <option value="9:21">Vertical Ultrawide (9:21)</option>
                            </select>
                        </div>

                        <div class="setting-group">
                            <label for="wp-bedrock-sd-steps">Inference Steps:</label>
                            <input type="number" id="wp-bedrock-sd-steps" min="10" max="150" value="50">
                        </div>

                        <div class="setting-group">
                            <label for="wp-bedrock-sd-cfg-scale">Prompt Strength:</label>
                            <input type="number" id="wp-bedrock-sd-cfg-scale" min="0" max="35" step="0.1" value="7.0">
                        </div>

                        <div class="setting-group">
                            <label for="wp-bedrock-sd-seed">Seed:</label>
                            <input type="number" id="wp-bedrock-sd-seed" min="0" max="4294967295" value="-1" placeholder="Random">
                        </div>

                        <div class="setting-group">
                            <label for="wp-bedrock-sd-negative-prompt">Negative Prompt:</label>
                            <textarea id="wp-bedrock-sd-negative-prompt" placeholder="Describe what you don't want in the image..." rows="2"></textarea>
                            <p class="description">Separate by commas</p>
                        </div>
                    </div>
                </div>

                <!-- Bedrock Titan Tab Content -->
                <div class="tab-content" id="bedrock-titan-content">
                    <div class="settings-panel">
                        <h3>Model Settings</h3>
                        <div class="setting-group">
                            <label for="wp-bedrock-titan-model">Model:</label>
                            <select id="wp-bedrock-titan-model">
                                <?php foreach ($image_models as $model): ?>
                                    <?php if ($model['type'] === 'bedrock-titan'): ?>
                                        <option value="<?php echo esc_attr($model['id']); ?>"><?php echo esc_html($model['name']); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="setting-group">
                            <label for="wp-bedrock-titan-quality">Quality:</label>
                            <select id="wp-bedrock-titan-quality">
                                <option value="standard">Standard</option>
                                <option value="premium">Premium</option>
                            </select>
                            <p class="description">Premium quality takes longer but produces better results</p>
                        </div>
                    </div>

                    <div class="settings-panel">
                        <h3>Generation Settings</h3>
                        
                        <div class="setting-group dimensions">
                            <label>Dimensions:</label>
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
                            <label for="wp-bedrock-titan-cfg-scale">CFG Scale:</label>
                            <input type="number" id="wp-bedrock-titan-cfg-scale" min="1.1" max="10.0" step="0.1" value="7.5">
                            <p class="description">Controls how closely to follow the prompt</p>
                        </div>

                        <div class="setting-group">
                            <label for="wp-bedrock-titan-seed">Seed:</label>
                            <input type="number" id="wp-bedrock-titan-seed" min="0" max="4294967295" value="-1" placeholder="Random">
                        </div>

                        <div class="setting-group">
                            <label for="wp-bedrock-titan-negative-prompt">Negative Prompt:</label>
                            <textarea id="wp-bedrock-titan-negative-prompt" placeholder="Describe what you don't want in the image..." rows="2"></textarea>
                            <p class="description">Separate by commas</p>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <div class="wp-bedrock-image-output">
            <div id="wp-bedrock-image-result"></div>
            <div id="wp-bedrock-image-loading" style="display: none;">
                <span class="spinner is-active"></span>
                <span>Generating image...</span>
            </div>
        </div>
    </div>
</div>

<style>
.wp-bedrock-tabs {
    margin: 20px 0;
    border-bottom: 1px solid #ddd;
}

.tab-button {
    padding: 10px 20px;
    margin-right: 5px;
    border: 1px solid #ddd;
    border-bottom: none;
    background: #f8f9fa;
    cursor: pointer;
    border-radius: 4px 4px 0 0;
}

.tab-button.active {
    background: #fff;
    border-bottom: 1px solid #fff;
    margin-bottom: -1px;
    font-weight: 500;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.wp-bedrock-image-container {
    margin-top: 20px;
    display: grid;
    grid-template-columns: minmax(300px, 2fr) 3fr;
    gap: 20px;
}

.wp-bedrock-image-input {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.prompt-section {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.prompt-section label {
    font-weight: 600;
}

.prompt-section textarea {
    width: 100%;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 10px;
    font-size: 14px;
}

.button-group {
    display: flex;
    gap: 10px;
}

.settings-panel {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 15px;
    margin-bottom: 15px;
}

.settings-panel h3 {
    margin: 0 0 15px 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
    font-size: 14px;
    font-weight: 600;
}

.setting-group {
    margin-bottom: 15px;
}

.setting-group:last-child {
    margin-bottom: 0;
}

.setting-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    font-size: 13px;
}

.setting-group select,
.setting-group input[type="number"],
.setting-group textarea {
    width: 100%;
    max-width: 100%;
    padding: 6px 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background-color: #fff;
}

.setting-group textarea {
    resize: vertical;
    min-height: 60px;
}

.setting-group .description {
    color: #666;
    font-size: 12px;
    margin-top: 4px;
}

.dimensions {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.dimension-inputs {
    display: flex;
    align-items: center;
    gap: 8px;
}

.dimension-inputs input {
    width: 80px;
}

.dimension-inputs span {
    color: #666;
}

.wp-bedrock-image-output {
    background: #f8f9fa;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 20px;
    min-height: 400px;
    display: flex;
    flex-direction: column;
    gap: 15px;
}

#wp-bedrock-image-result {
    flex: 1;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 15px;
    align-content: start;
}

#wp-bedrock-image-result img {
    width: 100%;
    height: auto;
    border-radius: 4px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

#wp-bedrock-image-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 20px;
    background: rgba(255,255,255,0.9);
    border-radius: 4px;
}

#wp-bedrock-image-progress {
    margin-top: 10px;
}

.progress-bar {
    height: 4px;
    background-color: #e9ecef;
    border-radius: 2px;
    overflow: hidden;
}

.progress-bar-fill {
    height: 100%;
    background-color: #2271b1;
    width: 0;
    transition: width 0.3s ease;
}

.progress-text {
    text-align: center;
    margin-top: 5px;
    font-size: 12px;
    color: #666;
}

.image-actions {
    display: flex;
    gap: 10px;
    padding: 10px;
    background: #fff;
    border-radius: 4px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}
</style>
