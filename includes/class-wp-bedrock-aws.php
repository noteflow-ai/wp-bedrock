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

    /**
     * Initialize AWS Bedrock client
     *
     * @param string $key AWS access key
     * @param string $secret AWS secret key
     */
    public function __construct($key, $secret, $region = 'us-west-2') {
        $credentials = new Credentials($key, $secret);
        
        $this->client = new BedrockRuntimeClient([
            'version' => 'latest',
            'region'  => $region,
            'credentials' => $credentials
        ]);
    }

    /**
     * Invoke Bedrock model
     *
     * @param string $message User message
     * @param string $model_id Model ID
     * @param float $temperature Temperature parameter
     * @param int $max_tokens Maximum tokens
     * @param bool $stream Whether to stream the response
     * @param callable|null $callback Callback function for streaming responses
     * @return string|null Model response (null if streaming)
     */
    public function invoke_model($message, $model_id = 'anthropic.claude-3-haiku-20240307-v1', $temperature = 0.7, $max_tokens = 2000, $stream = false, $callback = null) {
        $request_body = [];
        
        // Claude 3系列
        if (strpos($model_id, 'anthropic.claude-3') === 0) {
            $request_body = [
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $message
                    ]
                ],
                'max_tokens' => $max_tokens,
                'temperature' => $temperature,
                'anthropic_version' => 'bedrock-2023-05-31'
            ];
        }
        // AWS Nova (Titan)系列
        else if (strpos($model_id, 'amazon.titan') === 0) {
            $request_body = [
                'inputText' => $message,
                'textGenerationConfig' => [
                    'maxTokenCount' => $max_tokens,
                    'temperature' => $temperature,
                    'topP' => 1
                ]
            ];
        }
        // Llama 3系列
        else if (strpos($model_id, 'meta.llama3') === 0) {
            $request_body = [
                'prompt' => $message,
                'max_gen_len' => $max_tokens,
                'temperature' => $temperature,
                'top_p' => 1
            ];
        }
        // Mistral系列
        else if (strpos($model_id, 'mistral.') === 0) {
            $request_body = [
                'messages' => [
                    ['role' => 'user', 'content' => $message]
                ],
                'max_tokens' => $max_tokens,
                'temperature' => $temperature,
                'top_p' => 1
            ];
        }

        try {
            $params = [
                'body' => json_encode($request_body),
                'contentType' => 'application/json',
                'modelId' => $model_id
            ];
            
            error_log('AWS Bedrock request params: ' . json_encode($params));

            if ($stream) {
                $response = $this->client->invokeModelWithResponseStream($params);
                $eventStream = $response['body'];
                
                $fullResponse = '';
                foreach ($eventStream as $event) {
                    error_log('Stream event received: ' . json_encode($event));
                    
                    if (isset($event['chunk']['bytes'])) {
                        $chunk = json_decode($event['chunk']['bytes'], true);
                        error_log('Decoded chunk: ' . json_encode($chunk));
                        
                        if (isset($chunk['type'])) {
                            switch ($chunk['type']) {
                                case 'message_start':
                                    error_log('Message start received');
                                    break;
                                    
                                case 'content_block_start':
                                    error_log('Content block start received');
                                    break;
                                    
                                case 'content_block_delta':
                                    if (isset($chunk['delta']['text'])) {
                                        $text = $chunk['delta']['text'];
                                        error_log('Delta text received: ' . $text);
                                        $fullResponse .= $text;
                                        if ($callback) {
                                            call_user_func($callback, $text);
                                        }
                                    }
                                    break;
                                    
                                case 'content_block_stop':
                                    error_log('Content block stop received');
                                    break;
                                    
                                case 'message_stop':
                                    error_log('Message stop received');
                                    break;
                            }
                        }
                    }
                }
                return $fullResponse;
            } else {
                $response = $this->client->invokeModel($params);
                $result = json_decode($response['body']->getContents(), true);
                
                // 根据不同模型处理响应
                if (strpos($model_id, 'anthropic.claude-3') === 0) {
                    return $result['content'][0]['text'];
                } else if (strpos($model_id, 'amazon.titan') === 0) {
                    return $result['results'][0]['outputText'];
                } else if (strpos($model_id, 'meta.llama3') === 0) {
                    return $result['generation'];
                } else if (strpos($model_id, 'mistral.') === 0) {
                    return $result['choices'][0]['message']['content'];
                }
                
                return 'Unsupported model response format';
            }
        } catch (Exception $e) {
            throw new Exception('Error invoking Bedrock model: ' . $e->getMessage());
        }
    }
}
