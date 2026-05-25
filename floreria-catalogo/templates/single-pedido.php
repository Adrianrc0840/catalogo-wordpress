<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Fallback template cuando no hay página wrapper Elementor configurada.
// Todo el HTML lo genera el shortcode [floreria_rastreo_pedido].
get_header();
echo fc_render_rastreo_pedido_sc();
get_footer();
