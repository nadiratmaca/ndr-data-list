<?php
if (! defined('ABSPATH')) {
    exit;
}

class NDR_API_Frontend_Renderer
{
    public static function get_value($item, $path)
    {
        if (empty($path) || (! is_array($item) && ! is_object($item))) {
            return '';
        }
        $parts = explode('.', $path);
        $cur = $item;
        foreach ($parts as $p) {
            if (is_array($cur) && array_key_exists($p, $cur)) {
                $cur = $cur[$p];
            } elseif (is_object($cur) && isset($cur->$p)) {
                $cur = $cur->$p;
            } else {
                return '';
            }
        }
        if (is_array($cur) || is_object($cur)) {
            return wp_json_encode($cur, JSON_UNESCAPED_UNICODE);
        }
        return (string) $cur;
    }

    public static function apply_field_map($items, $field_map)
    {
        if (empty($field_map) || ! is_array($field_map)) {
            return $items;
        }
        $mapped = array();
        foreach ($items as $item) {
            if (! is_array($item)) {
                $item = (array) $item;
            }
            foreach ($field_map as $tpl_key => $api_key) {
                if ($tpl_key !== '' && $api_key !== '') {
                    $item[$tpl_key] = self::get_value($item, $api_key);
                }
            }
            $mapped[] = $item;
        }
        return $mapped;
    }

    public static function apply_pre_filters($items, $query_string)
    {
        if (empty($query_string) || empty($items)) {
            return $items;
        }
        $rules = array();
        parse_str($query_string, $rules);
        if (empty($rules) || ! is_array($rules)) {
            return $items;
        }

        $parsed_rules = array();
        foreach ($rules as $field => $val) {
            $field = trim((string) $field);
            if ($field === '' || is_array($val)) {
                continue;
            }
            $vals = array_filter(array_map(function ($v) {
                return mb_strtolower(trim($v), 'UTF-8');
            }, explode(',', (string) $val)), function ($v) {
                return $v !== '';
            });
            if (! empty($vals)) {
                $parsed_rules[$field] = $vals;
            }
        }

        if (empty($parsed_rules)) {
            return $items;
        }

        $out = array();
        foreach ($items as $item) {
            $match = true;
            foreach ($parsed_rules as $field => $allowed_vals) {
                $item_val = mb_strtolower(trim(self::get_value($item, $field)), 'UTF-8');
                if (! in_array($item_val, $allowed_vals, true)) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                $out[] = $item;
            }
        }
        return $out;
    }

    public static function get_global_vars()
    {
        $settings = class_exists('NDR_API_Settings_Page') ? NDR_API_Settings_Page::get_settings() : array();
        $root_dir = trim((string) ($settings['fm_root_dir'] ?? 'wp-content/uploads/ndr-files'), '/');
        $root_url = class_exists('NDR_API_File_Manager') ? NDR_API_File_Manager::get_root_url() : '';
        return array(
            'root_url'    => $root_url,
            'fm_root_url' => $root_url,
            'root_dir'    => $root_dir,
        );
    }

    public static function render_item($template, $item)
    {
        $gvars = self::get_global_vars();
        $html = preg_replace_callback('/\{\{\s*([^}]+?)\s*\}\}/', function ($m) use ($item, $gvars) {
            $expr = trim($m[1]);
            if (preg_match('/^join\s*:\s*(["\'])(.*?)\1\s*:\s*(.+)$/i', $expr, $jm)) {
                $sep    = $jm[2];
                $fields = array_map('trim', explode(',', $jm[3]));
                $parts  = array();
                foreach ($fields as $f) {
                    if ($f === '') continue;
                    $val = isset($gvars[$f]) ? $gvars[$f] : self::get_value($item, $f);
                    $val = trim((string) $val);
                    if ($val !== '') {
                        $parts[] = htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
                    }
                }
                return implode(htmlspecialchars($sep, ENT_QUOTES, 'UTF-8'), $parts);
            }
            if (isset($gvars[$expr])) {
                return htmlspecialchars($gvars[$expr], ENT_QUOTES, 'UTF-8');
            }
            return htmlspecialchars(self::get_value($item, $expr), ENT_QUOTES, 'UTF-8');
        }, $template);

        $html = preg_replace_callback('/\$\{\s*([^}]+?)\s*\}/', function ($m) use ($item) {
            $expr = trim($m[1]);
            if (strpos($expr, 'item.') === 0) {
                $expr = substr($expr, 5);
            }
            return htmlspecialchars(self::get_value($item, $expr), ENT_QUOTES, 'UTF-8');
        }, $html);

        return $html;
    }

    public static function render($post, $normalized_items, $legacy_cfg)
    {
        $template_id = absint(get_post_meta($post->ID, '_ndr_template_id', true));
        $tpl_cfg     = $template_id ? NDR_API_Template_Manager::get_template_config($template_id) : null;

        if ($tpl_cfg && ! empty($tpl_cfg['id'])) {
            $cfg = $tpl_cfg;
        } else {
            // Fallback for sources without an assigned central template
            $mode_map = array('preset_cards' => 'list', 'preset_grid' => 'grid', 'preset_table' => 'table', 'custom' => 'custom');
            $cfg = array(
                'layout_type'       => $mode_map[$legacy_cfg['template_mode']] ?? 'grid',
                'template_html'     => $legacy_cfg['template_html'],
                'table_headers'     => '',
                'template_css'      => $legacy_cfg['template_css'],
                'template_js'       => $legacy_cfg['template_js'],
                'controls_position' => 'top',
                'controls_html_top' => '',
                'controls_html_bot' => '',
                'controls_css'      => '',
                'empty_message'     => $legacy_cfg['empty_message'],
                'grid_min_width'    => '260px',
                'grid_max_width'    => '1fr',
                'enable_lightbox'   => false,
            );
        }

        // Per-source Search, Multi-Filter, Sort, and View Switcher settings
        $has_src_controls = (bool) get_post_meta($post->ID, '_ndr_src_controls_saved', true);
        if ($has_src_controls) {
            $src_filters_raw = get_post_meta($post->ID, '_ndr_src_filters', true);
            $src_filters     = is_array($src_filters_raw) ? $src_filters_raw : (json_decode((string) $src_filters_raw, true) ?: array());
            $src_views       = get_post_meta($post->ID, '_ndr_src_allowed_views', true);
            if (! is_array($src_views) || empty($src_views)) {
                $src_views = array('list', 'grid', 'table');
            }

            $cfg['enable_search']      = (bool) get_post_meta($post->ID, '_ndr_src_enable_search', true);
            $cfg['search_scope']       = get_post_meta($post->ID, '_ndr_src_search_scope', true) ?: 'all';
            $cfg['search_fields']      = (string) get_post_meta($post->ID, '_ndr_src_search_fields', true);
            $cfg['search_placeholder'] = (string) (get_post_meta($post->ID, '_ndr_src_search_placeholder', true) ?: __('Ara...', 'ndr-rest-api-to-render'));
            $cfg['enable_filters']     = (bool) get_post_meta($post->ID, '_ndr_src_enable_filters', true);
            $cfg['filters']            = $src_filters;
            $cfg['enable_sort']        = (bool) get_post_meta($post->ID, '_ndr_src_enable_sort', true);
            $cfg['sort_field']         = (string) (get_post_meta($post->ID, '_ndr_src_sort_field', true) ?: 'title');
            $cfg['sort_default_dir']   = get_post_meta($post->ID, '_ndr_src_sort_default_dir', true) ?: 'none';
            $cfg['enable_view_switch'] = (bool) get_post_meta($post->ID, '_ndr_src_enable_view_switch', true);
            $cfg['show_apply_btn']     = (bool) get_post_meta($post->ID, '_ndr_src_show_apply_btn', true);
            $cfg['show_reset_btn']     = (bool) get_post_meta($post->ID, '_ndr_src_show_reset_btn', true);
            $cfg['show_stats']         = (bool) get_post_meta($post->ID, '_ndr_src_show_stats', true);
            $cfg['allowed_views']      = $src_views;
        } else {
            // Backward compatibility for legacy sources
            $cfg['enable_search']      = (bool) ($legacy_cfg['enable_search'] ?? false);
            $cfg['search_scope']       = 'all';
            $cfg['search_fields']      = '';
            $cfg['search_placeholder'] = __('Ara...', 'ndr-rest-api-to-render');
            $cfg['enable_filters']     = ! empty($legacy_cfg['filter_field']);
            $cfg['filters']            = ! empty($legacy_cfg['filter_field']) ? array(
                array('field' => $legacy_cfg['filter_field'], 'label' => $legacy_cfg['filter_field'], 'type' => 'dropdown'),
            ) : array();
            $cfg['enable_sort']        = false;
            $cfg['sort_field']         = 'title';
            $cfg['sort_default_dir']   = 'none';
            $cfg['enable_view_switch'] = false;
            $cfg['show_apply_btn']     = true;
            $cfg['show_reset_btn']     = true;
            $cfg['show_stats']         = true;
            $cfg['allowed_views']      = array('list', 'grid', 'table');
        }

        // Apply dynamic field mapping if configured on the source
        $field_map_raw = get_post_meta($post->ID, '_ndr_field_map', true);
        $field_map     = is_array($field_map_raw) ? $field_map_raw : (json_decode((string) $field_map_raw, true) ?: array());
        $items         = self::apply_field_map($normalized_items, $field_map);

        // Apply Pre-Filter rules (e.g. "marka=dekorama,dekorflor&desen=kareli")
        $pre_filter_str = trim((string) get_post_meta($post->ID, '_ndr_pre_filter', true));
        $sc_filter_str  = trim((string) ($legacy_cfg['shortcode_filter'] ?? ''));
        $combined_pre   = array_filter(array($pre_filter_str, $sc_filter_str));
        if (! empty($combined_pre)) {
            $items = self::apply_pre_filters($items, implode('&', $combined_pre));
        }

        // Read URL parameters for SSR
        $q_param    = isset($_GET['ndr_q']) ? sanitize_text_field(wp_unslash($_GET['ndr_q'])) : '';
        $sort_param = isset($_GET['ndr_sort']) ? sanitize_text_field(wp_unslash($_GET['ndr_sort'])) : $cfg['sort_default_dir'];
        $view_param = isset($_GET['ndr_view']) ? sanitize_text_field(wp_unslash($_GET['ndr_view'])) : $cfg['layout_type'];
        if (! in_array($view_param, array('list', 'grid', 'table', 'custom'), true)) {
            $view_param = $cfg['layout_type'];
        }

        // Collect unique filter options from full dataset
        $filter_options = array();
        $active_filters = array();
        if (! empty($cfg['enable_filters']) && ! empty($cfg['filters'])) {
            foreach ($cfg['filters'] as $flt) {
                $f = $flt['field'];
                $param_key = 'ndr_f_' . sanitize_key($f);
                $raw_val = $_GET[$param_key] ?? '';
                if (is_array($raw_val)) {
                    $active_filters[$f] = array_map('sanitize_text_field', wp_unslash($raw_val));
                } elseif ($raw_val !== '') {
                    $active_filters[$f] = array(sanitize_text_field(wp_unslash($raw_val)));
                } else {
                    $active_filters[$f] = array();
                }

                $opts = array();
                foreach ($items as $it) {
                    $v = trim(self::get_value($it, $f));
                    if ($v !== '' && ! in_array($v, $opts, true)) {
                        $opts[] = $v;
                    }
                }
                sort($opts, SORT_NATURAL | SORT_FLAG_CASE);
                $filter_options[$f] = $opts;
            }
        }

        // Filter items server-side
        $search_fields_arr = array_filter(array_map('trim', explode(',', $cfg['search_fields'])));
        $filtered = array();
        foreach ($items as $it) {
            // Search check
            if ($q_param !== '') {
                $needle = mb_strtolower($q_param, 'UTF-8');
                $matched = false;
                if ($cfg['search_scope'] === 'specific' && ! empty($search_fields_arr)) {
                    foreach ($search_fields_arr as $sf) {
                        if (mb_stripos(self::get_value($it, $sf), $needle, 0, 'UTF-8') !== false) {
                            $matched = true;
                            break;
                        }
                    }
                } else {
                    $haystack = wp_json_encode($it, JSON_UNESCAPED_UNICODE);
                    if (mb_stripos($haystack, $needle, 0, 'UTF-8') !== false) {
                        $matched = true;
                    }
                }
                if (! $matched) {
                    continue;
                }
            }

            // Multi-filter check
            $pass_filters = true;
            foreach ($active_filters as $f => $selected_vals) {
                if (empty($selected_vals)) {
                    continue;
                }
                $val = mb_strtolower(trim(self::get_value($it, $f)), 'UTF-8');
                $lower_selected = array_map(function ($x) { return mb_strtolower(trim($x), 'UTF-8'); }, $selected_vals);
                if (! in_array($val, $lower_selected, true)) {
                    $pass_filters = false;
                    break;
                }
            }
            if ($pass_filters) {
                $filtered[] = $it;
            }
        }

        // Sort items server-side
        if (($sort_param === 'asc' || $sort_param === 'desc') && ! empty($cfg['sort_field'])) {
            $sf = $cfg['sort_field'];
            usort($filtered, function ($a, $b) use ($sf, $sort_param) {
                $va = self::get_value($a, $sf);
                $vb = self::get_value($b, $sf);
                $cmp = strnatcasecmp($va, $vb);
                return ($sort_param === 'desc') ? -$cmp : $cmp;
            });
        }

        $wrapper_id = 'ndr-render-' . $post->ID . '-' . wp_generate_uuid4();
        $has_controls = $cfg['enable_search'] || ($cfg['enable_filters'] && ! empty($cfg['filters'])) || $cfg['enable_sort'] || $cfg['enable_view_switch'];
        $pos = $cfg['controls_position'];
        $min_w = preg_match('/^[0-9]+(px|rem|em|%)$/', trim($cfg['grid_min_width'] ?? '260px')) ? trim($cfg['grid_min_width']) : '260px';
        $max_w = preg_match('/^[0-9]+(\.[0-9]+)?(px|rem|em|%|fr)$/', trim($cfg['grid_max_width'] ?? '1fr')) ? trim($cfg['grid_max_width']) : '1fr';
        $enable_lb = ! empty($cfg['enable_lightbox']);

        ob_start();
        ?>
        <style>
        #<?php echo esc_attr($wrapper_id); ?>{font-family:inherit;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-layout-wrap{display:flex;flex-direction:column;gap:20px;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-layout-wrap.ndr-pos-bottom{flex-direction:column-reverse;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-layout-wrap.ndr-pos-sidebar_left{flex-direction:row;align-items:flex-start;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-layout-wrap.ndr-pos-sidebar_right{flex-direction:row-reverse;align-items:flex-start;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-pos-sidebar_left .ndr-controls-panel,
        #<?php echo esc_attr($wrapper_id); ?> .ndr-pos-sidebar_right .ndr-controls-panel{flex:0 0 280px;position:sticky;top:24px;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-results-area{flex:1;min-width:0;width:100%;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-controls-panel{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-controls-form{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-pos-sidebar_left .ndr-controls-form,
        #<?php echo esc_attr($wrapper_id); ?> .ndr-pos-sidebar_right .ndr-controls-form{flex-direction:column;align-items:stretch;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-ctrl-group{display:flex;flex-direction:column;gap:6px;min-width:170px;flex:1;margin:0;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-ctrl-group label.ndr-ctrl-label{font-weight:600;font-size:13px;line-height:1.3;color:#1e293b;margin:0;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-ctrl-input,#<?php echo esc_attr($wrapper_id); ?> .ndr-ctrl-select{height:42px !important;min-height:42px !important;line-height:1.4 !important;padding:8px 12px !important;border:1px solid #cbd5e1 !important;border-radius:6px !important;background-color:#fff !important;font-size:14px !important;width:100% !important;box-sizing:border-box !important;margin:0 !important;vertical-align:middle;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-btn-apply,#<?php echo esc_attr($wrapper_id); ?> .ndr-btn-reset{height:42px;display:inline-flex;align-items:center;justify-content:center;padding:0 16px;box-sizing:border-box;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-check-list{display:flex;flex-wrap:wrap;gap:8px 14px;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-pos-sidebar_left .ndr-check-list,
        #<?php echo esc_attr($wrapper_id); ?> .ndr-pos-sidebar_right .ndr-check-list{flex-direction:column;gap:6px;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-check-item{display:inline-flex;align-items:center;gap:6px;font-size:13.5px;cursor:pointer;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-view-switcher{display:inline-flex;border:1px solid #cbd5e1;border-radius:6px;overflow:hidden;background:#fff;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-view-btn{border:none;background:transparent;padding:7px 12px;cursor:pointer;font-size:13px;color:#475569;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-view-btn.is-active{background:#1d4ed8;color:#fff;font-weight:600;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-ctrl-actions{display:flex;gap:8px;align-items:center;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-btn-apply{padding:8px 16px;background:#1d4ed8;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13.5px;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-btn-reset{padding:8px 12px;background:#e2e8f0;color:#334155;border:none;border-radius:6px;cursor:pointer;font-size:13.5px;text-decoration:none;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-results-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,<?php echo esc_attr($min_w); ?>),<?php echo esc_attr($max_w); ?>));gap:16px;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-results-list{display:flex;flex-direction:column;gap:12px;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-results-table-wrap{overflow-x:auto;width:100%;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-stats-bar{margin-top:12px;padding-top:10px;border-top:1px solid #e2e8f0;font-size:13px;color:#475569;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-stats-bar strong{color:#0f172a;}
        #<?php echo esc_attr($wrapper_id); ?> .ndr-empty-msg{padding:24px;text-align:center;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;color:#64748b;}
        .ndr-lightbox{display:none;position:fixed;inset:0;background:rgba(15,23,42,.92);z-index:999999;align-items:center;justify-content:center;backdrop-filter:blur(4px);user-select:none;}
        .ndr-lightbox.is-active{display:flex;}
        .ndr-lightbox-stage{width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:54px 64px 28px;box-sizing:border-box;}
        .ndr-slider-viewport{position:relative;width:100%;max-width:1100px;flex:1;min-height:0;overflow:hidden;display:flex;align-items:center;justify-content:center;}
        .ndr-slide{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;transition:transform .33s cubic-bezier(.25,.8,.25,1);will-change:transform;}
        .ndr-slide.no-trans{transition:none !important;}
        .ndr-slide.pos-center{transform:translateX(0);}
        .ndr-slide.pos-left{transform:translateX(-100%);}
        .ndr-slide.pos-right{transform:translateX(100%);}
        .ndr-slide img{max-width:100%;max-height:100%;object-fit:contain;border-radius:8px;box-shadow:0 20px 50px rgba(0,0,0,.55);background:#fff;}
        .ndr-lightbox-loader{display:none;position:absolute;width:42px;height:42px;border:3px solid rgba(255,255,255,.25);border-top-color:#fff;border-radius:50%;animation:ndrSpin .75s linear infinite;z-index:2;}
        .ndr-lightbox-loader.is-loading{display:block;}
        @keyframes ndrSpin{to{transform:rotate(360deg);}}
        .ndr-lightbox-meta{margin-top:14px;text-align:center;color:#f8fafc;display:flex;flex-direction:column;gap:4px;max-width:90vw;}
        .ndr-lightbox-counter{font-size:12.5px;color:#94a3b8;font-weight:600;letter-spacing:.4px;}
        .ndr-lightbox-caption{font-size:15px;font-weight:500;}
        .ndr-lb-btn{position:fixed;background:rgba(255,255,255,.14);color:#fff;border:none;width:46px;height:46px;border-radius:50%;font-size:24px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s,transform .15s;z-index:10;}
        .ndr-lb-btn:hover{background:rgba(255,255,255,.28);}
        .ndr-lb-close{top:16px;right:18px;font-size:28px;}
        .ndr-lb-prev{left:16px;top:50%;transform:translateY(-50%);}
        .ndr-lb-next{right:16px;top:50%;transform:translateY(-50%);}
        @media(max-width:768px){
            .ndr-lightbox-stage{padding:56px 12px 24px;}
            .ndr-lb-prev,.ndr-lb-next{width:38px;height:38px;font-size:20px;background:rgba(15,23,42,.55);}
            .ndr-lb-prev{left:8px;}
            .ndr-lb-next{right:8px;}
        }
        @media(max-width:768px){
            #<?php echo esc_attr($wrapper_id); ?> .ndr-layout-wrap.ndr-pos-sidebar_left,
            #<?php echo esc_attr($wrapper_id); ?> .ndr-layout-wrap.ndr-pos-sidebar_right{flex-direction:column;}
            #<?php echo esc_attr($wrapper_id); ?> .ndr-pos-sidebar_left .ndr-controls-panel,
            #<?php echo esc_attr($wrapper_id); ?> .ndr-pos-sidebar_right .ndr-controls-panel{flex:none;width:100%;position:static;}
        }
        <?php $g_settings = class_exists('NDR_API_Settings_Page') ? NDR_API_Settings_Page::get_settings() : array('global_css' => '', 'global_js' => ''); echo $g_settings['global_css'] . "\n" . $cfg['template_css'] . "\n" . $cfg['controls_css']; ?>
        </style>

        <div class="ndr-api-renderer" id="<?php echo esc_attr($wrapper_id); ?>" data-source-id="<?php echo esc_attr($post->ID); ?>">
            <div class="ndr-layout-wrap ndr-pos-<?php echo esc_attr($pos); ?>">
                <?php if ($has_controls) : ?>
                    <div class="ndr-controls-panel">
                        <?php if (! empty($cfg['controls_html_top'])) : ?>
                            <div class="ndr-controls-custom-top"><?php echo wp_kses_post($cfg['controls_html_top']); ?></div>
                        <?php endif; ?>

                        <form method="get" class="ndr-controls-form">
                            <?php if ($cfg['enable_search']) : ?>
                                <div class="ndr-ctrl-group ndr-ctrl-search">
                                    <label class="ndr-ctrl-label"><?php esc_html_e('Arama', 'ndr-rest-api-to-render'); ?></label>
                                    <input type="search" name="ndr_q" class="ndr-ctrl-input ndr-js-search" value="<?php echo esc_attr($q_param); ?>" placeholder="<?php echo esc_attr($cfg['search_placeholder'] ?: 'Ara...'); ?>" />
                                </div>
                            <?php endif; ?>

                            <?php if ($cfg['enable_filters'] && ! empty($cfg['filters'])) : ?>
                                <?php foreach ($cfg['filters'] as $flt) :
                                    $f_field = $flt['field'];
                                    $f_label = $flt['label'] ?: $f_field;
                                    $f_type  = $flt['type'] ?: 'dropdown';
                                    $p_name  = 'ndr_f_' . sanitize_key($f_field);
                                    $opts    = $filter_options[$f_field] ?? array();
                                    $sel     = $active_filters[$f_field] ?? array();
                                ?>
                                    <div class="ndr-ctrl-group ndr-ctrl-filter" data-filter-field="<?php echo esc_attr($f_field); ?>" data-filter-type="<?php echo esc_attr($f_type); ?>">
                                        <label class="ndr-ctrl-label"><?php echo esc_html($f_label); ?></label>
                                        <?php if ($f_type === 'dropdown') : ?>
                                            <select name="<?php echo esc_attr($p_name); ?>" class="ndr-ctrl-select ndr-js-filter" data-field="<?php echo esc_attr($f_field); ?>">
                                                <option value=""><?php esc_html_e('Tümü', 'ndr-rest-api-to-render'); ?></option>
                                                <?php foreach ($opts as $opt) : ?>
                                                    <option value="<?php echo esc_attr($opt); ?>" <?php selected(in_array($opt, $sel, true)); ?>><?php echo esc_html($opt); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php elseif ($f_type === 'radio') : ?>
                                            <div class="ndr-check-list">
                                                <label class="ndr-check-item">
                                                    <input type="radio" name="<?php echo esc_attr($p_name); ?>" class="ndr-js-filter" data-field="<?php echo esc_attr($f_field); ?>" value="" <?php checked(empty($sel)); ?> />
                                                    <span><?php esc_html_e('Tümü', 'ndr-rest-api-to-render'); ?></span>
                                                </label>
                                                <?php foreach ($opts as $opt) : ?>
                                                    <label class="ndr-check-item">
                                                        <input type="radio" name="<?php echo esc_attr($p_name); ?>" class="ndr-js-filter" data-field="<?php echo esc_attr($f_field); ?>" value="<?php echo esc_attr($opt); ?>" <?php checked(in_array($opt, $sel, true)); ?> />
                                                        <span><?php echo esc_html($opt); ?></span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php elseif ($f_type === 'checkbox') : ?>
                                            <div class="ndr-check-list">
                                                <?php foreach ($opts as $opt) : ?>
                                                    <label class="ndr-check-item">
                                                        <input type="checkbox" name="<?php echo esc_attr($p_name); ?>[]" class="ndr-js-filter" data-field="<?php echo esc_attr($f_field); ?>" value="<?php echo esc_attr($opt); ?>" <?php checked(in_array($opt, $sel, true)); ?> />
                                                        <span><?php echo esc_html($opt); ?></span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <?php if ($cfg['enable_sort']) : ?>
                                <div class="ndr-ctrl-group ndr-ctrl-sort">
                                    <label class="ndr-ctrl-label"><?php esc_html_e('Sıralama', 'ndr-rest-api-to-render'); ?></label>
                                    <select name="ndr_sort" class="ndr-ctrl-select ndr-js-sort">
                                        <option value="none" <?php selected($sort_param, 'none'); ?>><?php esc_html_e('Varsayılan', 'ndr-rest-api-to-render'); ?></option>
                                        <option value="asc" <?php selected($sort_param, 'asc'); ?>><?php esc_html_e("A'dan Z'ye", 'ndr-rest-api-to-render'); ?></option>
                                        <option value="desc" <?php selected($sort_param, 'desc'); ?>><?php esc_html_e("Z'den A'ya", 'ndr-rest-api-to-render'); ?></option>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <?php if ($cfg['enable_view_switch']) :
                                $view_labels = array('list' => 'Liste', 'grid' => 'Grid', 'table' => 'Tablo');
                            ?>
                                <div class="ndr-ctrl-group ndr-ctrl-view" style="flex:0 0 auto;min-width:auto;">
                                    <label class="ndr-ctrl-label"><?php esc_html_e('Görünüm', 'ndr-rest-api-to-render'); ?></label>
                                    <input type="hidden" name="ndr_view" class="ndr-js-view-input" value="<?php echo esc_attr($view_param); ?>" />
                                    <div class="ndr-view-switcher">
                                        <?php foreach ($cfg['allowed_views'] as $vw) : ?>
                                            <button type="button" class="ndr-view-btn <?php echo ($view_param === $vw) ? 'is-active' : ''; ?>" data-view="<?php echo esc_attr($vw); ?>">
                                                <?php echo esc_html($view_labels[$vw] ?? $vw); ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (! empty($cfg['show_apply_btn']) || ! empty($cfg['show_reset_btn'])) : ?>
                                <div class="ndr-ctrl-actions">
                                    <?php if (! empty($cfg['show_apply_btn'])) : ?>
                                        <button type="submit" class="ndr-btn-apply"><?php esc_html_e('Uygula', 'ndr-rest-api-to-render'); ?></button>
                                    <?php endif; ?>
                                    <?php if (! empty($cfg['show_reset_btn'])) : ?>
                                        <button type="button" class="ndr-btn-reset ndr-js-reset"><?php esc_html_e('Temizle', 'ndr-rest-api-to-render'); ?></button>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </form>

                        <?php if (! empty($cfg['show_stats'])) :
                            $total_cnt    = count($items);
                            $filtered_cnt = count($filtered);
                        ?>
                            <div class="ndr-stats-bar">
                                <span class="ndr-js-stats-text">
                                    <?php if ($filtered_cnt === $total_cnt) : ?>
                                        <?php echo sprintf(wp_kses_post(__('Toplam <strong>%d</strong> kayıt listeleniyor.', 'ndr-rest-api-to-render')), $total_cnt); ?>
                                    <?php else : ?>
                                        <?php echo sprintf(wp_kses_post(__('Toplam <strong>%1$d</strong> kayıt arasından <strong>%2$d</strong> sonuç gösteriliyor.', 'ndr-rest-api-to-render')), $total_cnt, $filtered_cnt); ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <?php if (! empty($cfg['controls_html_bot'])) : ?>
                            <div class="ndr-controls-custom-bot"><?php echo wp_kses_post($cfg['controls_html_bot']); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="ndr-results-area">
                    <div class="ndr-api-results">
                        <?php echo self::render_items_html($filtered, $cfg, $view_param); ?>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function(){
            var root = document.getElementById(<?php echo wp_json_encode($wrapper_id); ?>);
            if (!root) return;

            var allItems = <?php echo wp_json_encode($items, JSON_UNESCAPED_UNICODE); ?>;
            var cfg = <?php echo wp_json_encode(array(
                'templateHtml'    => $cfg['template_html'],
                'tableHeaders'    => $cfg['table_headers'],
                'layoutType'      => $cfg['layout_type'],
                'enableSearch'    => $cfg['enable_search'],
                'searchScope'     => $cfg['search_scope'],
                'searchFields'    => $search_fields_arr,
                'sortField'       => $cfg['sort_field'],
                'emptyMessage'    => $cfg['empty_message'] ?: 'Gösterilecek veri bulunamadı.',
                'customJs'        => trim(($g_settings['global_js'] ?? '') . "\n" . ($cfg['template_js'] ?? '')),
                'enableLightbox'  => $enable_lb,
                'globalVars'      => self::get_global_vars(),
                'presets'         => NDR_API_Template_Manager::get_default_presets(),
            ), JSON_UNESCAPED_UNICODE); ?>;

            function getVal(item, path) {
                if (!item || !path) return '';
                var parts = path.split('.');
                var cur = item;
                for (var i = 0; i < parts.length; i++) {
                    if (cur && typeof cur === 'object' && parts[i] in cur) {
                        cur = cur[parts[i]];
                    } else {
                        return '';
                    }
                }
                if (cur === null || cur === undefined) return '';
                return (typeof cur === 'object') ? JSON.stringify(cur) : String(cur);
            }

            function escHtml(str) {
                return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
            }

            function renderItem(tpl, item) {
                return tpl
                    .replace(/\{\{\s*([^}]+?)\s*\}\}/g, function(_, k){
                        var expr = k.trim();
                        var jm = expr.match(/^join\s*:\s*(["'])(.*?)\1\s*:\s*(.+)$/i);
                        if (jm) {
                            var sep = jm[2];
                            var fields = jm[3].split(',');
                            var parts = [];
                            for (var i = 0; i < fields.length; i++) {
                                var f = fields[i].trim();
                                if (!f) continue;
                                var v = (cfg.globalVars && Object.prototype.hasOwnProperty.call(cfg.globalVars, f))
                                    ? String(cfg.globalVars[f])
                                    : getVal(item, f);
                                v = v.trim();
                                if (v !== '') parts.push(escHtml(v));
                            }
                            return parts.join(escHtml(sep));
                        }
                        if (cfg.globalVars && Object.prototype.hasOwnProperty.call(cfg.globalVars, expr)) return escHtml(cfg.globalVars[expr]);
                        return escHtml(getVal(item, expr));
                    })
                    .replace(/\$\{\s*([^}]+?)\s*\}/g, function(_, k){
                        var expr = k.trim();
                        if (expr.indexOf('item.') === 0) expr = expr.slice(5);
                        return escHtml(getVal(item, expr));
                    });
            }

            function getState() {
                var qEl = root.querySelector('.ndr-js-search');
                var q = qEl ? qEl.value.trim() : '';
                var sortEl = root.querySelector('.ndr-js-sort');
                var sort = sortEl ? sortEl.value : 'none';
                var viewEl = root.querySelector('.ndr-js-view-input');
                var view = viewEl ? viewEl.value : cfg.layoutType;

                var filters = {};
                root.querySelectorAll('.ndr-ctrl-filter').forEach(function(grp){
                    var field = grp.getAttribute('data-filter-field');
                    var type = grp.getAttribute('data-filter-type');
                    var vals = [];
                    if (type === 'dropdown') {
                        var sel = grp.querySelector('select');
                        if (sel && sel.value !== '') vals.push(sel.value);
                    } else {
                        grp.querySelectorAll('input:checked').forEach(function(inp){
                            if (inp.value !== '') vals.push(inp.value);
                        });
                    }
                    if (vals.length) filters[field] = vals;
                });
                return { q: q, sort: sort, view: view, filters: filters };
            }

            function syncUrl(state) {
                if (!window.history || !window.history.replaceState) return;
                var url = new URL(window.location.href);
                // Clear existing ndr_ params
                Array.from(url.searchParams.keys()).forEach(function(k){
                    if (k === 'ndr_q' || k === 'ndr_sort' || k === 'ndr_view' || k.indexOf('ndr_f_') === 0) {
                        url.searchParams.delete(k);
                    }
                });
                if (state.q) url.searchParams.set('ndr_q', state.q);
                if (state.sort && state.sort !== 'none') url.searchParams.set('ndr_sort', state.sort);
                if (state.view && state.view !== cfg.layoutType) url.searchParams.set('ndr_view', state.view);
                Object.keys(state.filters).forEach(function(field){
                    var pKey = 'ndr_f_' + field.toLowerCase().replace(/[^a-z0-9_]+/g, '_');
                    state.filters[field].forEach(function(v){
                        url.searchParams.append(pKey, v);
                    });
                });
                window.history.replaceState(null, '', url.toString());
            }

            function update() {
                var state = getState();
                syncUrl(state);

                var needle = state.q.toLowerCase();
                var res = allItems.filter(function(item){
                    if (needle) {
                        var hit = false;
                        if (cfg.searchScope === 'specific' && cfg.searchFields.length) {
                            for (var i = 0; i < cfg.searchFields.length; i++) {
                                if (getVal(item, cfg.searchFields[i]).toLowerCase().indexOf(needle) !== -1) {
                                    hit = true; break;
                                }
                            }
                        } else {
                            if (JSON.stringify(item).toLowerCase().indexOf(needle) !== -1) hit = true;
                        }
                        if (!hit) return false;
                    }
                    var fKeys = Object.keys(state.filters);
                    for (var j = 0; j < fKeys.length; j++) {
                        var f = fKeys[j];
                        var v = getVal(item, f).trim().toLowerCase();
                        var allowed = state.filters[f].map(function(x){ return x.trim().toLowerCase(); });
                        if (allowed.indexOf(v) === -1) return false;
                    }
                    return true;
                });

                if ((state.sort === 'asc' || state.sort === 'desc') && cfg.sortField) {
                    res.sort(function(a, b){
                        var va = getVal(a, cfg.sortField);
                        var vb = getVal(b, cfg.sortField);
                        var cmp = va.localeCompare(vb, undefined, { numeric: true, sensitivity: 'base' });
                        return state.sort === 'desc' ? -cmp : cmp;
                    });
                }

                var statsEl = root.querySelector('.ndr-js-stats-text');
                if (statsEl) {
                    if (res.length === allItems.length) {
                        statsEl.innerHTML = 'Toplam <strong>' + allItems.length + '</strong> kayıt listeleniyor.';
                    } else {
                        statsEl.innerHTML = 'Toplam <strong>' + allItems.length + '</strong> kayıt arasından <strong>' + res.length + '</strong> sonuç gösteriliyor.';
                    }
                }
                var box = root.querySelector('.ndr-api-results');
                if (!res.length) {
                    box.innerHTML = '<div class="ndr-empty-msg">' + escHtml(cfg.emptyMessage) + '</div>';
                    return;
                }

                setTimeout(function(){ if (typeof runCustomJs === 'function') runCustomJs(); }, 0);
                var tpl = cfg.templateHtml;
                if (state.view !== cfg.layoutType && cfg.presets[state.view]) {
                    tpl = cfg.presets[state.view].html;
                }

                if (state.view === 'table') {
                    var ths = '';
                    if (cfg.tableHeaders) {
                        ths = '<thead><tr>' + cfg.tableHeaders.split(',').map(function(h){ return '<th>' + escHtml(h.trim()) + '</th>'; }).join('') + '</tr></thead>';
                    }
                    var rows = res.map(function(it){ return renderItem(tpl, it); }).join('');
                    box.innerHTML = '<div class="ndr-results-table-wrap"><table class="ndr-table">' + ths + '<tbody>' + rows + '</tbody></table></div>';
                } else {
                    var cls = (state.view === 'list') ? 'ndr-results-list' : 'ndr-results-grid';
                    box.innerHTML = '<div class="' + cls + '">' + res.map(function(it){ return renderItem(tpl, it); }).join('') + '</div>';
                }
            }

            var form = root.querySelector('.ndr-controls-form');
            if (form) {
                form.addEventListener('submit', function(e){
                    e.preventDefault();
                    update();
                });
            }
            var qInput = root.querySelector('.ndr-js-search');
            if (qInput) qInput.addEventListener('input', update);
            root.querySelectorAll('.ndr-js-filter, .ndr-js-sort').forEach(function(el){
                el.addEventListener('change', update);
            });
            root.querySelectorAll('.ndr-view-btn').forEach(function(btn){
                btn.addEventListener('click', function(){
                    root.querySelectorAll('.ndr-view-btn').forEach(function(b){ b.classList.remove('is-active'); });
                    btn.classList.add('is-active');
                    var vInput = root.querySelector('.ndr-js-view-input');
                    if (vInput) vInput.value = btn.getAttribute('data-view');
                    update();
                });
            });
            var resetBtn = root.querySelector('.ndr-js-reset');
            if (resetBtn) {
                resetBtn.addEventListener('click', function(){
                    if (qInput) qInput.value = '';
                    var sortEl = root.querySelector('.ndr-js-sort');
                    if (sortEl) sortEl.value = 'none';
                    root.querySelectorAll('.ndr-ctrl-filter select').forEach(function(s){ s.value = ''; });
                    root.querySelectorAll('.ndr-ctrl-filter input[type="checkbox"]').forEach(function(c){ c.checked = false; });
                    root.querySelectorAll('.ndr-ctrl-filter input[type="radio"][value=""]').forEach(function(r){ r.checked = true; });
                    update();
                });
            }

            // Execute custom template JS (compatible with legacy signature)
            function runCustomJs() {
                if (!cfg.customJs || !String(cfg.customJs).trim()) return;
                try {
                    var resultsEl = root.querySelector('.ndr-api-results');
                    var fn = new Function('wrapper', 'results', 'settings', 'items', 'getValue', 'renderItems', 'applyFilters', 'escapeHtml', String(cfg.customJs));
                    fn(root, resultsEl, cfg, allItems, getVal, function(){}, update, escHtml);
                } catch (e) {
                    console.error('NDR Custom JS error:', e);
                }
            }
            runCustomJs();

            // Built-in Animated Slide Lightbox (when enabled)
            if (cfg.enableLightbox) {
                var lightbox = document.getElementById('ndr-global-lightbox');
                if (!lightbox) {
                    lightbox = document.createElement('div');
                    lightbox.id = 'ndr-global-lightbox';
                    lightbox.className = 'ndr-lightbox';
                    lightbox.setAttribute('role', 'dialog');
                    lightbox.setAttribute('aria-modal', 'true');
                    lightbox.setAttribute('aria-hidden', 'true');
                    lightbox.innerHTML =
                        '<button type="button" class="ndr-lb-btn ndr-lb-close" aria-label="Kapat">&times;</button>' +
                        '<button type="button" class="ndr-lb-btn ndr-lb-nav ndr-lb-prev" aria-label="Önceki">&#10094;</button>' +
                        '<button type="button" class="ndr-lb-btn ndr-lb-nav ndr-lb-next" aria-label="Sonraki">&#10095;</button>' +
                        '<div class="ndr-lightbox-stage">' +
                            '<div class="ndr-slider-viewport">' +
                                '<div class="ndr-lightbox-loader"></div>' +
                                '<div class="ndr-slide slide-a pos-center"><img src="" alt="" /></div>' +
                                '<div class="ndr-slide slide-b pos-right"><img src="" alt="" /></div>' +
                            '</div>' +
                            '<div class="ndr-lightbox-meta">' +
                                '<div class="ndr-lightbox-counter"></div>' +
                                '<div class="ndr-lightbox-caption"></div>' +
                            '</div>' +
                        '</div>';
                    document.body.appendChild(lightbox);
                }

                var loaderEl   = lightbox.querySelector('.ndr-lightbox-loader');
                var slideA     = lightbox.querySelector('.slide-a');
                var slideB     = lightbox.querySelector('.slide-b');
                var captionEl  = lightbox.querySelector('.ndr-lightbox-caption');
                var counterEl  = lightbox.querySelector('.ndr-lightbox-counter');
                var btnClose   = lightbox.querySelector('.ndr-lb-close');
                var btnPrev    = lightbox.querySelector('.ndr-lb-prev');
                var btnNext    = lightbox.querySelector('.ndr-lb-next');
                var viewportEl = lightbox.querySelector('.ndr-slider-viewport');

                function isImgUrl(u) {
                    return /\.(jpe?g|png|webp|gif|avif|svg)(\?.*)?$/i.test(u || '');
                }

                function collectGallery(targetRoot) {
                    var list = [];
                    targetRoot.querySelectorAll('.ndr-api-results a, .ndr-api-results img.ndr-lightbox-img').forEach(function(el){
                        if (el.tagName === 'A') {
                            var h = el.getAttribute('href') || '';
                            if (isImgUrl(h) || el.hasAttribute('data-ndr-lightbox') || el.hasAttribute('data-lightbox') || el.classList.contains('ndr-lightbox-trigger')) {
                                var imgInside = el.querySelector('img');
                                list.push({
                                    el: el,
                                    src: h,
                                    title: el.getAttribute('title') || el.getAttribute('data-caption') || (imgInside ? (imgInside.getAttribute('alt') || '') : ''),
                                    alt: imgInside ? (imgInside.getAttribute('alt') || '') : ''
                                });
                            }
                        } else {
                            list.push({
                                el: el,
                                src: el.getAttribute('data-full') || el.src,
                                title: el.getAttribute('alt') || '',
                                alt: el.getAttribute('alt') || ''
                            });
                        }
                    });
                    return list;
                }

                if (!lightbox.dataset.ndrBound) {
                    lightbox.dataset.ndrBound = '1';
                    var activeSlide = slideA;
                    var incomingSlide = slideB;
                    var currentIndex = 0;
                    var isSliding = false;
                    var currentLoadToken = 0;
                    var galleryItems = [];

                    function closeLightbox() {
                        lightbox.classList.remove('is-active');
                        lightbox.setAttribute('aria-hidden', 'true');
                        document.body.style.overflow = '';
                        loaderEl.classList.remove('is-loading');
                        slideA.querySelector('img').src = '';
                        slideB.querySelector('img').src = '';
                        isSliding = false;
                        currentLoadToken++;
                    }

                    function openLightbox(items, index) {
                        galleryItems = items;
                        currentIndex = index;
                        var info = galleryItems[currentIndex];
                        if (!info) return;

                        captionEl.textContent = info.title || '';
                        counterEl.textContent = (currentIndex + 1) + ' / ' + galleryItems.length;
                        btnPrev.style.display = galleryItems.length > 1 ? 'flex' : 'none';
                        btnNext.style.display = galleryItems.length > 1 ? 'flex' : 'none';

                        activeSlide = slideA;
                        incomingSlide = slideB;
                        slideA.className = 'ndr-slide slide-a pos-center no-trans';
                        slideB.className = 'ndr-slide slide-b pos-right no-trans';

                        var activeImg = activeSlide.querySelector('img');
                        activeImg.src = '';
                        loaderEl.classList.add('is-loading');

                        var token = ++currentLoadToken;
                        var imgObj = new Image();
                        imgObj.onload = function () {
                            if (token !== currentLoadToken) return;
                            activeImg.src = info.src;
                            activeImg.alt = info.alt || '';
                            loaderEl.classList.remove('is-loading');
                        };
                        imgObj.onerror = function () {
                            if (token !== currentLoadToken) return;
                            activeImg.src = info.src;
                            loaderEl.classList.remove('is-loading');
                        };
                        imgObj.src = info.src;

                        lightbox.classList.add('is-active');
                        lightbox.setAttribute('aria-hidden', 'false');
                        document.body.style.overflow = 'hidden';
                    }

                    function slideTo(direction) {
                        if (isSliding || galleryItems.length <= 1) return;
                        isSliding = true;

                        if (direction === 'next') {
                            currentIndex = (currentIndex + 1) % galleryItems.length;
                        } else {
                            currentIndex = (currentIndex - 1 + galleryItems.length) % galleryItems.length;
                        }

                        var info = galleryItems[currentIndex];
                        captionEl.textContent = info.title || '';
                        counterEl.textContent = (currentIndex + 1) + ' / ' + galleryItems.length;

                        var incomingImg = incomingSlide.querySelector('img');
                        incomingImg.src = '';
                        loaderEl.classList.add('is-loading');

                        incomingSlide.className = 'ndr-slide no-trans ' + (direction === 'next' ? 'pos-right' : 'pos-left');
                        void incomingSlide.offsetWidth;

                        var token = ++currentLoadToken;
                        var tempImg = new Image();
                        tempImg.onload = function () {
                            if (token === currentLoadToken) {
                                incomingImg.src = info.src;
                                incomingImg.alt = info.alt || '';
                                loaderEl.classList.remove('is-loading');
                            }
                        };
                        tempImg.onerror = function () {
                            if (token === currentLoadToken) {
                                incomingImg.src = info.src;
                                loaderEl.classList.remove('is-loading');
                            }
                        };
                        tempImg.src = info.src;

                        activeSlide.className = 'ndr-slide ' + (direction === 'next' ? 'pos-left' : 'pos-right');
                        incomingSlide.className = 'ndr-slide pos-center';

                        setTimeout(function () {
                            var temp = activeSlide;
                            activeSlide = incomingSlide;
                            incomingSlide = temp;
                            isSliding = false;
                        }, 330);
                    }

                    window.__ndrOpenLightbox = openLightbox;

                    btnNext.addEventListener('click', function (e) { e.stopPropagation(); slideTo('next'); });
                    btnPrev.addEventListener('click', function (e) { e.stopPropagation(); slideTo('prev'); });
                    btnClose.addEventListener('click', closeLightbox);

                    lightbox.addEventListener('click', function (e) {
                        if (e.target === lightbox || e.target === viewportEl) {
                            closeLightbox();
                        }
                    });

                    document.addEventListener('keydown', function (e) {
                        if (!lightbox.classList.contains('is-active')) return;
                        if (e.key === 'Escape') closeLightbox();
                        else if (e.key === 'ArrowRight') slideTo('next');
                        else if (e.key === 'ArrowLeft') slideTo('prev');
                    });

                    var startX = 0, startY = 0;
                    lightbox.addEventListener('touchstart', function (e) {
                        startX = e.changedTouches[0].screenX;
                        startY = e.changedTouches[0].screenY;
                    }, { passive: true });

                    lightbox.addEventListener('touchend', function (e) {
                        var diffX = e.changedTouches[0].screenX - startX;
                        var diffY = e.changedTouches[0].screenY - startY;
                        if (Math.abs(diffX) > Math.abs(diffY) && Math.abs(diffX) > 45) {
                            slideTo(diffX < 0 ? 'next' : 'prev');
                        }
                    }, { passive: true });
                }

                root.addEventListener('click', function (e) {
                    var target = e.target.closest('a, img.ndr-lightbox-img');
                    if (!target) return;
                    var gallery = collectGallery(root);
                    var idx = -1;
                    for (var i = 0; i < gallery.length; i++) {
                        if (gallery[i].el === target) {
                            idx = i;
                            break;
                        }
                    }
                    if (idx !== -1 && window.__ndrOpenLightbox) {
                        e.preventDefault();
                        window.__ndrOpenLightbox(gallery, idx);
                    }
                });
            }
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    private static function render_items_html($items, $cfg, $view)
    {
        if (empty($items)) {
            return '<div class="ndr-empty-msg">' . esc_html($cfg['empty_message'] ?: __('Gösterilecek veri bulunamadı.', 'ndr-rest-api-to-render')) . '</div>';
        }
        $tpl = $cfg['template_html'];
        if ($view !== $cfg['layout_type']) {
            $presets = NDR_API_Template_Manager::get_default_presets();
            if (isset($presets[$view])) {
                $tpl = $presets[$view]['html'];
            }
        }
        if ($view === 'table') {
            $out = '<div class="ndr-results-table-wrap"><table class="ndr-table">';
            if (! empty($cfg['table_headers'])) {
                $out .= '<thead><tr>';
                foreach (explode(',', $cfg['table_headers']) as $th) {
                    $out .= '<th>' . esc_html(trim($th)) . '</th>';
                }
                $out .= '</tr></thead>';
            }
            $out .= '<tbody>';
            foreach ($items as $it) {
                $out .= self::render_item($tpl, $it);
            }
            $out .= '</tbody></table></div>';
            return $out;
        }
        $cls = ($view === 'list') ? 'ndr-results-list' : 'ndr-results-grid';
        $out = '<div class="' . esc_attr($cls) . '">';
        foreach ($items as $it) {
            $out .= self::render_item($tpl, $it);
        }
        $out .= '</div>';
        return $out;
    }
}