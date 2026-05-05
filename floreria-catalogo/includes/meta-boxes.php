<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'add_meta_boxes', 'fc_add_meta_boxes' );
function fc_add_meta_boxes() {
    add_meta_box( 'fc_descripcion', 'Descripción del arreglo', 'fc_render_descripcion_meta_box', 'arreglo', 'normal', 'high' );
    add_meta_box( 'fc_tamanos', 'Tamaños y Precios', 'fc_render_tamanos_meta_box', 'arreglo', 'normal', 'high' );
}

function fc_render_descripcion_meta_box( $post ) {
    $desc = get_post_meta( $post->ID, '_fc_descripcion', true );
    wp_nonce_field( 'fc_save_meta', 'fc_nonce' );
    ?>
    <textarea name="fc_descripcion" rows="4" style="width:100%;box-sizing:border-box;"><?php echo esc_textarea( $desc ); ?></textarea>
    <?php
}

function fc_render_tamanos_meta_box( $post ) {
    $tamanos = get_post_meta( $post->ID, '_fc_tamanos', true );
    if ( ! is_array( $tamanos ) ) $tamanos = [];
    ?>
    <div id="fc-tamanos-wrap">
        <div id="fc-tamanos-list">
            <?php foreach ( $tamanos as $i => $tamano ) : ?>
            <div class="fc-tamano-row">
                <div class="fc-field">
                    <label>Tamaño</label>
                    <input type="text" name="fc_tamanos[<?php echo $i; ?>][nombre]" value="<?php echo esc_attr( $tamano['nombre'] ?? '' ); ?>" placeholder="Ej: Chico" />
                </div>
                <div class="fc-field">
                    <label>Precio ($)</label>
                    <input type="number" name="fc_tamanos[<?php echo $i; ?>][precio]" value="<?php echo esc_attr( $tamano['precio'] ?? '' ); ?>" placeholder="299" min="0" step="0.01" />
                </div>
                <div class="fc-field fc-field-foto">
                    <label>Foto</label>
                    <div class="fc-foto-inner">
                        <input type="hidden" name="fc_tamanos[<?php echo $i; ?>][imagen_id]" class="fc-imagen-id" value="<?php echo esc_attr( $tamano['imagen_id'] ?? '' ); ?>" />
                        <input type="hidden" name="fc_tamanos[<?php echo $i; ?>][imagen_url]" class="fc-imagen-url" value="<?php echo esc_attr( $tamano['imagen_url'] ?? '' ); ?>" />
                        <img class="fc-preview-img" src="<?php echo esc_url( $tamano['imagen_url'] ?? '' ); ?>" style="<?php echo empty( $tamano['imagen_url'] ) ? 'display:none;' : ''; ?>" />
                        <div class="fc-foto-btns">
                            <button type="button" class="button fc-upload-btn"><?php echo ! empty( $tamano['imagen_url'] ) ? 'Cambiar foto' : 'Subir foto'; ?></button>
                            <?php if ( ! empty( $tamano['imagen_url'] ) ) : ?>
                            <button type="button" class="button fc-remove-img-btn">Quitar</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="fc-field fc-field-remove">
                    <button type="button" class="button button-link-delete fc-remove-row">✕ Eliminar</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="button button-secondary" id="fc-add-tamano">+ Añadir tamaño</button>
    </div>

    <script type="text/html" id="fc-tamano-template">
        <div class="fc-tamano-row">
            <div class="fc-field">
                <label>Tamaño</label>
                <input type="text" name="fc_tamanos[{{INDEX}}][nombre]" value="" placeholder="Ej: Chico" />
            </div>
            <div class="fc-field">
                <label>Precio ($)</label>
                <input type="number" name="fc_tamanos[{{INDEX}}][precio]" value="" placeholder="299" min="0" step="0.01" />
            </div>
            <div class="fc-field fc-field-foto">
                <label>Foto</label>
                <div class="fc-foto-inner">
                    <input type="hidden" name="fc_tamanos[{{INDEX}}][imagen_id]" class="fc-imagen-id" value="" />
                    <input type="hidden" name="fc_tamanos[{{INDEX}}][imagen_url]" class="fc-imagen-url" value="" />
                    <img class="fc-preview-img" src="" style="display:none;" />
                    <div class="fc-foto-btns">
                        <button type="button" class="button fc-upload-btn">Subir foto</button>
                    </div>
                </div>
            </div>
            <div class="fc-field fc-field-remove">
                <button type="button" class="button button-link-delete fc-remove-row">✕ Eliminar</button>
            </div>
        </div>
    </script>
    <?php
}

add_action( 'save_post_arreglo', 'fc_save_meta' );
function fc_save_meta( $post_id ) {
    if ( ! isset( $_POST['fc_nonce'] ) || ! wp_verify_nonce( $_POST['fc_nonce'], 'fc_save_meta' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    if ( isset( $_POST['fc_descripcion'] ) ) {
        update_post_meta( $post_id, '_fc_descripcion', sanitize_textarea_field( $_POST['fc_descripcion'] ) );
    }

    $tamanos = [];
    if ( isset( $_POST['fc_tamanos'] ) && is_array( $_POST['fc_tamanos'] ) ) {
        foreach ( $_POST['fc_tamanos'] as $tamano ) {
            if ( empty( $tamano['nombre'] ) ) continue;
            $tamanos[] = [
                'nombre'     => sanitize_text_field( $tamano['nombre'] ),
                'precio'     => floatval( $tamano['precio'] ?? 0 ),
                'imagen_id'  => intval( $tamano['imagen_id'] ?? 0 ),
                'imagen_url' => esc_url_raw( $tamano['imagen_url'] ?? '' ),
            ];
        }
    }
    update_post_meta( $post_id, '_fc_tamanos', $tamanos );
}

add_action( 'admin_enqueue_scripts', 'fc_admin_enqueue' );
function fc_admin_enqueue( $hook ) {
    global $post_type;
    if ( ( $hook === 'post.php' || $hook === 'post-new.php' ) && $post_type === 'arreglo' ) {
        wp_enqueue_media();
        wp_enqueue_script( 'fc-admin', FC_URL . 'assets/admin/admin.js', [ 'jquery' ], FC_VERSION, true );
        wp_enqueue_style( 'fc-admin', FC_URL . 'assets/admin/admin.css', [], FC_VERSION );
    }
}
