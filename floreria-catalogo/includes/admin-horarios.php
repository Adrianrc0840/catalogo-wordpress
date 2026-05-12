<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────────
// Horarios por defecto (primera vez / fallback)
// ─────────────────────────────────────────────
function fc_default_horarios() {
    return [
        // ── Bloques nuevos (desactivados) ──
        [ 'label' => '8:00am – 12:00pm',  'semana' => 0, 'sabado' => 0, 'domingo' => 0 ],
        [ 'label' => '9:00am – 1:00pm',   'semana' => 0, 'sabado' => 0, 'domingo' => 0 ],
        [ 'label' => '10:00am – 2:00pm',  'semana' => 0, 'sabado' => 0, 'domingo' => 0 ],
        [ 'label' => '11:00am – 3:00pm',  'semana' => 0, 'sabado' => 0, 'domingo' => 0 ],
        [ 'label' => '12:00pm – 4:00pm',  'semana' => 0, 'sabado' => 0, 'domingo' => 0 ],
        // ── Bloques actuales (activos) ──
        [ 'label' => '10:00am – 12:00pm', 'semana' => 1, 'sabado' => 1, 'domingo' => 0 ],
        [ 'label' => '11:00am – 1:00pm',  'semana' => 1, 'sabado' => 1, 'domingo' => 0 ],
        [ 'label' => '12:00pm – 2:00pm',  'semana' => 1, 'sabado' => 1, 'domingo' => 0 ],
        [ 'label' => '1:00pm – 3:00pm',   'semana' => 1, 'sabado' => 1, 'domingo' => 0 ],
        [ 'label' => '2:00pm – 4:00pm',   'semana' => 1, 'sabado' => 1, 'domingo' => 0 ],
        [ 'label' => '3:00pm – 5:00pm',   'semana' => 1, 'sabado' => 1, 'domingo' => 0 ],
        [ 'label' => '4:00pm – 6:00pm',   'semana' => 1, 'sabado' => 0, 'domingo' => 0 ],
        [ 'label' => '5:00pm – 7:00pm',   'semana' => 1, 'sabado' => 0, 'domingo' => 0 ],
    ];
}

// ─────────────────────────────────────────────
// Registrar submenú Horarios
// ─────────────────────────────────────────────
add_action( 'admin_menu', 'fc_add_horarios_submenu' );
function fc_add_horarios_submenu() {
    add_submenu_page(
        'edit.php?post_type=arreglo',
        'Horarios de entrega',
        'Horarios',
        'manage_options',
        'fc-horarios',
        'fc_render_horarios_page'
    );
}

// ─────────────────────────────────────────────
// Guardar horarios (admin_init para evitar headers sent)
// ─────────────────────────────────────────────
add_action( 'admin_init', 'fc_save_horarios' );
function fc_save_horarios() {
    if (
        ! isset( $_POST['fc_save_horarios'] ) ||
        ! current_user_can( 'manage_options' ) ||
        ! check_admin_referer( 'fc_save_horarios' )
    ) return;

    // ── Horarios ──
    $raw     = isset( $_POST['horarios'] ) && is_array( $_POST['horarios'] ) ? $_POST['horarios'] : [];
    $activos = isset( $_POST['activo'] )   && is_array( $_POST['activo'] )   ? $_POST['activo']   : [];

    $horarios = [];
    foreach ( $raw as $idx => $h ) {
        $label = sanitize_text_field( $h['label'] ?? '' );
        if ( $label === '' ) continue;
        $horarios[] = [
            'label'   => $label,
            'semana'  => isset( $activos[ $idx ]['semana'] )  ? 1 : 0,
            'sabado'  => isset( $activos[ $idx ]['sabado'] )  ? 1 : 0,
            'domingo' => isset( $activos[ $idx ]['domingo'] ) ? 1 : 0,
        ];
    }
    update_option( 'fc_horarios', $horarios );

    // ── Fechas especiales ──
    $raw_fechas = isset( $_POST['fechas_especiales'] ) && is_array( $_POST['fechas_especiales'] )
        ? $_POST['fechas_especiales'] : [];

    $fechas = [];
    foreach ( $raw_fechas as $f ) {
        $f = sanitize_text_field( $f );
        if ( preg_match( '/^\d{2}\/\d{2}$/', $f ) ) {
            $fechas[] = $f;
        }
    }
    $fechas = array_values( array_unique( $fechas ) );
    sort( $fechas );
    update_option( 'fc_fechas_especiales', $fechas );

    wp_safe_redirect( add_query_arg(
        [ 'post_type' => 'arreglo', 'page' => 'fc-horarios', 'saved' => '1' ],
        admin_url( 'edit.php' )
    ) );
    exit;
}

// ─────────────────────────────────────────────
// Renderizar página de horarios
// ─────────────────────────────────────────────
function fc_render_horarios_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $horarios = get_option( 'fc_horarios', null );
    if ( $horarios === null ) {
        $horarios = fc_default_horarios();
        update_option( 'fc_horarios', $horarios );
    }

    $fechas = get_option( 'fc_fechas_especiales', [] );
    if ( ! is_array( $fechas ) ) $fechas = [];

    $saved = isset( $_GET['saved'] );
    ?>
    <div class="wrap">
        <h1>Horarios de entrega</h1>

        <?php if ( $saved ) : ?>
        <div class="notice notice-success is-dismissible"><p>Horarios guardados correctamente.</p></div>
        <?php endif; ?>

        <p style="color:#666;margin-bottom:20px;">
            Activa o desactiva los bloques por tipo de día. Los cambios afectan inmediatamente al panel de floristas.
        </p>

        <form method="post" id="fc-horarios-form">
            <?php wp_nonce_field( 'fc_save_horarios' ); ?>

            <!-- ── Tabla de horarios ── -->
            <table class="wp-list-table widefat striped" style="max-width:820px;">
                <thead>
                    <tr>
                        <th style="width:42%;">Horario</th>
                        <th style="width:15%;text-align:center;">Lun – Vie</th>
                        <th style="width:15%;text-align:center;">Sábado</th>
                        <th style="width:15%;text-align:center;">Domingo</th>
                        <th style="width:13%;text-align:center;">Eliminar</th>
                    </tr>
                </thead>
                <tbody id="fc-horarios-tbody">
                    <?php foreach ( $horarios as $i => $h ) : ?>
                    <tr class="fc-horario-row" data-index="<?php echo $i; ?>">
                        <td>
                            <input
                                type="text"
                                name="horarios[<?php echo $i; ?>][label]"
                                value="<?php echo esc_attr( $h['label'] ); ?>"
                                style="width:100%;font-size:13px;padding:4px 6px;"
                            />
                        </td>
                        <td style="text-align:center;">
                            <label class="fc-toggle">
                                <input type="checkbox"
                                    name="activo[<?php echo $i; ?>][semana]"
                                    value="1"
                                    <?php checked( $h['semana'], 1 ); ?>
                                />
                                <span class="fc-toggle-slider"></span>
                            </label>
                        </td>
                        <td style="text-align:center;">
                            <label class="fc-toggle">
                                <input type="checkbox"
                                    name="activo[<?php echo $i; ?>][sabado]"
                                    value="1"
                                    <?php checked( $h['sabado'], 1 ); ?>
                                />
                                <span class="fc-toggle-slider"></span>
                            </label>
                        </td>
                        <td style="text-align:center;">
                            <label class="fc-toggle">
                                <input type="checkbox"
                                    name="activo[<?php echo $i; ?>][domingo]"
                                    value="1"
                                    <?php checked( $h['domingo'] ?? 0, 1 ); ?>
                                />
                                <span class="fc-toggle-slider"></span>
                            </label>
                        </td>
                        <td style="text-align:center;">
                            <button type="button" class="button-link fc-delete-row"
                                    style="color:#b91c1c;font-size:16px;" title="Eliminar">✕</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div style="margin-top:14px;max-width:820px;">
                <button type="button" id="fc-add-horario" class="button">+ Añadir horario</button>
            </div>

            <!-- ── Fechas especiales ── -->
            <h2 style="margin-top:36px;margin-bottom:6px;font-size:15px;">Fechas especiales</h2>
            <p style="color:#666;margin-bottom:16px;max-width:600px;">
                Estas fechas tendrán disponibles los horarios de <strong>Domingo</strong> aunque la tienda normalmente esté cerrada ese día.
                Úsalas para fechas como el 10 de mayo, 14 de febrero, etc.<br>
                Formato: <code>DD/MM</code> &nbsp;·&nbsp; Ejemplo: <code>10/05</code>
            </p>

            <div id="fc-fechas-list" style="max-width:420px;">
                <?php foreach ( $fechas as $fecha ) : ?>
                <div class="fc-fecha-row" style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                    <input type="text"
                        name="fechas_especiales[]"
                        value="<?php echo esc_attr( $fecha ); ?>"
                        placeholder="DD/MM"
                        maxlength="5"
                        style="width:90px;font-size:13px;padding:4px 6px;font-family:monospace;"
                    />
                    <button type="button" class="button-link fc-delete-fecha"
                            style="color:#b91c1c;font-size:15px;" title="Eliminar">✕</button>
                </div>
                <?php endforeach; ?>
            </div>

            <div style="margin-top:10px;">
                <button type="button" id="fc-add-fecha" class="button">+ Añadir fecha</button>
            </div>

            <!-- ── Botón guardar ── -->
            <div style="margin-top:28px;">
                <button type="submit" name="fc_save_horarios" class="button button-primary">Guardar cambios</button>
            </div>
        </form>
    </div>

    <style>
    /* Toggle switch */
    .fc-toggle { position:relative; display:inline-block; width:42px; height:24px; }
    .fc-toggle input { opacity:0; width:0; height:0; }
    .fc-toggle-slider {
        position:absolute; cursor:pointer; inset:0;
        background:#d1d5db; border-radius:24px;
        transition:.2s;
    }
    .fc-toggle-slider::before {
        content:''; position:absolute;
        width:18px; height:18px; left:3px; bottom:3px;
        background:#fff; border-radius:50%;
        transition:.2s;
    }
    .fc-toggle input:checked + .fc-toggle-slider { background:#16a34a; }
    .fc-toggle input:checked + .fc-toggle-slider::before { transform:translateX(18px); }
    </style>

    <script>
    (function() {
        var nextIdx = <?php echo count( $horarios ); ?>;

        // ── Eliminar fila / fecha ──
        document.addEventListener('click', function(e) {
            if (e.target.closest('.fc-delete-row')) {
                e.target.closest('tr').remove();
                renumber();
            }
            if (e.target.closest('.fc-delete-fecha')) {
                e.target.closest('.fc-fecha-row').remove();
            }
        });

        // ── Añadir horario ──
        document.getElementById('fc-add-horario').addEventListener('click', function() {
            var tbody = document.getElementById('fc-horarios-tbody');
            var tr = document.createElement('tr');
            tr.className = 'fc-horario-row';
            tr.dataset.index = nextIdx;
            tr.innerHTML = `
                <td>
                    <input type="text" name="horarios[${nextIdx}][label]" value=""
                           placeholder="ej. 8:00am – 10:00am"
                           style="width:100%;font-size:13px;padding:4px 6px;" />
                </td>
                <td style="text-align:center;">
                    <label class="fc-toggle">
                        <input type="checkbox" name="activo[${nextIdx}][semana]" value="1" />
                        <span class="fc-toggle-slider"></span>
                    </label>
                </td>
                <td style="text-align:center;">
                    <label class="fc-toggle">
                        <input type="checkbox" name="activo[${nextIdx}][sabado]" value="1" />
                        <span class="fc-toggle-slider"></span>
                    </label>
                </td>
                <td style="text-align:center;">
                    <label class="fc-toggle">
                        <input type="checkbox" name="activo[${nextIdx}][domingo]" value="1" />
                        <span class="fc-toggle-slider"></span>
                    </label>
                </td>
                <td style="text-align:center;">
                    <button type="button" class="button-link fc-delete-row"
                            style="color:#b91c1c;font-size:16px;" title="Eliminar">✕</button>
                </td>`;
            tbody.appendChild(tr);
            tr.querySelector('input[type="text"]').focus();
            nextIdx++;
        });

        // ── Añadir fecha especial ──
        document.getElementById('fc-add-fecha').addEventListener('click', function() {
            var list = document.getElementById('fc-fechas-list');
            var row = document.createElement('div');
            row.className = 'fc-fecha-row';
            row.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:8px;';
            row.innerHTML = `
                <input type="text" name="fechas_especiales[]" value=""
                       placeholder="DD/MM" maxlength="5"
                       style="width:90px;font-size:13px;padding:4px 6px;font-family:monospace;" />
                <button type="button" class="button-link fc-delete-fecha"
                        style="color:#b91c1c;font-size:15px;" title="Eliminar">✕</button>`;
            list.appendChild(row);
            row.querySelector('input').focus();
        });

        // ── Auto-formatear DD/MM (si alguien escribe 4 dígitos seguidos) ──
        document.addEventListener('blur', function(e) {
            if (!e.target.matches('input[name="fechas_especiales[]"]')) return;
            var val = e.target.value.replace(/\D/g, '');
            if (val.length === 4) {
                e.target.value = val.slice(0, 2) + '/' + val.slice(2);
            }
        }, true);

        // ── Re-numerar índices tras eliminar ──
        function renumber() {
            document.querySelectorAll('.fc-horario-row').forEach(function(tr, i) {
                tr.querySelectorAll('input').forEach(function(inp) {
                    inp.name = inp.name.replace(/\[\d+\]/, '[' + i + ']');
                });
            });
            nextIdx = document.querySelectorAll('.fc-horario-row').length;
        }
    })();
    </script>
    <?php
}
