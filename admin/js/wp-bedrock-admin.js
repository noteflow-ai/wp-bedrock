jQuery(document).ready(function($) {
    // Temperature slider
    $('.temperature-slider').on('input', function() {
        $(this).next('.temperature-value').text(this.value);
    });
});
