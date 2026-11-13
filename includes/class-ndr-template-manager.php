<?php
if (! defined('ABSPATH')) {
    exit;
}

class NDR_API_Template_Manager
{
    public function __construct()
    {
        add_action('init', array($this, 'register_template_post_type'));
        add_action('add_meta_boxes', array($this, 'add_template_meta_boxes'));
        add_action('save_post_ndr_api_template', array($this, 'save_template_meta'), 10, 2);
        add_filter('manage_ndr_api_template_posts_columns', array($this, 'add_template_columns'));
        add_action('manage_ndr_api_template_posts_custom_column', array($this, 'render_template_columns'), 10, 2);
        add_action('wp_ajax_ndr_api_get_template_data', array($this, 'ajax_get_template_data'));
        add_filter('post_row_actions', array($this, 'add_template_row_actions'), 10, 2);
        add_filter('bulk_actions-edit-ndr_api_template', array($this, 'add_bulk_export_action'));
        add_filter('handle_bulk_actions-edit-ndr_api_template', array($this, 'handle_bulk_export_action'), 10, 3);
        add_action('admin_init', array($this, 'handle_template_actions'));
        add_action('admin_notices', array($this, 'render_import_bar_and_notices'));
    }

    public function register_template_post_type()
    {
        $labels = array(
            'name'               => __('Şablonlar', 'ndr-rest-api-to-render'),
            'singular_name'      => __('Şablon', 'ndr-rest-api-to-render'),
            'menu_name'          => __('Şablonlar', 'ndr-rest-api-to-render'),
            'add_new'            => __('Yeni Şablon Ekle', 'ndr-rest-api-to-render'),
            'add_new_item'       => __('Yeni Ortak Şablon Ekle', 'ndr-rest-api-to-render'),
            'edit_item'          => __('Şablonu Düzenle', 'ndr-rest-api-to-render'),
            'new_item'           => __('Yeni Şablon', 'ndr-rest-api-to-render'),
            'view_item'          => __('Şablonu Görüntüle', 'ndr-rest-api-to-render'),
            'search_items'       => __('Şablon Ara', 'ndr-rest-api-to-render'),
            'not_found'          => __('Şablon bulunamadı.', 'ndr-rest-api-to-render'),
            'not_found_in_trash' => __('Çöp kutusunda şablon bulunamadı.', 'ndr-rest-api-to-render'),
        );

        register_post_type('ndr_api_template', array(
            'labels'              => $labels,
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => 'edit.php?post_type=ndr_api_source',
            'show_in_admin_bar'   => false,
            'show_in_nav_menus'   => false,
            'supports'            => array('title'),
            'capability_type'     => 'post',
            'has_archive'         => false,
            'exclude_from_search' => true,
        ));
    }

    public static function get_default_presets()
    {
        return array(
            'list' => array(
                'label' => __('Liste Görünümü (Responsive)', 'ndr-rest-api-to-render'),
                'html'  => "<div class=\"ndr-list-item\">\n  <div class=\"ndr-list-body\">\n    <h3>{{title}}</h3>\n    <p>{{description}}</p>\n  </div>\n  <div class=\"ndr-list-action\">\n    <a href=\"{{url}}\" target=\"_blank\" class=\"ndr-btn\">İncele</a>\n  </div>\n</div>",
                'css'   => ".ndr-list-item{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:16px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;margin-bottom:10px;}\n.ndr-list-item h3{margin:0 0 6px;font-size:17px;}\n.ndr-list-item p{margin:0;color:#4b5563;font-size:14px;}\n.ndr-btn{display:inline-block;padding:8px 16px;background:#1d4ed8;color:#fff;text-decoration:none;border-radius:6px;font-size:13px;white-space:nowrap;}\n@media(max-width:640px){.ndr-list-item{flex-direction:column;align-items:flex-start;}}",
            ),
            'grid' => array(
                'label' => __('Grid / Kart Görünümü (Responsive)', 'ndr-rest-api-to-render'),
                'html'  => "<div class=\"ndr-grid-item\">\n  <h3>{{title}}</h3>\n  <p>{{description}}</p>\n  <a href=\"{{url}}\" target=\"_blank\" class=\"ndr-btn\">Detay</a>\n</div>",
                'css'   => ".ndr-grid-item{border:1px solid #e5e7eb;padding:18px;border-radius:12px;background:#fff;display:flex;flex-direction:column;justify-content:space-between;gap:10px;box-shadow:0 2px 6px rgba(0,0,0,.04);}\n.ndr-grid-item h3{margin:0;font-size:17px;}\n.ndr-grid-item p{margin:0;color:#4b5563;font-size:14px;flex:1;}\n.ndr-btn{display:inline-block;padding:8px 14px;background:#1d4ed8;color:#fff;text-decoration:none;border-radius:6px;font-size:13px;align-self:flex-start;}",
            ),
            'table' => array(
                'label' => __('Tablo Görünümü (Responsive)', 'ndr-rest-api-to-render'),
                'html'  => "<tr>\n  <td data-label=\"Başlık\">{{title}}</td>\n  <td data-label=\"Açıklama\">{{description}}</td>\n  <td data-label=\"Bağlantı\"><a href=\"{{url}}\" target=\"_blank\">Link</a></td>\n</tr>",
                'css'   => ".ndr-table{width:100%;border-collapse:collapse;background:#fff;}\n.ndr-table th,.ndr-table td{padding:11px 14px;border:1px solid #e5e7eb;text-align:left;}\n.ndr-table th{background:#f8fafc;font-weight:600;}\n@media(max-width:640px){.ndr-table thead{display:none;}.ndr-table tr{display:block;margin-bottom:12px;border:1px solid #e5e7eb;border-radius:8px;}.ndr-table td{display:flex;justify-content:space-between;border:none;border-bottom:1px solid #f1f5f9;}.ndr-table td:before{content:attr(data-label);font-weight:600;margin-right:10px;}}",
            ),
            'custom' => array(
                'label' => __('Özel Şablon (Tam Kontrol)', 'ndr-rest-api-to-render'),
                'html'  => "<div class=\"ndr-custom-item\">\n  <h3>{{title}}</h3>\n  <p>{{description}}</p>\n</div>",
                'css'   => ".ndr-custom-item{padding:16px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;}",
            ),
        );
    }

    public static function get_default_config()
    {
        return array(
            'id'                  => 0,
            'title'               => '',
            'layout_type'         => 'grid',
            'template_html'       => '',
            'table_headers'       => '',
            'template_css'        => '',
            'template_js'         => '',
            'controls_position'   => 'top',
            'controls_html_top'   => '',
            'controls_html_bot'   => '',
            'controls_css'        => '',
            'empty_message'       => 'Gösterilecek veri bulunamadı.',
            'grid_min_width'      => '260px',
            'grid_max_width'      => '1fr',
            'enable_lightbox'     => false,
        );
    }

    public static function get_template_config($template_id)
    {
        $post = get_post(absint($template_id));
        if (! $post || 'ndr_api_template' !== $post->post_type) {
            return self::get_default_config();
        }

        $presets     = self::get_default_presets();
        $layout_type = get_post_meta($post->ID, '_ndr_tpl_layout_type', true) ?: 'grid';
        $html        = (string) get_post_meta($post->ID, '_ndr_tpl_html', true);
        $css         = (string) get_post_meta($post->ID, '_ndr_tpl_css', true);

        if ($html === '' && isset($presets[$layout_type])) {
            $html = $presets[$layout_type]['html'];
        }
        if ($css === '' && isset($presets[$layout_type])) {
            $css = $presets[$layout_type]['css'];
        }

        return array(
            'id'                  => $post->ID,
            'title'               => $post->post_title,
            'layout_type'         => $layout_type,
            'template_html'       => $html,
            'table_headers'       => (string) get_post_meta($post->ID, '_ndr_tpl_table_headers', true),
            'template_css'        => $css,
            'template_js'         => (string) get_post_meta($post->ID, '_ndr_tpl_js', true),
            'controls_position'   => get_post_meta($post->ID, '_ndr_tpl_controls_position', true) ?: 'top',
            'controls_html_top'   => (string) get_post_meta($post->ID, '_ndr_tpl_controls_html_top', true),
            'controls_html_bot'   => (string) get_post_meta($post->ID, '_ndr_tpl_controls_html_bot', true),
            'controls_css'        => (string) get_post_meta($post->ID, '_ndr_tpl_controls_css', true),
            'empty_message'       => (string) (get_post_meta($post->ID, '_ndr_tpl_empty_message', true) ?: 'Gösterilecek veri bulunamadı.'),
            'grid_min_width'      => (string) (get_post_meta($post->ID, '_ndr_tpl_grid_min_width', true) ?: '260px'),
            'grid_max_width'      => (string) (get_post_meta($post->ID, '_ndr_tpl_grid_max_width', true) ?: '1fr'),
            'enable_lightbox'     => (bool) get_post_meta($post->ID, '_ndr_tpl_enable_lightbox', true),
        );
    }

    public static function render_shared_template_fields($cfg)
    {
        $presets     = self::get_default_presets();
        $layout_type = $cfg['layout_type'] ?? 'grid';
        if (empty($cfg['template_html']) && isset($presets[$layout_type])) {
            $cfg['template_html'] = $presets[$layout_type]['html'];
        }
        if (empty($cfg['template_css']) && isset($presets[$layout_type])) {
            $cfg['template_css'] = $presets[$layout_type]['css'];
        }
        ?>
        <div class="ndr-tpl-card">
            <h3><?php esc_html_e('Ortak Şablon Tasarımı (Bu Şablonu Kullanan Tüm Kaynaklar İçin Ortaktır)', 'ndr-rest-api-to-render'); ?></h3>
            <div class="ndr-tpl-grid">
                <div>
                    <label for="ndr_tpl_layout_type"><strong><?php esc_html_e('Temel Şablon Tipi', 'ndr-rest-api-to-render'); ?></strong></label>
                    <select id="ndr_tpl_layout_type" name="ndr_tpl_layout_type" class="widefat">
                        <option value="list" <?php selected($layout_type, 'list'); ?>><?php esc_html_e('Liste (Responsive)', 'ndr-rest-api-to-render'); ?></option>
                        <option value="grid" <?php selected($layout_type, 'grid'); ?>><?php esc_html_e('Grid / Kart (Responsive)', 'ndr-rest-api-to-render'); ?></option>
                        <option value="table" <?php selected($layout_type, 'table'); ?>><?php esc_html_e('Tablo (Responsive)', 'ndr-rest-api-to-render'); ?></option>
                        <option value="custom" <?php selected($layout_type, 'custom'); ?>><?php esc_html_e('Özel (Custom)', 'ndr-rest-api-to-render'); ?></option>
                    </select>
                    <p><button type="button" class="button button-secondary" id="ndr_tpl_load_preset" style="margin-top:6px;"><?php esc_html_e('Seçili Tipin Hazır HTML/CSS Taslağını Yükle', 'ndr-rest-api-to-render'); ?></button></p>
                </div>
                <div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                        <div>
                            <label for="ndr_tpl_grid_min_width"><strong><?php esc_html_e('Grid Min Genişlik', 'ndr-rest-api-to-render'); ?></strong></label>
                            <input type="text" id="ndr_tpl_grid_min_width" name="ndr_tpl_grid_min_width" class="widefat" value="<?php echo esc_attr($cfg['grid_min_width'] ?: '260px'); ?>" placeholder="260px" />
                        </div>
                        <div>
                            <label for="ndr_tpl_grid_max_width"><strong><?php esc_html_e('Grid Max Genişlik', 'ndr-rest-api-to-render'); ?></strong></label>
                            <input type="text" id="ndr_tpl_grid_max_width" name="ndr_tpl_grid_max_width" class="widefat" value="<?php echo esc_attr($cfg['grid_max_width'] ?: '1fr'); ?>" placeholder="1fr veya 380px" />
                        </div>
                    </div>
                    <p style="margin-top:8px;">
                        <label for="ndr_tpl_table_headers"><strong><?php esc_html_e('Tablo Başlıkları (Tablo tipi için virgülle ayırın)', 'ndr-rest-api-to-render'); ?></strong></label>
                        <input type="text" id="ndr_tpl_table_headers" name="ndr_tpl_table_headers" class="widefat" value="<?php echo esc_attr($cfg['table_headers']); ?>" placeholder="Başlık, Açıklama, İşlem" />
                    </p>
                    <p style="margin-top:8px;margin-bottom:0;">
                        <label><input type="checkbox" id="ndr_tpl_enable_lightbox" name="ndr_tpl_enable_lightbox" value="1" <?php checked(! empty($cfg['enable_lightbox']), true); ?> /> <strong><?php esc_html_e('Yerleşik Lightbox Kullan (Görsel linklerini pencerede aç)', 'ndr-rest-api-to-render'); ?></strong></label>
                    </p>
                </div>
            </div>

            <p>
                <label for="ndr_tpl_html"><strong><?php esc_html_e('Öğe HTML Şablonu ({{alan_adi}} değişkenleri ile)', 'ndr-rest-api-to-render'); ?></strong></label>
                <textarea id="ndr_tpl_html" name="ndr_tpl_html" rows="9" class="widefat ndr-code-editor" data-mode="htmlmixed"><?php echo esc_textarea($cfg['template_html']); ?></textarea>
                <span class="description"><?php esc_html_e('Hazır sistem değişkenleri: {{root_url}} (Dosya Yöneticisi kök dizin URL\'i, ör. <img src="{{root_url}}/{{resim}}" />), {{root_dir}} (kök dizin yolu).', 'ndr-rest-api-to-render'); ?></span>
            </p>
            <div class="ndr-tpl-grid">
                <div>
                    <label for="ndr_tpl_css"><strong><?php esc_html_e('Şablon CSS', 'ndr-rest-api-to-render'); ?></strong></label>
                    <textarea id="ndr_tpl_css" name="ndr_tpl_css" rows="8" class="widefat ndr-code-editor" data-mode="css"><?php echo esc_textarea($cfg['template_css']); ?></textarea>
                </div>
                <div>
                    <label for="ndr_tpl_js"><strong><?php esc_html_e('Şablon JS (Opsiyonel)', 'ndr-rest-api-to-render'); ?></strong></label>
                    <textarea id="ndr_tpl_js" name="ndr_tpl_js" rows="8" class="widefat ndr-code-editor" data-mode="javascript"><?php echo esc_textarea($cfg['template_js']); ?></textarea>
                </div>
            </div>
        </div>

        <div class="ndr-tpl-card">
            <h3><?php esc_html_e('Kontrol Paneli Yerleşimi ve HTML/CSS Tasarımı (Ortak)', 'ndr-rest-api-to-render'); ?></h3>
            <div class="ndr-tpl-grid">
                <div>
                    <label for="ndr_tpl_controls_position"><strong><?php esc_html_e('Arama & Filtre Konumu', 'ndr-rest-api-to-render'); ?></strong></label>
                    <select id="ndr_tpl_controls_position" name="ndr_tpl_controls_position" class="widefat">
                        <option value="top" <?php selected($cfg['controls_position'], 'top'); ?>><?php esc_html_e('Üst (Top)', 'ndr-rest-api-to-render'); ?></option>
                        <option value="bottom" <?php selected($cfg['controls_position'], 'bottom'); ?>><?php esc_html_e('Alt (Bottom)', 'ndr-rest-api-to-render'); ?></option>
                        <option value="sidebar_left" <?php selected($cfg['controls_position'], 'sidebar_left'); ?>><?php esc_html_e('Sol Kenar Çubuğu (Sidebar Left)', 'ndr-rest-api-to-render'); ?></option>
                        <option value="sidebar_right" <?php selected($cfg['controls_position'], 'sidebar_right'); ?>><?php esc_html_e('Sağ Kenar Çubuğu (Sidebar Right)', 'ndr-rest-api-to-render'); ?></option>
                    </select>
                    <span class="description"><?php esc_html_e('Arama/filtre panelinin sonuç listesine göre konumu. Kenar çubuğu (Sidebar) seçilirse mobilde otomatik olarak üste yerleşir.', 'ndr-rest-api-to-render'); ?></span>
                </div>
                <div>
                    <label for="ndr_tpl_empty_message"><strong><?php esc_html_e('Boş Durum Mesajı', 'ndr-rest-api-to-render'); ?></strong></label>
                    <input type="text" id="ndr_tpl_empty_message" name="ndr_tpl_empty_message" class="widefat" value="<?php echo esc_attr($cfg['empty_message'] ?: 'Gösterilecek veri bulunamadı.'); ?>" />
                    <span class="description"><?php esc_html_e('Arama veya filtre sonucunda eşleşen kayıt bulunamadığında ekranda gösterilecek uyarı metni.', 'ndr-rest-api-to-render'); ?></span>
                </div>
            </div>
            <div class="ndr-tpl-grid">
                <div>
                    <label for="ndr_tpl_controls_html_top"><strong><?php esc_html_e('Kontrol Alanı Üst Özel HTML', 'ndr-rest-api-to-render'); ?></strong></label>
                    <textarea id="ndr_tpl_controls_html_top" name="ndr_tpl_controls_html_top" rows="4" class="widefat ndr-code-editor" data-mode="htmlmixed"><?php echo esc_textarea($cfg['controls_html_top']); ?></textarea>
                    <span class="description"><?php esc_html_e('Arama ve filtre kutularının hemen üstüne eklenir. Başlık, kısa açıklama veya yönlendirme HTML\'i yazabilirsiniz (ör. <h4>Ürün Filtreleme</h4>).', 'ndr-rest-api-to-render'); ?></span>
                </div>
                <div>
                    <label for="ndr_tpl_controls_html_bot"><strong><?php esc_html_e('Kontrol Alanı Alt Özel HTML', 'ndr-rest-api-to-render'); ?></strong></label>
                    <textarea id="ndr_tpl_controls_html_bot" name="ndr_tpl_controls_html_bot" rows="4" class="widefat ndr-code-editor" data-mode="htmlmixed"><?php echo esc_textarea($cfg['controls_html_bot']); ?></textarea>
                    <span class="description"><?php esc_html_e('Filtrelerin ve butonların hemen altına eklenir. Bilgilendirme notu, destek linki veya özel HTML blokları ekleyebilirsiniz.', 'ndr-rest-api-to-render'); ?></span>
                </div>
            </div>
            <p>
                <label for="ndr_tpl_controls_css"><strong><?php esc_html_e('Kontrol Alanı Özel CSS', 'ndr-rest-api-to-render'); ?></strong></label>
                <textarea id="ndr_tpl_controls_css" name="ndr_tpl_controls_css" rows="4" class="widefat ndr-code-editor" data-mode="css"><?php echo esc_textarea($cfg['controls_css']); ?></textarea>
                <span class="description"><?php esc_html_e('Kontrol panelini özelleştirmek için kullanabileceğiniz hazır CSS sınıfları: .ndr-controls-panel, .ndr-controls-form, .ndr-ctrl-group, .ndr-ctrl-label, .ndr-ctrl-input, .ndr-ctrl-select, .ndr-btn-apply, .ndr-btn-reset', 'ndr-rest-api-to-render'); ?></span>
            </p>
        </div>
        <?php
    }

    public static function save_shared_template_fields($template_id)
    {
        $template_id = absint($template_id);
        if (! $template_id || 'ndr_api_template' !== get_post_type($template_id)) {
            return;
        }
        if (! current_user_can('edit_post', $template_id)) {
            return;
        }

        if (isset($_POST['ndr_tpl_layout_type'])) {
            update_post_meta($template_id, '_ndr_tpl_layout_type', sanitize_text_field(wp_unslash($_POST['ndr_tpl_layout_type'])));
        }
        if (isset($_POST['ndr_tpl_grid_min_width'])) {
            $min_w = sanitize_text_field(wp_unslash($_POST['ndr_tpl_grid_min_width']));
            update_post_meta($template_id, '_ndr_tpl_grid_min_width', $min_w ?: '260px');
        }
        if (isset($_POST['ndr_tpl_grid_max_width'])) {
            $max_w = sanitize_text_field(wp_unslash($_POST['ndr_tpl_grid_max_width']));
            update_post_meta($template_id, '_ndr_tpl_grid_max_width', $max_w ?: '1fr');
        }
        update_post_meta($template_id, '_ndr_tpl_enable_lightbox', isset($_POST['ndr_tpl_enable_lightbox']) ? 1 : 0);
        if (isset($_POST['ndr_tpl_table_headers'])) {
            update_post_meta($template_id, '_ndr_tpl_table_headers', sanitize_text_field(wp_unslash($_POST['ndr_tpl_table_headers'])));
        }
        if (isset($_POST['ndr_tpl_html'])) {
            update_post_meta($template_id, '_ndr_tpl_html', wp_unslash($_POST['ndr_tpl_html']));
        }
        if (isset($_POST['ndr_tpl_css'])) {
            update_post_meta($template_id, '_ndr_tpl_css', wp_unslash($_POST['ndr_tpl_css']));
        }
        if (isset($_POST['ndr_tpl_js'])) {
            update_post_meta($template_id, '_ndr_tpl_js', wp_unslash($_POST['ndr_tpl_js']));
        }
        if (isset($_POST['ndr_tpl_controls_position'])) {
            update_post_meta($template_id, '_ndr_tpl_controls_position', sanitize_text_field(wp_unslash($_POST['ndr_tpl_controls_position'])));
        }
        if (isset($_POST['ndr_tpl_controls_html_top'])) {
            update_post_meta($template_id, '_ndr_tpl_controls_html_top', wp_unslash($_POST['ndr_tpl_controls_html_top']));
        }
        if (isset($_POST['ndr_tpl_controls_html_bot'])) {
            update_post_meta($template_id, '_ndr_tpl_controls_html_bot', wp_unslash($_POST['ndr_tpl_controls_html_bot']));
        }
        if (isset($_POST['ndr_tpl_controls_css'])) {
            update_post_meta($template_id, '_ndr_tpl_controls_css', wp_unslash($_POST['ndr_tpl_controls_css']));
        }
        if (isset($_POST['ndr_tpl_empty_message'])) {
            update_post_meta($template_id, '_ndr_tpl_empty_message', sanitize_text_field(wp_unslash($_POST['ndr_tpl_empty_message'])));
        }
    }

    public function add_template_meta_boxes()
    {
        add_meta_box(
            'ndr_api_template_builder',
            __('Merkezi Şablon Tasarımı (HTML / CSS / JS ve Yerleşim)', 'ndr-rest-api-to-render'),
            array($this, 'render_template_meta_box'),
            'ndr_api_template',
            'normal',
            'high'
        );
    }

    public function render_template_meta_box($post)
    {
        wp_nonce_field('ndr_template_meta', 'ndr_template_meta_nonce');
        $cfg = self::get_template_config($post->ID);
        self::render_shared_template_fields($cfg);
        ?>
        <p class="description">
            <?php esc_html_e('Not: Arama, çoklu filtreleme, sıralama ve görünüm değiştirici ayarları her API Kaynağı için ayrı ayrı belirlenir.', 'ndr-rest-api-to-render'); ?>
        </p>
        <?php
    }

    public function save_template_meta($post_id, $post)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (! isset($_POST['ndr_template_meta_nonce']) || ! wp_verify_nonce(wp_unslash($_POST['ndr_template_meta_nonce']), 'ndr_template_meta')) {
            return;
        }
        if (! current_user_can('edit_post', $post_id) || 'ndr_api_template' !== $post->post_type) {
            return;
        }

        self::save_shared_template_fields($post_id);
    }

    public function ajax_get_template_data()
    {
        check_ajax_referer('ndr_api_get_template_nonce', 'security');
        if (! current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => __('Yetkiniz yok.', 'ndr-rest-api-to-render')), 403);
        }
        $template_id = isset($_POST['template_id']) ? absint($_POST['template_id']) : 0;
        $cfg = self::get_template_config($template_id);
        wp_send_json_success($cfg);
    }

    public function add_template_columns($columns)
    {
        $new = array();
        foreach ($columns as $k => $v) {
            $new[$k] = $v;
            if ('title' === $k) {
                $new['tpl_layout']   = __('Şablon Tipi', 'ndr-rest-api-to-render');
                $new['tpl_sources']  = __('Bağlı Kaynaklar', 'ndr-rest-api-to-render');
            }
        }
        return $new;
    }

    public function render_template_columns($column, $post_id)
    {
        if ('tpl_layout' === $column) {
            $type = get_post_meta($post_id, '_ndr_tpl_layout_type', true) ?: 'grid';
            $map  = array('list' => 'Liste', 'grid' => 'Grid', 'table' => 'Tablo', 'custom' => 'Özel');
            echo '<strong>' . esc_html($map[$type] ?? $type) . '</strong>';
        } elseif ('tpl_sources' === $column) {
            $q = new WP_Query(array(
                'post_type'      => 'ndr_api_source',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_key'       => '_ndr_template_id',
                'meta_value'     => $post_id,
            ));
            echo esc_html(sprintf(__('%d kaynak', 'ndr-rest-api-to-render'), $q->found_posts));
        }
    }

    public function add_template_row_actions($actions, $post)
    {
        if ('ndr_api_template' !== $post->post_type || ! current_user_can('edit_posts')) {
            return $actions;
        }
        $dup_url = wp_nonce_url(
            add_query_arg(array('action' => 'ndr_duplicate_template', 'post' => $post->ID), admin_url('edit.php?post_type=ndr_api_template')),
            'ndr_dup_tpl_' . $post->ID
        );
        $exp_url = wp_nonce_url(
            add_query_arg(array('action' => 'ndr_export_template', 'post' => $post->ID), admin_url('edit.php?post_type=ndr_api_template')),
            'ndr_exp_tpl_' . $post->ID
        );
        $actions['ndr_duplicate'] = '<a href="' . esc_url($dup_url) . '">' . esc_html__('Çoğalt', 'ndr-rest-api-to-render') . '</a>';
        $actions['ndr_export']    = '<a href="' . esc_url($exp_url) . '">' . esc_html__('Dışa Aktar (JSON)', 'ndr-rest-api-to-render') . '</a>';
        return $actions;
    }

    public function add_bulk_export_action($bulk_actions)
    {
        $bulk_actions['ndr_bulk_export_templates'] = __('Seçilenleri Dışa Aktar (JSON)', 'ndr-rest-api-to-render');
        return $bulk_actions;
    }

    public function handle_bulk_export_action($redirect_to, $doaction, $post_ids)
    {
        if ($doaction !== 'ndr_bulk_export_templates' || empty($post_ids)) {
            return $redirect_to;
        }
        $bundle = array(
            'plugin'         => 'NDR-DataList',
            'schema_version' => 1,
            'exported_at'    => gmdate('c'),
            'templates'      => array(),
        );
        foreach ($post_ids as $pid) {
            $cfg = self::get_template_config($pid);
            if (! empty($cfg['id'])) {
                $bundle['templates'][] = $this->build_export_payload($cfg);
            }
        }
        $filename = 'ndr-templates-bundle-' . date('Y-m-d_His') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo wp_json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function build_export_payload($cfg)
    {
        return array(
            'title'             => $cfg['title'],
            'layout_type'       => $cfg['layout_type'],
            'grid_min_width'    => $cfg['grid_min_width'],
            'grid_max_width'    => $cfg['grid_max_width'],
            'enable_lightbox'   => ! empty($cfg['enable_lightbox']) ? 1 : 0,
            'table_headers'     => $cfg['table_headers'],
            'template_html'     => $cfg['template_html'],
            'template_css'      => $cfg['template_css'],
            'template_js'       => $cfg['template_js'],
            'controls_position' => $cfg['controls_position'],
            'controls_html_top' => $cfg['controls_html_top'],
            'controls_html_bot' => $cfg['controls_html_bot'],
            'controls_css'      => $cfg['controls_css'],
            'empty_message'     => $cfg['empty_message'],
        );
    }

    public static function create_template_from_payload($data, $title_override = '')
    {
        $title = $title_override !== '' ? $title_override : sanitize_text_field($data['title'] ?? 'İçe Aktarılan Şablon');
        $new_id = wp_insert_post(array(
            'post_type'   => 'ndr_api_template',
            'post_title'  => $title,
            'post_status' => 'publish',
        ));
        if (is_wp_error($new_id) || ! $new_id) {
            return $new_id;
        }
        $meta_map = array(
            'layout_type'       => '_ndr_tpl_layout_type',
            'grid_min_width'    => '_ndr_tpl_grid_min_width',
            'grid_max_width'    => '_ndr_tpl_grid_max_width',
            'enable_lightbox'   => '_ndr_tpl_enable_lightbox',
            'table_headers'     => '_ndr_tpl_table_headers',
            'template_html'     => '_ndr_tpl_html',
            'template_css'      => '_ndr_tpl_css',
            'template_js'       => '_ndr_tpl_js',
            'controls_position' => '_ndr_tpl_controls_position',
            'controls_html_top' => '_ndr_tpl_controls_html_top',
            'controls_html_bot' => '_ndr_tpl_controls_html_bot',
            'controls_css'      => '_ndr_tpl_controls_css',
            'empty_message'     => '_ndr_tpl_empty_message',
        );
        foreach ($meta_map as $key => $meta_key) {
            if (array_key_exists($key, $data)) {
                update_post_meta($new_id, $meta_key, $data[$key]);
            }
        }
        return $new_id;
    }

    public function handle_template_actions()
    {
        if (! is_admin() || ! current_user_can('edit_posts')) {
            return;
        }

        // Duplicate template
        if (isset($_GET['action'], $_GET['post']) && $_GET['action'] === 'ndr_duplicate_template') {
            $post_id = absint($_GET['post']);
            check_admin_referer('ndr_dup_tpl_' . $post_id);
            $cfg = self::get_template_config($post_id);
            if (! empty($cfg['id'])) {
                $payload = $this->build_export_payload($cfg);
                $new_id  = self::create_template_from_payload($payload, $cfg['title'] . ' (Kopya)');
                wp_safe_redirect(add_query_arg(array('post_type' => 'ndr_api_template', 'ndr_tpl_msg' => 'duplicated', 'new_id' => $new_id), admin_url('edit.php')));
                exit;
            }
        }

        // Export single template
        if (isset($_GET['action'], $_GET['post']) && $_GET['action'] === 'ndr_export_template') {
            $post_id = absint($_GET['post']);
            check_admin_referer('ndr_exp_tpl_' . $post_id);
            $cfg = self::get_template_config($post_id);
            if (! empty($cfg['id'])) {
                $bundle = array(
                    'plugin'         => 'NDR-DataList',
                    'schema_version' => 1,
                    'exported_at'    => gmdate('c'),
                    'templates'      => array($this->build_export_payload($cfg)),
                );
                $slug = sanitize_title($cfg['title'] ?: 'sablon-' . $post_id);
                $filename = 'ndr-template-' . $slug . '-' . date('Y-m-d') . '.json';
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                echo wp_json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        // Import templates from JSON file
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ndr_tpl_import_nonce'])) {
            check_admin_referer('ndr_tpl_import_action', 'ndr_tpl_import_nonce');
            if (empty($_FILES['ndr_tpl_import_file']['tmp_name'])) {
                wp_safe_redirect(add_query_arg(array('post_type' => 'ndr_api_source', 'page' => 'ndr-data-settings', 'ndr_tpl_err' => 'no_file'), admin_url('edit.php')));
                exit;
            }
            $raw = file_get_contents($_FILES['ndr_tpl_import_file']['tmp_name']);
            $json = json_decode((string) $raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($json)) {
                wp_safe_redirect(add_query_arg(array('post_type' => 'ndr_api_source', 'page' => 'ndr-data-settings', 'ndr_tpl_err' => 'bad_json'), admin_url('edit.php')));
                exit;
            }

            $list = array();
            if (! empty($json['templates']) && is_array($json['templates'])) {
                $list = $json['templates'];
            } elseif (isset($json['layout_type']) || isset($json['template_html'])) {
                $list = array($json);
            }

            $imported = 0;
            foreach ($list as $tpl_data) {
                if (is_array($tpl_data)) {
                    $res = self::create_template_from_payload($tpl_data);
                    if (! is_wp_error($res) && $res) {
                        $imported++;
                    }
                }
            }
            wp_safe_redirect(add_query_arg(array('post_type' => 'ndr_api_source', 'page' => 'ndr-data-settings', 'ndr_msg' => 'tpl_imported', 'imp_cnt' => $imported), admin_url('edit.php')));
            exit;
        }
    }

    public function render_import_bar_and_notices()
    {
        global $typenow, $pagenow;
        if ('edit.php' !== $pagenow || 'ndr_api_template' !== $typenow) {
            return;
        }

        $msg = sanitize_text_field(wp_unslash($_GET['ndr_tpl_msg'] ?? ''));
        $err = sanitize_text_field(wp_unslash($_GET['ndr_tpl_err'] ?? ''));
        if ($msg === 'duplicated') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Şablon başarıyla çoğaltıldı.', 'ndr-rest-api-to-render') . '</p></div>';
        } elseif ($msg === 'imported') {
            $cnt = absint($_GET['imp_cnt'] ?? 0);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('%d adet şablon başarıyla içe aktarıldı.', 'ndr-rest-api-to-render'), $cnt)) . '</p></div>';
        }
        if ($err === 'no_file') {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Lütfen bir JSON dosyası seçin.', 'ndr-rest-api-to-render') . '</p></div>';
        } elseif ($err === 'bad_json') {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Geçersiz şablon JSON dosyası.', 'ndr-rest-api-to-render') . '</p></div>';
        }

    }
}