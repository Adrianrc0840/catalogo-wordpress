<?php
if ( ! defined( 'ABSPATH' ) ) exit;
nocache_headers();
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Asistencia — Florería Monarca</title>
    <meta name="theme-color" content="#c8185a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<?php fc_asistencia_render_content(); ?>

<?php wp_footer(); ?>
</body>
</html>
