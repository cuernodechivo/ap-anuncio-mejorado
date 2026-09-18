<?php
/**
 * Plugin Name: Anuncio entre productos Audio Pro
 * Description: Inserta varias imagenes publicitarias entre productos. Compatible con Flatsome, categorias y taxonomias de marca.
 * Version: 1.12.0
 * Author: Audio Pro
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: ap-anuncio-productos
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

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

    echo '<div class="notice notice-warning"><p><strong>Anuncio entre productos:</strong> WooCommerce debe estar activo para usar este plugin.</p></div>';
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'ap_anuncio_flatsome_action_links');

function ap_anuncio_flatsome_action_links($links) {
    $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=ap-anuncio-productos')) . '">Ajustes</a>';
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

    private $count = 0;

    private $ads_rendered = 0;

    private $loop_ads = null;

    private $loop_skip_rows = null;

    private $default_grid_columns = 4;

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

        add_action('product_cat_add_form_fields', [$this, 'add_term_fields']);
        add_action('product_cat_edit_form_fields', [$this, 'edit_term_fields']);
        add_action('created_product_cat', [$this, 'save_term_fields']);
        add_action('edited_product_cat', [$this, 'save_term_fields']);

        foreach ($this->brand_taxonomies as $taxonomy) {
            add_action($taxonomy . '_add_form_fields', [$this, 'add_term_fields']);
            add_action($taxonomy . '_edit_form_fields', [$this, 'edit_term_fields']);
            add_action('created_' . $taxonomy, [$this, 'save_term_fields']);
            add_action('edited_' . $taxonomy, [$this, 'save_term_fields']);
        }

        add_action('woocommerce_before_shop_loop', [$this, 'reset_count'], 1);
        add_action('woocommerce_shop_loop', [$this, 'show_ad'], 1);
        add_action('woocommerce_shop_loop', [$this, 'count_product'], 999);

        add_action('wp_enqueue_scripts', [$this, 'styles']);
    }

    public function menu() {
        add_submenu_page(
            'woocommerce',
            'Anuncio entre productos',
            'Anuncio entre productos',
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

    public function sanitize_ads($ads) {
        if (!is_array($ads)) {
            return [];
        }

        $grid_columns = $this->get_grid_columns();
        $sanitized = [];

        foreach ($ads as $index => $ad) {
            if (!is_array($ad)) {
                continue;
            }

            $image_id = isset($ad['image_id']) ? absint($ad['image_id']) : 0;
            if (!$image_id) {
                continue;
            }

            $link = (isset($ad['link']) && is_scalar($ad['link'])) ? (string) $ad['link'] : '';
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
                $row = $this->sanitize_positive_int($index + 1);
                $column = $grid_columns;
                $position = (($row - 1) * $grid_columns) + $column;
            }

            $sanitized[] = [
                'image_id' => $image_id,
                'link' => esc_url_raw($link),
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

    public function reset_count() {
        $this->count = 0;
        $this->ads_rendered = 0;
        $this->loop_ads = null;
        $this->loop_skip_rows = null;
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

        foreach ($this->loop_ads as $ad) {
            if (!empty($ad['row']) && in_array(absint($ad['row']), $this->loop_skip_rows, true)) {
                continue;
            }

            $next_cell = $this->count + $this->ads_rendered + 1;

            if ($next_cell !== intval($ad['position'])) {
                continue;
            }

            $this->render_frontend_ad($ad);
            $this->ads_rendered++;
        }
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
                        if ($link) echo '<a href="' . esc_url($link) . '" rel="sponsored noopener">';
                        echo wp_kses_post($image_html);
                        if ($link) echo '</a>';
                    echo '</div>';
                echo '</div>';
            echo '</div>';
        echo '</div>';
    }

    private function get_ads_for_current_page() {
        $ads = $this->get_global_ads();

        if (is_product_category() || $this->is_brand_page()) {
            $term = get_queried_object();

            if ($term && !empty($term->term_id)) {
                $disabled = get_term_meta($term->term_id, 'ap_cat_ad_disabled', true);

                if ('1' === (string) $disabled) {
                    return [];
                }

                $term_ads = $this->get_term_ads($term->term_id);
                if ($term_ads) {
                    $ads = $term_ads;
                }
            }
        }

        return $ads;
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

        wp_register_style('ap-flatsome-ad-style', false, [], '1.9.2');
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

    private function render_ad_item_row($field_name, $index, $ad = []) {
        $grid_columns = $this->get_grid_columns();
        $image_id = isset($ad['image_id']) ? absint($ad['image_id']) : 0;
        $image_url = $image_id ? wp_get_attachment_url($image_id) : '';
        $link = isset($ad['link']) ? (string) $ad['link'] : '';
        $position = isset($ad['position']) ? $this->sanitize_positive_int($ad['position']) : $grid_columns;
        $row = isset($ad['row']) ? $this->sanitize_positive_int($ad['row']) : max(1, (int) ceil($position / $grid_columns));
        $column = isset($ad['column']) ? $this->sanitize_positive_int($ad['column']) : (($position - 1) % $grid_columns) + 1;
        $column = min($column, $grid_columns);
        $start_date = isset($ad['start_date']) ? $this->sanitize_ad_date($ad['start_date']) : '';
        $end_date = isset($ad['end_date']) ? $this->sanitize_ad_date($ad['end_date']) : '';
        ?>
        <div class="ap-ad-item" style="border:1px solid #ccd0d4; padding:12px; margin:0 0 12px; max-width:720px; background:#fff;">
            <input type="hidden"
                   name="<?php echo esc_attr($field_name); ?>[<?php echo esc_attr($index); ?>][image_id]"
                   class="ap_ad_image_id"
                   value="<?php echo esc_attr($image_id); ?>">

            <div style="margin-bottom:10px;">
                <img class="ap_ad_image_preview"
                     src="<?php echo esc_url($image_url); ?>"
                     style="max-width:180px; display:<?php echo $image_url ? 'block' : 'none'; ?>; margin-bottom:10px;"
                     alt="">

                <button type="button" class="button ap_select_image">Seleccionar imagen</button>
                <button type="button" class="button ap_remove_image" style="display:<?php echo $image_url ? 'inline-block' : 'none'; ?>;">Quitar imagen</button>
                <button type="button" class="button-link-delete ap_remove_ad_item" style="margin-left:10px;">Eliminar anuncio</button>
            </div>

            <p>
                <label>Link del anuncio</label><br>
                <input type="url"
                       name="<?php echo esc_attr($field_name); ?>[<?php echo esc_attr($index); ?>][link]"
                       value="<?php echo esc_attr($link); ?>"
                       placeholder="https://tusitio.com/oferta"
                       style="width:500px; max-width:100%;">
            </p>

            <p>
                <label>Fila</label><br>
                <input type="number"
                       class="ap_ad_row"
                       name="<?php echo esc_attr($field_name); ?>[<?php echo esc_attr($index); ?>][row]"
                       value="<?php echo esc_attr($row); ?>"
                       min="1"
                       style="width:90px;">
            </p>

            <p>
                <label>Columna</label><br>
                <input type="number"
                       class="ap_ad_column"
                       name="<?php echo esc_attr($field_name); ?>[<?php echo esc_attr($index); ?>][column]"
                       value="<?php echo esc_attr($column); ?>"
                       min="1"
                       max="<?php echo esc_attr($grid_columns); ?>"
                       style="width:90px;">
                <span class="description">La columna maxima actual es <?php echo esc_html($grid_columns); ?>.</span>
            </p>

            <p>
                <label>Fecha de inicio</label><br>
                <input type="date"
                       name="<?php echo esc_attr($field_name); ?>[<?php echo esc_attr($index); ?>][start_date]"
                       value="<?php echo esc_attr($start_date); ?>">
            </p>

            <p>
                <label>Fecha de fin</label><br>
                <input type="date"
                       name="<?php echo esc_attr($field_name); ?>[<?php echo esc_attr($index); ?>][end_date]"
                       value="<?php echo esc_attr($end_date); ?>">
                <span class="description">Deja las fechas vacias para mostrarlo siempre.</span>
            </p>
        </div>
        <?php
    }

    public function add_term_fields() {
        $ads = [[
            'image_id' => 0,
            'link' => '',
            'row' => 1,
            'column' => $this->get_grid_columns(),
        ]];
        ?>
        <div class="form-field ap-ad-items-wrap">
            <?php wp_nonce_field('ap_cat_ad_fields', 'ap_cat_ad_nonce'); ?>
            <label>Anuncios publicitarios</label>
            <div class="ap-ad-items" data-field-name="ap_cat_ad_items" data-next-index="<?php echo esc_attr(count($ads)); ?>" data-grid-columns="<?php echo esc_attr($this->get_grid_columns()); ?>">
                <?php foreach ($ads as $index => $ad) : ?>
                    <?php $this->render_ad_item_row('ap_cat_ad_items', $index, $ad); ?>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button ap_add_ad_item">Agregar otro anuncio</button>
            <p class="description">Cada anuncio puede tener su propia imagen, link, fila y columna.</p>
        </div>

        <div class="form-field">
            <label>Filas sin anuncio</label>
            <input type="text" name="ap_cat_ad_skip_rows" placeholder="Ejemplo: 2, 5, 7">
            <p class="description">Escribe las filas donde no debe aparecer ninguna imagen.</p>
        </div>

        <div class="form-field">
            <label>
                <input type="checkbox" name="ap_cat_ad_disabled" value="1">
                No mostrar anuncio en esta categoria o marca
            </label>
        </div>
        <?php
    }

    public function edit_term_fields($term) {
        $ads = $this->get_term_ads($term->term_id, false);
        if (!$ads) {
            $ads = [[
                'image_id' => 0,
                'link' => '',
                'row' => 1,
                'column' => $this->get_grid_columns(),
            ]];
        }

        $disabled = get_term_meta($term->term_id, 'ap_cat_ad_disabled', true);
        $skip_rows = $this->sanitize_rows_string(get_term_meta($term->term_id, 'ap_cat_ad_skip_rows', true));
        ?>
        <tr class="form-field">
            <th>Anuncios publicitarios</th>
            <td>
                <?php wp_nonce_field('ap_cat_ad_fields', 'ap_cat_ad_nonce'); ?>
                <div class="ap-ad-items" data-field-name="ap_cat_ad_items" data-next-index="<?php echo esc_attr(count($ads)); ?>" data-grid-columns="<?php echo esc_attr($this->get_grid_columns()); ?>">
                    <?php foreach ($ads as $index => $ad) : ?>
                        <?php $this->render_ad_item_row('ap_cat_ad_items', $index, $ad); ?>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="button ap_add_ad_item">Agregar otro anuncio</button>
                <p class="description">Cada anuncio puede tener su propia imagen, link, fila y columna.</p>
            </td>
        </tr>

        <tr class="form-field">
            <th>Filas sin anuncio</th>
            <td>
                <input type="text"
                       name="ap_cat_ad_skip_rows"
                       value="<?php echo esc_attr($skip_rows); ?>"
                       placeholder="Ejemplo: 2, 5, 7"
                       style="width:240px; max-width:100%;">
                <p class="description">Escribe las filas donde no debe aparecer ninguna imagen.</p>
            </td>
        </tr>

        <tr class="form-field">
            <th>Estado</th>
            <td>
                <label>
                    <input type="checkbox" name="ap_cat_ad_disabled" value="1" <?php checked('1', (string) $disabled); ?>>
                    No mostrar anuncio en esta categoria o marca
                </label>
            </td>
        </tr>
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
        $disabled = isset($_POST['ap_cat_ad_disabled']) ? 1 : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        update_term_meta($term_id, 'ap_cat_ad_items', $ads);
        update_term_meta($term_id, 'ap_cat_ad_skip_rows', $skip_rows);
        update_term_meta($term_id, 'ap_cat_ad_disabled', $disabled);
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

    public function admin_scripts($hook) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $taxonomies = array_merge(['product_cat'], $this->brand_taxonomies);
        $is_settings_page = 'woocommerce_page_ap-anuncio-productos' === $hook;
        $is_term_page = $screen && !empty($screen->taxonomy) && in_array($screen->taxonomy, $taxonomies, true);

        if (!$is_settings_page && !$is_term_page) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_script('jquery');

        wp_add_inline_script('jquery', <<<'JS'
            jQuery(document).ready(function($) {
                let frame;

                function getGridColumns(items) {
                    const setting = $('#ap_ad_grid_columns');
                    const settingValue = parseInt(setting.val() || '0', 10);

                    if (settingValue > 0) {
                        return settingValue;
                    }

                    return parseInt(items.attr('data-grid-columns') || '4', 10);
                }

                function getNextPlacement(items) {
                    let maxPosition = 0;
                    const gridColumns = getGridColumns(items);

                    items.find('.ap-ad-item').each(function() {
                        const item = $(this);
                        const row = parseInt(item.find('.ap_ad_row').val() || '0', 10);
                        const column = parseInt(item.find('.ap_ad_column').val() || '0', 10);

                        if (row > 0 && column > 0) {
                            maxPosition = Math.max(maxPosition, ((row - 1) * gridColumns) + column);
                        }
                    });

                    const nextPosition = maxPosition + 1;

                    return {
                        row: Math.max(1, Math.ceil(nextPosition / gridColumns)),
                        column: ((nextPosition - 1) % gridColumns) + 1,
                        gridColumns: gridColumns
                    };
                }

                function buildAdItem(fieldName, index, placement) {
                    return '' +
                        '<div class="ap-ad-item" style="border:1px solid #ccd0d4; padding:12px; margin:0 0 12px; max-width:720px; background:#fff;">' +
                            '<input type="hidden" name="' + fieldName + '[' + index + '][image_id]" class="ap_ad_image_id" value="">' +
                            '<div style="margin-bottom:10px;">' +
                                '<img class="ap_ad_image_preview" style="max-width:180px; display:none; margin-bottom:10px;" alt="">' +
                                '<button type="button" class="button ap_select_image">Seleccionar imagen</button> ' +
                                '<button type="button" class="button ap_remove_image" style="display:none;">Quitar imagen</button> ' +
                                '<button type="button" class="button-link-delete ap_remove_ad_item" style="margin-left:10px;">Eliminar anuncio</button>' +
                            '</div>' +
                            '<p>' +
                                '<label>Link del anuncio</label><br>' +
                                '<input type="url" name="' + fieldName + '[' + index + '][link]" value="" placeholder="https://tusitio.com/oferta" style="width:500px; max-width:100%;">' +
                            '</p>' +
                            '<p>' +
                                '<label>Fila</label><br>' +
                                '<input type="number" class="ap_ad_row" name="' + fieldName + '[' + index + '][row]" value="' + placement.row + '" min="1" style="width:90px;">' +
                            '</p>' +
                            '<p>' +
                                '<label>Columna</label><br>' +
                                '<input type="number" class="ap_ad_column" name="' + fieldName + '[' + index + '][column]" value="' + placement.column + '" min="1" max="' + placement.gridColumns + '" style="width:90px;"> ' +
                                '<span class="description">El anuncio aparece en esa celda.</span>' +
                            '</p>' +
                            '<p>' +
                                '<label>Fecha de inicio</label><br>' +
                                '<input type="date" name="' + fieldName + '[' + index + '][start_date]" value="">' +
                            '</p>' +
                            '<p>' +
                                '<label>Fecha de fin</label><br>' +
                                '<input type="date" name="' + fieldName + '[' + index + '][end_date]" value=""> ' +
                                '<span class="description">Deja las fechas vacias para mostrarlo siempre.</span>' +
                            '</p>' +
                        '</div>';
                }

                $(document).on('click', '.ap_add_ad_item', function(e) {
                    e.preventDefault();

                    const button = $(this);
                    const items = button.siblings('.ap-ad-items').first();
                    const fieldName = items.data('field-name');
                    const index = parseInt(items.attr('data-next-index') || '0', 10);
                    const placement = getNextPlacement(items);

                    items.append(buildAdItem(fieldName, index, placement));
                    items.attr('data-next-index', index + 1);
                });

                $(document).on('change input', '#ap_ad_grid_columns', function() {
                    const gridColumns = parseInt($(this).val() || '4', 10);

                    $('.ap-ad-items').attr('data-grid-columns', gridColumns);
                    $('.ap_ad_column').attr('max', gridColumns).each(function() {
                        const input = $(this);
                        const column = parseInt(input.val() || '1', 10);

                        if (column > gridColumns) {
                            input.val(gridColumns);
                        }
                    });
                });

                $(document).on('click', '.ap_select_image, #ap_select_image', function(e) {
                    e.preventDefault();

                    const button = $(this);
                    const wrapper = button.closest('.ap-ad-item, td, .form-field, .wrap');

                    frame = wp.media({
                        title: 'Seleccionar imagen publicitaria',
                        button: { text: 'Usar esta imagen' },
                        multiple: false
                    });

                    frame.on('select', function() {
                        const attachment = frame.state().get('selection').first().toJSON();

                        wrapper.find('.ap_ad_image_id, #ap_ad_image_id').first().val(attachment.id);
                        wrapper.find('.ap_ad_image_preview, #ap_ad_image_preview').first().attr('src', attachment.url).show();
                        wrapper.find('.ap_remove_image, #ap_remove_image').first().show();
                    });

                    frame.open();
                });

                $(document).on('click', '.ap_remove_image, #ap_remove_image', function(e) {
                    e.preventDefault();

                    const button = $(this);
                    const wrapper = button.closest('.ap-ad-item, td, .form-field, .wrap');

                    wrapper.find('.ap_ad_image_id, #ap_ad_image_id').first().val('');
                    wrapper.find('.ap_ad_image_preview, #ap_ad_image_preview').first().attr('src', '').hide();
                    button.hide();
                });

                $(document).on('click', '.ap_remove_ad_item', function(e) {
                    e.preventDefault();

                    const item = $(this).closest('.ap-ad-item');
                    const items = item.closest('.ap-ad-items');

                    if (items.find('.ap-ad-item').length > 1) {
                        item.remove();
                        return;
                    }

                    item.find('.ap_ad_image_id').val('');
                    item.find('.ap_ad_image_preview').attr('src', '').hide();
                    item.find('.ap_remove_image').hide();
                    item.find('input[type="url"]').val('');
                    item.find('input[type="date"]').val('');
                    item.find('.ap_ad_row').val('1');
                    item.find('.ap_ad_column').val(getGridColumns(items));
                });
            });
JS
        );
    }

    public function settings_page() {
        $ads = $this->get_global_ads(false, false);
        if (!$ads) {
            $ads = [[
                'image_id' => 0,
                'link' => '',
                'row' => 1,
                'column' => $this->get_grid_columns(),
            ]];
        }

        $grid_columns = $this->get_grid_columns();
        $skip_rows = $this->sanitize_rows_string(get_option('ap_ad_skip_rows', ''));
        $randomize_global = (int) get_option('ap_ad_randomize_global', 0);
        ?>
        <div class="wrap">
            <h1>Anuncio general entre productos</h1>
            <p>Estos anuncios se usaran en la tienda general o como respaldo si una categoria o marca no tiene anuncios propios.</p>

            <form method="post" action="options.php">
                <?php settings_fields('ap_anuncio_productos'); ?>
                <input type="hidden" name="ap_ad_image_id" value="0">
                <input type="hidden" name="ap_ad_link" value="">
                <input type="hidden" name="ap_ad_position" value="4">

                <table class="form-table">
                    <tr>
                        <th>Anuncios generales</th>
                        <td>
                            <div class="ap-ad-items" data-field-name="ap_ad_items" data-next-index="<?php echo esc_attr(count($ads)); ?>" data-grid-columns="<?php echo esc_attr($grid_columns); ?>">
                                <?php foreach ($ads as $index => $ad) : ?>
                                    <?php $this->render_ad_item_row('ap_ad_items', $index, $ad); ?>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="button ap_add_ad_item">Agregar otro anuncio</button>
                            <p class="description">Cada anuncio puede tener su propia imagen, link, fila y columna.</p>
                            <p class="description">Si dejas el link vacio, la imagen se mostrara sin enlace.</p>
                        </td>
                    </tr>

                    <tr>
                        <th>Orden aleatorio</th>
                        <td>
                            <input type="hidden" name="ap_ad_randomize_global" value="0">
                            <label>
                                <input type="checkbox" name="ap_ad_randomize_global" value="1" <?php checked(1, $randomize_global); ?>>
                                Rotar aleatoriamente las imagenes generales entre las posiciones configuradas
                            </label>
                            <p class="description">Mantiene fila y columna, pero cambia que imagen y link aparece en cada espacio.</p>
                        </td>
                    </tr>

                    <tr>
                        <th>Columnas de la grilla</th>
                        <td>
                            <input type="number"
                                   id="ap_ad_grid_columns"
                                   name="ap_ad_grid_columns"
                                   value="<?php echo esc_attr($grid_columns); ?>"
                                   min="1"
                                   max="8"
                                   style="width:90px;">
                            <p class="description">Debe coincidir con las columnas de productos en escritorio. Por ejemplo: 4 columnas.</p>
                        </td>
                    </tr>

                    <tr>
                        <th>Filas sin anuncio</th>
                        <td>
                            <input type="text"
                                   name="ap_ad_skip_rows"
                                   value="<?php echo esc_attr($skip_rows); ?>"
                                   placeholder="Ejemplo: 2, 5, 7"
                                   style="width:240px; max-width:100%;">
                            <p class="description">En estas filas no se mostrara ninguna imagen publicitaria.</p>
                        </td>
                    </tr>

                </table>

                <?php submit_button('Guardar cambios'); ?>
            </form>
        </div>
        <?php
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
