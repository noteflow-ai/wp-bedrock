<?php
/**
 * The public-facing functionality of the plugin.
 *
 * @since      1.0.0
 * @package    WP_Bedrock
 * @subpackage WP_Bedrock/public
 */

namespace AICHAT_AMAZON_BEDROCK;

class WP_Bedrock_Public {

    /**
     * The ID of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $plugin_name    The ID of this plugin.
     */
    private $plugin_name;

    /**
     * The version of this plugin.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $version    The current version of this plugin.
     */
    private $version;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param    string    $plugin_name       The name of the plugin.
     * @param    string    $version    The version of this plugin.
     */
    public function __construct($plugin_name, $version) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;

        // 添加前端钩子
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Add shortcodes
        add_shortcode('ai_chat_for_amazon_bedrock', array($this, 'render_shortcode'));
        // Backward compatibility shortcode
        add_shortcode('bedrock_chat', array($this, 'render_shortcode'));
    }

    /**
     * Register the stylesheets for the public-facing side of the site.
     *
     * @since    1.0.0
     */
    public function enqueue_styles() {
        wp_enqueue_style(
            $this->plugin_name,
            plugin_dir_url(__FILE__) . 'css/wp-bedrock-public.css',
            array(),
            $this->version,
            'all'
        );
    }

    /**
     * Register the JavaScript for the public-facing side of the site.
     *
     * @since    1.0.0
     */
    public function enqueue_scripts() {
        wp_enqueue_script(
            $this->plugin_name,
            plugin_dir_url(__FILE__) . 'js/wp-bedrock-public.js',
            array('jquery'),
            $this->version,
            false
        );

        // 添加AJAX URL到JavaScript
        wp_localize_script(
            $this->plugin_name,
            'wpBedrock',
            array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wp_bedrock_nonce')
            )
        );
    }

    /**
     * Render the shortcode [ai_chat_for_amazon_bedrock]
     *
     * @since    1.0.0
     * @param    array    $atts    Shortcode attributes
     * @return   string            Shortcode output
     */
    public function render_shortcode($atts) {
        // 合并默认属性
        $atts = shortcode_atts(
            array(
                'title' => 'AI Chat for Amazon Bedrock',
            ),
            $atts,
            'ai_chat_for_amazon_bedrock'
        );

        // 开始输出缓冲
        ob_start();

        // 包含模板文件
        include plugin_dir_path(__FILE__) . 'partials/wp-bedrock-public-display.php';

        // 返回输出内容
        return ob_get_clean();
    }
}
