<?php
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

while ( have_posts() ) : the_post();
    $tamanos  = get_post_meta( get_the_ID(), '_fc_tamanos', true );
    $desc     = get_post_meta( get_the_ID(), '_fc_descripcion', true );
    if ( ! is_array( $tamanos ) ) $tamanos = [];

    $agotado  = get_post_meta( get_the_ID(), '_fc_agotado',  true ) === '1';
    $especial = get_post_meta( get_the_ID(), '_fc_especial', true ) === '1';
    $cats      = get_the_terms( get_the_ID(), 'categoria_arreglo' );
    $cat_name  = ( ! empty( $cats ) && ! is_wp_error( $cats ) ) ? implode( ', ', wp_list_pluck( $cats, 'name' ) ) : '';
    $cat_slugs = ( ! empty( $cats ) && ! is_wp_error( $cats ) ) ? wp_list_pluck( $cats, 'slug' ) : [];

    $first_img    = ! empty( $tamanos[0]['imagen_url'] ) ? $tamanos[0]['imagen_url'] : get_the_post_thumbnail_url( get_the_ID(), 'large' );
    $first_precio = ! empty( $tamanos[0]['precio'] )     ? $tamanos[0]['precio']     : 0;

    $catalog_url = get_option( 'fc_catalog_page_url', home_url() );

    // Arreglos recomendados de la misma categoría
    $recomendados = [];
    if ( ! empty( $cat_slugs ) ) {
        $recomendados = new WP_Query( [
            'post_type'      => 'arreglo',
            'post_status'    => 'publish',
            'posts_per_page' => 4,
            'post__not_in'   => [ get_the_ID() ],
            'tax_query'      => [ [
                'taxonomy' => 'categoria_arreglo',
                'field'    => 'slug',
                'terms'    => $cat_slugs,
                'operator' => 'IN',
            ] ],
        ] );
    }
?>

<div class="fc-detalle">
    <a href="<?php echo esc_url( $catalog_url ); ?>" class="fc-back-btn">&#8592; Volver al catálogo</a>

    <div class="fc-detalle-inner">

        <!-- Columna imagen -->
        <div>
            <div class="fc-detalle-img-wrap">
                <?php if ( $first_img ) : ?>
                <img id="fc-main-img" src="<?php echo esc_url( $first_img ); ?>" alt="<?php the_title_attribute(); ?>" class="fc-img-clickable" />
                <button class="fc-lightbox-trigger" aria-label="Ver imagen ampliada">&#x26F6;</button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Columna info -->
        <div class="fc-detalle-info">

            <h1 class="fc-detalle-titulo"><?php the_title(); ?></h1>

            <?php if ( ! empty( $cats ) && ! is_wp_error( $cats ) ) : ?>
            <div class="fc-detalle-cats">
                <?php foreach ( $cats as $cat ) : ?>
                <a href="<?php echo esc_url( $catalog_url . '#cat=' . $cat->slug ); ?>" class="fc-detalle-cat"><?php echo esc_html( $cat->name ); ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ( $desc ) : ?>
            <p class="fc-detalle-desc"><?php echo nl2br( esc_html( $desc ) ); ?></p>
            <?php endif; ?>

            <?php if ( $especial ) : ?>
            <div class="fc-especial-aviso">
                &#128337; Este arreglo requiere <strong>al menos 2 días hábiles de anticipación</strong>. Sábado y domingo no cuentan como días hábiles.
            </div>
            <?php endif; ?>

            <p class="fc-detalle-precio">
                <span id="fc-precio-val">
                    <?php echo $first_precio ? '$' . number_format( (float) $first_precio, 0, '.', ',' ) : ''; ?>
                </span>
            </p>

            <!-- Selector de tamaños -->
            <?php if ( ! empty( $tamanos ) ) : ?>
            <div>
                <span class="fc-tamanos-label">Tamaño</span>
                <div class="fc-tamanos-btns">
                    <?php foreach ( $tamanos as $i => $tamano ) : ?>
                    <button type="button" class="fc-tamano-btn <?php echo $i === 0 ? 'active' : ''; ?>">
                        <?php echo esc_html( $tamano['nombre'] ); ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Pedido -->
            <?php if ( $agotado ) : ?>
            <div class="fc-agotado-msg">
                <span class="fc-agotado-icon">&#x1F33A;</span>
                <div>
                    <strong>Este arreglo no está disponible</strong>
                    <p>Actualmente se encuentra fuera de stock. Te invitamos a explorar nuestro catálogo para encontrar otras opciones.</p>
                    <a href="<?php echo esc_url( $catalog_url ); ?>" class="fc-ver-catalogo-btn">Ver catálogo completo</a>
                </div>
            </div>
            <?php else : ?>
            <div class="fc-pedir-section">

                <div>
                    <label>¿Cómo lo recibirás?</label>
                    <div class="fc-tipo-btns">
                        <button type="button" class="fc-tipo-btn active" data-tipo="envio">Envío a domicilio</button>
                        <button type="button" class="fc-tipo-btn" data-tipo="recoleccion">Recolección en tienda</button>
                    </div>
                </div>

                <div>
                    <label for="fc-fecha">¿Cuándo lo necesitas?</label>
                    <div class="fc-fecha-wrap" id="fc-fecha-wrap">
                        <input type="date" id="fc-fecha" name="fc_fecha" />
                    </div>
                    <p class="fc-fecha-display" id="fc-fecha-display"></p>
                </div>

                <p class="fc-cerrado-msg" id="fc-cerrado" style="display:none;"></p>
                <p class="fc-cerrado-msg" id="fc-anticipacion" style="display:none;"></p>

                <!-- Envío: bloques horarios + dirección -->
                <div id="fc-envio-section">
                    <div class="fc-horario-wrap" id="fc-horario-wrap">
                        <label for="fc-horario">Horario de entrega</label>
                        <select id="fc-horario" name="fc_horario">
                            <option value="">-- Selecciona un horario --</option>
                        </select>
                    </div>
                    <div>
                        <label for="fc-direccion">Dirección de entrega</label>
                        <div class="fc-direccion-wrap">
                            <input type="text" id="fc-direccion" name="fc_direccion" placeholder="Calle, número, colonia, ciudad..." autocomplete="off" />
                            <span class="fc-direccion-icon">&#x1F4CD;</span>
                        </div>
                        <p id="fc-direccion-hint" class="fc-direccion-hint" style="display:none;">Incluye calle, número y colonia (mín. 15 caracteres).</p>
                    </div>
                </div>

                <!-- Recolección: hora libre dentro del horario -->
                <div id="fc-recoleccion-section" style="display:none;">
                    <div>
                        <label for="fc-hora-recoleccion">Hora de recolección</label>
                        <div class="fc-fecha-wrap" id="fc-hora-wrap">
                            <input type="time" id="fc-hora-recoleccion" name="fc_hora_recoleccion" />
                        </div>
                        <p class="fc-horario-hint" id="fc-horario-hint"></p>
                    </div>
                </div>

                <label class="fc-politicas-check">
                    <input type="checkbox" id="fc-politicas-cb" />
                    <span>Leí y acepto las <a href="<?php echo esc_url( get_option( 'fc_politicas_url', '#' ) ); ?>" target="_blank" rel="noopener">políticas</a></span>
                </label>

                <a href="#" class="fc-whatsapp-btn fc-btn-disabled" id="fc-wa-btn">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/>
                    </svg>
                    Pedir por WhatsApp
                </a>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- Arreglos recomendados -->
    <?php if ( $recomendados && $recomendados->have_posts() ) : ?>
    <div class="fc-recomendados">
        <h2 class="fc-recomendados-titulo">También te puede interesar</h2>
        <div class="fc-recomendados-grid">
            <?php while ( $recomendados->have_posts() ) : $recomendados->the_post(); ?>
            <?php
                $r_tamanos  = get_post_meta( get_the_ID(), '_fc_tamanos', true );
                $r_img      = ! empty( $r_tamanos[0]['imagen_url'] ) ? $r_tamanos[0]['imagen_url'] : get_the_post_thumbnail_url( get_the_ID(), 'medium' );
                $r_precios  = ! empty( $r_tamanos ) ? array_column( $r_tamanos, 'precio' ) : [];
                $r_precio   = ! empty( $r_precios ) ? min( $r_precios ) : '';
                $r_cats     = get_the_terms( get_the_ID(), 'categoria_arreglo' );
                $r_cat_name = ( ! empty( $r_cats ) && ! is_wp_error( $r_cats ) ) ? implode( ', ', wp_list_pluck( $r_cats, 'name' ) ) : '';
            ?>
            <a href="<?php the_permalink(); ?>" class="fc-card">
                <div class="fc-card-img">
                    <?php if ( $r_img ) : ?>
                    <img src="<?php echo esc_url( $r_img ); ?>" alt="<?php the_title_attribute(); ?>" loading="lazy" />
                    <?php else : ?>
                    <div class="fc-card-no-img">&#127800;</div>
                    <?php endif; ?>
                </div>
                <div class="fc-card-body">
                    <?php if ( $r_cat_name ) : ?>
                    <span class="fc-card-cat"><?php echo esc_html( $r_cat_name ); ?></span>
                    <?php endif; ?>
                    <h3 class="fc-card-title"><?php the_title(); ?></h3>
                    <?php if ( $r_precio !== '' ) : ?>
                    <p class="fc-card-precio">Desde $<?php echo number_format( $r_precio, 0, '.', ',' ); ?></p>
                    <?php endif; ?>
                </div>
            </a>
            <?php endwhile; wp_reset_postdata(); ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Lightbox -->
<div class="fc-lightbox" id="fc-lightbox" role="dialog" aria-modal="true" aria-label="Imagen ampliada">
    <button class="fc-lightbox-close" id="fc-lightbox-close" aria-label="Cerrar">&times;</button>
    <img id="fc-lightbox-img" src="" alt="" />
</div>

<?php endwhile; ?>

<?php get_footer(); ?>
