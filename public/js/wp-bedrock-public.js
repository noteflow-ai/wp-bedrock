jQuery(document).ready(function($) {
    'use strict';

    // Initialize each chat container
    $('.chat-container').each(function() {
        const container = $(this);
        
        // Wrap container in a div with appropriate classes
        if (!container.parent().hasClass('bedrock-chat-wrapper')) {
            container.wrap('<div class="bedrock-chat-wrapper"></div>');
        }
        
        const wrapper = container.parent();
        
        // Add shortcode class if not in widget
        if (!wrapper.closest('.widget').length) {
            wrapper.addClass('bedrock-chat-shortcode');
        }
        
        // Set dimensions from data attributes or defaults
        const height = wrapper.data('height') || '500px';
        const width = wrapper.data('width') || '100%';
        
        wrapper.css({
            'width': width,
            '--chat-height': height // CSS variable for height
        });

        // Hide admin-only elements in frontend
        container.find('.wrap h1').hide();
        
        // Initialize chat functionality
        if (typeof window.initializeChat === 'function') {
            window.initializeChat(container);
        } else {
            console.error('Chat initialization function not found');
            container.html(`
                <div class="chat-error">
                    <p>Error: Chat Initialization Failed</p>
                    <p>Failed to load required libraries. Please check your browser console for errors and try refreshing the page.</p>
                </div>
            `);
        }
    });

    // Handle responsive behavior
    function handleResponsive() {
        if (window.innerWidth <= 782) {
            $('.chat-container').each(function() {
                const container = $(this);
                // Adjust height for mobile
                if (!container.closest('.widget').length) { // Don't adjust widget height
                    container.css('height', '400px');
                }
            });
        }
    }

    // Run on load and resize
    handleResponsive();
    $(window).on('resize', handleResponsive);

    // Add custom event handlers for frontend
    $(document).on('click', '.fullscreen-chat', function(e) {
        e.preventDefault();
        const container = $(this).closest('.chat-container');
        
        if (container.hasClass('fullscreen')) {
            container.removeClass('fullscreen');
            container.css({
                'height': container.data('original-height') || '500px',
                'width': container.data('original-width') || '100%'
            });
        } else {
            // Store original dimensions
            container.data('original-height', container.css('height'));
            container.data('original-width', container.css('width'));
            
            container.addClass('fullscreen');
        }
    });

    // Handle errors
    window.addEventListener('error', function(e) {
        if (e.message.includes('wpbedrock_chat') || 
            e.message.includes('markdownit') || 
            e.message.includes('hljs')) {
            console.error('Chat dependency loading error:', e.message);
            $('.chat-container').each(function() {
                $(this).html(`
                    <div class="chat-error">
                        <p>Error: Chat Initialization Failed</p>
                        <p>Failed to load required libraries: ${e.message}</p>
                        <p>Please check your browser console for errors and try refreshing the page.</p>
                    </div>
                `);
            });
        }
    });
});
