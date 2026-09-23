<?php
/**
 * Plugin Name: Anuncio entre productos Audio Pro
 * Description: Inserta varias imagenes publicitarias entre productos. Compatible con Flatsome, categorias y taxonomias de marca.
 * Version: 1.13.0
 * Author: Audio Pro
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: ap-anuncio-productos
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

define('AP_ANUNCIO_FLATSOME_VERSION', '1.13.0');
define('AP_ANUNCIO_FLATSOME_FILE', __FILE__);

// El plugin no toca pedidos: declarar compatibilidad con HPOS para que
// WooCommerce no lo marque como "compatibilidad desconocida".
add_action('before_woocommerce_init', 'ap_anuncio_flatsome_declare_wc_compat');

function ap_anuncio_flatsome_declare_wc_compat() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
}

add_action('plugins_loaded', 'ap_anuncio_flatsome_bootstrap');

function ap_anuncio_flatsome_bootstrap() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'ap_anuncio_flatsome_woocommerce_notice');
        return;
    }

    new AP_Anuncio_Flatsome_Categorias_Marcas_Mejorado();
}

function ap_anuncio_flatsome_woocommerce_notice() {
    if (!current_user_can('activate_plugins')) {
        return;
    }

    echo '<div class="notice notice-warning"><p><strong>Anuncios entre productos:</strong> WooCommerce debe estar activo para usar este plugin.</p></div>';
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'ap_anuncio_flatsome_action_links');

function ap_anuncio_flatsome_action_links($links) {
    $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=ap-anuncio-productos')) . '">Configurar anuncios</a>';
    array_unshift($links, $settings_link);

    return $links;
}

register_uninstall_hook(__FILE__, 'ap_anuncio_flatsome_uninstall');

function ap_anuncio_flatsome_uninstall() {
    global $wpdb;

    // Opciones del plugin (incluye opciones heredadas de la cache del buscador
    // de versiones anteriores, por si quedaran guardadas).
    $options = [
        'ap_ad_image_id',
        'ap_ad_link',
        'ap_ad_position',
        'ap_ad_grid_columns',
        'ap_ad_skip_rows',
        'ap_ad_items',
        'ap_ad_randomize_global',
        'ap_search_cache_enabled',
        'ap_search_cache_ttl',
        'ap_wc_search_cache_keys',
    ];

    foreach ($options as $option) {
        delete_option($option);
    }

    // Metadatos de anuncios por categoria/marca en todas las taxonomias.
    $term_meta_keys = [
        'ap_cat_ad_items',
        'ap_cat_ad_skip_rows',
        'ap_cat_ad_disabled',
        'ap_cat_ad_mode',
        'ap_cat_ad_image_id',
        'ap_cat_ad_link',
        'ap_cat_ad_position',
    ];

    foreach ($term_meta_keys as $meta_key) {
        delete_metadata('term', 0, $meta_key, '', true);
    }

    // Transients heredados de la cache del buscador, si quedaran de versiones previas.
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '\\_transient\\_ap\\_wc\\_search\\_%'
            OR option_name LIKE '\\_transient\\_timeout\\_ap\\_wc\\_search\\_%'"
    );
}

class AP_Anuncio_Flatsome_Categorias_Marcas_Mejorado {

    // Que anuncios usa una categoria o marca.
    const TERM_MODES = ['global', 'custom', 'none'];

    private $count = 0;

    private $ads_rendered = 0;

    private $loop_ads = null;

    private $loop_skip_rows = null;

    // Indice del siguiente anuncio pendiente dentro de $loop_ads (ya ordenados por posicion).
    private $next_ad_index = 0;

    // Indica que estamos dentro del loop principal de la tienda.
    private $in_main_loop = false;

    private $default_grid_columns = 4;

    private $settings_hook = '';

    private $brand_taxonomies = [
        'product_brand',
        'pa_brand',
        'pwb-brand',
        'yith_product_brand',
        'berocket_brand',
        'brand'
    ];

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'settings']);
        add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);

        foreach ($this->get_managed_taxonomies() as $taxonomy) {
            add_action($taxonomy . '_add_form_fields', [$this, 'add_term_fields']);
            add_action($taxonomy . '_edit_form_fields', [$this, 'edit_term_fields'], 10, 2);
            add_action('created_' . $taxonomy, [$this, 'save_term_fields']);
            add_action('edited_' . $taxonomy, [$this, 'save_term_fields']);
        }

        add_action('woocommerce_before_shop_loop', [$this, 'reset_count'], 1);
        add_action('woocommerce_shop_loop', [$this, 'show_ad'], 1);
        add_action('woocommerce_shop_loop', [$this, 'count_product'], 999);
        add_filter('woocommerce_product_loop_end', [$this, 'append_trailing_ads'], 1);

        add_action('wp_enqueue_scripts', [$this, 'styles']);
    }

    public function menu() {
        $this->settings_hook = (string) add_submenu_page(
            'woocommerce',
            'Anuncios entre productos',
            'Anuncios entre productos',
            'manage_options',
            'ap-anuncio-productos',
            [$this, 'settings_page']
        );
    }

    public function settings() {
        register_setting('ap_anuncio_productos', 'ap_ad_image_id', [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 0,
        ]);

        register_setting('ap_anuncio_productos', 'ap_ad_link', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => '',
        ]);

        register_setting('ap_anuncio_productos', 'ap_ad_position', [
            'type' => 'integer',
            'sanitize_callback' => [$this, 'sanitize_positive_int'],
            'default' => 4,
        ]);

        register_setting('ap_anuncio_productos', 'ap_ad_grid_columns', [
            'type' => 'integer',
            'sanitize_callback' => [$this, 'sanitize_grid_columns'],
            'default' => 4,
        ]);

        register_setting('ap_anuncio_productos', 'ap_ad_skip_rows', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_rows_string'],
            'default' => '',
        ]);

        register_setting('ap_anuncio_productos', 'ap_ad_items', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_ads'],
            'default' => [],
        ]);

        register_setting('ap_anuncio_productos', 'ap_ad_randomize_global', [
            'type' => 'boolean',
            'sanitize_callback' => [$this, 'sanitize_checkbox'],
            'default' => 0,
        ]);
    }

    public function sanitize_positive_int($value) {
        return max(1, absint($value));
    }

    public function sanitize_checkbox($value) {
        return empty($value) ? 0 : 1;
    }

    public function sanitize_grid_columns($value) {
        $columns = absint($value);

        if ($columns < 1) {
            return $this->default_grid_columns;
        }

        return min(8, $columns);
    }

    public function sanitize_rows_string($value) {
        if (is_array($value)) {
            $value = implode(',', $value);
        }

        if (!is_scalar($value)) {
            return '';
        }

        preg_match_all('/\d+/', (string) $value, $matches);
        $rows = [];

        foreach ($matches[0] as $row) {
            $row = absint($row);

            if ($row > 0) {
                $rows[] = $row;
            }
        }

        $rows = array_values(array_unique($rows));
        sort($rows);

        return implode(',', $rows);
    }

    public function sanitize_ad_date($value) {
        if (!is_scalar($value)) {
            return '';
        }

        $value = trim((string) $value);
        if ('' === $value) {
            return '';
        }

        $date = DateTime::createFromFormat('Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            return '';
        }

        return $value;
    }

    /**
     * Acepta enlaces escritos sin "https://" (por ejemplo "tienda.com/ofertas")
     * y enlaces internos como "/ofertas".
     */
    public function sanitize_ad_link($value) {
        if (!is_scalar($value)) {
            return '';
        }

        $link = trim((string) $value);
        if ('' === $link) {
            return '';
        }

        if (!preg_match('#^([a-z][a-z0-9+.\-]*:|/|\#|\?)#i', $link)) {
            $link = 'https://' . $link;
        }

        return esc_url_raw($link);
    }

    public function sanitize_ads($ads) {
        if (!is_array($ads)) {
            return [];
        }

        $grid_columns = $this->get_grid_columns();
        $sanitized = [];

        foreach ($ads as $ad) {
            if (!is_array($ad)) {
                continue;
            }

            $image_id = isset($ad['image_id']) ? absint($ad['image_id']) : 0;
            if (!$image_id) {
                continue;
            }

            $link = isset($ad['link']) ? $this->sanitize_ad_link($ad['link']) : '';
            $row = (isset($ad['row']) && is_scalar($ad['row'])) ? $this->sanitize_positive_int($ad['row']) : 0;
            $column = (isset($ad['column']) && is_scalar($ad['column'])) ? $this->sanitize_positive_int($ad['column']) : 0;
            $position = (isset($ad['position']) && is_scalar($ad['position'])) ? $this->sanitize_positive_int($ad['position']) : 0;
            $start_date = isset($ad['start_date']) ? $this->sanitize_ad_date($ad['start_date']) : '';
            $end_date = isset($ad['end_date']) ? $this->sanitize_ad_date($ad['end_date']) : '';

            if ($row && $column) {
                $column = min($column, $grid_columns);
                $position = (($row - 1) * $grid_columns) + $column;
            } elseif ($position) {
                $row = max(1, (int) ceil($position / $grid_columns));
                $column = (($position - 1) % $grid_columns) + 1;
            } else {
                // Sin fila/columna ni posicion: colocar en la ultima columna de la
                // siguiente fila libre.
                $row = count($sanitized) + 1;
                $column = $grid_columns;
                $position = (($row - 1) * $grid_columns) + $column;
            }

            $sanitized[] = [
                'image_id' => $image_id,
                'link' => $link,
                'row' => $row,
                'column' => $column,
                'position' => $position,
                'start_date' => $start_date,
                'end_date' => $end_date,
            ];
        }

        usort($sanitized, function($a, $b) {
            return $a['position'] <=> $b['position'];
        });

        return array_values($sanitized);
    }

    /* ---------------------------------------------------------------------
     * Tienda (frontend)
     * ------------------------------------------------------------------- */

    public function reset_count() {
        $this->count = 0;
        $this->ads_rendered = 0;
        $this->loop_ads = null;
        $this->loop_skip_rows = null;
        $this->next_ad_index = 0;
        $this->in_main_loop = true;
    }

    public function count_product() {
        if (!$this->is_valid_page()) return;
        $this->count++;
    }

    public function show_ad() {
        if (!$this->is_valid_page()) return;

        if (null === $this->loop_ads) {
            $this->loop_ads = $this->get_ads_for_current_page();
        }

        if (null === $this->loop_skip_rows) {
            $this->loop_skip_rows = $this->get_skip_rows_for_current_page();
        }

        $total_ads = count($this->loop_ads);

        // Los anuncios vienen ordenados por posicion, asi que solo hace falta
        // mirar el siguiente pendiente. Si dos anuncios comparten celda, el
        // segundo se muestra en la celda inmediatamente posterior en vez de
        // perderse.
        while ($this->next_ad_index < $total_ads) {
            $ad = $this->loop_ads[$this->next_ad_index];

            if (!empty($ad['row']) && in_array(absint($ad['row']), $this->loop_skip_rows, true)) {
                $this->next_ad_index++;
                continue;
            }

            $next_cell = $this->count + $this->ads_rendered + 1;

            if (intval($ad['position']) > $next_cell) {
                break;
            }

            $this->render_frontend_ad($ad);
            $this->ads_rendered++;
            $this->next_ad_index++;
        }
    }

    /**
     * Muestra los anuncios que quedan justo despues del ultimo producto de la
     * pagina (por ejemplo, un anuncio en fila 1 columna 4 en una categoria con
     * solo 3 productos).
     */
    public function append_trailing_ads($loop_end_html) {
        if (!$this->in_main_loop) {
            return $loop_end_html;
        }

        $this->in_main_loop = false;

        if (!$this->is_valid_page()) {
            return $loop_end_html;
        }

        ob_start();
        $this->show_ad();
        $trailing = ob_get_clean();

        return $trailing . $loop_end_html;
    }

    private function render_frontend_ad($ad) {
        if (empty($ad['image_id'])) {
            return;
        }

        $image_id = absint($ad['image_id']);
        $alt = trim((string) get_post_meta($image_id, '_wp_attachment_image_alt', true));
        if ('' === $alt) {
            $alt = 'Publicidad';
        }

        $image_html = wp_get_attachment_image($image_id, 'large', false, [
            'class' => 'ap-flatsome-ad-image',
            'alt' => $alt,
            'loading' => 'lazy',
            'decoding' => 'async',
        ]);

        if (!$image_html) return;

        $link = !empty($ad['link']) ? esc_url($ad['link']) : '';

        echo '<div class="product-small col has-hover ap-flatsome-ad">';
            echo '<div class="col-inner">';
                echo '<div class="box box-normal">';
                    echo '<div class="box-image">';
                        if ($link) echo '<a href="' . $link . '" rel="sponsored noopener">';
                        echo wp_kses_post($image_html);
                        if ($link) echo '</a>';
                    echo '</div>';
                echo '</div>';
            echo '</div>';
        echo '</div>';
    }

    private function get_ads_for_current_page() {
        if (is_product_category() || $this->is_brand_page()) {
            $term = get_queried_object();

            if ($term && !empty($term->term_id)) {
                $mode = $this->get_term_mode($term->term_id);

                if ('none' === $mode) {
                    return [];
                }

                if ('custom' === $mode) {
                    // Si ninguno de los anuncios propios esta vigente se usan los generales.
                    $term_ads = $this->get_term_ads($term->term_id);
                    if ($term_ads) {
                        return $term_ads;
                    }
                }
            }
        }

        return $this->get_global_ads();
    }

    /**
     * Devuelve 'global', 'custom' o 'none'. Los terminos guardados con
     * versiones anteriores (sin modo) se interpretan como antes.
     */
    private function get_term_mode($term_id) {
        $mode = (string) get_term_meta($term_id, 'ap_cat_ad_mode', true);

        if (in_array($mode, self::TERM_MODES, true)) {
            return $mode;
        }

        if ('1' === (string) get_term_meta($term_id, 'ap_cat_ad_disabled', true)) {
            return 'none';
        }

        return $this->get_term_ads($term_id, false) ? 'custom' : 'global';
    }

    private function get_skip_rows_for_current_page() {
        $skip_rows = $this->get_rows_from_string(get_option('ap_ad_skip_rows', ''));

        if (is_product_category() || $this->is_brand_page()) {
            $term = get_queried_object();

            if ($term && !empty($term->term_id)) {
                $term_skip_rows = $this->get_rows_from_string(get_term_meta($term->term_id, 'ap_cat_ad_skip_rows', true));
                $skip_rows = array_merge($skip_rows, $term_skip_rows);
            }
        }

        $skip_rows = array_values(array_unique(array_map('absint', $skip_rows)));
        sort($skip_rows);

        return $skip_rows;
    }

    private function get_rows_from_string($value) {
        $rows = $this->sanitize_rows_string($value);

        if ('' === $rows) {
            return [];
        }

        return array_map('absint', explode(',', $rows));
    }

    private function get_global_ads($randomize = true, $active_only = true) {
        $raw_ads = get_option('ap_ad_items', null);

        if (is_array($raw_ads)) {
            $ads = $this->sanitize_ads($raw_ads);
        } else {
            $ads = $this->get_legacy_global_ad();
        }

        if ($active_only) {
            $ads = $this->filter_active_ads($ads);
        }

        return $randomize ? $this->maybe_randomize_global_ads($ads) : $ads;
    }

    private function filter_active_ads($ads) {
        $active_ads = [];

        foreach ($ads as $ad) {
            if ($this->is_ad_active($ad)) {
                $active_ads[] = $ad;
            }
        }

        return $active_ads;
    }

    private function is_ad_active($ad) {
        $today = current_time('Y-m-d');
        $start_date = !empty($ad['start_date']) ? $this->sanitize_ad_date($ad['start_date']) : '';
        $end_date = !empty($ad['end_date']) ? $this->sanitize_ad_date($ad['end_date']) : '';

        if ($start_date && $today < $start_date) {
            return false;
        }

        if ($end_date && $today > $end_date) {
            return false;
        }

        return true;
    }

    private function maybe_randomize_global_ads($ads) {
        if (!(bool) get_option('ap_ad_randomize_global', 0) || count($ads) < 2) {
            return $ads;
        }

        $placements = [];
        $creatives = [];

        foreach ($ads as $ad) {
            $placements[] = [
                'row' => isset($ad['row']) ? absint($ad['row']) : 1,
                'column' => isset($ad['column']) ? absint($ad['column']) : $this->get_grid_columns(),
                'position' => isset($ad['position']) ? absint($ad['position']) : $this->get_grid_columns(),
            ];

            $creatives[] = [
                'image_id' => isset($ad['image_id']) ? absint($ad['image_id']) : 0,
                'link' => isset($ad['link']) ? esc_url_raw($ad['link']) : '',
                'start_date' => isset($ad['start_date']) ? $this->sanitize_ad_date($ad['start_date']) : '',
                'end_date' => isset($ad['end_date']) ? $this->sanitize_ad_date($ad['end_date']) : '',
            ];
        }

        shuffle($creatives);

        $randomized = [];
        foreach ($placements as $index => $placement) {
            $randomized[] = array_merge($placement, $creatives[$index]);
        }

        return $randomized;
    }

    private function get_term_ads($term_id, $active_only = true) {
        $raw_ads = get_term_meta($term_id, 'ap_cat_ad_items', true);

        if (is_array($raw_ads)) {
            $ads = $this->sanitize_ads($raw_ads);
        } else {
            $ads = $this->get_legacy_term_ad($term_id);
        }

        return $active_only ? $this->filter_active_ads($ads) : $ads;
    }

    private function get_legacy_global_ad() {
        $image_id = absint(get_option('ap_ad_image_id'));

        if (!$image_id) {
            return [];
        }

        $position = $this->sanitize_positive_int(get_option('ap_ad_position', $this->get_grid_columns()));
        $grid_columns = $this->get_grid_columns();

        return [[
            'image_id' => $image_id,
            'link' => esc_url_raw(get_option('ap_ad_link')),
            'row' => max(1, (int) ceil($position / $grid_columns)),
            'column' => (($position - 1) % $grid_columns) + 1,
            'position' => $position,
        ]];
    }

    private function get_legacy_term_ad($term_id) {
        $image_id = absint(get_term_meta($term_id, 'ap_cat_ad_image_id', true));

        if (!$image_id) {
            return [];
        }

        $position = $this->sanitize_positive_int(get_term_meta($term_id, 'ap_cat_ad_position', true) ?: $this->get_grid_columns());
        $grid_columns = $this->get_grid_columns();

        return [[
            'image_id' => $image_id,
            'link' => esc_url_raw(get_term_meta($term_id, 'ap_cat_ad_link', true)),
            'row' => max(1, (int) ceil($position / $grid_columns)),
            'column' => (($position - 1) % $grid_columns) + 1,
            'position' => $position,
        ]];
    }

    public function styles() {
        if (!$this->is_valid_page()) return;

        wp_register_style('ap-flatsome-ad-style', false, [], AP_ANUNCIO_FLATSOME_VERSION);
        wp_enqueue_style('ap-flatsome-ad-style');

        $fallback_width = 100 / $this->get_grid_columns();

        wp_add_inline_style('ap-flatsome-ad-style', '
            .ap-flatsome-ad {
                padding-bottom: 30px;
            }

            .ap-flatsome-ad .box-image,
            .ap-flatsome-ad a {
                display: block;
                width: 100%;
            }

            .ap-flatsome-ad-image {
                width: 100%;
                height: auto;
                display: block;
                border-radius: 6px;
            }

            .products:not([class*="large-columns"]):not([class*="columns-"]) > .ap-flatsome-ad {
                flex-basis: ' . esc_attr($fallback_width) . '%;
                max-width: ' . esc_attr($fallback_width) . '%;
            }

            @media (max-width: 849px) {
                .products:not([class*="medium-columns"]):not([class*="columns-"]) > .ap-flatsome-ad {
                    flex-basis: 50%;
                    max-width: 50%;
                }
            }

            @media (max-width: 549px) {
                .products:not([class*="small-columns"]):not([class*="columns-"]) > .ap-flatsome-ad {
                    flex-basis: 50%;
                    max-width: 50%;
                }
            }
        ');
    }

    /* ---------------------------------------------------------------------
     * Administracion: editor de anuncios (compartido)
     * ------------------------------------------------------------------- */

    /**
     * Editor completo: vista previa de la grilla + tarjetas de anuncios.
     * $extra_skip_rows son filas que tambien se omiten (por ejemplo, las
     * generales cuando se edita una categoria).
     */
    private function render_ads_editor($field_name, $ads, $extra_skip_rows = '') {
        $ads = array_values($ads);
        ?>
        <div class="ap-ads-editor"
             data-field-name="<?php echo esc_attr($field_name); ?>"
             data-next-index="<?php echo esc_attr(count($ads)); ?>"
             data-grid-columns="<?php echo esc_attr($this->get_grid_columns()); ?>"
             data-extra-skip-rows="<?php echo esc_attr($extra_skip_rows); ?>">

            <div class="ap-preview-wrap hide-if-no-js">
                <div class="ap-preview-head">
                    <strong>Vista previa en computadora</strong>
                    <span class="ap-legend">
                        <span class="ap-legend__item"><i class="ap-legend__swatch ap-legend__swatch--product"></i>Producto</span>
                        <span class="ap-legend__item"><i class="ap-legend__swatch ap-legend__swatch--ad"></i>Anuncio</span>
                        <span class="ap-legend__item"><i class="ap-legend__swatch ap-legend__swatch--skip"></i>Fila sin anuncios</span>
                    </span>
                </div>
                <div class="ap-preview" role="group" aria-label="Vista previa de la grilla de productos"></div>
                <p class="ap-preview-more" hidden></p>
                <p class="ap-preview-help">Haz clic en un producto gris para poner un anuncio en su lugar, o en un anuncio para editarlo. En celular hay menos columnas, pero cada anuncio sigue apareciendo después del mismo producto.</p>
            </div>

            <div class="ap-ad-list">
                <?php
                foreach ($ads as $index => $ad) {
                    $this->render_ad_card($field_name, $index, $ad, $index + 1);
                }
                ?>
            </div>

            <p class="ap-empty"<?php echo $ads ? ' hidden' : ''; ?>>Todavía no hay anuncios. Usa el botón «Agregar anuncio» o haz clic en una casilla de la vista previa.</p>

            <p class="ap-add-wrap">
                <button type="button" class="button ap-add-ad"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>Agregar anuncio</button>
            </p>

            <script type="text/html" class="ap-ad-template"><?php $this->render_ad_card($field_name, '__INDEX__', [], ''); ?></script>
        </div>
        <?php
    }

    private function render_ad_card($field_name, $index, $ad = [], $number = '') {
        $grid_columns = $this->get_grid_columns();
        $image_id = isset($ad['image_id']) ? absint($ad['image_id']) : 0;
        $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'medium') : '';
        $image_missing = $image_id && !$image_url;
        $link = isset($ad['link']) ? (string) $ad['link'] : '';
        $position = isset($ad['position']) ? $this->sanitize_positive_int($ad['position']) : $grid_columns;
        $row = isset($ad['row']) ? $this->sanitize_positive_int($ad['row']) : max(1, (int) ceil($position / $grid_columns));
        $column = isset($ad['column']) ? $this->sanitize_positive_int($ad['column']) : (($position - 1) % $grid_columns) + 1;
        $column = min($column, $grid_columns);
        $start_date = isset($ad['start_date']) ? $this->sanitize_ad_date($ad['start_date']) : '';
        $end_date = isset($ad['end_date']) ? $this->sanitize_ad_date($ad['end_date']) : '';
        $name = $field_name . '[' . $index . ']';
        ?>
        <div class="ap-ad-card<?php echo $image_url ? ' has-image' : ''; ?>"<?php echo $image_missing ? ' data-image-missing="1"' : ''; ?>>
            <input type="hidden" class="ap-ad-image-id" name="<?php echo esc_attr($name); ?>[image_id]" value="<?php echo $image_url ? esc_attr($image_id) : ''; ?>">

            <div class="ap-ad-card__media">
                <button type="button" class="ap-ad-card__picker ap-select-image" aria-label="Elegir o cambiar la imagen del anuncio">
                    <img class="ap-ad-thumb" alt=""<?php echo $image_url ? ' src="' . esc_url($image_url) . '"' : ' hidden'; ?>>
                    <span class="ap-ad-card__placeholder"<?php echo $image_url ? ' hidden' : ''; ?>>
                        <span class="dashicons dashicons-format-image" aria-hidden="true"></span>
                        Elegir imagen
                    </span>
                </button>
                <div class="ap-ad-card__media-actions">
                    <button type="button" class="button-link ap-select-image">Cambiar</button>
                    <button type="button" class="button-link ap-remove-image">Quitar</button>
                </div>
            </div>

            <div class="ap-ad-card__body">
                <div class="ap-ad-card__head">
                    <strong class="ap-ad-card__title">Anuncio <span class="ap-ad-number"><?php echo esc_html($number); ?></span></strong>
                    <span class="ap-badge"></span>
                    <button type="button" class="button-link button-link-delete ap-remove-ad"><span class="dashicons dashicons-trash" aria-hidden="true"></span>Eliminar</button>
                </div>

                <p class="ap-ad-card__note" hidden></p>

                <div class="ap-fields">
                    <label class="ap-field ap-field--wide">
                        <span class="ap-field__label">Enlace al hacer clic <em>(opcional)</em></span>
                        <input type="text"
                               inputmode="url"
                               autocomplete="off"
                               class="ap-input ap-ad-link"
                               name="<?php echo esc_attr($name); ?>[link]"
                               value="<?php echo esc_attr($link); ?>"
                               placeholder="Ej.: https://tusitio.com/ofertas">
                    </label>

                    <label class="ap-field">
                        <span class="ap-field__label">Fila</span>
                        <input type="number"
                               class="ap-input ap-ad-row"
                               name="<?php echo esc_attr($name); ?>[row]"
                               value="<?php echo esc_attr($row); ?>"
                               min="1"
                               step="1">
                    </label>

                    <label class="ap-field">
                        <span class="ap-field__label">Columna</span>
                        <input type="number"
                               class="ap-input ap-ad-column"
                               name="<?php echo esc_attr($name); ?>[column]"
                               value="<?php echo esc_attr($column); ?>"
                               min="1"
                               max="<?php echo esc_attr($grid_columns); ?>"
                               step="1">
                    </label>

                    <label class="ap-field">
                        <span class="ap-field__label">Mostrar desde <em>(opcional)</em></span>
                        <input type="date"
                               class="ap-input ap-ad-start"
                               name="<?php echo esc_attr($name); ?>[start_date]"
                               value="<?php echo esc_attr($start_date); ?>">
                    </label>

                    <label class="ap-field">
                        <span class="ap-field__label">Mostrar hasta <em>(opcional)</em></span>
                        <input type="date"
                               class="ap-input ap-ad-end"
                               name="<?php echo esc_attr($name); ?>[end_date]"
                               value="<?php echo esc_attr($end_date); ?>">
                    </label>
                </div>

                <p class="ap-ad-card__where" hidden></p>
            </div>
        </div>
        <?php
    }

    /* ---------------------------------------------------------------------
     * Administracion: categorias y marcas
     * ------------------------------------------------------------------- */

    public function add_term_fields($taxonomy = 'product_cat') {
        ?>
        <div class="form-field ap-term-field">
            <span class="ap-term-field__title">Anuncios entre productos</span>
            <?php $this->render_term_box($taxonomy); ?>
        </div>
        <?php
    }

    public function edit_term_fields($term, $taxonomy = '') {
        if (!$taxonomy && isset($term->taxonomy)) {
            $taxonomy = $term->taxonomy;
        }
        ?>
        <tr class="form-field ap-term-field">
            <th scope="row">Anuncios entre productos</th>
            <td><?php $this->render_term_box($taxonomy, $term); ?></td>
        </tr>
        <?php
    }

    private function render_term_box($taxonomy, $term = null) {
        $term_id = ($term && !empty($term->term_id)) ? (int) $term->term_id : 0;
        $noun = $this->get_term_noun($taxonomy);
        $mode = $term_id ? $this->get_term_mode($term_id) : 'global';
        $ads = $term_id ? $this->get_term_ads($term_id, false) : [];
        $skip_rows = $term_id ? $this->sanitize_rows_string(get_term_meta($term_id, 'ap_cat_ad_skip_rows', true)) : '';
        $global_skip_rows = $this->sanitize_rows_string(get_option('ap_ad_skip_rows', ''));
        $global_active = 0;
        foreach ($this->get_global_ads(false, true) as $global_ad) {
            if (wp_get_attachment_image_url($global_ad['image_id'], 'thumbnail')) {
                $global_active++;
            }
        }
        $skip_input_id = 'ap-cat-skip-rows-' . ($term_id ? $term_id : 'new');

        if (0 === $global_active) {
            $global_text = 'Ahora mismo no hay anuncios generales activos.';
        } elseif (1 === $global_active) {
            $global_text = 'Ahora mismo hay 1 anuncio general activo.';
        } else {
            $global_text = sprintf('Ahora mismo hay %d anuncios generales activos.', $global_active);
        }

        $options = [
            'global' => ['Usar los anuncios generales', 'Los mismos que en la tienda. ' . $global_text],
            'custom' => ['Usar anuncios propios', 'Imágenes y casillas solo para ' . $noun . '.'],
            'none' => ['No mostrar anuncios', 'En ' . $noun . ' no aparecerá ninguna imagen publicitaria.'],
        ];
        ?>
        <div class="ap-admin ap-term-box">
            <?php wp_nonce_field('ap_cat_ad_fields', 'ap_cat_ad_nonce'); ?>

            <div class="ap-mode" role="radiogroup" aria-label="<?php echo esc_attr('Qué anuncios mostrar en ' . $noun); ?>">
                <?php foreach ($options as $value => $option) : ?>
                    <label class="ap-mode__option<?php echo $mode === $value ? ' is-selected' : ''; ?>">
                        <input type="radio" class="ap-mode-input" name="ap_cat_ad_mode" value="<?php echo esc_attr($value); ?>" <?php checked($mode, $value); ?>>
                        <span class="ap-mode__text">
                            <strong><?php echo esc_html($option[0]); ?></strong>
                            <small><?php echo esc_html($option[1]); ?></small>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>

            <p class="description ap-mode-panel" data-ap-modes="global"<?php echo 'global' === $mode ? '' : ' hidden'; ?>>
                Los anuncios generales se editan en
                <a href="<?php echo esc_url(admin_url('admin.php?page=ap-anuncio-productos')); ?>" target="_blank" rel="noopener">WooCommerce › Anuncios entre productos</a>.
            </p>

            <div class="ap-mode-panel" data-ap-modes="custom"<?php echo 'custom' === $mode ? '' : ' hidden'; ?>>
                <p class="description ap-custom-help">Si no agregas anuncios con imagen, o si ninguno está vigente por sus fechas, se mostrarán los anuncios generales.</p>
                <?php $this->render_ads_editor('ap_cat_ad_items', $ads, $global_skip_rows); ?>
            </div>

            <div class="ap-mode-panel ap-term-skip" data-ap-modes="global custom"<?php echo 'none' === $mode ? ' hidden' : ''; ?>>
                <label class="ap-field" for="<?php echo esc_attr($skip_input_id); ?>">
                    <span class="ap-field__label">Filas sin anuncios en <?php echo esc_html($noun); ?> <em>(opcional)</em></span>
                    <input type="text"
                           id="<?php echo esc_attr($skip_input_id); ?>"
                           class="ap-input ap-skip-rows-input"
                           name="ap_cat_ad_skip_rows"
                           value="<?php echo esc_attr($skip_rows); ?>"
                           placeholder="Ej.: 2, 5">
                </label>
                <p class="description">
                    Números de fila separados por coma.
                    <?php if ($global_skip_rows) : ?>
                        También se respetan las filas sin anuncios generales: <?php echo esc_html(str_replace(',', ', ', $global_skip_rows)); ?>.
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <?php
    }

    public function save_term_fields($term_id) {
        if (!$this->can_save_term_fields()) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in can_save_term_fields().
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Values are normalized below by sanitize_ads() and sanitize_rows_string().
        $ads = isset($_POST['ap_cat_ad_items']) ? $this->sanitize_ads(wp_unslash($_POST['ap_cat_ad_items'])) : [];
        $skip_rows = isset($_POST['ap_cat_ad_skip_rows']) ? $this->sanitize_rows_string(wp_unslash($_POST['ap_cat_ad_skip_rows'])) : '';
        $mode = isset($_POST['ap_cat_ad_mode']) ? sanitize_key(wp_unslash($_POST['ap_cat_ad_mode'])) : '';
        $legacy_disabled = isset($_POST['ap_cat_ad_disabled']);
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if (!in_array($mode, self::TERM_MODES, true)) {
            $mode = $legacy_disabled ? 'none' : ($ads ? 'custom' : 'global');
        }

        update_term_meta($term_id, 'ap_cat_ad_items', $ads);
        update_term_meta($term_id, 'ap_cat_ad_skip_rows', $skip_rows);
        update_term_meta($term_id, 'ap_cat_ad_mode', $mode);
        // Se mantiene sincronizado por compatibilidad con versiones anteriores.
        update_term_meta($term_id, 'ap_cat_ad_disabled', 'none' === $mode ? 1 : 0);
        delete_term_meta($term_id, 'ap_cat_ad_image_id');
        delete_term_meta($term_id, 'ap_cat_ad_link');
        delete_term_meta($term_id, 'ap_cat_ad_position');
    }

    private function can_save_term_fields() {
        if (!isset($_POST['ap_cat_ad_nonce'])) {
            return false;
        }

        $nonce = sanitize_text_field(wp_unslash($_POST['ap_cat_ad_nonce']));
        if (!wp_verify_nonce($nonce, 'ap_cat_ad_fields')) {
            return false;
        }

        $taxonomy = isset($_POST['taxonomy']) ? sanitize_key(wp_unslash($_POST['taxonomy'])) : '';
        $taxonomy_object = $taxonomy ? get_taxonomy($taxonomy) : null;
        $capability = ($taxonomy_object && !empty($taxonomy_object->cap->edit_terms)) ? $taxonomy_object->cap->edit_terms : 'manage_categories';

        return current_user_can($capability);
    }

    /* ---------------------------------------------------------------------
     * Administracion: scripts y pagina de ajustes
     * ------------------------------------------------------------------- */

    public function admin_scripts($hook) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $is_settings_page = $this->settings_hook ? ($hook === $this->settings_hook) : ('woocommerce_page_ap-anuncio-productos' === $hook);
        $is_term_page = $screen
            && in_array($screen->base, ['edit-tags', 'term'], true)
            && in_array($screen->taxonomy, $this->get_managed_taxonomies(), true);

        if (!$is_settings_page && !$is_term_page) {
            return;
        }

        $assets_url = plugin_dir_url(AP_ANUNCIO_FLATSOME_FILE) . 'assets/';

        wp_enqueue_media();
        wp_enqueue_style('ap-anuncio-admin', $assets_url . 'admin.css', [], AP_ANUNCIO_FLATSOME_VERSION);
        wp_enqueue_script('ap-anuncio-admin', $assets_url . 'admin.js', ['jquery', 'media-editor'], AP_ANUNCIO_FLATSOME_VERSION, true);
        wp_add_inline_script(
            'ap-anuncio-admin',
            'window.apAnuncioAdmin = ' . wp_json_encode(['today' => current_time('Y-m-d')]) . ';',
            'before'
        );
    }

    public function settings_page() {
        $ads = $this->get_global_ads(false, false);
        $grid_columns = $this->get_grid_columns();
        $skip_rows = $this->sanitize_rows_string(get_option('ap_ad_skip_rows', ''));
        $randomize_global = (int) get_option('ap_ad_randomize_global', 0);
        $term_links = $this->get_term_admin_links();
        ?>
        <div class="wrap ap-admin">
            <h1>Anuncios entre productos</h1>
            <?php settings_errors(); ?>

            <div class="ap-intro">
                <p><strong>¿Cómo funciona?</strong> Cada anuncio es una imagen que ocupa el lugar de un producto dentro de la grilla de la tienda.</p>
                <ol>
                    <li>Indica cuántos productos por fila muestra tu tienda en computadora.</li>
                    <li>Agrega tus anuncios: elige la imagen, el enlace y la casilla. También puedes hacer clic directamente en la vista previa.</li>
                    <li>Si una categoría o marca necesita anuncios distintos, configúrala al editarla<?php echo $term_links ? ' desde ' . implode(' o ', $term_links) : ''; ?>.</li>
                </ol>
            </div>

            <form method="post" action="options.php" class="ap-settings-form">
                <?php settings_fields('ap_anuncio_productos'); ?>
                <input type="hidden" name="ap_ad_image_id" value="0">
                <input type="hidden" name="ap_ad_link" value="">
                <input type="hidden" name="ap_ad_position" value="4">

                <div class="ap-panel">
                    <h2 class="ap-panel__title"><span class="ap-step" aria-hidden="true">1</span>Tu grilla de productos</h2>

                    <div class="ap-setting">
                        <label class="ap-setting__label" for="ap_ad_grid_columns">Productos por fila</label>
                        <div class="ap-setting__control">
                            <input type="number"
                                   id="ap_ad_grid_columns"
                                   class="small-text ap-grid-columns-input"
                                   name="ap_ad_grid_columns"
                                   value="<?php echo esc_attr($grid_columns); ?>"
                                   min="1"
                                   max="8"
                                   step="1">
                            <?php $this->render_columns_hint($grid_columns); ?>
                            <p class="description">Cuántos productos se ven por fila en computadora. Se usa para saber en qué casilla cae cada anuncio.</p>
                        </div>
                    </div>

                    <div class="ap-setting">
                        <label class="ap-setting__label" for="ap_ad_skip_rows">Filas sin anuncios</label>
                        <div class="ap-setting__control">
                            <input type="text"
                                   id="ap_ad_skip_rows"
                                   class="regular-text ap-skip-rows-input"
                                   name="ap_ad_skip_rows"
                                   value="<?php echo esc_attr($skip_rows); ?>"
                                   placeholder="Ej.: 2, 5">
                            <p class="description">Opcional. Números de fila separados por coma donde nunca debe aparecer un anuncio.</p>
                        </div>
                    </div>
                </div>

                <div class="ap-panel">
                    <h2 class="ap-panel__title"><span class="ap-step" aria-hidden="true">2</span>Anuncios generales</h2>
                    <p class="ap-panel__intro">Se muestran en la tienda, en las etiquetas y en las categorías o marcas que no tienen anuncios propios. <?php echo esc_html($this->get_image_size_hint()); ?></p>

                    <?php $this->render_ads_editor('ap_ad_items', $ads); ?>

                    <div class="ap-setting">
                        <span class="ap-setting__label">Orden aleatorio</span>
                        <div class="ap-setting__control">
                            <input type="hidden" name="ap_ad_randomize_global" value="0">
                            <label>
                                <input type="checkbox" name="ap_ad_randomize_global" value="1" <?php checked(1, $randomize_global); ?>>
                                Mezclar las imágenes en cada visita
                            </label>
                            <p class="description">Las casillas no cambian: solo se reparte al azar qué imagen, con su enlace, aparece en cada una. Útil si tienes 2 anuncios o más.</p>
                        </div>
                    </div>
                </div>

                <div class="ap-panel">
                    <h2 class="ap-panel__title">Categorías y marcas con configuración propia</h2>
                    <?php $this->render_terms_overview($term_links); ?>
                </div>

                <div class="ap-submit-bar">
                    <?php submit_button('Guardar cambios', 'primary', 'submit', false); ?>
                    <span class="ap-unsaved" hidden>Tienes cambios sin guardar.</span>
                </div>
            </form>
        </div>
        <?php
    }

    private function render_columns_hint($grid_columns) {
        $detected = $this->get_theme_grid_columns();

        if (!$detected) {
            return;
        }

        $source = ('flatsome' === get_template()) ? 'Flatsome' : 'WooCommerce';
        $matches = ($detected === (int) $grid_columns);
        ?>
        <span class="ap-columns-hint" data-detected="<?php echo esc_attr($detected); ?>">
            <span class="ap-hint ap-hint--ok"<?php echo $matches ? '' : ' hidden'; ?>>
                <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                Coincide con la configuración de <?php echo esc_html($source); ?>.
            </span>
            <span class="ap-hint ap-hint--warn"<?php echo $matches ? ' hidden' : ''; ?>>
                <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                <?php echo esc_html(sprintf('%s está configurado con %d productos por fila.', $source, $detected)); ?>
                <button type="button" class="button button-small ap-use-detected" data-columns="<?php echo esc_attr($detected); ?>">Usar <?php echo esc_html($detected); ?></button>
            </span>
        </span>
        <?php
    }

    private function render_terms_overview($term_links) {
        $items = $this->get_terms_with_own_settings();

        if (!$items) {
            echo '<p>Ninguna por ahora. Todas las categorías y marcas usan los anuncios generales.</p>';
        } else {
            ?>
            <table class="widefat striped ap-overview">
                <thead>
                    <tr>
                        <th scope="col">Nombre</th>
                        <th scope="col">Tipo</th>
                        <th scope="col">Qué muestra</th>
                        <th scope="col"><span class="screen-reader-text">Acciones</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item) : ?>
                        <tr>
                            <td><strong><?php echo esc_html($item['name']); ?></strong></td>
                            <td><?php echo esc_html($item['type']); ?></td>
                            <td><?php echo esc_html($item['summary']); ?></td>
                            <td class="ap-overview__action">
                                <?php if ($item['edit_url']) : ?>
                                    <a href="<?php echo esc_url($item['edit_url']); ?>">Editar</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        }

        if ($term_links) {
            echo '<p class="description">Para cambiar una categoría o marca, edítala desde ' . implode(' o ', $term_links) . ' y busca la sección «Anuncios entre productos».</p>';
        }
    }

    private function get_terms_with_own_settings() {
        global $wpdb;

        $taxonomies = $this->get_existing_ad_taxonomies();
        if (!$taxonomies) {
            return [];
        }

        $meta_keys = ['ap_cat_ad_mode', 'ap_cat_ad_items', 'ap_cat_ad_disabled', 'ap_cat_ad_image_id', 'ap_cat_ad_skip_rows'];
        $placeholders = implode(', ', array_fill(0, count($meta_keys), '%s'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Solo se interpolan placeholders.
        $term_ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT term_id FROM {$wpdb->termmeta} WHERE meta_key IN ($placeholders) LIMIT 500", $meta_keys));

        if (!$term_ids) {
            return [];
        }

        $terms = get_terms([
            'taxonomy' => $taxonomies,
            'include' => array_map('absint', $term_ids),
            'hide_empty' => false,
            'orderby' => 'name',
        ]);

        if (is_wp_error($terms) || !$terms) {
            return [];
        }

        $items = [];

        foreach ($terms as $term) {
            $mode = $this->get_term_mode($term->term_id);
            $skip_rows = $this->sanitize_rows_string(get_term_meta($term->term_id, 'ap_cat_ad_skip_rows', true));

            if ('global' === $mode && '' === $skip_rows) {
                continue;
            }

            if ('none' === $mode) {
                $summary = 'No muestra anuncios.';
            } elseif ('custom' === $mode) {
                $total = count($this->get_term_ads($term->term_id, false));
                $active = count($this->get_term_ads($term->term_id, true));

                if (!$total) {
                    $summary = 'Anuncios propios sin imágenes: se muestran los generales.';
                } elseif (!$active) {
                    $summary = sprintf('%s, ninguno vigente hoy: se muestran los generales.', $this->plural($total, 'anuncio propio', 'anuncios propios'));
                } else {
                    $summary = sprintf('Anuncios propios: %d de %d activos.', $active, $total);
                }
            } else {
                $summary = 'Anuncios generales.';
            }

            if ('none' !== $mode && '' !== $skip_rows) {
                $summary .= ' Filas sin anuncios: ' . str_replace(',', ', ', $skip_rows) . '.';
            }

            $taxonomy_object = get_taxonomy($term->taxonomy);
            $edit_url = get_edit_term_link($term->term_id, $term->taxonomy, 'product');

            $items[] = [
                'name' => $term->name,
                'type' => $taxonomy_object ? $taxonomy_object->labels->singular_name : $term->taxonomy,
                'summary' => $summary,
                'edit_url' => $edit_url ? $edit_url : '',
            ];
        }

        return $items;
    }

    private function get_term_admin_links() {
        $links = [];

        foreach ($this->get_existing_ad_taxonomies() as $taxonomy) {
            $taxonomy_object = get_taxonomy($taxonomy);

            if (!$taxonomy_object) {
                continue;
            }

            $url = admin_url('edit-tags.php?taxonomy=' . $taxonomy . '&post_type=product');
            $links[] = '<a href="' . esc_url($url) . '">Productos › ' . esc_html($taxonomy_object->labels->name) . '</a>';
        }

        return $links;
    }

    /**
     * Productos por fila que usa el tema en computadora (0 si no se sabe).
     */
    private function get_theme_grid_columns() {
        if ('flatsome' === get_template()) {
            return min(8, absint(get_theme_mod('category_row_count', 3)));
        }

        if (function_exists('wc_get_default_products_per_row')) {
            return min(8, absint(wc_get_default_products_per_row()));
        }

        return 0;
    }

    private function get_image_size_hint() {
        if (function_exists('wc_get_image_size')) {
            $size = wc_get_image_size('woocommerce_thumbnail');
            $width = isset($size['width']) ? absint($size['width']) : 0;
            $height = isset($size['height']) ? absint($size['height']) : 0;

            if ($width && $height) {
                return sprintf('Consejo: usa imágenes con la misma proporción que las fotos de tus productos (%1$d × %2$d px, o más grandes en esa proporción) para que la grilla quede pareja.', $width, $height);
            }
        }

        return 'Consejo: usa imágenes con la misma proporción que las fotos de tus productos para que la grilla quede pareja.';
    }

    private function get_term_noun($taxonomy) {
        return in_array($taxonomy, $this->brand_taxonomies, true) ? 'esta marca' : 'esta categoría';
    }

    private function plural($count, $singular, $plural) {
        return $count . ' ' . (1 === (int) $count ? $singular : $plural);
    }

    /* ---------------------------------------------------------------------
     * Utilidades
     * ------------------------------------------------------------------- */

    private function get_managed_taxonomies() {
        return array_merge(['product_cat'], $this->brand_taxonomies);
    }

    private function get_existing_ad_taxonomies() {
        return array_values(array_filter($this->get_managed_taxonomies(), 'taxonomy_exists'));
    }

    private function get_grid_columns() {
        return $this->sanitize_grid_columns(get_option('ap_ad_grid_columns', $this->default_grid_columns));
    }

    private function is_valid_page() {
        if (!function_exists('is_shop')) {
            return false;
        }

        return is_shop() || is_product_category() || is_product_tag() || $this->is_brand_page();
    }

    private function is_brand_page() {
        foreach ($this->brand_taxonomies as $taxonomy) {
            if (taxonomy_exists($taxonomy) && is_tax($taxonomy)) {
                return true;
            }
        }

        return false;
    }
}
