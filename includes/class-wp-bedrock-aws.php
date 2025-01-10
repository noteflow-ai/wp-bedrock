<?php
/**
 * AWS Bedrock client class
 *
 * @since      1.0.0
 * @package    WP_Bedrock
 * @subpackage WP_Bedrock/includes
 */

namespace WPBEDROCK;

use Aws\Credentials\Credentials;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Exception;

class WP_Bedrock_AWS {
    private $client;
    private $max_retries = 3;
    private $retry_delay = 1; // seconds

    /**
     * Log debug message if WP_DEBUG_LOG is enabled
     */
    private function log_debug($message, $data = null) {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $log_message = '[WP Bedrock] ' . $message;
            if ($data !== null) {
                $log_message .= ' ' . (is_string($data) ? $data : json_encode($data));
            }
            error_log($log_message);
        }
    }

    /**
     * Initialize AWS Bedrock client
     */
    public function __construct($key, $secret, $region = 'us-west-2', $max_retries = 3, $retry_delay = 1) {
        $credentials = new Credentials($key, $secret);
        
        $this->client = new BedrockRuntimeClient([
            'version' => 'latest',
            'region'  => $region,
            'credentials' => $credentials
        ]);

        $this->max_retries = $max_retries;
        $this->retry_delay = $retry_delay;
    }

    /**
     * Execute with retry logic
     */
    private function execute_with_retry($operation) {
        $last_exception = null;
        
        for ($attempt = 1; $attempt <= $this->max_retries; $attempt++) {
            try {
                return $operation();
            } catch (Exception $e) {
                $last_exception = $e;
                $this->log_debug("Request failed (attempt $attempt of {$this->max_retries}): " . $e->getMessage());
                
                if ($attempt < $this->max_retries) {
                    sleep($this->retry_delay * $attempt);
                }
            }
        }
        
        throw new Exception("Connection failed after {$this->max_retries} retries: " . $last_exception->getMessage());
    }

    /**
     * Invoke Bedrock model
     * 
     * @param array $request_body The complete request body formatted by frontend
     * @param string $model_id The model identifier
     * @param bool $stream Whether to stream the response
     * @param callable|null $callback Callback function for streaming responses
     * @return string|null Model response
     */
    public function invoke_model($request_body, $model_id, $stream = false, $callback = null) {
        try {
            $params = [
                'body' => json_encode($request_body),
                'contentType' => 'application/json',
                'modelId' => $model_id
            ];

            $this->log_debug('Request:', [
                'model_id' => $model_id,
                'stream' => $stream,
                'body' => $request_body
            ]);

            if ($stream) {
                $response = $this->execute_with_retry(function() use ($params) {
                    return $this->client->invokeModelWithResponseStream($params);
                });

                if (!isset($response['body'])) {
                    throw new Exception('Invalid streaming response: missing body');
                }

                $eventStream = $response['body'];
                if (!is_object($eventStream) || !method_exists($eventStream, 'current')) {
                    throw new Exception('Invalid event stream');
                }

                foreach ($eventStream as $event) {
                    if (!isset($event['chunk'])) continue;

                    try {
                        $chunkBytes = is_object($event['chunk']) && method_exists($event['chunk'], 'getContents') 
                            ? $event['chunk']->getContents()
                            : (is_array($event['chunk']) ? json_encode($event['chunk']) : strval($event['chunk']));
                            
                        $chunk = json_decode($chunkBytes, true);
                        if ($chunk === null && json_last_error() !== JSON_ERROR_NONE) {
                            $this->log_debug('Failed to decode chunk:', json_last_error_msg());
                            continue;
                        }

                        if ($callback) {
                            call_user_func($callback, $chunk);
                        }
                    } catch (Exception $e) {
                        $this->log_debug('Error processing chunk:', $e->getMessage());
                    }
                }

                return null;
            } else {
                $response = $this->execute_with_retry(function() use ($params) {
                    return $this->client->invokeModel($params);
                });

                $responseContent = $response['body']->getContents();
                $result = json_decode($responseContent, true);
                
                if ($result === null && json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Failed to decode response: ' . json_last_error_msg());
                }

                return $result;
            }
        } catch (Exception $e) {
            throw new Exception('Error invoking Bedrock model: ' . $e->getMessage());
        }
    }

    /**
     * Generate images using Bedrock image models
     */
    public function generate_image($request_body, $model_id) {
        try {
            $params = [
                'body' => json_encode($request_body),
                'contentType' => 'application/json',
                'modelId' => $model_id
            ];

            $this->log_debug('Image generation request:', [
                'model_id' => $model_id,
                'body' => $request_body
            ]);

            $response = $this->execute_with_retry(function() use ($params) {
                return $this->client->invokeModel($params);
            });

            $result = json_decode($response['body']->getContents(), true);

            if (!isset($result['artifacts']) || empty($result['artifacts'])) {
                throw new Exception('No images generated in response');
            }

            return $result['artifacts'];
        } catch (Exception $e) {
            throw new Exception('Error generating image: ' . $e->getMessage());
        }
    }

    /**
     * Upscale an image using Stable Diffusion
     */
    public function upscale_image($request_body) {
        try {
            $params = [
                'body' => json_encode($request_body),
                'contentType' => 'application/json',
                'modelId' => 'stability.stable-diffusion-xl-upscaler'
            ];

            $response = $this->execute_with_retry(function() use ($params) {
                return $this->client->invokeModel($params);
            });

            $result = json_decode($response['body']->getContents(), true);

            if (!isset($result['artifacts']) || empty($result['artifacts'])) {
                throw new Exception('No upscaled image in response');
            }

            return $result['artifacts'][0];
        } catch (Exception $e) {
            throw new Exception('Error upscaling image: ' . $e->getMessage());
        }
    }

    /**
     * Create a variation of an existing image
     */
    public function create_image_variation($request_body) {
        try {
            $params = [
                'body' => json_encode($request_body),
                'contentType' => 'application/json',
                'modelId' => 'stability.stable-diffusion-xl-v1'
            ];

            $response = $this->execute_with_retry(function() use ($params) {
                return $this->client->invokeModel($params);
            });

            $result = json_decode($response['body']->getContents(), true);

            if (!isset($result['artifacts']) || empty($result['artifacts'])) {
                throw new Exception('No variation image in response');
            }

            return $result['artifacts'][0];
        } catch (Exception $e) {
            throw new Exception('Error creating image variation: ' . $e->getMessage());
        }
    }
}
