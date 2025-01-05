jQuery(document).ready(function($) {
    // 密码字段的显示/隐藏切换
    $('.wp-bedrock-settings').on('click', '.toggle-password', function(e) {
        e.preventDefault();
        const passwordField = $(this).siblings('input[type="password"]');
        const type = passwordField.attr('type') === 'password' ? 'text' : 'password';
        passwordField.attr('type', type);
        $(this).toggleClass('dashicons-visibility dashicons-hidden');
    });

    // 数字输入字段的验证
    $('.wp-bedrock-settings input[type="number"]').on('change', function() {
        const min = parseFloat($(this).attr('min'));
        const max = parseFloat($(this).attr('max'));
        let value = parseFloat($(this).val());

        if (value < min) {
            value = min;
        } else if (value > max) {
            value = max;
        }

        $(this).val(value);
    });

    // 设置保存成功提示
    if ($('.settings-error').length) {
        setTimeout(function() {
            $('.settings-error').fadeOut();
        }, 3000);
    }

    // 表单提交前验证
    $('.wp-bedrock-settings form').on('submit', function(e) {
        const awsKey = $('input[name="wpbedrock_aws_key"]').val();
        const awsSecret = $('input[name="wpbedrock_aws_secret"]').val();

        if (!awsKey || !awsSecret) {
            e.preventDefault();
            alert('AWS credentials are required.');
            return false;
        }

        return true;
    });
});
