#!/bin/bash

# Check if composer is installed
if ! command -v composer &> /dev/null; then
    echo "Composer is not installed. Please install composer first."
    echo "Visit https://getcomposer.org/download/ for installation instructions."
    exit 1
fi

# Check if we're in the plugin directory
if [ ! -f "composer.json" ]; then
    echo "Please run this script from the wp-bedrock plugin directory"
    exit 1
fi

echo "Installing AWS SDK and dependencies..."

# Install dependencies
composer install --optimize-autoloader

# Check if installation was successful
if [ $? -eq 0 ]; then
    echo "Dependencies installed successfully!"
    
    # Create vendor directory if it doesn't exist
    if [ ! -d "vendor" ]; then
        echo "Warning: vendor directory not found after composer install"
        exit 1
    fi
    
    # Set permissions
    chmod -R 755 vendor
    
    echo "Setup completed successfully!"
    echo "Please configure your AWS credentials in the WordPress admin panel."
else
    echo "Error installing dependencies. Please check the error messages above."
    exit 1
fi
