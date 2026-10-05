<?php
/**
 * Plugin Name: Anuncio entre productos Audio Pro
 * Description: Inserta imagenes, videos y Shorts de YouTube publicitarios entre productos. Compatible con Flatsome, categorias y taxonomias de marca.
 * Version: 1.14.0
 * Author: Audio Pro
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: ap-anuncio-productos
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

define('AP_ANUNCIO_FLATSOME_VERSION', '1.14.0');
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

    // Tipos de anuncio y formas (proporciones) disponibles para video.
    const AD_TYPES = ['image', 'video', 'youtube'];

    const AD_FORMATS = ['auto', 'product', 'vertical', 'horizontal'];

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

            // Los anuncios guardados antes de la version 1.14 no tienen tipo: son imagenes.
            $type = (isset($ad['type']) && in_array($ad['type'], self::AD_TYPES, true)) ? $ad['type'] : 'image';
            $image_id = isset($ad['image_id']) ? absint($ad['image_id']) : 0;
            $video_id = isset($ad['video_id']) ? absint($ad['video_id']) : 0;
            $youtube = $this->parse_youtube(isset($ad['youtube_url']) ? $ad['youtube_url'] : '');

            $has_media = ('image' === $type && $image_id)
                || ('video' === $type && $video_id)
                || ('youtube' === $type && $youtube['id']);

            if (!$has_media) {
                continue;
            }

            $format = (isset($ad['format']) && in_array($ad['format'], self::AD_FORMATS, true)) ? $ad['format'] : $this->get_default_format($type);
            $button_text = (isset($ad['button_text']) && is_scalar($ad['button_text'])) ? $this->limit_text(sanitize_text_field((string) $ad['button_text']), 40) : '';
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
                'type' => $type,
                'image_id' => $image_id,
                'video_id' => $video_id,
                'youtube_id' => $youtube['id'],
                'youtube_url' => $youtube['url'],
                'format' => $format,
                'button_text' => $button_text,
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

    /**
     * Extrae el ID de un enlace de YouTube: Shorts, watch?v=, youtu.be, embed o
     * live. Tambien acepta el ID suelto de 11 caracteres.
     */
    public function parse_youtube($value) {
        $empty = ['id' => '', 'url' => '', 'short' => false];

        if (!is_scalar($value)) {
            return $empty;
        }

        $value = trim((string) $value);
        if ('' === $value) {
            return $empty;
        }

        $id = '';
        $short = false;
        $patterns = [
            '~youtube(?:-nocookie)?\.com/shorts/([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])~i' => true,
            '~youtu\.be/([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])~i' => false,
            '~youtube(?:-nocookie)?\.com/(?:embed|live|v)/([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])~i' => false,
            '~youtube\.com/.*[?&]v=([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])~i' => false,
        ];

        if (preg_match('~^[A-Za-z0-9_-]{11}$~', $value)) {
            $id = $value;
        } else {
            foreach ($patterns as $pattern => $is_short) {
                if (preg_match($pattern, $value, $matches)) {
                    $id = $matches[1];
                    $short = $is_short;
                    break;
                }
            }
        }

        if ('' === $id) {
            return $empty;
        }

        return [
            'id' => $id,
            'short' => $short,
            'url' => $short ? 'https://www.youtube.com/shorts/' . $id : 'https://www.youtube.com/watch?v=' . $id,
        ];
    }

    private function get_default_format($type) {
        return 'video' === $type ? 'product' : 'auto';
    }

    private function limit_text($text, $length) {
        return function_exists('mb_substr') ? mb_substr($text, 0, $length) : substr($text, 0, $length);
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

            // Si el archivo ya no existe no se muestra nada y la casilla queda para un producto.
            if ($this->render_frontend_ad($ad)) {
                $this->ads_rendered++;
            }

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

    /**
     * Imprime el anuncio dentro de la grilla. Devuelve false si no habia nada
     * que mostrar (por ejemplo, si el archivo se borro de la biblioteca).
     */
    private function render_frontend_ad($ad) {
        $type = (isset($ad['type']) && in_array($ad['type'], self::AD_TYPES, true)) ? $ad['type'] : 'image';

        if ('video' === $type) {
            $media = $this->get_video_ad_html($ad);
        } elseif ('youtube' === $type) {
            $media = $this->get_youtube_ad_html($ad);
        } else {
            $media = $this->get_image_ad_html($ad);
        }

        if ('' === $media) {
            return false;
        }

        $after = '';
        if ('youtube' === $type && !empty($ad['link'])) {
            $button_text = !empty($ad['button_text']) ? $ad['button_text'] : 'Ver oferta';
            $after = '<div class="box-text text-center ap-ad-cta-wrap">'
                . '<a class="button primary is-small ap-ad-cta" href="' . esc_url($ad['link']) . '" rel="sponsored noopener">' . esc_html($button_text) . '</a>'
                . '</div>';
        }

        echo '<div class="product-small col has-hover ap-flatsome-ad ap-flatsome-ad--' . esc_attr($type) . '">';
            echo '<div class="col-inner">';
                echo '<div class="box box-normal">';
                    echo '<div class="box-image">' . $media . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escapado en get_*_ad_html().
                    echo $after; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escapado arriba.
                echo '</div>';
            echo '</div>';
        echo '</div>';

        return true;
    }

    private function get_image_ad_html($ad) {
        $image_id = !empty($ad['image_id']) ? absint($ad['image_id']) : 0;
        if (!$image_id) {
            return '';
        }

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

        if (!$image_html) {
            return '';
        }

        return $this->wrap_ad_link(wp_kses_post($image_html), $ad);
    }

    /**
     * Video subido a la biblioteca: se reproduce solo, sin sonido y en bucle
     * cuando entra en pantalla (lo controla assets/front.js).
     */
    private function get_video_ad_html($ad) {
        $video_id = !empty($ad['video_id']) ? absint($ad['video_id']) : 0;
        $video_url = $video_id ? wp_get_attachment_url($video_id) : '';

        if (!$video_url) {
            return '';
        }

        $mime = (string) get_post_mime_type($video_id);
        if (0 !== strpos($mime, 'video/')) {
            $mime = 'video/mp4';
        }

        $poster = !empty($ad['image_id']) ? wp_get_attachment_image_url(absint($ad['image_id']), 'large') : '';

        $video = '<video class="ap-ad-video" muted loop playsinline preload="none" data-ap-autoplay aria-label="Publicidad"'
            . ($poster ? ' poster="' . esc_url($poster) . '"' : '') . '>'
            . '<source src="' . esc_url($video_url) . '" type="' . esc_attr($mime) . '">'
            . '</video>';

        $this->enqueue_front_script();

        return $this->wrap_ad_link($this->get_media_box($video, $this->get_ad_ratio($ad), 'video'), $ad, 'Publicidad');
    }

    /**
     * YouTube: se muestra la miniatura con un boton de reproducir y el
     * reproductor solo se carga al tocarla, para no hacer lenta la tienda.
     */
    private function get_youtube_ad_html($ad) {
        $youtube = $this->parse_youtube(isset($ad['youtube_url']) ? $ad['youtube_url'] : '');

        if (!$youtube['id']) {
            return '';
        }

        $ratio = $this->get_ad_ratio($ad);
        $cover = !empty($ad['image_id']) ? wp_get_attachment_image_url(absint($ad['image_id']), 'large') : '';

        if ($cover) {
            $thumb = '<img class="ap-ad-youtube__thumb" src="' . esc_url($cover) . '" alt="" loading="lazy" decoding="async">';
        } else {
            // La miniatura de YouTube trae bandas negras: se amplia para recortarlas.
            $scale = $this->get_youtube_thumb_scale($ratio, $youtube['short'] ? 9 / 16 : 16 / 9);
            $thumb = '<img class="ap-ad-youtube__thumb" src="' . esc_url('https://i.ytimg.com/vi/' . $youtube['id'] . '/hqdefault.jpg') . '" alt="" loading="lazy" decoding="async"'
                . ($scale > 1 ? ' style="transform:scale(' . esc_attr($this->css_number($scale)) . ')"' : '') . '>';
        }

        $icon = '<span class="ap-ad-youtube__icon" aria-hidden="true"><svg viewBox="0 0 68 48" width="68" height="48" focusable="false">'
            . '<path d="M66.5 7.7a8.5 8.5 0 0 0-6-6C55.2.3 34 .3 34 .3s-21.2 0-26.5 1.4a8.5 8.5 0 0 0-6 6C.1 13 .1 24 .1 24s0 11 1.4 16.3a8.5 8.5 0 0 0 6 6C12.8 47.7 34 47.7 34 47.7s21.2 0 26.5-1.4a8.5 8.5 0 0 0 6-6C67.9 35 67.9 24 67.9 24s0-11-1.4-16.3z" fill="#f00"/>'
            . '<path d="M45 24 27 14v20z" fill="#fff"/></svg></span>';

        $play = '<a class="ap-ad-youtube__play" href="' . esc_url($youtube['url']) . '" target="_blank" rel="noopener" aria-label="Reproducir video">' . $thumb . $icon . '</a>';

        $this->enqueue_front_script();

        return $this->get_media_box($play, $ratio, 'youtube', ' data-ap-youtube="' . esc_attr($youtube['id']) . '"');
    }

    private function wrap_ad_link($html, $ad, $label = '') {
        $link = !empty($ad['link']) ? esc_url($ad['link']) : '';

        if (!$link) {
            return $html;
        }

        return '<a href="' . $link . '" rel="sponsored noopener"' . ($label ? ' aria-label="' . esc_attr($label) . '"' : '') . '>' . $html . '</a>';
    }

    private function get_media_box($inner, $ratio, $kind, $attributes = '') {
        $ratio = max(0.2, min(5, (float) $ratio));
        $style = 'aspect-ratio:' . $this->css_number($ratio) . ';--ap-pad:' . $this->css_number(100 / $ratio) . '%';

        return '<div class="ap-ad-media ap-ad-media--' . esc_attr($kind) . '" style="' . esc_attr($style) . '"' . $attributes . '>' . $inner . '</div>';
    }

    /**
     * Proporcion ancho/alto de un anuncio de video o YouTube.
     */
    private function get_ad_ratio($ad) {
        $type = isset($ad['type']) ? $ad['type'] : 'image';
        $format = isset($ad['format']) ? $ad['format'] : $this->get_default_format($type);

        if ('vertical' === $format) {
            return 9 / 16;
        }

        if ('horizontal' === $format) {
            return 16 / 9;
        }

        if ('auto' === $format) {
            if ('youtube' === $type) {
                $youtube = $this->parse_youtube(isset($ad['youtube_url']) ? $ad['youtube_url'] : '');
                return $youtube['short'] ? 9 / 16 : 16 / 9;
            }

            $video_ratio = 'video' === $type && !empty($ad['video_id']) ? $this->get_video_ratio(absint($ad['video_id'])) : 0;
            if ($video_ratio) {
                return $video_ratio;
            }
        }

        return $this->get_product_ratio();
    }

    private function get_video_ratio($video_id) {
        $meta = wp_get_attachment_metadata($video_id);

        if (is_array($meta) && !empty($meta['width']) && !empty($meta['height'])) {
            return (int) $meta['width'] / (int) $meta['height'];
        }

        return 0;
    }

    /**
     * Proporcion de las fotos de productos segun WooCommerce (1 si no se recortan).
     */
    private function get_product_ratio() {
        if (function_exists('wc_get_image_size')) {
            $size = wc_get_image_size('woocommerce_thumbnail');
            $width = isset($size['width']) ? absint($size['width']) : 0;
            $height = isset($size['height']) ? absint($size['height']) : 0;

            if ($width && $height) {
                return $width / $height;
            }
        }

        return 1.0;
    }

    /**
     * La miniatura hqdefault de YouTube es 4:3 y el video va centrado con bandas
     * negras. Devuelve cuanto hay que ampliarla para que el video llene la caja.
     */
    private function get_youtube_thumb_scale($box_ratio, $video_ratio) {
        $image_ratio = 4 / 3;
        $frame_width = $video_ratio < $image_ratio ? $video_ratio / $image_ratio : 1;
        $frame_height = $video_ratio < $image_ratio ? 1 : $image_ratio / $video_ratio;

        if ($box_ratio > $image_ratio) {
            $image_width = $box_ratio;
            $image_height = $box_ratio / $image_ratio;
        } else {
            $image_width = $image_ratio;
            $image_height = 1;
        }

        return max(1, $box_ratio / ($image_width * $frame_width), 1 / ($image_height * $frame_height));
    }

    private function css_number($number) {
        return rtrim(rtrim(number_format((float) $number, 4, '.', ''), '0'), '.');
    }

    private function enqueue_front_script() {
        wp_enqueue_script(
            'ap-anuncio-front',
            plugin_dir_url(AP_ANUNCIO_FLATSOME_FILE) . 'assets/front.js',
            [],
            AP_ANUNCIO_FLATSOME_VERSION,
            true
        );
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

        // Las casillas se quedan fijas; se mezcla el contenido (imagen, video,
        // enlace, fechas...).
        $placement_keys = ['row' => true, 'column' => true, 'position' => true];
        $placements = [];
        $creatives = [];

        foreach ($ads as $ad) {
            $placements[] = array_intersect_key($ad, $placement_keys);
            $creatives[] = array_diff_key($ad, $placement_keys);
        }

        shuffle($creatives);

        $randomized = [];
        foreach ($placements as $index => $placement) {
            $randomized[] = array_merge($creatives[$index], $placement);
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
            .ap-flatsome-ad .box-image > a {
                display: block;
                width: 100%;
            }

            .ap-ad-media {
                position: relative;
                display: block;
                width: 100%;
                overflow: hidden;
                border-radius: 6px;
                background: #111;
            }

            @supports not (aspect-ratio: 1 / 1) {
                .ap-ad-media::before {
                    content: "";
                    display: block;
                    padding-top: var(--ap-pad, 100%);
                }
            }

            .ap-ad-media > video,
            .ap-ad-media > iframe,
            .ap-ad-media .ap-ad-youtube__thumb {
                position: absolute;
                top: 0;
                left: 0;
                display: block;
                width: 100%;
                height: 100%;
                max-width: none;
                margin: 0;
                border: 0;
                object-fit: cover;
            }

            .ap-ad-youtube__play {
                position: absolute;
                top: 0;
                left: 0;
                display: block;
                width: 100%;
                height: 100%;
                overflow: hidden;
                cursor: pointer;
            }

            .ap-ad-youtube__icon {
                position: absolute;
                top: 50%;
                left: 50%;
                width: 68px;
                height: 48px;
                margin: -24px 0 0 -34px;
                opacity: 0.9;
                transition: opacity 0.2s ease, transform 0.2s ease;
            }

            .ap-ad-youtube__icon svg {
                display: block;
                width: 100%;
                height: 100%;
            }

            .ap-ad-youtube__play:hover .ap-ad-youtube__icon,
            .ap-ad-youtube__play:focus-visible .ap-ad-youtube__icon {
                opacity: 1;
                transform: scale(1.08);
            }

            .ap-flatsome-ad .ap-ad-cta-wrap {
                padding: 10px 0 0;
            }

            .ap-flatsome-ad .ap-ad-cta {
                margin: 0;
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

                <div class="ap-add-menu" role="group" aria-label="Elegir qué poner en esta casilla" hidden>
                    <span class="ap-add-menu__title">¿Qué quieres poner aquí?</span>
                    <?php $this->render_type_buttons('ap-add-menu__item'); ?>
                </div>
            </div>

            <div class="ap-ad-list">
                <?php
                foreach ($ads as $index => $ad) {
                    $this->render_ad_card($field_name, $index, $ad, $index + 1);
                }
                ?>
            </div>

            <p class="ap-empty"<?php echo $ads ? ' hidden' : ''; ?>>Todavía no hay anuncios. Usa los botones de abajo o haz clic en una casilla de la vista previa.</p>

            <div class="ap-add-wrap">
                <span class="ap-add-wrap__label">Agregar anuncio:</span>
                <?php $this->render_type_buttons('ap-add-ad'); ?>
            </div>

            <script type="text/html" class="ap-ad-template"><?php $this->render_ad_card($field_name, '__INDEX__', [], ''); ?></script>
        </div>
        <?php
    }

    private function get_ad_type_labels() {
        return [
            'image' => ['label' => 'Imagen', 'icon' => 'dashicons-format-image'],
            'video' => ['label' => 'Video', 'icon' => 'dashicons-video-alt3'],
            'youtube' => ['label' => 'YouTube', 'icon' => 'dashicons-youtube'],
        ];
    }

    private function render_type_buttons($class) {
        foreach ($this->get_ad_type_labels() as $type => $info) {
            printf(
                '<button type="button" class="button %1$s" data-type="%2$s"><span class="dashicons %3$s" aria-hidden="true"></span>%4$s</button> ',
                esc_attr($class),
                esc_attr($type),
                esc_attr($info['icon']),
                esc_html($info['label'])
            );
        }
    }

    private function render_ad_card($field_name, $index, $ad = [], $number = '') {
        $grid_columns = $this->get_grid_columns();
        $type = (isset($ad['type']) && in_array($ad['type'], self::AD_TYPES, true)) ? $ad['type'] : 'image';

        $image_id = isset($ad['image_id']) ? absint($ad['image_id']) : 0;
        $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'medium') : '';
        $image_missing = $image_id && !$image_url;

        $video_id = isset($ad['video_id']) ? absint($ad['video_id']) : 0;
        $video_url = $video_id ? wp_get_attachment_url($video_id) : '';
        $video_missing = $video_id && !$video_url;
        $video_mime = $video_url ? (string) get_post_mime_type($video_id) : '';
        $video_size = $video_url ? $this->get_attachment_filesize($video_id) : 0;
        $video_ratio = $video_url ? $this->get_video_ratio($video_id) : 0;

        $youtube_url = isset($ad['youtube_url']) ? (string) $ad['youtube_url'] : '';
        $has_format = isset($ad['format']) && in_array($ad['format'], self::AD_FORMATS, true);
        $format = $has_format ? $ad['format'] : $this->get_default_format($type);
        $button_text = isset($ad['button_text']) ? (string) $ad['button_text'] : '';
        $link = isset($ad['link']) ? (string) $ad['link'] : '';
        $position = isset($ad['position']) ? $this->sanitize_positive_int($ad['position']) : $grid_columns;
        $row = isset($ad['row']) ? $this->sanitize_positive_int($ad['row']) : max(1, (int) ceil($position / $grid_columns));
        $column = isset($ad['column']) ? $this->sanitize_positive_int($ad['column']) : (($position - 1) % $grid_columns) + 1;
        $column = min($column, $grid_columns);
        $start_date = isset($ad['start_date']) ? $this->sanitize_ad_date($ad['start_date']) : '';
        $end_date = isset($ad['end_date']) ? $this->sanitize_ad_date($ad['end_date']) : '';
        $name = $field_name . '[' . $index . ']';

        $formats = [
            'auto' => 'Original del video',
            'product' => 'Como las fotos de productos',
            'vertical' => 'Vertical 9:16',
            'horizontal' => 'Horizontal 16:9',
        ];
        ?>
        <div class="ap-ad-card ap-ad-card--<?php echo esc_attr($type); ?><?php echo ('image' === $type && $image_url) ? ' has-media' : ''; ?>"
             data-image-url="<?php echo esc_url($image_url); ?>"
             data-video-url="<?php echo esc_url($video_url); ?>"
             data-video-mime="<?php echo esc_attr($video_mime); ?>"
             data-video-size="<?php echo esc_attr($video_size); ?>"
             data-video-ratio="<?php echo esc_attr($video_ratio ? $this->css_number($video_ratio) : ''); ?>"
             data-format-touched="<?php echo ($has_format && 'image' !== $type) ? '1' : ''; ?>"<?php echo $image_missing ? ' data-image-missing="1"' : ''; ?><?php echo $video_missing ? ' data-video-missing="1"' : ''; ?>>
            <input type="hidden" class="ap-ad-image-id" name="<?php echo esc_attr($name); ?>[image_id]" value="<?php echo $image_url ? esc_attr($image_id) : ''; ?>">
            <input type="hidden" class="ap-ad-video-id" name="<?php echo esc_attr($name); ?>[video_id]" value="<?php echo $video_url ? esc_attr($video_id) : ''; ?>">

            <div class="ap-ad-card__media">
                <button type="button" class="ap-ad-card__picker ap-media-picker" aria-label="Elegir el contenido del anuncio">
                    <img class="ap-media-img" alt=""<?php echo ('image' === $type && $image_url) ? ' src="' . esc_url($image_url) . '"' : ' hidden'; ?>>
                    <video class="ap-media-video" muted loop playsinline preload="metadata" hidden></video>
                    <span class="ap-media-play" aria-hidden="true" hidden><span class="dashicons dashicons-controls-play"></span></span>
                    <span class="ap-ad-card__placeholder"<?php echo ('image' === $type && $image_url) ? ' hidden' : ''; ?>>
                        <span class="dashicons dashicons-format-image" aria-hidden="true"></span>
                        <span class="ap-placeholder-text">Elegir imagen</span>
                    </span>
                </button>
                <div class="ap-ad-card__media-actions">
                    <button type="button" class="button-link ap-media-change">Cambiar</button>
                    <button type="button" class="button-link ap-media-remove">Quitar</button>
                </div>
            </div>

            <div class="ap-ad-card__body">
                <div class="ap-ad-card__head">
                    <strong class="ap-ad-card__title">Anuncio <span class="ap-ad-number"><?php echo esc_html($number); ?></span></strong>
                    <span class="ap-badge"></span>
                    <button type="button" class="button-link button-link-delete ap-remove-ad"><span class="dashicons dashicons-trash" aria-hidden="true"></span>Eliminar</button>
                </div>

                <div class="ap-type" role="radiogroup" aria-label="Tipo de anuncio">
                    <?php foreach ($this->get_ad_type_labels() as $value => $info) : ?>
                        <label class="ap-type__option<?php echo $type === $value ? ' is-selected' : ''; ?>">
                            <input type="radio" class="ap-ad-type" name="<?php echo esc_attr($name); ?>[type]" value="<?php echo esc_attr($value); ?>" <?php checked($type, $value); ?>>
                            <span class="dashicons <?php echo esc_attr($info['icon']); ?>" aria-hidden="true"></span><?php echo esc_html($info['label']); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="ap-type-help" data-ap-types="video"<?php echo 'video' === $type ? '' : ' hidden'; ?>>Se reproduce solo, sin sonido y en bucle cuando el cliente llega a esa parte de la página.</p>
                <p class="ap-type-help" data-ap-types="youtube"<?php echo 'youtube' === $type ? '' : ' hidden'; ?>>Se muestra la miniatura con un botón de reproducir. El video de YouTube se carga solo cuando el cliente lo toca.</p>

                <p class="ap-ad-card__note" hidden></p>

                <div class="ap-fields">
                    <label class="ap-field ap-field--wide" data-ap-types="youtube"<?php echo 'youtube' === $type ? '' : ' hidden'; ?>>
                        <span class="ap-field__label">Enlace del Short o del video de YouTube</span>
                        <input type="text"
                               inputmode="url"
                               autocomplete="off"
                               class="ap-input ap-ad-youtube"
                               name="<?php echo esc_attr($name); ?>[youtube_url]"
                               value="<?php echo esc_attr($youtube_url); ?>"
                               placeholder="Ej.: https://youtube.com/shorts/…">
                    </label>

                    <div class="ap-field ap-field--wide ap-cover" data-ap-types="video youtube"<?php echo 'image' === $type ? ' hidden' : ''; ?>>
                        <span class="ap-field__label">Portada <em>(opcional)</em></span>
                        <div class="ap-cover__row">
                            <img class="ap-cover__thumb" alt=""<?php echo $image_url ? ' src="' . esc_url($image_url) . '"' : ' hidden'; ?>>
                            <button type="button" class="button button-small ap-cover-select"><?php echo $image_url ? 'Cambiar' : 'Elegir imagen'; ?></button>
                            <button type="button" class="button-link ap-cover-remove"<?php echo $image_url ? '' : ' hidden'; ?>>Quitar</button>
                            <span class="ap-cover__help"
                                  data-text-video="Se ve mientras el video carga."
                                  data-text-youtube="Reemplaza la miniatura de YouTube."></span>
                        </div>
                    </div>

                    <label class="ap-field ap-field--wide">
                        <span class="ap-field__label"><span class="ap-link-label" data-text-default="Enlace al hacer clic" data-text-youtube="Enlace del botón «Ver oferta»"><?php echo 'youtube' === $type ? 'Enlace del botón «Ver oferta»' : 'Enlace al hacer clic'; ?></span> <em>(opcional)</em></span>
                        <input type="text"
                               inputmode="url"
                               autocomplete="off"
                               class="ap-input ap-ad-link"
                               name="<?php echo esc_attr($name); ?>[link]"
                               value="<?php echo esc_attr($link); ?>"
                               placeholder="Ej.: https://tusitio.com/ofertas">
                    </label>

                    <label class="ap-field" data-ap-types="video youtube"<?php echo 'image' === $type ? ' hidden' : ''; ?>>
                        <span class="ap-field__label">Forma</span>
                        <select class="ap-input ap-ad-format" name="<?php echo esc_attr($name); ?>[format]">
                            <?php foreach ($formats as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($format, $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="ap-field" data-ap-types="youtube"<?php echo 'youtube' === $type ? '' : ' hidden'; ?>>
                        <span class="ap-field__label">Texto del botón</span>
                        <input type="text"
                               class="ap-input ap-ad-button-text"
                               name="<?php echo esc_attr($name); ?>[button_text]"
                               value="<?php echo esc_attr($button_text); ?>"
                               maxlength="40"
                               placeholder="Ver oferta">
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

    private function get_attachment_filesize($attachment_id) {
        $meta = wp_get_attachment_metadata($attachment_id);

        if (is_array($meta) && !empty($meta['filesize'])) {
            return (int) $meta['filesize'];
        }

        $file = get_attached_file($attachment_id);

        return ($file && file_exists($file)) ? (int) filesize($file) : 0;
    }

    /**
     * Indica si el anuncio tiene algo que mostrar ahora mismo.
     */
    private function ad_has_media($ad) {
        $type = isset($ad['type']) ? $ad['type'] : 'image';

        if ('video' === $type) {
            return !empty($ad['video_id']) && (bool) wp_get_attachment_url(absint($ad['video_id']));
        }

        if ('youtube' === $type) {
            return !empty($ad['youtube_id']);
        }

        return !empty($ad['image_id']) && (bool) wp_get_attachment_image_url(absint($ad['image_id']), 'thumbnail');
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
            if ($this->ad_has_media($global_ad)) {
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
            'custom' => ['Usar anuncios propios', 'Imágenes, videos y casillas solo para ' . $noun . '.'],
            'none' => ['No mostrar anuncios', 'En ' . $noun . ' no aparecerá ninguna imagen ni video publicitario.'],
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
                <p class="description ap-custom-help">Si no agregas anuncios con imagen o video, o si ninguno está vigente por sus fechas, se mostrarán los anuncios generales.</p>
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
            'window.apAnuncioAdmin = ' . wp_json_encode(['today' => current_time('Y-m-d'), 'productRatio' => round($this->get_product_ratio(), 4)]) . ';',
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
                <p><strong>¿Cómo funciona?</strong> Cada anuncio es una imagen, un video o un video de YouTube que ocupa el lugar de un producto dentro de la grilla de la tienda.</p>
                <ol>
                    <li>Indica cuántos productos por fila muestra tu tienda en computadora.</li>
                    <li>Agrega tus anuncios: elige la imagen o el video, el enlace y la casilla. También puedes hacer clic directamente en la vista previa.</li>
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
                                Mezclar los anuncios en cada visita
                            </label>
                            <p class="description">Las casillas no cambian: solo se reparte al azar qué anuncio, con su enlace, aparece en cada una. Útil si tienes 2 anuncios o más.</p>
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
                    $summary = 'Anuncios propios sin imagen ni video: se muestran los generales.';
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
                return sprintf('Consejo: usa imágenes con la misma proporción que las fotos de tus productos (%1$d × %2$d px, o más grandes en esa proporción) para que la grilla quede pareja. Los videos conviene que duren menos de 15 segundos y pesen menos de 5 MB.', $width, $height);
            }
        }

        return 'Consejo: usa imágenes con la misma proporción que las fotos de tus productos para que la grilla quede pareja. Los videos conviene que duren menos de 15 segundos y pesen menos de 5 MB.';
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
