<?php
if (! defined('ABSPATH')) {
    exit;
}

class NDR_API_Settings_Page
{
    public function __construct()
    {
        add_action('admin_menu', array($this, 'register_settings_menu'), 25);
        add_action('admin_init', array($this, 'handle_actions'));
    }

    public function register_settings_menu()
    {
        add_submenu_page(
            'edit.php?post_type=ndr_api_source',
            __('Veri Gösterimi — Ayarlar', 'ndr-rest-api-to-render'),
            __('Ayarlar', 'ndr-rest-api-to-render'),
            'manage_options',
            'ndr-data-settings',
            array($this, 'render_settings_page')
        );
    }

    public static function get_settings()
    {
        $defaults = array(
            'cache_enabled'       => 0,
            'cache_minutes'       => 10,
            'global_css'          => '',
            'global_js'           => '',
            'default_empty_msg'   => 'Gösterilecek veri bulunamadı.',
            'default_search_ph'   => 'Ara...',
            'fm_root_dir'         => 'wp-content/uploads/ndr-files',
        );
        $saved = get_option('ndr_data_settings', array());
        return wp_parse_args(is_array($saved) ? $saved : array(), $defaults);
    }

    public function handle_actions()
    {
        if (! is_admin() || ! isset($_POST['ndr_settings_nonce'])) {
            return;
        }
        if (! wp_verify_nonce(wp_unslash($_POST['ndr_settings_nonce']), 'ndr_save_settings')) {
            return;
        }
        if (! current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['ndr_clear_cache'])) {
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ndr_api_cache_%' OR option_name LIKE '_transient_timeout_ndr_api_cache_%'");
            wp_redirect(add_query_arg(array('post_type' => 'ndr_api_source', 'page' => 'ndr-data-settings', 'ndr_msg' => 'cache_cleared'), admin_url('edit.php')));
            exit;
        }

        $settings = array(
            'cache_enabled'     => isset($_POST['cache_enabled']) ? 1 : 0,
            'cache_minutes'     => max(1, absint($_POST['cache_minutes'] ?? 10)),
            'global_css'        => wp_unslash($_POST['global_css'] ?? ''),
            'global_js'         => wp_unslash($_POST['global_js'] ?? ''),
            'default_empty_msg' => sanitize_text_field(wp_unslash($_POST['default_empty_msg'] ?? 'Gösterilecek veri bulunamadı.')),
            'default_search_ph' => sanitize_text_field(wp_unslash($_POST['default_search_ph'] ?? 'Ara...')),
            'fm_root_dir'       => trim(sanitize_text_field(wp_unslash($_POST['fm_root_dir'] ?? 'wp-content/uploads/ndr-files'))),
        );
        update_option('ndr_data_settings', $settings);
        wp_redirect(add_query_arg(array('post_type' => 'ndr_api_source', 'page' => 'ndr-data-settings', 'ndr_msg' => 'saved'), admin_url('edit.php')));
        exit;
    }

    public function render_settings_page()
    {
        $s = self::get_settings();
        $msg = isset($_GET['ndr_msg']) ? sanitize_text_field(wp_unslash($_GET['ndr_msg'])) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Veri Gösterimi — Genel Ayarlar', 'ndr-rest-api-to-render'); ?></h1>

            <?php if ($msg === 'saved') : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Ayarlar başarıyla kaydedildi.', 'ndr-rest-api-to-render'); ?></p></div>
            <?php elseif ($msg === 'cache_cleared') : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Tüm API önbelleği temizlendi.', 'ndr-rest-api-to-render'); ?></p></div>
            <?php elseif ($msg === 'tpl_imported') :
                $cnt = absint($_GET['imp_cnt'] ?? 0);
            ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php echo esc_html(sprintf(__('%d adet şablon başarıyla içe aktarıldı.', 'ndr-rest-api-to-render'), $cnt)); ?>
                        &mdash; <a href="<?php echo esc_url(admin_url('edit.php?post_type=ndr_api_template')); ?>"><?php esc_html_e('Şablonları Görüntüle', 'ndr-rest-api-to-render'); ?></a>
                    </p>
                </div>
            <?php endif; ?>
            <?php
            $tpl_err = sanitize_text_field(wp_unslash($_GET['ndr_tpl_err'] ?? ''));
            if ($tpl_err === 'no_file') : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Lütfen bir JSON dosyası seçin.', 'ndr-rest-api-to-render'); ?></p></div>
            <?php elseif ($tpl_err === 'bad_json') : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Geçersiz şablon JSON dosyası.', 'ndr-rest-api-to-render'); ?></p></div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field('ndr_save_settings', 'ndr_settings_nonce'); ?>

                <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px 20px;margin:16px 0;">
                    <h2 style="margin-top:0;"><?php esc_html_e('Önbellek (Cache) Yönetimi', 'ndr-rest-api-to-render'); ?></h2>
                    <p>
                        <label><input type="checkbox" name="cache_enabled" value="1" <?php checked($s['cache_enabled'], 1); ?> /> <strong><?php esc_html_e('Harici API URL yanıtlarını sunucuda önbelleğe al (Transient Cache)', 'ndr-rest-api-to-render'); ?></strong></label>
                    </p>
                    <p>
                        <label for="ndr_cache_minutes"><strong><?php esc_html_e('Önbellek Süresi (Dakika)', 'ndr-rest-api-to-render'); ?></strong></label><br />
                        <input type="number" id="ndr_cache_minutes" name="cache_minutes" min="1" max="1440" value="<?php echo esc_attr($s['cache_minutes']); ?>" style="width:120px;" />
                    </p>
                    <p>
                        <button type="submit" name="ndr_clear_cache" value="1" class="button button-secondary"><?php esc_html_e('Tüm Önbelleği Şimdi Temizle', 'ndr-rest-api-to-render'); ?></button>
                    </p>
                </div>

                <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px 20px;margin:16px 0;">
                    <h2 style="margin-top:0;"><?php esc_html_e('Genel CSS ve Genel JS (Tüm Listelerde Ortak Çalışır)', 'ndr-rest-api-to-render'); ?></h2>
                    <style>.CodeMirror{border:1px solid #8c8f94;border-radius:4px;height:220px;min-height:120px;resize:vertical;overflow:auto !important;font-size:13px;}</style>
                    <p>
                        <label for="ndr_global_css"><strong><?php esc_html_e('Genel CSS', 'ndr-rest-api-to-render'); ?></strong></label>
                        <textarea id="ndr_global_css" name="global_css" rows="8" class="widefat ndr-code-editor" data-mode="css"><?php echo esc_textarea($s['global_css']); ?></textarea>
                    </p>
                    <p>
                        <label for="ndr_global_js"><strong><?php esc_html_e('Genel JS', 'ndr-rest-api-to-render'); ?></strong></label>
                        <textarea id="ndr_global_js" name="global_js" rows="8" class="widefat ndr-code-editor" data-mode="javascript"><?php echo esc_textarea($s['global_js']); ?></textarea>
                    </p>
                </div>

                <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px 20px;margin:16px 0;">
                    <h2 style="margin-top:0;"><?php esc_html_e('Dosya Yöneticisi Ayarları', 'ndr-rest-api-to-render'); ?></h2>
                    <p>
                        <label for="ndr_fm_root_dir"><strong><?php esc_html_e('Kök Dizin (WordPress Ana Dizinine Göre)', 'ndr-rest-api-to-render'); ?></strong></label><br />
                        <input type="text" id="ndr_fm_root_dir" name="fm_root_dir" class="regular-text" style="min-width:360px;font-family:monospace;" value="<?php echo esc_attr($s['fm_root_dir']); ?>" placeholder="wp-content/uploads/ndr-files" />
                        <br /><span class="description"><?php esc_html_e('Örnek: wp-content/uploads/ndr-files veya wp-content/uploads (WordPress ana dizini dışına çıkılamaz).', 'ndr-rest-api-to-render'); ?></span>
                    </p>
                    <div style="background:#f0f6fc;border:1px solid #c3dafe;border-left:4px solid #2271b1;padding:10px 14px;border-radius:4px;font-size:13px;">
                        <strong><?php esc_html_e('Şablonda ve İçerikte Kullanım:', 'ndr-rest-api-to-render'); ?></strong><br />
                        &bull; <?php esc_html_e('Şablon HTML içinde:', 'ndr-rest-api-to-render'); ?> <code>{{root_url}}</code> &rarr; <code><?php echo esc_html(class_exists('NDR_API_File_Manager') ? NDR_API_File_Manager::get_root_url() : ''); ?></code>
                        <br />&nbsp;&nbsp;<em><?php esc_html_e('Örnek:', 'ndr-rest-api-to-render'); ?></em> <code>&lt;img src="{{root_url}}/gorseller/{{resim}}" alt="{{title}}" /&gt;</code> <?php esc_html_e('veya', 'ndr-rest-api-to-render'); ?> <code>&lt;a href="{{root_url}}/{{dosya}}"&gt;İndir&lt;/a&gt;</code><br />
                        &bull; <?php esc_html_e('Kök dizin yolu değişkeni:', 'ndr-rest-api-to-render'); ?> <code>{{root_dir}}</code><br />
                        &bull; <?php esc_html_e('Sayfa/yazı içinde Shortcode:', 'ndr-rest-api-to-render'); ?> <code>[ndr_root_url]</code>
                    </div>
                </div>

                <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px 20px;margin:16px 0;">
                    <h2 style="margin-top:0;"><?php esc_html_e('Varsayılan Metinler', 'ndr-rest-api-to-render'); ?></h2>
                    <p>
                        <label for="ndr_default_empty_msg"><strong><?php esc_html_e('Varsayılan Boş Durum Mesajı', 'ndr-rest-api-to-render'); ?></strong></label>
                        <input type="text" id="ndr_default_empty_msg" name="default_empty_msg" class="regular-text" value="<?php echo esc_attr($s['default_empty_msg']); ?>" />
                    </p>
                    <p>
                        <label for="ndr_default_search_ph"><strong><?php esc_html_e('Varsayılan Arama Placeholder', 'ndr-rest-api-to-render'); ?></strong></label>
                        <input type="text" id="ndr_default_search_ph" name="default_search_ph" class="regular-text" value="<?php echo esc_attr($s['default_search_ph']); ?>" />
                    </p>
                </div>

                <?php submit_button(__('Ayarları Kaydet', 'ndr-rest-api-to-render')); ?>
            </form>

            <div style="background:#fff;border:1px solid #dcdcde;border-left:4px solid #2271b1;border-radius:6px;padding:16px 20px;margin:20px 0;">
                <h2 style="margin-top:0;"><?php esc_html_e('Şablon İçe Aktar (JSON)', 'ndr-rest-api-to-render'); ?></h2>
                <p class="description"><?php esc_html_e('Başka bir siteden veya yedekten dışa aktardığınız tekli ya da toplu .json şablon dosyasını buradan yükleyebilirsiniz.', 'ndr-rest-api-to-render'); ?></p>
                <form method="post" enctype="multipart/form-data" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:10px;">
                    <?php wp_nonce_field('ndr_tpl_import_action', 'ndr_tpl_import_nonce'); ?>
                    <input type="file" name="ndr_tpl_import_file" accept=".json,application/json" required />
                    <button type="submit" class="button button-primary"><?php esc_html_e('Şablonu İçe Aktar', 'ndr-rest-api-to-render'); ?></button>
                </form>
            </div>
        </div>
        <?php
    }
}