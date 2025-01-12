jQuery(document).ready(function($) {
    if (!wpbedrock_image) {
        console.error('AI Chat for Amazon Bedrock Image configuration not found');
        return;
    }

    // Tab Controls
    const tabButtons = $('.tab-button');
    const tabContents = $('.tab-content');
    let activeTab = 'bedrock-nova';

    // Common UI Elements
    const generateButton = $('#wp-bedrock-generate-image');
    const surpriseButton = $('#wp-bedrock-surprise-me');
    const promptInput = $('#wp-bedrock-image-prompt');
    const resultDiv = $('#wp-bedrock-image-result');
    const loadingDiv = $('#wp-bedrock-image-loading');
    const imageUpload = $('#wp-bedrock-image-upload');
    const imagePreview = $('#wp-bedrock-image-preview');
    const previewImage = $('#wp-bedrock-preview-image');
    const removeImageButton = $('#wp-bedrock-remove-image');

    // Bedrock Nova Controls
    const novaModelSelect = $('#wp-bedrock-nova-model');
    const novaQualitySelect = $('#wp-bedrock-nova-quality');
    const novaWidthInput = $('#wp-bedrock-nova-width');
    const novaHeightInput = $('#wp-bedrock-nova-height');
    const novaSeedInput = $('#wp-bedrock-nova-seed');
    const novaNegativePromptInput = $('#wp-bedrock-nova-negative-prompt');
    const novaSizeSelect = $('#wp-bedrock-nova-size');
    const novaDurationInput = $('#wp-bedrock-nova-duration');
    const novaFpsInput = $('#wp-bedrock-nova-fps');

    // Bedrock SD Controls
    const sdModelSelect = $('#wp-bedrock-sd-model');
    const sdStyleSelect = $('#wp-bedrock-sd-style');
    const sdWidthInput = $('#wp-bedrock-sd-width');
    const sdHeightInput = $('#wp-bedrock-sd-height');
    const sdStepsInput = $('#wp-bedrock-sd-steps');
    const sdCfgScaleInput = $('#wp-bedrock-sd-cfg-scale');
    const sdSeedInput = $('#wp-bedrock-sd-seed');
    const sdNegativePromptInput = $('#wp-bedrock-sd-negative-prompt');
    const sdAspectRatioSelect = $('#wp-bedrock-sd-aspect-ratio');
    
    // Bedrock Titan Controls
    const titanModelSelect = $('#wp-bedrock-titan-model');
    const titanQualitySelect = $('#wp-bedrock-titan-quality');
    const titanWidthInput = $('#wp-bedrock-titan-width');
    const titanHeightInput = $('#wp-bedrock-titan-height');
    const titanCfgScaleInput = $('#wp-bedrock-titan-cfg-scale');
    const titanSeedInput = $('#wp-bedrock-titan-seed');
    const titanNegativePromptInput = $('#wp-bedrock-titan-negative-prompt');
    const titanSizeSelect = $('#wp-bedrock-titan-size');

    // Progress Bar
    const progressDiv = $('<div id="wp-bedrock-image-progress"><div class="progress-bar"><div class="progress-bar-fill"></div></div><div class="progress-text"></div></div>');
    loadingDiv.after(progressDiv);

    // Tab Switching
    tabButtons.on('click', function() {
        const tab = $(this).data('tab');
        tabButtons.removeClass('active');
        tabContents.removeClass('active');
        $(this).addClass('active');
        $(`#${tab}-content`).addClass('active');
        activeTab = tab;
    });

    // Sample prompts for "Surprise Me" button
    const samplePrompts = [
        "A magical forest at twilight with glowing mushrooms and fairy lights",
        "A futuristic cityscape with flying cars and neon signs",
        "An underwater palace with mermaids and bioluminescent sea creatures",
        "A steampunk airship floating through clouds at sunset",
        "A cozy cottage in a snowy mountain landscape",
        "A mystical garden with giant crystal formations",
        "An ancient temple hidden in a dense jungle",
        "A space station orbiting a colorful nebula",
        "A dragon's lair filled with treasure and magical artifacts",
        "A cyberpunk street market at night in the rain"
    ];

    // Utility Functions
    function updateDimensionsFromSize(sizeSelect, widthInput, heightInput) {
        const size = sizeSelect.val();
        if (!size) return;

        const [width, height] = size.split('x').map(Number);
        widthInput.val(width);
        heightInput.val(height);
    }

    function getRandomPrompt() {
        return samplePrompts[Math.floor(Math.random() * samplePrompts.length)];
    }

    function updateProgress(step, totalSteps) {
        const percent = (step / totalSteps) * 100;
        $('#wp-bedrock-image-progress .progress-bar-fill').css('width', `${percent}%`);
        $('#wp-bedrock-image-progress .progress-text').text(`Step ${step} of ${totalSteps}`);
    }

    function showError(message) {
        resultDiv.html(`<div class="notice notice-error"><p>Error: ${message}</p></div>`);
    }

    async function generateWithRetry(data, maxRetries = 3, delay = 1000) {
        for (let attempt = 1; attempt <= maxRetries; attempt++) {
            try {
                const response = await $.ajax({
                    url: wpbedrock_image.ajaxurl,
                    type: 'POST',
                    data: data
                });

                if (!response.success) {
                    throw new Error(response.data || 'Unknown error occurred');
                }

                return response;
            } catch (error) {
                if (attempt === maxRetries) {
                    throw error;
                }
                await new Promise(resolve => setTimeout(resolve, delay * attempt));
            }
        }
    }

    // Image Handling
    let currentImage = null;

    function handleImageUpload(file) {
        if (!file || !file.type.startsWith('image/')) {
            alert('Please select a valid image file.');
            return;
        }

        const reader = new FileReader();
        reader.onload = function(e) {
            currentImage = e.target.result;
            previewImage.attr('src', currentImage);
            imagePreview.show();
        };
        reader.onerror = function(e) {
            console.error('Error reading image:', e);
            alert('Error reading image file');
        };
        reader.readAsDataURL(file);
    }

    function removeImage() {
        currentImage = null;
        previewImage.attr('src', '');
        imagePreview.hide();
        imageUpload.val('');
    }

    // Request Body Formatting
    function formatRequestBody(prompt, modelSettings) {
        if (!modelSettings) return null;
        const model = modelSettings.model || '';

        // Handle Bedrock Stable Diffusion
        if (model && model.includes('stability')) {
            return {
                prompt,
                negative_prompt: modelSettings.negative_prompt || '',
                mode: 'text-to-image',
                seed: modelSettings.seed || 0,
                aspect_ratio: modelSettings.aspect_ratio || '1:1',
                output_format: 'png',
                style_preset: modelSettings.style_preset,
                cfg_scale: modelSettings.cfg_scale,
                steps: modelSettings.steps,
                width: modelSettings.width,
                height: modelSettings.height
            };
        }

        // Handle Nova Reel video generation
        if (model && model.includes('us.amazon.nova-reel')) {
            const requestBody = {
                taskType: 'TEXT_VIDEO',
                textToVideoParams: {
                    text: prompt,
                    ...(currentImage && {
                        images: [{
                            format: currentImage.includes('image/png') ? 'png' : 'jpeg',
                            source: {
                                bytes: currentImage.split(',')[1]
                            }
                        }]
                    })
                },
                videoGenerationConfig: {
                    durationSeconds: parseInt(modelSettings.duration) || 6,
                    fps: parseInt(modelSettings.fps) || 24,
                    dimension: `${modelSettings.width}x${modelSettings.height}`,
                    seed: modelSettings.seed || Math.floor(Math.random() * 214783647)
                }
            };

            return {
                body: requestBody,
                outputConfig: {
                    s3OutputDataConfig: {
                        s3Uri: wpbedrock_image.s3_output_path
                    }
                }
            };
        }

        // Handle Titan image generation
        if (model && model.includes('amazon.titan-image')) {
            return {
                taskType: 'TEXT_IMAGE',
                textToImageParams: {
                    text: prompt,
                    negativeText: modelSettings.negative_prompt ||
                        'blurry, distorted, low resolution, pixelated, overexposed, underexposed, dark, grainy, noisy, watermark'
                },
                imageGenerationConfig: {
                    numberOfImages: modelSettings.numberOfImages || 1,
                    quality: modelSettings.quality || 'standard',
                    height: modelSettings.height || 768,
                    width: modelSettings.width || 1280,
                    cfgScale: Math.min(
                        Math.max(parseFloat(modelSettings.cfg_scale) || 7.5, 1.1),
                        10.0
                    ),
                    seed: modelSettings.seed || Math.floor(Math.random() * 214783647)
                }
            };
        }

        // Handle Nova Canvas image generation
        if (model && model.includes('us.amazon.nova-canvas')) {
            return {
                taskType: 'TEXT_IMAGE',
                textToImageParams: {
                    text: prompt,
                    negativeText: modelSettings.negative_prompt ||
                        'blurry, distorted, low resolution, pixelated, overexposed, underexposed, dark, grainy, noisy, watermark'
                },
                imageGenerationConfig: {
                    width: modelSettings.width || 1024,
                    height: modelSettings.height || 1024,
                    quality: modelSettings.quality || 'standard',
                    seed: modelSettings.seed || Math.floor(Math.random() * 214783647),
                    numberOfImages: modelSettings.numberOfImages || 1
                }
            };
        }

        throw new Error(`Unsupported image model: ${model}`);
    }

    // Video Status Checking
    async function checkVideoStatus(invocationArn) {
        try {
            const response = await generateWithRetry({
                action: 'wpbedrock_check_video_status',
                nonce: wpbedrock_image.nonce,
                invocation_arn: invocationArn
            });

            if (!response.success) {
                throw new Error(response.data || 'Failed to check video status');
            }

            const status = response.data.status;
            const videoUrl = response.data.videoUrl;
            const error = response.data.error;

            if (status === 'Failed') {
                throw new Error(error || 'Video generation failed');
            }

            if (status === 'Completed' && videoUrl) {
                return { completed: true, videoUrl };
            }

            return { completed: false };
        } catch (error) {
            console.error('Video status check error:', error);
            throw error;
        }
    }

    async function pollVideoStatus(invocationArn) {
        const maxAttempts = 60; // 5 minutes with 5-second intervals
        let attempts = 0;

        while (attempts < maxAttempts) {
            try {
                const result = await checkVideoStatus(invocationArn);
                if (result.completed) {
                    return result.videoUrl;
                }
                updateProgress(attempts + 1, maxAttempts);
                await new Promise(resolve => setTimeout(resolve, 5000));
                attempts++;
            } catch (error) {
                throw error;
            }
        }

        throw new Error('Video generation timed out');
    }

    // Initialize UI
    function initializeDefaults() {
        if (!wpbedrock_image?.models) {
            console.error('No models found in configuration');
            return;
        }

        // Find default models
        const defaultNovaModel = wpbedrock_image.models.find(m => m?.type === 'bedrock-nova');
        const defaultSdModel = wpbedrock_image.models.find(m => m?.type === 'bedrock-sd');
        const defaultTitanModel = wpbedrock_image.models.find(m => m?.type === 'bedrock-titan');

        // Nova defaults
        novaModelSelect.val(defaultNovaModel?.id || '');
        novaQualitySelect.val('standard');
        novaWidthInput.val(1024);
        novaHeightInput.val(1024);
        novaSeedInput.val('-1');
        novaSizeSelect.val('1024x1024');
        novaDurationInput.val(6);
        novaFpsInput.val(24);

        // SD defaults
        sdModelSelect.val(defaultSdModel?.id || '');
        sdStyleSelect.val(wpbedrock_image.default_style_preset || '');
        sdWidthInput.val(wpbedrock_image.default_width || 1024);
        sdHeightInput.val(wpbedrock_image.default_height || 1024);
        sdStepsInput.val(wpbedrock_image.default_steps || 50);
        sdCfgScaleInput.val(wpbedrock_image.default_cfg_scale || 7.0);
        sdSeedInput.val('-1');

        // Titan defaults
        titanModelSelect.val(defaultTitanModel?.id || '');
        titanQualitySelect.val('standard');
        titanWidthInput.val(1024);
        titanHeightInput.val(1024);
        titanCfgScaleInput.val(7.5);
        titanSeedInput.val('-1');
        titanSizeSelect.val('1024x1024');

        // Update model descriptions
        updateModelDescription(sdModelSelect);
        updateModelDescription(titanModelSelect);
        updateModelDescription(novaModelSelect);
    }

    function updateModelDescription(select) {
        if (!wpbedrock_image?.models) return;
        const modelId = select.val();
        if (!modelId) return;
        
        const model = wpbedrock_image.models.find(m => m?.id === modelId);
        if (model?.description) {
            select.closest('.setting-group').find('.description').text(model.description);
        }
    }

    initializeDefaults();

    // Event listeners
    sdModelSelect.on('change', function() {
        updateModelDescription($(this));
    });

    titanModelSelect.on('change', function() {
        updateModelDescription($(this));
    });

    novaModelSelect.on('change', function() {
        updateModelDescription($(this));
    });

    // Size select handlers
    titanSizeSelect.on('change', function() {
        updateDimensionsFromSize($(this), titanWidthInput, titanHeightInput);
    });

    novaSizeSelect.on('change', function() {
        updateDimensionsFromSize($(this), novaWidthInput, novaHeightInput);
    });

    // Image upload handlers
    imageUpload.on('change', function() {
        if (this.files && this.files[0]) {
            handleImageUpload(this.files[0]);
        }
    });

    removeImageButton.on('click', removeImage);

    surpriseButton.on('click', function() {
        promptInput.val(getRandomPrompt());
    });

    // Generate button handler
    generateButton.on('click', async function() {
        const prompt = promptInput.val().trim();
        if (!prompt) {
            alert('Please enter a prompt');
            return;
        }

        generateButton.prop('disabled', true);
        resultDiv.empty();
        loadingDiv.show();
        progressDiv.show();

        try {
            // Get model-specific settings based on active tab
            let modelSettings;
            switch (activeTab) {
                case 'bedrock-nova':
                    modelSettings = {
                        model: novaModelSelect.val(),
                        width: parseInt(novaWidthInput.val()),
                        height: parseInt(novaHeightInput.val()),
                        seed: parseInt(novaSeedInput.val()),
                        negative_prompt: novaNegativePromptInput.val().trim(),
                        quality: novaQualitySelect.val(),
                        duration: parseInt(novaDurationInput.val()),
                        fps: parseInt(novaFpsInput.val())
                    };
                    break;
                case 'bedrock-sd':
                    modelSettings = {
                        model: sdModelSelect.val(),
                        width: parseInt(sdWidthInput.val()),
                        height: parseInt(sdHeightInput.val()),
                        steps: parseInt(sdStepsInput.val()),
                        cfg_scale: parseFloat(sdCfgScaleInput.val()),
                        seed: parseInt(sdSeedInput.val()),
                        negative_prompt: sdNegativePromptInput.val().trim(),
                        style_preset: sdStyleSelect.val()
                    };
                    break;
                case 'bedrock-titan':
                    modelSettings = {
                        model: titanModelSelect.val(),
                        width: parseInt(titanWidthInput.val()),
                        height: parseInt(titanHeightInput.val()),
                        cfg_scale: parseFloat(titanCfgScaleInput.val()),
                        seed: parseInt(titanSeedInput.val()),
                        negative_prompt: titanNegativePromptInput.val().trim(),
                        quality: titanQualitySelect.val()
                    };
                    break;
            }

            const requestBody = formatRequestBody(prompt, modelSettings);
            if (!requestBody) {
                throw new Error('Failed to format request body');
            }

            const isVideoRequest = modelSettings?.model && modelSettings.model === 'us.amazon.nova-reel-v1:0';

            const response = await generateWithRetry({
                action: 'wpbedrock_generate_image',
                nonce: wpbedrock_image.nonce,
                prompt: prompt,
                body: JSON.stringify(requestBody)
            });

            if (isVideoRequest && response.data?.invocationArn) {
                try {
                    const videoUrl = await pollVideoStatus(response.data.invocationArn);
                    const container = $('<div class="video-container"></div>');
                    const video = $('<video controls></video>').attr('src', videoUrl);
                    container.append(video);
                    resultDiv.html(container);
                } catch (error) {
                    showError(error.message);
                }
            } else if (response.data?.images) {
                const container = $('<div class="image-grid"></div>');
                response.data.images.forEach((imageData) => {
                    const wrapper = $('<div class="image-wrapper"></div>');
                    const img = $('<img>', {
                        src: 'data:image/png;base64,' + imageData.image,
                        'data-seed': imageData.seed
                    });
                    
                    wrapper.append(img);
                    wrapper.append(`<div class="image-info">Seed: ${imageData.seed}</div>`);
                    container.append(wrapper);
                });
                
                resultDiv.html(container);
                
                // Add action buttons for single images
                const actionButtons = $('<div class="image-actions"></div>');
                const upscaleButton = $('<button class="button">Upscale 2x</button>');
                const variationButton = $('<button class="button">Create Variation</button>');
                
                upscaleButton.on('click', function() {
                    const img = resultDiv.find('img').first();
                    if (!img.length) return;
                    upscaleImage(img.attr('src').split(',')[1]);
                });

                variationButton.on('click', function() {
                    const img = resultDiv.find('img').first();
                    if (!img.length) return;
                    createVariation(img.attr('src').split(',')[1]);
                });

                actionButtons.append(upscaleButton, variationButton);
                container.append(actionButtons);
            }
        } catch (error) {
            showError(error.message);
        } finally {
            generateButton.prop('disabled', false);
            loadingDiv.hide();
            progressDiv.hide();
        }
    });

    async function upscaleImage(imageData) {
        try {
            loadingDiv.show();
            $('.image-actions button').prop('disabled', true);

            const response = await generateWithRetry({
                action: 'wpbedrock_upscale_image',
                nonce: wpbedrock_image.nonce,
                image: imageData,
                scale: 2
            });

            if (response.success && response.data?.image) {
                const container = $('<div class="image-grid"></div>');
                const wrapper = $('<div class="image-wrapper"></div>');
                const img = $('<img>', {
                    src: 'data:image/png;base64,' + response.data.image
                });
                wrapper.append(img);
                container.append(wrapper);
                resultDiv.html(container);
            }
        } catch (error) {
            showError(error.message);
        } finally {
            loadingDiv.hide();
            $('.image-actions button').prop('disabled', false);
        }
    }

    async function createVariation(imageData) {
        try {
            loadingDiv.show();
            $('.image-actions button').prop('disabled', true);

            const response = await generateWithRetry({
                action: 'wpbedrock_image_variation',
                nonce: wpbedrock_image.nonce,
                image: imageData,
                strength: 0.7,
                seed: Math.floor(Math.random() * 4294967295)
            });

            if (response.success && response.data?.image) {
                const container = $('<div class="image-grid"></div>');
                const wrapper = $('<div class="image-wrapper"></div>');
                const img = $('<img>', {
                    src: 'data:image/png;base64,' + response.data.image,
                    'data-seed': response.data.seed
                });
                wrapper.append(img);
                wrapper.append(`<div class="image-info">Seed: ${response.data.seed}</div>`);
                container.append(wrapper);
                resultDiv.html(container);
            }
        } catch (error) {
            showError(error.message);
        } finally {
            loadingDiv.hide();
            $('.image-actions button').prop('disabled', false);
        }
    }

    // Input validation functions
    function validateDimensions(input, maxSize = 1024) {
        let val = parseInt(input.val());
        if (val < 512) val = 512;
        if (val > maxSize) val = maxSize;
        val = Math.round(val / 8) * 8;
        input.val(val);
    }

    function validateSteps(input) {
        let val = parseInt(input.val());
        if (val < 10) val = 10;
        if (val > 150) val = 150;
        input.val(val);
    }

    function validateCfgScale(input, min = 0, max = 35) {
        let val = parseFloat(input.val());
        if (val < min) val = min;
        if (val > max) val = max;
        input.val(val);
    }

    function validateFps(input) {
        let val = parseInt(input.val());
        if (val < 1) val = 1;
        if (val > 60) val = 60;
        input.val(val);
    }

    function validateDuration(input) {
        let val = parseInt(input.val());
        if (val < 1) val = 1;
        if (val > 30) val = 30;
        input.val(val);
    }

    // Input validation event handlers
    sdWidthInput.on('change', function() { validateDimensions($(this), 1024); });
    sdHeightInput.on('change', function() { validateDimensions($(this), 1024); });
    titanWidthInput.on('change', function() { validateDimensions($(this), 1792); });
    titanHeightInput.on('change', function() { validateDimensions($(this), 1792); });
    novaWidthInput.on('change', function() { validateDimensions($(this), 1792); });
    novaHeightInput.on('change', function() { validateDimensions($(this), 1792); });
    sdStepsInput.on('change', function() { validateSteps($(this)); });
    sdCfgScaleInput.on('change', function() { validateCfgScale($(this), 0, 35); });
    titanCfgScaleInput.on('change', function() { validateCfgScale($(this), 1.1, 10.0); });
    novaFpsInput.on('change', function() { validateFps($(this)); });
    novaDurationInput.on('change', function() { validateDuration($(this)); });

    // Add styles for image grid and video container
    $('<style>')
        .text(`
            .image-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
                margin-top: 20px;
            }
            .image-wrapper {
                position: relative;
            }
            .image-wrapper img {
                width: 100%;
                height: auto;
                display: block;
                border-radius: 4px;
            }
            .image-info {
                position: absolute;
                bottom: 0;
                left: 0;
                right: 0;
                background: rgba(0,0,0,0.7);
                color: white;
                padding: 5px 10px;
                font-size: 12px;
                border-radius: 0 0 4px 4px;
            }
            .video-container {
                width: 100%;
                max-width: 800px;
                margin: 20px auto;
            }
            .video-container video {
                width: 100%;
                height: auto;
                border-radius: 4px;
            }
        `)
        .appendTo('head');
});
