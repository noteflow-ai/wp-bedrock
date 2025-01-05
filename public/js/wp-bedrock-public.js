(function($) {
    'use strict';

    /**
     * 所有公共JS都包含在这个文件中
     */

    $(document).ready(function() {
        // 初始化插件功能
        initWpBedrock();
    });

    /**
     * 初始化插件功能
     */
    function initWpBedrock() {
        // 绑定按钮点击事件
        $('.wp-bedrock-button').on('click', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var action = $button.data('action');

            // 根据不同的action执行不同的操作
            switch(action) {
                case 'example':
                    handleExampleAction($button);
                    break;
                default:
                    console.log('未知的操作：' + action);
            }
        });
    }

    /**
     * 处理示例操作
     * @param {jQuery} $button 按钮元素
     */
    function handleExampleAction($button) {
        // 禁用按钮，显示加载状态
        $button.prop('disabled', true).text('处理中...');

        // 发送AJAX请求
        $.ajax({
            url: wpBedrock.ajaxurl,
            type: 'POST',
            data: {
                action: 'wp_bedrock_example_action',
                nonce: wpBedrock.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message || '操作成功！');
                } else {
                    alert(response.data.message || '操作失败！');
                }
            },
            error: function() {
                alert('请求失败，请稍后重试！');
            },
            complete: function() {
                // 恢复按钮状态
                $button.prop('disabled', false).text('点击我');
            }
        });
    }

})(jQuery);
