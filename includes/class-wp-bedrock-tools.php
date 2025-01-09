<?php
namespace WPBEDROCK;

class WP_Bedrock_Tools {
    private $tools;

    public function __construct() {
        $this->load_tools();
    }

    private function load_tools() {
        $tools_file = plugin_dir_path(__FILE__) . 'tools.json';
        if (file_exists($tools_file)) {
            $this->tools = json_decode(file_get_contents($tools_file), true);
        } else {
            $this->tools = ['tools' => []];
        }
    }

    public function get_tools() {
        return $this->tools['tools'];
    }

    public function execute_tool($tool_name, $parameters) {
        switch ($tool_name) {
            case 'duckduckgo_search':
                return $this->duckduckgo_search($parameters);
            case 'arxiv_search':
                return $this->arxiv_search($parameters);
            default:
                throw new \Exception("Unknown tool: {$tool_name}");
        }
    }

    private function duckduckgo_search($params) {
        if (empty($params['q'])) {
            throw new \Exception('Search query is required');
        }

        $url = 'https://lite.duckduckgo.com/lite/';
        $query = http_build_query([
            'q' => $params['q'],
            'kl' => isset($params['kl']) ? $params['kl'] : 'wt-wt',
            'o' => 'json',
            'api' => 'd.js'
        ]);

        $response = wp_remote_post($url . '?' . $query);
        
        if (is_wp_error($response)) {
            throw new \Exception('Search request failed: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        
        // Parse the HTML response to extract search results
        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        @$doc->loadHTML($body);
        $xpath = new \DOMXPath($doc);
        
        $results = [];
        $links = $xpath->query("//table[@class='result-link']//a");
        $snippets = $xpath->query("//table[@class='result-snippet']");
        
        if ($links && $snippets) {
            for ($i = 0; $i < $links->length; $i++) {
                $link = $links->item($i);
                $snippet = $snippets->item($i);
                
                if ($link && $snippet) {
                    $results[] = [
                        'title' => trim($link->nodeValue),
                        'url' => $link->attributes->getNamedItem('href')->nodeValue,
                        'snippet' => trim($snippet->nodeValue)
                    ];

                    // Limit to first 5 results
                    if (count($results) >= 5) break;
                }
            }
        }

        return [
            'success' => true,
            'data' => $results
        ];
    }

    private function arxiv_search($params) {
        if (empty($params['search_query'])) {
            throw new \Exception('Search query is required');
        }

        $url = 'https://export.arxiv.org/api/query';
        $query = http_build_query([
            'search_query' => $params['search_query'],
            'sortBy' => isset($params['sortBy']) ? $params['sortBy'] : 'relevance',
            'sortOrder' => isset($params['sortOrder']) ? $params['sortOrder'] : 'descending',
            'max_results' => isset($params['max_results']) ? min((int)$params['max_results'], 10) : 5
        ]);

        $response = wp_remote_get($url . '?' . $query);
        
        if (is_wp_error($response)) {
            throw new \Exception('Arxiv request failed: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        
        // Parse XML response
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        
        if (!$xml) {
            throw new \Exception('Failed to parse Arxiv response');
        }

        $results = [];
        foreach ($xml->entry as $entry) {
            $results[] = [
                'title' => (string)$entry->title,
                'authors' => array_map(function($author) {
                    return (string)$author->name;
                }, $entry->author),
                'summary' => (string)$entry->summary,
                'published' => (string)$entry->published,
                'updated' => (string)$entry->updated,
                'link' => (string)$entry->id
            ];
        }

        return [
            'success' => true,
            'data' => $results
        ];
    }
}
