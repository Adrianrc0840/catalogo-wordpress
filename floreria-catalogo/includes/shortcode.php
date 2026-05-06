<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'catalogo_floreria', 'fc_render_catalogo' );
function fc_render_catalogo( $atts ) {
    $atts = shortcode_atts( [
        'categoria' => '',
        'limite'    => -1,
    ], $atts );

    $args = [
        'post_type'      => 'arreglo',
        'post_status'    => 'publish',
        'posts_per_page' => intval( $atts['limite'] ),
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
    ];

    if ( ! empty( $atts['categoria'] ) ) {
        $args['tax_query'] = [ [
            'taxonomy' => 'categoria_arreglo',
            'field'    => 'slug',
            'terms'    => $atts['categoria'],
        ] ];
    }

    $query = new WP_Query( $args );

    if ( ! $query->have_posts() ) {
        return '<p class="fc-no-results">No hay arreglos disponibles.</p>';
    }

    $categorias = get_terms( [ 'taxonomy' => 'categoria_arreglo', 'hide_empty' => true, 'orderby' => 'name', 'order' => 'ASC' ] );

    ob_start();
    ?>
    <div class="fc-catalogo">

        <div class="fc-buscador-wrap">
            <input type="text" id="fc-buscador" class="fc-buscador" placeholder="Buscar arreglos o categorías..." autocomplete="off" />
            <span class="fc-buscador-icon">&#128269;</span>
        </div>
        <p class="fc-sin-resultados" id="fc-sin-resultados" style="display:none;">No se encontraron arreglos con esa búsqueda.</p>

        <?php if ( ! empty( $categorias ) && ! is_wp_error( $categorias ) ) : ?>
        <div class="fc-filtro-wrap">
            <button class="fc-filtro-toggle" id="fc-filtro-toggle" aria-expanded="false">
                <span id="fc-filtro-label">Todas las categorías</span>
                <span class="fc-filtro-chevron">&#9660;</span>
            </button>
            <div class="fc-filtro-panel" id="fc-filtro-panel">
                <button class="fc-filtro-btn active" data-categoria="todos">Todas</button>
                <?php foreach ( $categorias as $cat ) : ?>
                <button class="fc-filtro-btn" data-categoria="<?php echo esc_attr( $cat->slug ); ?>">
                    <?php echo esc_html( $cat->name ); ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="fc-grid">
            <?php while ( $query->have_posts() ) : $query->the_post(); ?>
            <?php
                $tamanos   = get_post_meta( get_the_ID(), '_fc_tamanos', true );
                $agotado   = get_post_meta( get_the_ID(), '_fc_agotado', true ) === '1';
                $cats      = get_the_terms( get_the_ID(), 'categoria_arreglo' );
                $cat_slugs = ( ! empty( $cats ) && ! is_wp_error( $cats ) ) ? implode( ' ', wp_list_pluck( $cats, 'slug' ) ) : '';
                $cat_name  = ( ! empty( $cats ) && ! is_wp_error( $cats ) ) ? implode( ', ', wp_list_pluck( $cats, 'name' ) ) : '';

                // Usar la foto marcada como principal del catálogo; si no hay, usar la primera
                $img_url = '';
                foreach ( $tamanos as $t ) {
                    if ( ! empty( $t['foto_catalogo'] ) && $t['foto_catalogo'] === '1' && ! empty( $t['imagen_url'] ) ) {
                        $img_url = $t['imagen_url'];
                        break;
                    }
                }
                if ( ! $img_url && ! empty( $tamanos[0]['imagen_url'] ) ) {
                    $img_url = $tamanos[0]['imagen_url'];
                } elseif ( ! $img_url && has_post_thumbnail() ) {
                    $img_url = get_the_post_thumbnail_url( get_the_ID(), 'medium' );
                }

                $precio_desde = '';
                if ( ! empty( $tamanos ) ) {
                    $precios      = array_column( $tamanos, 'precio' );
                    $precio_desde = min( $precios );
                }
            ?>
            <a href="<?php the_permalink(); ?>"
               class="fc-card <?php echo esc_attr( $cat_slugs ); ?> <?php echo $agotado ? 'fc-card-agotado' : ''; ?>"
               data-titulo="<?php echo esc_attr( strtolower( get_the_title() ) ); ?>"
               data-categoria="<?php echo esc_attr( strtolower( str_replace( ', ', ' ', $cat_name ) ) ); ?>">
                <div class="fc-card-img">
                    <?php if ( $img_url ) : ?>
                    <img src="<?php echo esc_url( $img_url ); ?>" alt="<?php the_title_attribute(); ?>" loading="lazy" />
                    <?php else : ?>
                    <div class="fc-card-no-img">&#127800;</div>
                    <?php endif; ?>
                    <?php if ( $agotado ) : ?>
                    <span class="fc-badge-agotado">Agotado</span>
                    <?php endif; ?>
                </div>
                <div class="fc-card-body">
                    <?php if ( $cat_name ) : ?>
                    <span class="fc-card-cat"><?php echo esc_html( $cat_name ); ?></span>
                    <?php endif; ?>
                    <h3 class="fc-card-title"><?php the_title(); ?></h3>
                    <?php if ( $precio_desde !== '' ) : ?>
                    <p class="fc-card-precio">Desde $<?php echo number_format( $precio_desde, 0, '.', ',' ); ?></p>
                    <?php endif; ?>
                </div>
            </a>
            <?php endwhile; wp_reset_postdata(); ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
