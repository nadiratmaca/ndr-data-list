<?php

/**
 * Plugin Name: NDR-DataList
 * Description: Multiple API data sources with templates, CSS, JS and shortcodes.
 * Version: 1.3.4
 * Author: Nadir Atmaca
 * Text Domain: ndr-rest-api-to-render
 */

if (! defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/class-ndr-template-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ndr-frontend-renderer.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ndr-data-source-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ndr-settings-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ndr-file-manager.php';

if (! class_exists('NDR_REST_API_To_Render')) {
    class NDR_REST_API_To_Render
    {
        public function __construct()
        {
            add_action('init', array($this, 'register_post_type'));
            add_action('admin_menu', array($this, 'organize_admin_menu'), 99);
            add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
            add_action('save_post_ndr_api_source', array($this, 'save_source_meta'), 10, 2);
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
            add_action('admin_head-edit.php', array($this, 'add_admin_css'));
            add_action('admin_footer-edit.php', array($this, 'add_admin_preview_modal'));
            add_action('wp_ajax_ndr_api_preview_source', array($this, 'ajax_preview_source'));
            add_filter('manage_ndr_api_source_posts_columns', array($this, 'add_shortcode_column'));
            add_action('manage_ndr_api_source_posts_custom_column', array($this, 'render_shortcode_column'), 10, 2);
            add_shortcode('ndr_api_renderer', array($this, 'render_shortcode'));
            add_action('before_delete_post', array($this, 'cleanup_deleted_source'));
        }

        public function register_post_type()
        {
            $labels = array(
                'name'               => __('Listeler', 'ndr-rest-api-to-render'),
                'singular_name'      => __('Liste', 'ndr-rest-api-to-render'),
                'all_items'          => __('Listeler', 'ndr-rest-api-to-render'),
                'add_new'            => __('Yeni Liste Ekle', 'ndr-rest-api-to-render'),
                'add_new_item'       => __('Yeni Liste Ekle', 'ndr-rest-api-to-render'),
                'edit_item'          => __('Listeyi Düzenle', 'ndr-rest-api-to-render'),
                'new_item'           => __('Yeni Liste', 'ndr-rest-api-to-render'),
                'view_item'          => __('Listeyi Görüntüle', 'ndr-rest-api-to-render'),
                'search_items'       => __('Liste Ara', 'ndr-rest-api-to-render'),
                'not_found'          => __('Liste bulunamadı.', 'ndr-rest-api-to-render'),
                'not_found_in_trash' => __('Çöp kutusunda liste bulunamadı.', 'ndr-rest-api-to-render'),
                'menu_name'          => __('Veri Gösterimi', 'ndr-rest-api-to-render'),
            );

            $args = array(
                'labels' => $labels,
                'public' => false,
                'show_ui' => true,
                'show_in_menu' => true,
                'show_in_admin_bar' => false,
                'supports' => array('title'),
                'capability_type' => 'post',
                'has_archive' => false,
                'exclude_from_search' => true,
                'menu_icon' => 'dashicons-admin-links',
            );

            register_post_type('ndr_api_source', $args);
        }

        public function organize_admin_menu()
        {
            global $submenu;
            $parent = 'edit.php?post_type=ndr_api_source';
            remove_submenu_page($parent, 'post-new.php?post_type=ndr_api_source');

            if (isset($submenu[$parent]) && is_array($submenu[$parent])) {
                $order = array(
                    'edit.php?post_type=gs_sheet_manager' => 1,
                    'edit.php?post_type=ndr_api_source'   => 2,
                    'edit.php?post_type=ndr_api_template' => 3,
                    'ndr-file-manager'                    => 4,
                    'ndr-data-settings'                   => 5,
                );
                usort($submenu[$parent], function ($a, $b) use ($order) {
                    $pa = $order[$a[2]] ?? 99;
                    $pb = $order[$b[2]] ?? 99;
                    return $pa - $pb;
                });
            }
        }

        public function add_meta_boxes()
        {
            add_meta_box(
                'ndr_api_source_settings',
                __('Kaynak Ayarları', 'ndr-rest-api-to-render'),
                array($this, 'render_meta_box'),
                'ndr_api_source',
                'normal',
                'high'
            );
        }

        public function enqueue_admin_assets($hook)
        {
            global $typenow;

            $is_settings_page = (isset($_GET['page']) && in_array($_GET['page'], array('ndr-data-settings', 'ndr-file-manager'), true));
            if (! $is_settings_page) {
                if (! in_array($typenow, array('ndr_api_source', 'ndr_api_template'), true)) {
                    return;
                }
                if ('post.php' !== $hook && 'post-new.php' !== $hook) {
                    return;
                }
            }

            if (function_exists('wp_enqueue_code_editor')) {
                wp_enqueue_code_editor(array('type' => 'text/html'));
                wp_enqueue_code_editor(array('type' => 'text/css'));
                wp_enqueue_code_editor(array('type' => 'application/javascript'));
            }

            wp_enqueue_script('wp-codemirror');
            wp_enqueue_style('wp-codemirror');

            $presets_json   = wp_json_encode(NDR_API_Template_Manager::get_default_presets());
            $get_tpl_nonce  = wp_create_nonce('ndr_api_get_template_nonce');

            $editor_script = <<<JS
jQuery(function($){
    window.ndrCodeEditors = window.ndrCodeEditors || {};
    var presets = {$presets_json};

    function initNdrCodeEditors() {
        if (!window.wp || !wp.codeEditor || !wp.codeEditor.initialize) return;
        $('.ndr-code-editor').each(function(){
            var \$ta = $(this);
            var id = \$ta.attr('id');
            if (!id || window.ndrCodeEditors[id]) return;
            var mode = \$ta.data('mode') || 'htmlmixed';
            var settings = wp.codeEditor.defaultSettings ? $.extend(true, {}, wp.codeEditor.defaultSettings) : {};
            settings.codemirror = $.extend({}, settings.codemirror, {
                mode: mode,
                lineNumbers: true,
                matchBrackets: true,
                autoCloseBrackets: true,
                autoCloseTags: true,
                indentUnit: 2,
                tabSize: 2,
                lineWrapping: true
            });
            var editor = wp.codeEditor.initialize(\$ta, settings);
            if (editor && editor.codemirror) {
                editor.codemirror.on('change', function(cm){
                    cm.save();
                });
                window.ndrCodeEditors[id] = editor.codemirror;
            }
        });
    }

    window.ndrSetEditorVal = function(id, val) {
        val = val || '';
        $('#' + id).val(val);
        if (window.ndrCodeEditors && window.ndrCodeEditors[id]) {
            window.ndrCodeEditors[id].setValue(val);
            window.ndrCodeEditors[id].save();
            setTimeout(function(){ window.ndrCodeEditors[id].refresh(); }, 50);
        }
    };

    initNdrCodeEditors();

    $(document).on('click', '#ndr_tpl_load_preset', function(){
        var type = $('#ndr_tpl_layout_type').val();
        if (presets[type] && confirm('Mevcut HTML ve CSS alanları seçilen şablon taslağı ile değiştirilsin mi?')) {
            window.ndrSetEditorVal('ndr_tpl_html', presets[type].html);
            window.ndrSetEditorVal('ndr_tpl_css', presets[type].css);
        }
    });

    // When Ortak Şablon dropdown changes on ndr_api_source, load its shared fields via AJAX
    $(document).on('change', '#ndr_template_id', function(){
        var tplId = parseInt($(this).val(), 10) || 0;
        var \$box = $('#ndr_source_shared_template_box');
        var \$empty = $('#ndr_source_no_template_notice');
        if (!tplId) {
            \$box.hide();
            \$empty.show();
            return;
        }
        \$empty.hide();
        \$box.show().css('opacity', '0.6');
        $.post(ajaxurl, {
            action: 'ndr_api_get_template_data',
            security: '{$get_tpl_nonce}',
            template_id: tplId
        }).done(function(res){
            \$box.css('opacity', '1');
            if (res && res.success && res.data) {
                var d = res.data;
                $('#ndr_tpl_layout_type').val(d.layout_type || 'grid');
                $('#ndr_tpl_grid_min_width').val(d.grid_min_width || '260px');
                $('#ndr_tpl_grid_max_width').val(d.grid_max_width || '1fr');
                $('#ndr_tpl_enable_lightbox').prop('checked', !!d.enable_lightbox);
                $('#ndr_tpl_table_headers').val(d.table_headers || '');
                $('#ndr_tpl_controls_position').val(d.controls_position || 'top');
                $('#ndr_tpl_empty_message').val(d.empty_message || '');
                window.ndrSetEditorVal('ndr_tpl_html', d.template_html || '');
                window.ndrSetEditorVal('ndr_tpl_css', d.template_css || '');
                window.ndrSetEditorVal('ndr_tpl_js', d.template_js || '');
                window.ndrSetEditorVal('ndr_tpl_controls_html_top', d.controls_html_top || '');
                window.ndrSetEditorVal('ndr_tpl_controls_html_bot', d.controls_html_bot || '');
                window.ndrSetEditorVal('ndr_tpl_controls_css', d.controls_css || '');
            }
        }).fail(function(){
            \$box.css('opacity', '1');
        });
    });

    // API URL JSON key preview on ndr_api_source
    var previewBox = $('#ndr_api_variable_preview');
    if (previewBox.length) {
        function collectKeys(obj, prefix, keys) {
            if ($.isArray(obj)) {
                if (obj.length > 0) collectKeys(obj[0], prefix, keys);
                return;
            }
            if (obj && typeof obj === 'object') {
                $.each(obj, function(k, v){
                    var path = prefix ? prefix + '.' + k : k;
                    if (v && typeof v === 'object') {
                        collectKeys(v, path, keys);
                    } else {
                        keys.push(path);
                    }
                });
            }
        }
        function renderPreview(url) {
            if (!url) {
                previewBox.html('API URL girildiğinde kullanılabilir JSON değişkenleri burada görünecek.');
                return;
            }
            previewBox.html('API kontrol ediliyor...');
            fetch(url).then(function(r){
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            }).then(function(data){
                var items = $.isArray(data) ? data : (data.items || data.data || data.results || [data]);
                var keys = [];
                if (items.length) collectKeys(items[0], '', keys);
                var uniqueKeys = keys.filter(function(v, i, s){ return s.indexOf(v) === i; });
                previewBox.html('<strong>Kullanılabilir JSON Alanları:</strong><pre style="margin-top:6px;">' + $('<div />').text(JSON.stringify(uniqueKeys.slice(0, 50), null, 2)).html() + '</pre>');
            }).catch(function(err){
                previewBox.html('<span style="color:#b91c1c;">API okunamadı: ' + $('<div />').text(err.message || err).html() + '</span>');
            });
        }
        $('#ndr_api_url').on('change', function(){ renderPreview($(this).val()); });
        renderPreview($('#ndr_api_url').val());
    }
});
JS;

            wp_add_inline_script('code-editor', $editor_script);
        }
        private function get_template_presets($template_mode = 'preset_cards')
        {
            $presets = array(
                'preset_cards' => array(
                    'html' => '<div class="ndr-card"><h3>{{title}}</h3><p>{{description}}</p><a href="{{url}}" target="_blank">Detay</a></div>',
                    'css' => '.ndr-card{border:1px solid #e5e7eb;padding:16px;border-radius:12px;margin-bottom:12px;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.04);}.ndr-api-controls{display:flex;gap:10px;margin-bottom:12px;flex-wrap:wrap;}.ndr-api-controls input,.ndr-api-controls select{padding:8px 10px;border:1px solid #d0d7de;border-radius:8px;min-width:220px;}',
                    'js' => '',
                ),
                'preset_grid' => array(
                    'html' => '<div class="ndr-grid-item"><h3>{{title}}</h3><p>{{description}}</p><a href="{{url}}" target="_blank">İncele</a></div>',
                    'css' => '.ndr-grid-item{border:1px solid #e5e7eb;padding:16px;border-radius:12px;background:#fff;}.ndr-api-results{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;}',
                    'js' => '',
                ),
                'preset_table' => array(
                    'html' => '<tr><td>{{title}}</td><td>{{description}}</td><td><a href="{{url}}" target="_blank">Link</a></td></tr>',
                    'css' => 'table{width:100%;border-collapse:collapse;}td,th{padding:10px 12px;border:1px solid #e5e7eb;}.ndr-api-results{overflow-x:auto;}',
                    'js' => '',
                ),
            );

            if (isset($presets[$template_mode])) {
                return $presets[$template_mode];
            }

            return $presets['preset_cards'];
        }

        public function render_meta_box($post)
        {
            wp_nonce_field('ndr_source_meta', 'ndr_source_meta_nonce');

            $data_source_id = absint(get_post_meta($post->ID, '_ndr_data_source_id', true));
            $api_url        = get_post_meta($post->ID, '_ndr_api_url', true);
            $data_sources   = get_posts(array('post_type' => 'gs_sheet_manager', 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC'));
            $pre_filter    = (string) get_post_meta($post->ID, '_ndr_pre_filter', true);
            $template_id   = absint(get_post_meta($post->ID, '_ndr_template_id', true));
            $field_map_raw = get_post_meta($post->ID, '_ndr_field_map', true);
            $field_map     = is_array($field_map_raw) ? $field_map_raw : (json_decode((string) $field_map_raw, true) ?: array());
            $templates     = get_posts(array('post_type' => 'ndr_api_template', 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC'));

            $tpl_cfg = $template_id ? NDR_API_Template_Manager::get_template_config($template_id) : NDR_API_Template_Manager::get_default_config();

            // Per-source search / filter / sort / view settings (with backward compat)
            $has_src_controls = (bool) get_post_meta($post->ID, '_ndr_src_controls_saved', true);
            if ($has_src_controls) {
                $enable_search      = (bool) get_post_meta($post->ID, '_ndr_src_enable_search', true);
                $search_scope       = get_post_meta($post->ID, '_ndr_src_search_scope', true) ?: 'all';
                $search_fields      = (string) get_post_meta($post->ID, '_ndr_src_search_fields', true);
                $search_placeholder = (string) (get_post_meta($post->ID, '_ndr_src_search_placeholder', true) ?: 'Ara...');
                $enable_filters     = (bool) get_post_meta($post->ID, '_ndr_src_enable_filters', true);
                $filters_raw        = get_post_meta($post->ID, '_ndr_src_filters', true);
                $filters            = is_array($filters_raw) ? $filters_raw : (json_decode((string) $filters_raw, true) ?: array());
                $enable_sort        = (bool) get_post_meta($post->ID, '_ndr_src_enable_sort', true);
                $sort_field         = (string) (get_post_meta($post->ID, '_ndr_src_sort_field', true) ?: 'title');
                $sort_default_dir   = get_post_meta($post->ID, '_ndr_src_sort_default_dir', true) ?: 'none';
                $enable_view_switch = (bool) get_post_meta($post->ID, '_ndr_src_enable_view_switch', true);
                $show_apply_btn     = (bool) get_post_meta($post->ID, '_ndr_src_show_apply_btn', true);
                $show_reset_btn     = (bool) get_post_meta($post->ID, '_ndr_src_show_reset_btn', true);
                $show_stats         = (bool) get_post_meta($post->ID, '_ndr_src_show_stats', true);
                $allowed_views      = get_post_meta($post->ID, '_ndr_src_allowed_views', true);
                if (! is_array($allowed_views) || empty($allowed_views)) {
                    $allowed_views = array('list', 'grid', 'table');
                }
            } else {
                $enable_search      = (bool) get_post_meta($post->ID, '_ndr_enable_search', true);
                $search_scope       = 'all';
                $search_fields      = '';
                $search_placeholder = 'Ara...';
                $legacy_filter      = get_post_meta($post->ID, '_ndr_filter_field', true);
                $enable_filters     = ! empty($legacy_filter);
                $filters            = ! empty($legacy_filter) ? array(array('field' => $legacy_filter, 'label' => $legacy_filter, 'type' => 'dropdown')) : array();
                $enable_sort        = false;
                $sort_field         = 'title';
                $sort_default_dir   = 'none';
                $enable_view_switch = false;
                $show_apply_btn     = true;
                $show_reset_btn     = true;
                $show_stats         = true;
                $allowed_views      = array('list', 'grid', 'table');
            }
            ?>
            <style>
                .ndr-tpl-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:14px;}
                .ndr-tpl-card{background:#f9fafb;border:1px solid #dcdcde;border-radius:6px;padding:14px 16px;margin-bottom:14px;}
                .ndr-tpl-card h3{margin:0 0 12px;padding-bottom:8px;border-bottom:1px solid #e2e4e7;font-size:14px;}
                .ndr-filter-row{display:grid;grid-template-columns:1.2fr 1.2fr 1fr auto;gap:8px;align-items:center;margin-bottom:8px;background:#fff;padding:8px;border:1px solid #dcdcde;border-radius:4px;}
                .CodeMirror{border:1px solid #8c8f94;border-radius:4px;height:210px;min-height:120px;resize:vertical;overflow:auto !important;font-size:13px;}
                @media(max-width:782px){.ndr-tpl-grid{grid-template-columns:1fr;}.ndr-filter-row{grid-template-columns:1fr;}}
            </style>

            <div class="ndr-tpl-card">
                <h3><?php esc_html_e('1. API Kaynağı ve Kısa Kod', 'ndr-rest-api-to-render'); ?></h3>
                <div class="ndr-tpl-grid">
                    <div>
                        <label for="ndr_data_source_id"><strong><?php esc_html_e('Veri Kaynağı Bağla (Merkezi Kaynak)', 'ndr-rest-api-to-render'); ?></strong></label>
                        <?php
                        $ds_urls = array();
                        $ud = wp_upload_dir();
                        foreach ($data_sources as $ds) {
                            $ds_urls[$ds->ID] = trailingslashit($ud['baseurl']) . 'gs-api-storage/' . absint($ds->ID) . '/data.json';
                        }
                        ?>
                        <select id="ndr_data_source_id" name="ndr_data_source_id" class="widefat">
                            <option value="0"><?php esc_html_e('— Harici API URL Kullan —', 'ndr-rest-api-to-render'); ?></option>
                            <?php foreach ($data_sources as $ds) : ?>
                                <option value="<?php echo esc_attr($ds->ID); ?>" data-json-url="<?php echo esc_attr($ds_urls[$ds->ID]); ?>" <?php selected($data_source_id, $ds->ID); ?>>
                                    <?php echo esc_html($ds->post_title . ' (ID: ' . $ds->ID . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p style="margin-top:8px;margin-bottom:0;">
                            <label for="ndr_api_url"><strong><?php esc_html_e('API URL (Harici veya Otomatik)', 'ndr-rest-api-to-render'); ?></strong></label>
                            <input type="url" id="ndr_api_url" name="ndr_api_url" value="<?php echo esc_attr($api_url); ?>" class="widefat" />
                        </p>
                    </div>
                    <div>
                        <label><strong><?php esc_html_e('Shortcode', 'ndr-rest-api-to-render'); ?></strong></label>
                        <input type="text" class="widefat" value="<?php echo esc_attr('[ndr_api_renderer id="' . $post->ID . '"]'); ?>" readonly onclick="this.select();" style="font-family:monospace;background:#fff;" />
                        <p class="description" style="margin-top:6px;">
                            <?php esc_html_e('Bir Veri Kaynağı seçtiğinizde veriler doğrudan sunucudaki hızlı JSON dosyasından okunur. Aynı veri kaynağını birden fazla görünümde kullanabilirsiniz.', 'ndr-rest-api-to-render'); ?>
                        </p>
                    </div>
                </div>
                <script>
                jQuery(function($){
                    $('#ndr_data_source_id').on('change', function(){
                        var url = $(this).find('option:selected').data('json-url') || '';
                        if (url) {
                            $('#ndr_api_url').val(url).trigger('change');
                        }
                    });
                });
                </script>
                <p style="margin-top:10px;">
                    <label for="ndr_pre_filter"><strong><?php esc_html_e('Ön Filtreleme Kuralı (Opsiyonel — API Verisini Süz)', 'ndr-rest-api-to-render'); ?></strong></label>
                    <input type="text" id="ndr_pre_filter" name="ndr_pre_filter" value="<?php echo esc_attr($pre_filter); ?>" class="widefat" placeholder="örnek: marka=dekorama,dekorflor&desen=kareli" style="font-family:monospace;" />
                    <span class="description"><?php esc_html_e('API kaynağından gelen tüm veriler arasından yalnızca bu kurala uyanlar listelenir. Birden fazla değer için virgül (,), birden fazla alan için & kullanın.', 'ndr-rest-api-to-render'); ?></span>
                </p>
                <?php
                $raw_count = null;
                $flt_count = null;
                $raw_items_tmp = array();
                if ($data_source_id > 0) {
                    $ud_tmp = wp_upload_dir();
                    $lf = trailingslashit($ud_tmp['basedir']) . 'gs-api-storage/' . $data_source_id . '/data.json';
                    if (! file_exists($lf)) {
                        $lf = trailingslashit($ud_tmp['basedir']) . 'gs-api-storage/' . $data_source_id . '/latest.json';
                    }
                    if (file_exists($lf)) {
                        $dec_tmp = json_decode((string) file_get_contents($lf), true);
                        if (is_array($dec_tmp)) {
                            $raw_items_tmp = $this->php_normalize_data($dec_tmp);
                            $raw_count = count($raw_items_tmp);
                        }
                    }
                } elseif (! empty($api_url)) {
                    $resp_tmp = wp_remote_get($api_url, array('timeout' => 5, 'sslverify' => false));
                    if (! is_wp_error($resp_tmp) && wp_remote_retrieve_response_code($resp_tmp) === 200) {
                        $dec_tmp = json_decode(wp_remote_retrieve_body($resp_tmp), true);
                        if (is_array($dec_tmp)) {
                            $raw_items_tmp = $this->php_normalize_data($dec_tmp);
                            $raw_count = count($raw_items_tmp);
                        }
                    }
                }
                if ($raw_count !== null) {
                    $fmap_tmp = is_array($field_map) ? $field_map : array();
                    $mapped_tmp = NDR_API_Frontend_Renderer::apply_field_map($raw_items_tmp, $fmap_tmp);
                    $flt_items_tmp = ($pre_filter !== '') ? NDR_API_Frontend_Renderer::apply_pre_filters($mapped_tmp, $pre_filter) : $mapped_tmp;
                    $flt_count = count($flt_items_tmp);
                }
                ?>
                <?php if ($raw_count !== null) : ?>
                    <div style="display:flex;gap:16px;flex-wrap:wrap;background:#fff;border:1px solid #dcdcde;border-left:4px solid #00a32a;border-radius:4px;padding:10px 14px;margin:10px 0;">
                        <span><strong><?php esc_html_e('Ham Veri:', 'ndr-rest-api-to-render'); ?></strong> <?php echo esc_html($raw_count); ?> <?php esc_html_e('kayıt', 'ndr-rest-api-to-render'); ?></span>
                        <?php if ($pre_filter !== '') : ?>
                            <span><strong><?php esc_html_e('Ön Filtre Sonrası Listelenen:', 'ndr-rest-api-to-render'); ?></strong> <span style="color:#008a20;font-weight:700;"><?php echo esc_html($flt_count); ?> <?php esc_html_e('kayıt', 'ndr-rest-api-to-render'); ?></span></span>
                        <?php else : ?>
                            <span><strong><?php esc_html_e('Listelenen:', 'ndr-rest-api-to-render'); ?></strong> <?php echo esc_html($flt_count); ?> <?php esc_html_e('kayıt (Ön filtre yok)', 'ndr-rest-api-to-render'); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div id="ndr_api_variable_preview" class="ndr-api-variable-preview">
                    <?php esc_html_e('API URL girildiğinde değişkenler burada görünecek.', 'ndr-rest-api-to-render'); ?>
                </div>
            </div>

            <div class="ndr-tpl-card" style="background:#f0f6fc;border-color:#c3dafe;border-left:4px solid #1d4ed8;">
                <h3><?php esc_html_e('2. Ortak Şablon Seçimi (HTML / CSS / JS ve Yerleşim)', 'ndr-rest-api-to-render'); ?></h3>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <select id="ndr_template_id" name="ndr_template_id" style="min-width:300px;flex:1;">
                        <option value="0"><?php esc_html_e('— Ortak Şablon Seçin —', 'ndr-rest-api-to-render'); ?></option>
                        <?php foreach ($templates as $tpl) : ?>
                            <option value="<?php echo esc_attr($tpl->ID); ?>" <?php selected($template_id, $tpl->ID); ?>>
                                <?php echo esc_html($tpl->post_title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <a href="<?php echo esc_url(admin_url('post-new.php?post_type=ndr_api_template')); ?>" target="_blank" class="button button-secondary">+ <?php esc_html_e('Yeni Ortak Şablon Oluştur', 'ndr-rest-api-to-render'); ?></a>
                </div>
                <p class="description" style="margin-top:6px;">
                    <?php esc_html_e('Seçtiğiniz ortak şablonun HTML, CSS, JS ve yerleşim ayarları hemen aşağıda açılır. Bu sayfada "Güncelle" dediğinizde seçili ortak şablon da güncellenir ve o şablonu kullanan tüm kaynaklara yansır.', 'ndr-rest-api-to-render'); ?>
                </p>
            </div>

            <div id="ndr_source_no_template_notice" class="notice notice-warning inline" style="<?php echo $template_id ? 'display:none;' : 'margin:0 0 14px;'; ?>">
                <p><?php esc_html_e('Henüz bir Ortak Şablon seçilmedi. Yukarıdan bir Ortak Şablon seçin veya yeni bir şablon oluşturun.', 'ndr-rest-api-to-render'); ?></p>
            </div>

            <div id="ndr_source_shared_template_box" style="<?php echo $template_id ? '' : 'display:none;'; ?>">
                <?php NDR_API_Template_Manager::render_shared_template_fields($tpl_cfg); ?>
            </div>

            <div class="ndr-tpl-card">
                <h3><?php esc_html_e('3. Dinamik Veri Alanı Eşleştirme (Bu Kaynağa Özel)', 'ndr-rest-api-to-render'); ?></h3>
                <p class="description" style="margin:0 0 10px;"><?php esc_html_e('Ortak şablondaki {{title}}, {{category}} gibi değişkenleri bu API kaynağındaki farklı JSON alan adlarıyla eşleştirin:', 'ndr-rest-api-to-render'); ?></p>
                <div id="ndr_field_map_rows">
                    <?php foreach ($field_map as $tpl_key => $api_key) : ?>
                        <div class="ndr-fmap-row" style="display:grid;grid-template-columns:1fr 1fr auto;gap:8px;margin-bottom:6px;">
                            <input type="text" name="ndr_fmap_tpl[]" value="<?php echo esc_attr($tpl_key); ?>" placeholder="Şablon Değişkeni (ör. title)" class="widefat" />
                            <input type="text" name="ndr_fmap_api[]" value="<?php echo esc_attr($api_key); ?>" placeholder="API JSON Alanı (ör. urun_adi)" class="widefat" />
                            <button type="button" class="button button-link-delete ndr-remove-fmap">Sil</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="button button-secondary" id="ndr_add_fmap_row">+ <?php esc_html_e('Alan Eşleşmesi Ekle', 'ndr-rest-api-to-render'); ?></button>
            </div>

            <div class="ndr-tpl-card">
                <h3><?php esc_html_e('4. Arama, Filtreleme, Sıralama ve Görünüm (Bu Kaynağa Özel)', 'ndr-rest-api-to-render'); ?></h3>
                <input type="hidden" name="ndr_src_controls_saved" value="1" />

                <div class="ndr-tpl-grid">
                    <div style="background:#fff;padding:12px;border:1px solid #e2e4e7;border-radius:4px;">
                        <p style="margin-top:0;">
                            <label><input type="checkbox" name="ndr_src_enable_search" value="1" <?php checked($enable_search, true); ?> /> <strong><?php esc_html_e('Arama Özelliğini Aktif Et (AÇ / KAPA)', 'ndr-rest-api-to-render'); ?></strong></label>
                        </p>
                        <p>
                            <label for="ndr_src_search_scope"><strong><?php esc_html_e('Arama Kapsamı', 'ndr-rest-api-to-render'); ?></strong></label>
                            <select id="ndr_src_search_scope" name="ndr_src_search_scope" class="widefat">
                                <option value="all" <?php selected($search_scope, 'all'); ?>><?php esc_html_e('Tüm Veri Alanlarında Ara', 'ndr-rest-api-to-render'); ?></option>
                                <option value="specific" <?php selected($search_scope, 'specific'); ?>><?php esc_html_e('Sadece Belirlenen Alanlarda Ara', 'ndr-rest-api-to-render'); ?></option>
                            </select>
                        </p>
                        <p>
                            <label for="ndr_src_search_fields"><strong><?php esc_html_e('Arama Yapılacak Alanlar (Virgülle ayırın)', 'ndr-rest-api-to-render'); ?></strong></label>
                            <input type="text" id="ndr_src_search_fields" name="ndr_src_search_fields" class="widefat" value="<?php echo esc_attr($search_fields); ?>" placeholder="title, description, category" />
                        </p>
                        <p style="margin-bottom:0;">
                            <label for="ndr_src_search_placeholder"><strong><?php esc_html_e('Arama Placeholder', 'ndr-rest-api-to-render'); ?></strong></label>
                            <input type="text" id="ndr_src_search_placeholder" name="ndr_src_search_placeholder" class="widefat" value="<?php echo esc_attr($search_placeholder); ?>" />
                        </p>
                    </div>

                    <div style="background:#fff;padding:12px;border:1px solid #e2e4e7;border-radius:4px;">
                        <p style="margin-top:0;">
                            <label><input type="checkbox" name="ndr_src_enable_sort" value="1" <?php checked($enable_sort, true); ?> /> <strong><?php esc_html_e('Sıralama Kontrolünü Aç (A-Z / Z-A)', 'ndr-rest-api-to-render'); ?></strong></label>
                        </p>
                        <p>
                            <label for="ndr_src_sort_field"><strong><?php esc_html_e('Sıralanacak Alan Adı', 'ndr-rest-api-to-render'); ?></strong></label>
                            <input type="text" id="ndr_src_sort_field" name="ndr_src_sort_field" class="widefat" value="<?php echo esc_attr($sort_field); ?>" placeholder="title" />
                        </p>
                        <p>
                            <label for="ndr_src_sort_default_dir"><strong><?php esc_html_e('Varsayılan Sıralama', 'ndr-rest-api-to-render'); ?></strong></label>
                            <select id="ndr_src_sort_default_dir" name="ndr_src_sort_default_dir" class="widefat">
                                <option value="none" <?php selected($sort_default_dir, 'none'); ?>><?php esc_html_e('API Sırası (Değiştirme)', 'ndr-rest-api-to-render'); ?></option>
                                <option value="asc" <?php selected($sort_default_dir, 'asc'); ?>><?php esc_html_e("A'dan Z'ye (Artan)", 'ndr-rest-api-to-render'); ?></option>
                                <option value="desc" <?php selected($sort_default_dir, 'desc'); ?>><?php esc_html_e("Z'den A'ya (Azalan)", 'ndr-rest-api-to-render'); ?></option>
                            </select>
                        </p>
                        <hr />
                        <p>
                            <label><input type="checkbox" name="ndr_src_enable_view_switch" value="1" <?php checked($enable_view_switch, true); ?> /> <strong><?php esc_html_e('Görünüm Değiştiriciyi Aç (Liste/Grid/Tablo)', 'ndr-rest-api-to-render'); ?></strong></label>
                        </p>
                        <p>
                            <label style="margin-right:12px;"><input type="checkbox" name="ndr_src_allowed_views[]" value="list" <?php checked(in_array('list', $allowed_views, true)); ?> /> Liste</label>
                            <label style="margin-right:12px;"><input type="checkbox" name="ndr_src_allowed_views[]" value="grid" <?php checked(in_array('grid', $allowed_views, true)); ?> /> Grid</label>
                            <label><input type="checkbox" name="ndr_src_allowed_views[]" value="table" <?php checked(in_array('table', $allowed_views, true)); ?> /> Tablo</label>
                        </p>
                        <hr />
                        <p style="margin-bottom:0;">
                            <strong><?php esc_html_e('Kontrol Butonları:', 'ndr-rest-api-to-render'); ?></strong><br />
                            <label style="margin-right:14px;"><input type="checkbox" name="ndr_src_show_apply_btn" value="1" <?php checked($show_apply_btn, true); ?> /> <?php esc_html_e('Uygula Butonunu Göster', 'ndr-rest-api-to-render'); ?></label>
                            <label style="margin-right:14px;"><input type="checkbox" name="ndr_src_show_reset_btn" value="1" <?php checked($show_reset_btn, true); ?> /> <?php esc_html_e('Temizle Butonunu Göster', 'ndr-rest-api-to-render'); ?></label>
                            <label><input type="checkbox" name="ndr_src_show_stats" value="1" <?php checked($show_stats, true); ?> /> <?php esc_html_e('Sonuç Sayacını Göster (İstatistik)', 'ndr-rest-api-to-render'); ?></label>
                        </p>
                    </div>
                </div>

                <div style="background:#fff;padding:12px;border:1px solid #e2e4e7;border-radius:4px;">
                    <p style="margin-top:0;">
                        <label><input type="checkbox" name="ndr_src_enable_filters" value="1" <?php checked($enable_filters, true); ?> /> <strong><?php esc_html_e('Çoklu Filtreleme Özelliğini Aktif Et (AÇ / KAPA)', 'ndr-rest-api-to-render'); ?></strong></label>
                    </p>
                    <div id="ndr_src_filters_list">
                        <?php foreach ($filters as $flt) : ?>
                            <div class="ndr-filter-row">
                                <input type="text" name="ndr_src_filter_field[]" value="<?php echo esc_attr($flt['field'] ?? ''); ?>" placeholder="JSON Alan Adı (ör. category)" class="widefat" />
                                <input type="text" name="ndr_src_filter_label[]" value="<?php echo esc_attr($flt['label'] ?? ''); ?>" placeholder="Etiket (ör. Kategori)" class="widefat" />
                                <select name="ndr_src_filter_type[]" class="widefat">
                                    <option value="dropdown" <?php selected($flt['type'] ?? 'dropdown', 'dropdown'); ?>>Dropdown (Seçim Kutusu)</option>
                                    <option value="checkbox" <?php selected($flt['type'] ?? '', 'checkbox'); ?>>Checkbox (Çoklu Seçim)</option>
                                    <option value="radio" <?php selected($flt['type'] ?? '', 'radio'); ?>>Radio (Tekli Seçim)</option>
                                </select>
                                <button type="button" class="button button-link-delete ndr-remove-src-filter">Sil</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button button-secondary" id="ndr_add_src_filter">+ <?php esc_html_e('Yeni Filtre Alanı Ekle', 'ndr-rest-api-to-render'); ?></button>
                </div>
            </div>

            <script>
            (function($){
                $('#ndr_add_fmap_row').on('click', function(){
                    $('#ndr_field_map_rows').append('<div class="ndr-fmap-row" style="display:grid;grid-template-columns:1fr 1fr auto;gap:8px;margin-bottom:6px;"><input type="text" name="ndr_fmap_tpl[]" value="" placeholder="Şablon Değişkeni (ör. title)" class="widefat" /><input type="text" name="ndr_fmap_api[]" value="" placeholder="API JSON Alanı (ör. urun_adi)" class="widefat" /><button type="button" class="button button-link-delete ndr-remove-fmap">Sil</button></div>');
                });
                $(document).on('click', '.ndr-remove-fmap', function(){ $(this).closest('.ndr-fmap-row').remove(); });

                $('#ndr_add_src_filter').on('click', function(){
                    var row = '<div class="ndr-filter-row">' +
                        '<input type="text" name="ndr_src_filter_field[]" value="" placeholder="JSON Alan Adı (ör. category)" class="widefat" />' +
                        '<input type="text" name="ndr_src_filter_label[]" value="" placeholder="Etiket (ör. Kategori)" class="widefat" />' +
                        '<select name="ndr_src_filter_type[]" class="widefat"><option value="dropdown">Dropdown (Seçim Kutusu)</option><option value="checkbox">Checkbox (Çoklu Seçim)</option><option value="radio">Radio (Tekli Seçim)</option></select>' +
                        '<button type="button" class="button button-link-delete ndr-remove-src-filter">Sil</button>' +
                    '</div>';
                    $('#ndr_src_filters_list').append(row);
                });
                $(document).on('click', '.ndr-remove-src-filter', function(){ $(this).closest('.ndr-filter-row').remove(); });
            })(jQuery);
            </script>
            <?php
        }

        public function save_source_meta($post_id, $post)
        {
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return;
            }
            if (! isset($_POST['ndr_source_meta_nonce']) || ! wp_verify_nonce(wp_unslash($_POST['ndr_source_meta_nonce']), 'ndr_source_meta')) {
                return;
            }
            if (! current_user_can('edit_post', $post_id) || 'ndr_api_source' !== $post->post_type) {
                return;
            }

            update_post_meta($post_id, '_ndr_data_source_id', absint($_POST['ndr_data_source_id'] ?? 0));
            if (isset($_POST['ndr_api_url'])) {
                update_post_meta($post_id, '_ndr_api_url', esc_url_raw(wp_unslash($_POST['ndr_api_url'])));
            }
            if (isset($_POST['ndr_pre_filter'])) {
                update_post_meta($post_id, '_ndr_pre_filter', sanitize_text_field(wp_unslash($_POST['ndr_pre_filter'])));
            }

            $template_id = absint($_POST['ndr_template_id'] ?? 0);
            update_post_meta($post_id, '_ndr_template_id', $template_id);

            // If a central template is selected, save its shared HTML/CSS/JS/Layout fields back to the template
            if ($template_id > 0) {
                NDR_API_Template_Manager::save_shared_template_fields($template_id);
            }

            // Save per-source dynamic field mapping
            $fmap_tpl = isset($_POST['ndr_fmap_tpl']) ? (array) wp_unslash($_POST['ndr_fmap_tpl']) : array();
            $fmap_api = isset($_POST['ndr_fmap_api']) ? (array) wp_unslash($_POST['ndr_fmap_api']) : array();
            $fmap     = array();
            foreach ($fmap_tpl as $idx => $tk) {
                $tk_clean = sanitize_text_field($tk);
                $ak_clean = sanitize_text_field($fmap_api[$idx] ?? '');
                if ($tk_clean !== '' && $ak_clean !== '') {
                    $fmap[$tk_clean] = $ak_clean;
                }
            }
            update_post_meta($post_id, '_ndr_field_map', $fmap);

            // Save per-source Search, Multi-Filter, Sort, View Switcher
            update_post_meta($post_id, '_ndr_src_controls_saved', 1);
            update_post_meta($post_id, '_ndr_src_enable_search', isset($_POST['ndr_src_enable_search']) ? 1 : 0);
            update_post_meta($post_id, '_ndr_src_search_scope', sanitize_text_field(wp_unslash($_POST['ndr_src_search_scope'] ?? 'all')));
            update_post_meta($post_id, '_ndr_src_search_fields', sanitize_text_field(wp_unslash($_POST['ndr_src_search_fields'] ?? '')));
            update_post_meta($post_id, '_ndr_src_search_placeholder', sanitize_text_field(wp_unslash($_POST['ndr_src_search_placeholder'] ?? 'Ara...')));

            update_post_meta($post_id, '_ndr_src_enable_filters', isset($_POST['ndr_src_enable_filters']) ? 1 : 0);
            $f_fields = isset($_POST['ndr_src_filter_field']) ? (array) wp_unslash($_POST['ndr_src_filter_field']) : array();
            $f_labels = isset($_POST['ndr_src_filter_label']) ? (array) wp_unslash($_POST['ndr_src_filter_label']) : array();
            $f_types  = isset($_POST['ndr_src_filter_type']) ? (array) wp_unslash($_POST['ndr_src_filter_type']) : array();
            $filters  = array();
            foreach ($f_fields as $i => $f) {
                $f_clean = sanitize_text_field($f);
                if ($f_clean === '') {
                    continue;
                }
                $filters[] = array(
                    'field' => $f_clean,
                    'label' => sanitize_text_field($f_labels[$i] ?? $f_clean),
                    'type'  => in_array($f_types[$i] ?? 'dropdown', array('dropdown', 'checkbox', 'radio'), true) ? $f_types[$i] : 'dropdown',
                );
            }
            update_post_meta($post_id, '_ndr_src_filters', $filters);

            update_post_meta($post_id, '_ndr_src_enable_sort', isset($_POST['ndr_src_enable_sort']) ? 1 : 0);
            update_post_meta($post_id, '_ndr_src_sort_field', sanitize_text_field(wp_unslash($_POST['ndr_src_sort_field'] ?? 'title')));
            update_post_meta($post_id, '_ndr_src_sort_default_dir', sanitize_text_field(wp_unslash($_POST['ndr_src_sort_default_dir'] ?? 'none')));

            update_post_meta($post_id, '_ndr_src_enable_view_switch', isset($_POST['ndr_src_enable_view_switch']) ? 1 : 0);
            update_post_meta($post_id, '_ndr_src_show_apply_btn', isset($_POST['ndr_src_show_apply_btn']) ? 1 : 0);
            update_post_meta($post_id, '_ndr_src_show_reset_btn', isset($_POST['ndr_src_show_reset_btn']) ? 1 : 0);
            update_post_meta($post_id, '_ndr_src_show_stats', isset($_POST['ndr_src_show_stats']) ? 1 : 0);
            $allowed = isset($_POST['ndr_src_allowed_views']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['ndr_src_allowed_views'])) : array('list', 'grid', 'table');
            update_post_meta($post_id, '_ndr_src_allowed_views', $allowed);
        }
        public function add_admin_css()
        {
            global $typenow;
            if ('ndr_api_source' !== $typenow) {
                return;
            }
        ?>
<style>
.post-type-ndr_api_source .wp-list-table .column-shortcode {
    width: 34%;
}
.ndr-shortcode-col-wrap {
    display: flex;
    align-items: center;
    gap: 6px;
}
.ndr-shortcode-col-wrap .ndr-shortcode-input {
    font-family: monospace;
    font-size: 12px;
    background: #f6f7f7;
    cursor: pointer;
}
.ndr-preview-modal-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.6);
    z-index: 100000;
    align-items: center;
    justify-content: center;
    padding: 24px;
}
.ndr-preview-modal-backdrop.is-open {
    display: flex;
}
.ndr-preview-modal {
    background: #fff;
    border-radius: 8px;
    width: 100%;
    max-width: 1040px;
    max-height: 85vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.25);
    overflow: hidden;
}
.ndr-preview-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 20px;
    border-bottom: 1px solid #dcdcde;
    background: #f6f7f7;
}
.ndr-preview-modal-header h2 {
    margin: 0;
    font-size: 16px;
}
.ndr-preview-modal-body {
    padding: 20px;
    overflow: auto;
    flex: 1;
    background: #fff;
}

.ndr-api-variable-preview {
    margin-top: 8px;
    padding: 10px 12px;
    border: 1px solid #d0d7de;
    border-radius: 8px;
    background: #f8fafc;
    font-size: 13px;
    line-height: 1.5;
}

.ndr-api-variable-preview pre {
    margin: 0;
    white-space: pre-wrap;
    word-break: break-word;
}
</style>
<?php
        }

        public function add_shortcode_column($columns)
        {
            $new_columns = array();
            foreach ($columns as $key => $value) {
                $new_columns[$key] = $value;
                if ('title' === $key) {
                    $new_columns['data_source']       = __('Veri Kaynağı', 'ndr-rest-api-to-render');
                    $new_columns['assigned_template'] = __('Ortak Şablon', 'ndr-rest-api-to-render');
                    $new_columns['record_stats']      = __('Veri İstatistiği', 'ndr-rest-api-to-render');
                    $new_columns['shortcode'] = __('Shortcode', 'ndr-rest-api-to-render');
                }
            }
            return $new_columns;
        }

        public function render_shortcode_column($column, $post_id)
        {
            if ('data_source' === $column) {
                $ds_id = absint(get_post_meta($post_id, '_ndr_data_source_id', true));
                if ($ds_id && ($ds = get_post($ds_id))) {
                    echo '<a href="' . esc_url(get_edit_post_link($ds_id)) . '"><strong>' . esc_html($ds->post_title) . '</strong></a>';
                } else {
                    echo '<span style="color:#646970;">' . esc_html__('Harici URL', 'ndr-rest-api-to-render') . '</span>';
                }
                return;
            }
            if ('assigned_template' === $column) {
                $tpl_id = absint(get_post_meta($post_id, '_ndr_template_id', true));
                if ($tpl_id && ($tpl = get_post($tpl_id))) {
                    echo '<a href="' . esc_url(get_edit_post_link($tpl_id)) . '"><strong>' . esc_html($tpl->post_title) . '</strong></a>';
                } else {
                    echo '<span style="color:#646970;">' . esc_html__('Kaynağa Özel', 'ndr-rest-api-to-render') . '</span>';
                }
                return;
            }
            if ('record_stats' === $column) {
                $ds_id = absint(get_post_meta($post_id, '_ndr_data_source_id', true));
                $pre_f = trim((string) get_post_meta($post_id, '_ndr_pre_filter', true));
                if ($ds_id > 0) {
                    $ud = wp_upload_dir();
                    $lf = trailingslashit($ud['basedir']) . 'gs-api-storage/' . $ds_id . '/data.json';
                    if (file_exists($lf)) {
                        $dec = json_decode((string) file_get_contents($lf), true);
                        if (is_array($dec)) {
                            $raw = $this->php_normalize_data($dec);
                            $raw_c = count($raw);
                            if ($pre_f !== '') {
                                $fmap = get_post_meta($post_id, '_ndr_field_map', true);
                                $mapped = NDR_API_Frontend_Renderer::apply_field_map($raw, is_array($fmap) ? $fmap : array());
                                $flt_c = count(NDR_API_Frontend_Renderer::apply_pre_filters($mapped, $pre_f));
                                echo '<strong>' . esc_html($flt_c) . '</strong> / ' . esc_html($raw_c) . ' <span class="description">(filtreli)</span>';
                            } else {
                                echo '<strong>' . esc_html($raw_c) . '</strong> kayıt';
                            }
                            return;
                        }
                    }
                }
                echo '<span style="color:#646970;">—</span>';
                return;
            }
            if ('shortcode' === $column) {
                $post = get_post($post_id);
                if ($post) {
                    $shortcode = '[ndr_api_renderer id="' . absint($post->ID) . '"]';
                    echo '<div class="ndr-shortcode-col-wrap">';
                    echo '<input type="text" class="widefat ndr-shortcode-input" value="' . esc_attr($shortcode) . '" readonly onclick="this.select();" />';
                    echo '<button type="button" class="button button-small ndr-copy-shortcode" data-shortcode="' . esc_attr($shortcode) . '">' . esc_html__('Kopyala', 'ndr-rest-api-to-render') . '</button>';
                    echo '<button type="button" class="button button-small button-primary ndr-preview-shortcode" data-post-id="' . esc_attr($post->ID) . '" data-title="' . esc_attr(get_the_title($post->ID)) . '">' . esc_html__('Önizle', 'ndr-rest-api-to-render') . '</button>';
                    echo '</div>';
                }
            }
        }

        public function add_admin_preview_modal()
        {
            global $typenow;
            if ('ndr_api_source' !== $typenow) {
                return;
            }
            $nonce = wp_create_nonce('ndr_api_preview_nonce');
            ?>
            <div id="ndr-preview-modal-backdrop" class="ndr-preview-modal-backdrop" role="dialog" aria-modal="true">
                <div class="ndr-preview-modal">
                    <div class="ndr-preview-modal-header">
                        <h2 id="ndr-preview-modal-title"><?php esc_html_e('Önizleme', 'ndr-rest-api-to-render'); ?></h2>
                        <button type="button" class="button" id="ndr-preview-modal-close">&times; <?php esc_html_e('Kapat', 'ndr-rest-api-to-render'); ?></button>
                    </div>
                    <div class="ndr-preview-modal-body" id="ndr-preview-modal-body">
                        <p><?php esc_html_e('Yükleniyor...', 'ndr-rest-api-to-render'); ?></p>
                    </div>
                </div>
            </div>
            <script>
            (function ($) {
                $(document).on('click', '.ndr-copy-shortcode', function () {
                    var $btn = $(this);
                    var text = $btn.data('shortcode');
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text);
                    } else {
                        var $input = $btn.closest('.ndr-shortcode-col-wrap').find('.ndr-shortcode-input');
                        $input.focus().select();
                        document.execCommand('copy');
                    }
                    var orig = $btn.text();
                    $btn.text('Kopyalandı!');
                    setTimeout(function () { $btn.text(orig); }, 1500);
                });

                $(document).on('click', '.ndr-preview-shortcode', function () {
                    var postId = $(this).data('post-id');
                    var title  = $(this).data('title') || ('#' + postId);
                    $('#ndr-preview-modal-title').text('Önizleme: ' + title + ' (ID: ' + postId + ')');
                    $('#ndr-preview-modal-body').html('<p>Yükleniyor...</p>');
                    $('#ndr-preview-modal-backdrop').addClass('is-open');

                    $.post(ajaxurl, {
                        action: 'ndr_api_preview_source',
                        security: '<?php echo esc_js($nonce); ?>',
                        post_id: postId
                    }).done(function (res) {
                        if (res && res.success && res.data && res.data.html) {
                            $('#ndr-preview-modal-body').html(res.data.html);
                        } else {
                            var msg = (res && res.data && res.data.message) ? res.data.message : 'Önizleme yüklenemedi.';
                            $('#ndr-preview-modal-body').html('<div class="notice notice-error"><p>' + msg + '</p></div>');
                        }
                    }).fail(function () {
                        $('#ndr-preview-modal-body').html('<div class="notice notice-error"><p>Sunucu isteği başarısız oldu.</p></div>');
                    });
                });

                $(document).on('click', '#ndr-preview-modal-close', function () {
                    $('#ndr-preview-modal-backdrop').removeClass('is-open');
                });
                $(document).on('click', '#ndr-preview-modal-backdrop', function (e) {
                    if (e.target === this) {
                        $(this).removeClass('is-open');
                    }
                });
            })(jQuery);
            </script>
            <?php
        }

        public function ajax_preview_source()
        {
            check_ajax_referer('ndr_api_preview_nonce', 'security');
            if (! current_user_can('edit_posts')) {
                wp_send_json_error(array('message' => __('Yetkiniz yok.', 'ndr-rest-api-to-render')), 403);
            }
            $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
            if (! $post_id) {
                wp_send_json_error(array('message' => __('Geçersiz kaynak ID.', 'ndr-rest-api-to-render')));
            }
            $html = $this->render_shortcode(array('id' => $post_id));
            wp_send_json_success(array('html' => $html));
        }

        private function php_normalize_data($data)
        {
            if (empty($data)) {
                return array();
            }
            if (is_array($data)) {
                $has_numeric = false;
                foreach (array_keys($data) as $key) {
                    if (is_int($key) || ctype_digit(strval($key))) {
                        $has_numeric = true;
                        break;
                    }
                }
                if ($has_numeric) {
                    return array_values($data);
                }
                if (isset($data['items']) && is_array($data['items'])) {
                    return array_values($data['items']);
                }
                return array($data);
            }
            return array();
        }

        private function php_get_value($item, $path)
        {
            if (empty($item)) {
                return '';
            }
            preg_match_all('/([^[.\]]+)|\[(\d+)\]/', $path, $matches);
            $parts = array();
            if (! empty($matches[0])) {
                foreach ($matches[0] as $i => $full_match) {
                    if ($matches[1][$i] !== '') {
                        $parts[] = $matches[1][$i];
                    } elseif ($matches[2][$i] !== '') {
                        $parts[] = intval($matches[2][$i]);
                    }
                }
            }
            if (empty($parts)) {
                $parts = explode('.', $path);
            }

            $current = $item;
            foreach ($parts as $part) {
                if (is_array($current) && isset($current[$part])) {
                    $current = $current[$part];
                } elseif (is_object($current) && isset($current->$part)) {
                    $current = $current->$part;
                } else {
                    return '';
                }
            }

            if (is_array($current) || is_object($current)) {
                return json_encode($current, JSON_UNESCAPED_UNICODE);
            }
            return strval($current);
        }

        private function php_render_template($template, $item)
        {
            $html = $template;
            $html = preg_replace_callback('/\{\{\s*([^}]+?)\s*\}\}/', function ($matches) use ($item) {
                $key = trim($matches[1]);
                return htmlspecialchars($this->php_get_value($item, $key), ENT_QUOTES, 'UTF-8');
            }, $html);

            $html = preg_replace_callback('/\$\{\s*([^}]+?)\s*\}/', function ($matches) use ($item) {
                $expression = trim($matches[1]);
                if (strpos($expression, 'item.') === 0) {
                    $expression = substr($expression, 5);
                } elseif ($expression === 'item') {
                    return htmlspecialchars(is_array($item) ? json_encode($item, JSON_UNESCAPED_UNICODE) : strval($item), ENT_QUOTES, 'UTF-8');
                }
                return htmlspecialchars($this->php_get_value($item, $expression), ENT_QUOTES, 'UTF-8');
            }, $html);

            return $html;
        }

        public function render_shortcode($atts)
        {
            $atts = shortcode_atts(
                array(
                    'id' => 0,
                    'slug' => '',
                    'filter' => '',
                ),
                $atts,
                'ndr_api_renderer'
            );

            $debug_steps = array('Shortcode başlatıldı.');
            $post = null;
            if (! empty($atts['id'])) {
                $post = get_post(absint($atts['id']));
                $debug_steps[] = 'Shortcode ID ile arama yapıldı: ' . absint($atts['id']);
            } elseif (! empty($atts['slug'])) {
                $post = get_page_by_path($atts['slug'], OBJECT, 'ndr_api_source');
                $debug_steps[] = 'Shortcode slug ile arama yapıldı: ' . sanitize_text_field($atts['slug']);
            }

            if (! $post || 'ndr_api_source' !== $post->post_type) {
                $debug_steps[] = 'Kaynak bulunamadı.';
                return '<div class="ndr-debug-log"><strong>Debug</strong><ul><li>' . esc_html(implode('</li><li>', $debug_steps)) . '</li></ul></div><p>' . esc_html__('Geçerli bir API kaynağı bulunamadı.', 'ndr-rest-api-to-render') . '</p>';
            }

            $debug_steps[] = 'Kaynak bulundu: ' . $post->ID;

            $api_url = get_post_meta($post->ID, '_ndr_api_url', true);
            $template_mode = get_post_meta($post->ID, '_ndr_template_mode', true);
            $template_html = get_post_meta($post->ID, '_ndr_template_html', true);
            $template_css = get_post_meta($post->ID, '_ndr_template_css', true);
            $template_js = get_post_meta($post->ID, '_ndr_template_js', true);
            $enable_search = (bool) get_post_meta($post->ID, '_ndr_enable_search', true);
            $filter_field = get_post_meta($post->ID, '_ndr_filter_field', true);
            $filter_placeholder = get_post_meta($post->ID, '_ndr_filter_placeholder', true);
            $empty_message = get_post_meta($post->ID, '_ndr_empty_message', true);
            $template_id   = absint(get_post_meta($post->ID, '_ndr_template_id', true));
            $field_map_raw = get_post_meta($post->ID, '_ndr_field_map', true);
            $field_map     = is_array($field_map_raw) ? $field_map_raw : (json_decode((string) $field_map_raw, true) ?: array());
            $templates     = get_posts(array('post_type' => 'ndr_api_template', 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC'));

            if (empty($template_mode)) {
                $template_mode = 'preset_cards';
            }
            if (empty($template_html)) {
                if ('preset_cards' === $template_mode) {
                    $template_html = '<div class="ndr-card"><h3>{{title}}</h3><p>{{description}}</p><a href="{{url}}" target="_blank">Detay</a></div>';
                } elseif ('preset_grid' === $template_mode) {
                    $template_html = '<div class="ndr-grid-item"><h3>{{title}}</h3><p>{{description}}</p><a href="{{url}}" target="_blank">İncele</a></div>';
                } elseif ('preset_table' === $template_mode) {
                    $template_html = '<tr><td>{{title}}</td><td>{{description}}</td><td><a href="{{url}}" target="_blank">Link</a></td></tr>';
                } else {
                    $template_html = '<div class="ndr-card"><h3>{{title}}</h3><p>{{description}}</p><a href="{{url}}" target="_blank">Detay</a></div>';
                }
            }

            // Fetch API data on the server side (prefer local fast file if linked to a Veri Kaynağı)
            $api_data       = array();
            $data_source_id = absint(get_post_meta($post->ID, '_ndr_data_source_id', true));
            $loaded_local   = false;
            if ($data_source_id > 0) {
                $ud         = wp_upload_dir();
                $local_dir  = trailingslashit($ud['basedir']) . 'gs-api-storage/' . $data_source_id . '/';
                $local_file = file_exists($local_dir . 'data.json') ? $local_dir . 'data.json' : (file_exists($local_dir . 'latest.json') ? $local_dir . 'latest.json' : '');
                if ($local_file) {
                    $decoded = json_decode((string) file_get_contents($local_file), true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $api_data     = $decoded;
                        $loaded_local = true;
                        $debug_steps[] = 'Veri Kaynağı yerel JSON dosyasından okundu: ID ' . $data_source_id;
                    }
                }
            }
            if (! $loaded_local && ! empty($api_url)) {
                $debug_steps[] = 'PHP API isteği gönderiliyor: ' . $api_url;
                $g_set     = class_exists('NDR_API_Settings_Page') ? NDR_API_Settings_Page::get_settings() : array();
                $use_cache = ! empty($g_set['cache_enabled']);
                $cache_key = 'ndr_api_cache_' . md5($api_url);
                $cached    = $use_cache ? get_transient($cache_key) : false;
                if (false !== $cached && is_array($cached)) {
                    $api_data = $cached;
                    $debug_steps[] = 'API yanıtı önbellekten okundu.';
                } else {
                    $response = wp_remote_get($api_url, array('timeout' => 15));
                    if (! is_wp_error($response)) {
                        $response_code = wp_remote_retrieve_response_code($response);
                        if ($response_code === 200) {
                            $body = wp_remote_retrieve_body($response);
                            $decoded = json_decode($body, true);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $api_data = $decoded;
                                if ($use_cache) {
                                    set_transient($cache_key, $decoded, max(1, absint($g_set['cache_minutes'] ?? 10)) * MINUTE_IN_SECONDS);
                                }
                                $debug_steps[] = 'API yanıtı alındı ve JSON başarıyla çözümlendi.';
                            } else {
                                $debug_steps[] = 'JSON decode hatası: ' . json_last_error_msg();
                            }
                        } else {
                            $debug_steps[] = 'API HTTP hata kodu: ' . $response_code;
                        }
                    } else {
                        $debug_steps[] = 'API wp_remote_get hatası: ' . $response->get_error_message();
                    }
                }
            } else {
                $debug_steps[] = 'API URL boş.';
            }

            $normalized_items = $this->php_normalize_data($api_data);
            $debug_steps[] = 'Bulunan öğe sayısı: ' . count($normalized_items);

            return NDR_API_Frontend_Renderer::render($post, $normalized_items, array(
                'template_mode'      => $template_mode,
                'template_html'      => $template_html,
                'template_css'       => $template_css,
                'template_js'        => $template_js,
                'enable_search'      => $enable_search,
                'filter_field'       => $filter_field,
                'filter_placeholder' => $filter_placeholder,
                'empty_message'      => $empty_message,
                'shortcode_filter'   => $atts['filter'],
            ));
        }

        public function cleanup_deleted_source($post_id)
        {
            $post = get_post($post_id);
            if (! $post || 'ndr_api_source' !== $post->post_type) {
                return;
            }

            $meta_keys = array(
                '_ndr_api_url',
                '_ndr_template_mode',
                '_ndr_template_html',
                '_ndr_template_css',
                '_ndr_template_js',
                '_ndr_enable_search',
                '_ndr_filter_field',
                '_ndr_filter_placeholder',
                '_ndr_empty_message',
            );
            foreach ($meta_keys as $meta_key) {
                delete_post_meta($post_id, $meta_key);
            }
        }


        
    }

    new GS_Sheet_Manager();
    new NDR_API_Template_Manager();
    new NDR_API_Settings_Page();
    new NDR_API_File_Manager();
    new NDR_REST_API_To_Render();
}