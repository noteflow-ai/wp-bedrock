<?php
namespace AICHAT_AMAZON_BEDROCK;

class WP_Bedrock_Widget extends \WP_Widget {
    public function __construct() {
        parent::__construct(
            'wp_bedrock_widget',
            esc_html__('AI Chat Widget', 'ai-chat-for-amazon-bedrock'),
            array('description' => esc_html__('Add an AI chatbot to your sidebar', 'ai-chat-for-amazon-bedrock'))
        );
    }

    public function widget($args, $instance) {
        echo wp_kses_post($args['before_widget']);
        
        if (!empty($instance['title'])) {
            echo wp_kses_post($args['before_title'] . apply_filters('widget_title', $instance['title']) . $args['after_title']);
        }

        // Get height from instance or use default
        $height = !empty($instance['height']) ? esc_attr($instance['height']) : '500px';
        
        // Include the chatbot template with custom height
        include AICHAT_BEDROCK_PLUGIN_DIR . 'admin/partials/wp-bedrock-admin-chatbot.php';
        
        echo wp_kses_post($args['after_widget']);
    }

    public function form($instance) {
        $title = !empty($instance['title']) ? $instance['title'] : '';
        $height = !empty($instance['height']) ? $instance['height'] : '500px';
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php esc_html_e('Title:', 'ai-chat-for-amazon-bedrock'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($title); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('height')); ?>"><?php esc_html_e('Height:', 'ai-chat-for-amazon-bedrock'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('height')); ?>" name="<?php echo esc_attr($this->get_field_name('height')); ?>" type="text" value="<?php echo esc_attr($height); ?>" placeholder="500px">
        </p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        $instance = array();
        $instance['title'] = (!empty($new_instance['title'])) ? wp_strip_all_tags($new_instance['title']) : '';
        $instance['height'] = (!empty($new_instance['height'])) ? wp_strip_all_tags($new_instance['height']) : '500px';
        return $instance;
    }
}
