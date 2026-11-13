<?php



if (! defined('ABSPATH')) {
    exit;
}

if (! class_exists('GS_Sheet_Manager')) {

    /**
     * GS Sheet Manager main plugin class.
     */
    class GS_Sheet_Manager
    {

        /**
         * Plugin constructor.
         */
        public function __construct()
        {
            add_action('init', array($this, 'register_post_type'));
            add_filter('manage_gs_sheet_manager_posts_columns', array($this, 'add_ds_columns'));
            add_action('manage_gs_sheet_manager_posts_custom_column', array($this, 'render_ds_columns'), 10, 2);
            add_action('add_meta_boxes', array($this, 'register_meta_box'));
            add_action('save_post', array($this, 'save_meta_box'), 10, 2);
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
            add_action('wp_ajax_gs_sheet_manager_fetch_schema', array($this, 'ajax_fetch_schema'));
            add_action('admin_post_gs_sheet_manager_sync', array($this, 'handle_sync_action'));
            add_action('admin_post_gs_sheet_manager_delete_version', array($this, 'handle_delete_version_action'));
            add_action('admin_post_gs_sheet_manager_activate_version', array($this, 'handle_activate_version_action'));
            add_action('admin_post_gs_sheet_manager_bulk_delete_versions', array($this, 'handle_bulk_delete_versions_action'));
            add_action('rest_api_init', array($this, 'register_rest_routes'));
            add_shortcode('google_sheet', array($this, 'render_sheet_shortcode'));
            add_action('admin_notices', array($this, 'admin_notices'));
            add_action('before_delete_post', array($this, 'handle_post_deletion'));
        }

        /**
         * Register the hidden admin-only custom post type.
         */
        public function register_post_type()
        {
            $labels = array(
                'name'                  => __('Veri Kaynakları', 'gs-sheet-manager'),
                'singular_name'         => __('Veri Kaynağı', 'gs-sheet-manager'),
                'menu_name'             => __('Veri Kaynakları', 'gs-sheet-manager'),
                'add_new'               => __('Veri Kaynağı Ekle', 'gs-sheet-manager'),
                'add_new_item'          => __('Veri Kaynağı Ekle', 'gs-sheet-manager'),
                'edit_item'             => __('Veri Kaynağını Düzenle', 'gs-sheet-manager'),
                'new_item'              => __('Yeni Veri Kaynağı', 'gs-sheet-manager'),
                'view_item'             => __('Veri Kaynağını Görüntüle', 'gs-sheet-manager'),
                'search_items'          => __('Veri Kaynağı Ara', 'gs-sheet-manager'),
                'not_found'             => __('Veri kaynağı bulunamadı.', 'gs-sheet-manager'),
                'not_found_in_trash'    => __('Çöp kutusunda veri kaynağı bulunamadı.', 'gs-sheet-manager'),
            );

            $args = array(
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
                'menu_icon'           => 'dashicons-media-spreadsheet',
            );

            register_post_type('gs_sheet_manager', $args);
        }

        /**
         * Register the custom meta box for sheet settings, schema and version control.
         */

        public function add_ds_columns($columns)
        {
            $new = array();
            foreach ($columns as $k => $v) {
                $new[$k] = $v;
                if ('title' === $k) {
                    $new['ds_type']  = __('Kaynak Tipi', 'gs-sheet-manager');
                    $new['ds_stats'] = __('Kayıt / Versiyon', 'gs-sheet-manager');
                }
            }
            return $new;
        }

        public function render_ds_columns($column, $post_id)
        {
            if ('ds_type' === $column) {
                $t = get_post_meta($post_id, '_gs_source_type', true) ?: 'google_sheet';
                $map = array('google_sheet' => 'Google Sheet', 'json' => 'JSON / REST', 'csv' => 'CSV URL', 'xml' => 'XML URL', 'upload' => 'Dosya');
                echo esc_html($map[$t] ?? $t);
            } elseif ('ds_stats' === $column) {
                $ud = wp_upload_dir();
                $lf = trailingslashit($ud['basedir']) . 'gs-api-storage/' . absint($post_id) . '/data.json';
                $cnt = 0;
                if (file_exists($lf)) {
                    $dec = json_decode((string) file_get_contents($lf), true);
                    if (is_array($dec)) $cnt = count($dec);
                }
                $ver = get_post_meta($post_id, '_gs_active_version', true) ?: get_post_meta($post_id, '_gs_current_version', true);
                echo '<strong>' . esc_html($cnt) . ' kayıt</strong>';
                if ($ver) {
                    echo ' <span class="description">(v' . esc_html($ver) . ')</span>';
                }
            }
        }

        public function register_meta_box()
        {
            add_meta_box(
                'gs_sheet_manager_meta',
                __('GS Sheet Ayarları', 'gs-sheet-manager'),
                array($this, 'render_meta_box'),
                'gs_sheet_manager',
                'normal',
                'high'
            );
        }

        /**
         * Enqueue admin JavaScript for schema parsing and version actions.
         */
        public function enqueue_admin_assets($hook)
        {
            if ('post.php' !== $hook && 'post-new.php' !== $hook) {
                return;
            }

            $screen = get_current_screen();
            if (! $screen || 'gs_sheet_manager' !== $screen->post_type) {
                return;
            }

            wp_enqueue_media();
            wp_enqueue_script(
                'gs-sheet-manager-admin',
                plugins_url('ds-admin.js', __FILE__),
                array('jquery'),
                '1.0.3',
                true
            );

            wp_localize_script(
                'gs-sheet-manager-admin',
                'gsSheetManagerData',
                array(
                    'ajaxUrl'      => admin_url('admin-ajax.php'),
                    'nonce'        => wp_create_nonce('gs_sheet_manager_schema'),
                    'syncNonce'    => wp_create_nonce('gs_sheet_manager_sync'),
                    'deleteNonce'   => wp_create_nonce('gs_sheet_manager_delete'),
                    'activateNonce'   => wp_create_nonce('gs_sheet_manager_activate'),
                    'bulkDeleteNonce' => wp_create_nonce('gs_sheet_manager_bulk_delete'),
                )
            );
        }

        /**
         * Render the meta box UI with mapping table and version file list.
         */
        public function render_meta_box($post)
        {
            $source_type       = get_post_meta($post->ID, '_gs_source_type', true) ?: 'google_sheet';
            $sheet_url         = get_post_meta($post->ID, '_gs_sheet_url', true);
            $uploaded_file_url = get_post_meta($post->ID, '_gs_uploaded_file_url', true);
            $uploaded_file_id  = absint(get_post_meta($post->ID, '_gs_uploaded_file_id', true));
            $mapping_rules     = get_post_meta($post->ID, '_gs_mapping_rules', true);
            $mapping_rules     = ! empty($mapping_rules) ? json_decode($mapping_rules, true) : array();
            $current_ver       = get_post_meta($post->ID, '_gs_current_version', true);
            $post_nonce        = wp_nonce_field('gs_sheet_manager_meta', 'gs_sheet_manager_meta_nonce', true, false);
            $version_files     = $this->get_version_files($post->ID);
?>
            <div class="gs-sheet-manager-meta">
                <p>
                    <label for="gs_source_type"><strong><?php esc_html_e('Veri Kaynağı Tipi', 'gs-sheet-manager'); ?></strong></label><br />
                    <select id="gs_source_type" name="gs_source_type" class="widefat">
                        <option value="google_sheet" <?php selected($source_type, 'google_sheet'); ?>><?php esc_html_e('Google Sheet URL', 'gs-sheet-manager'); ?></option>
                        <option value="json" <?php selected($source_type, 'json'); ?>><?php esc_html_e('JSON / REST API URL', 'gs-sheet-manager'); ?></option>
                        <option value="csv" <?php selected($source_type, 'csv'); ?>><?php esc_html_e('CSV Dosya URL', 'gs-sheet-manager'); ?></option>
                        <option value="xml" <?php selected($source_type, 'xml'); ?>><?php esc_html_e('XML Dosya URL', 'gs-sheet-manager'); ?></option>
                        <option value="upload" <?php selected($source_type, 'upload'); ?>><?php esc_html_e('Dosya Yükleme (JSON / CSV / XML)', 'gs-sheet-manager'); ?></option>
                    </select>
                </p>
                <p id="gs-url-input-wrap" style="<?php echo ($source_type === 'upload') ? 'display:none;' : ''; ?>">
                    <label for="gs_sheet_manager_sheet_url"><strong><?php esc_html_e('Kaynak URL (Google Sheet / JSON / CSV / XML)', 'gs-sheet-manager'); ?></strong></label><br />
                    <input type="url" id="gs_sheet_manager_sheet_url" name="gs_sheet_manager_sheet_url" value="<?php echo esc_attr($sheet_url); ?>" class="widefat" placeholder="https://..." />
                </p>
                <p id="gs-upload-input-wrap" style="<?php echo ($source_type === 'upload') ? '' : 'display:none;'; ?>">
                    <label for="gs_uploaded_file_url"><strong><?php esc_html_e('Yüklü Dosya URL (JSON / CSV / XML)', 'gs-sheet-manager'); ?></strong></label><br />
                    <span style="display:flex;gap:8px;align-items:center;">
                        <input type="url" id="gs_uploaded_file_url" name="gs_uploaded_file_url" value="<?php echo esc_attr($uploaded_file_url); ?>" class="widefat" placeholder="https://.../dosya.json" />
                        <input type="hidden" id="gs_uploaded_file_id" name="gs_uploaded_file_id" value="<?php echo esc_attr($uploaded_file_id); ?>" />
                        <button type="button" class="button button-secondary" id="gs-upload-media-btn" style="white-space:nowrap;"><?php esc_html_e('Dosya Seç / Yükle', 'gs-sheet-manager'); ?></button>
                    </span>
                </p>
                <p>
                    <button type="button" class="button button-primary" id="gs-sheet-manager-analyze-button">
                        <?php esc_html_e('Şemayı Çözümle', 'gs-sheet-manager'); ?>
                    </button>
                </p>
                <div id="gs-sheet-manager-schema-container">
                    <?php if (! empty($mapping_rules)) : ?>
                        <?php $this->render_mapping_rows($mapping_rules); ?>
                    <?php else : ?>
                        <p><?php esc_html_e('URL girdikten sonra Şemayı Çözümle butonuna basın.', 'gs-sheet-manager'); ?></p>
                    <?php endif; ?>
                </div>
                <input type="hidden" name="gs_sheet_manager_mapping_rules" id="gs_sheet_manager_mapping_rules" value="<?php echo esc_attr(wp_json_encode($mapping_rules)); ?>" />
                <p>
                    <button type="button" class="button button-secondary" id="gs-sheet-manager-sync-button" data-post-id="<?php echo esc_attr($post->ID); ?>">
                        <?php esc_html_e('Şimdi Senkronize Et', 'gs-sheet-manager'); ?>
                    </button>
                </p>
                <?php
                $active_ver = get_post_meta($post->ID, '_gs_active_version', true);
                if (empty($active_ver) && ! empty($current_ver)) {
                    $active_ver = $current_ver;
                }
                $active_ver_num = absint($active_ver);
                $rest_url        = esc_url(rest_url('gs-api/v1/data/' . $post->ID));
                $upload_dir      = wp_upload_dir();
                $static_json_url = esc_url(trailingslashit($upload_dir['baseurl']) . 'gs-api-storage/' . absint($post->ID) . '/data.json');
                $active_info     = null;
                foreach ($version_files as $vf) {
                    if ($vf['number'] === $active_ver_num) {
                        $active_info = $vf;
                        break;
                    }
                }
                ?>
                <div class="gs-version-status-card">
                    <div class="gs-version-status-header">
                        <div>
                            <span class="gs-version-status-label"><?php esc_html_e('Yayındaki Aktif Versiyon', 'gs-sheet-manager'); ?></span>
                            <?php if ($active_ver_num > 0) : ?>
                                <span class="gs-version-badge gs-version-badge-live">
                                    <span class="gs-version-dot"></span>
                                    <?php echo esc_html(sprintf('v%d — YAYINDA', $active_ver_num)); ?>
                                </span>
                                <?php if ($active_info) : ?>
                                    <span class="gs-version-status-meta">
                                        <?php echo esc_html(date_i18n('d.m.Y H:i:s', $active_info['time'])); ?>
                                        &bull;
                                        <?php echo esc_html(sprintf(__('%d kayıt', 'gs-sheet-manager'), $active_info['rows'])); ?>
                                        &bull;
                                        <?php echo esc_html(size_format($active_info['size'])); ?>
                                    </span>
                                <?php endif; ?>
                            <?php else : ?>
                                <span class="gs-version-badge gs-version-badge-none"><?php esc_html_e('Henüz yayınlanmış versiyon yok', 'gs-sheet-manager'); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="gs-version-api-box" style="display:flex; flex-direction:column; gap:4px; align-items:flex-end;">
                            <div>
                                <strong><?php esc_html_e('Statik JSON (Hızlı):', 'gs-sheet-manager'); ?></strong>
                                <a href="<?php echo $static_json_url; ?>" target="_blank" rel="noopener noreferrer"><code><?php echo $static_json_url; ?></code></a>
                            </div>
                            <div>
                                <strong><?php esc_html_e('REST API:', 'gs-sheet-manager'); ?></strong>
                                <a href="<?php echo $rest_url; ?>" target="_blank" rel="noopener noreferrer"><code><?php echo $rest_url; ?></code></a>
                                <span class="description"><?php esc_html_e('(Belirli versiyon için ?version=N)', 'gs-sheet-manager'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <h4><?php esc_html_e('Versiyon Geçmişi', 'gs-sheet-manager'); ?></h4>
                <?php $this->render_version_table($post->ID, $version_files, $active_ver_num); ?>
                <?php echo $post_nonce; ?>
            </div>
            <style>
                .gs-sheet-manager-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 0.75rem;
                    background: #fff;
                }
                .gs-sheet-manager-table th,
                .gs-sheet-manager-table td {
                    border: 1px solid #dcdcde;
                    padding: 10px 12px;
                    vertical-align: middle;
                    text-align: left;
                }
                .gs-sheet-manager-table th {
                    background: #f6f7f7;
                    font-weight: 600;
                    color: #1d2327;
                }
                .gs-version-status-card {
                    margin: 16px 0 12px;
                    padding: 14px 16px;
                    background: #f0f9f2;
                    border: 1px solid #a7d7b4;
                    border-left: 4px solid #00a32a;
                    border-radius: 4px;
                }
                .gs-version-status-header {
                    display: flex;
                    flex-wrap: wrap;
                    justify-content: space-between;
                    align-items: center;
                    gap: 10px;
                }
                .gs-version-status-label {
                    font-weight: 600;
                    color: #1d2327;
                    margin-right: 8px;
                }
                .gs-version-status-meta {
                    color: #50575e;
                    font-size: 12.5px;
                    margin-left: 8px;
                }
                .gs-version-api-box {
                    font-size: 12.5px;
                    color: #3c434a;
                }
                .gs-version-badge {
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                    padding: 3px 10px;
                    border-radius: 12px;
                    font-size: 12px;
                    font-weight: 600;
                    line-height: 1.4;
                }
                .gs-version-badge-live {
                    background: #00a32a;
                    color: #fff;
                }
                .gs-version-badge-ver {
                    background: #f0f0f1;
                    color: #2c3338;
                    border: 1px solid #c3c4c7;
                }
                .gs-version-badge-none {
                    background: #dba617;
                    color: #fff;
                }
                .gs-version-dot {
                    width: 7px;
                    height: 7px;
                    border-radius: 50%;
                    background: #fff;
                    display: inline-block;
                }
                .gs-version-row-active {
                    background: #f2fbf4 !important;
                }
                .gs-version-row-active td:first-child {
                    border-left: 4px solid #00a32a;
                }
                .gs-version-actions {
                    display: flex;
                    align-items: center;
                    gap: 6px;
                    flex-wrap: wrap;
                }
                .gs-version-active-note {
                    color: #008a20;
                    font-size: 12px;
                    font-weight: 500;
                    margin-left: 4px;
                }
            </style>
        <?php
        }

        /**
         * Render saved mapping rows in the meta box.
         *
         * @param array $mapping_rules Mapping definition array.
         */
        private function render_mapping_rows($mapping_rules)
        {
        ?>
            <table class="gs-sheet-manager-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Aktif', 'gs-sheet-manager'); ?></th>
                        <th><?php esc_html_e('Sütun Başlığı', 'gs-sheet-manager'); ?></th>
                        <th><?php esc_html_e('Değişken Adı', 'gs-sheet-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mapping_rules as $index => $rule) : ?>
                        <tr>
                            <td style="text-align:center;">
                                <input type="checkbox" class="gs-sheet-manager-active" value="1" <?php checked(isset($rule['active']) && $rule['active'], true); ?> />
                            </td>
                            <td>
                                <input type="text" class="widefat gs-sheet-manager-header" value="<?php echo esc_attr($rule['header']); ?>" readonly />
                            </td>
                            <td>
                                <input type="text" class="widefat gs-sheet-manager-key" value="<?php echo esc_attr($rule['key']); ?>" />
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
<?php
        }

        /**
         * Render the list of version files and delete buttons.
         *
         * @param int   $post_id       Post ID.
         * @param array $version_files Version files list.
         */
        private function render_version_table($post_id, $version_files, $active_version = 0)
        {
            if (empty($version_files)) {
                echo '<p>' . esc_html__('Henüz versiyon dosyası yok.', 'gs-sheet-manager') . '</p>';
                return;
            }

            echo '<div class="gs-version-toolbar" style="display:flex; justify-content:space-between; align-items:center; margin:10px 0 6px; gap:10px; flex-wrap:wrap;">';
            echo '<div style="display:flex; align-items:center; gap:8px;">';
            echo '<button type="button" class="button button-secondary" id="gs-sheet-manager-bulk-delete-btn" data-post-id="' . esc_attr($post_id) . '" disabled>' . esc_html__('Seçilenleri Sil', 'gs-sheet-manager') . '</button>';
            echo '<span class="description">' . esc_html__('Yayındaki aktif versiyon toplu silme işlemine dahil edilmez.', 'gs-sheet-manager') . '</span>';
            echo '</div>';
            echo '<span class="description"><strong>' . esc_html(sprintf(__('Toplam %d versiyon', 'gs-sheet-manager'), count($version_files))) . '</strong></span>';
            echo '</div>';

            echo '<table class="gs-sheet-manager-table">';
            echo '<thead><tr>';
            echo '<th style="width:32px; text-align:center;"><input type="checkbox" id="gs-sheet-manager-select-all" title="' . esc_attr__('Tümünü Seç', 'gs-sheet-manager') . '" /></th>';
            echo '<th>' . esc_html__('Versiyon / Durum', 'gs-sheet-manager') . '</th>';
            echo '<th>' . esc_html__('Oluşturulma Tarihi', 'gs-sheet-manager') . '</th>';
            echo '<th>' . esc_html__('Kayıt / Boyut', 'gs-sheet-manager') . '</th>';
            echo '<th>' . esc_html__('İşlemler', 'gs-sheet-manager') . '</th>';
            echo '</tr></thead><tbody>';

            $upload_dir = wp_upload_dir();
            $base_url   = trailingslashit($upload_dir['baseurl']) . 'gs-api-storage/' . absint($post_id) . '/';
            foreach ($version_files as $file) {
                $file_name  = basename($file['name']);
                $ver_num    = absint($file['number']);
                $is_active  = ($active_version > 0 && $ver_num === $active_version);
                $modified   = date_i18n('d.m.Y H:i:s', $file['time']);
                $open_url   = esc_url($base_url . $file_name);
                $row_class  = $is_active ? ' class="gs-version-row-active"' : '';
                $rows_label = sprintf(_n('%d kayıt', '%d kayıt', $file['rows'], 'gs-sheet-manager'), $file['rows']);
                $size_label = size_format($file['size']);

                echo '<tr' . $row_class . '>';
                echo '<td style="text-align:center;">';
                if ($is_active) {
                    echo '<input type="checkbox" disabled title="' . esc_attr__('Yayındaki aktif versiyon silinemez', 'gs-sheet-manager') . '" />';
                } else {
                    echo '<input type="checkbox" class="gs-sheet-manager-version-cb" value="' . esc_attr($file_name) . '" />';
                }
                echo '</td>';
                echo '<td>';
                if ($is_active) {
                    echo '<span class="gs-version-badge gs-version-badge-live"><span class="gs-version-dot"></span>' . esc_html(sprintf('v%d — YAYINDA', $ver_num)) . '</span>';
                } else {
                    echo '<span class="gs-version-badge gs-version-badge-ver">' . esc_html(sprintf('v%d', $ver_num)) . '</span>';
                }
                echo '<div style="margin-top:4px; font-size:11.5px; color:#646970;"><code>' . esc_html($file_name) . '</code></div>';
                echo '</td>';
                echo '<td>' . esc_html($modified) . '</td>';
                echo '<td>' . esc_html($rows_label . ' • ' . $size_label) . '</td>';
                echo '<td><div class="gs-version-actions">';
                echo '<a class="button button-secondary" href="' . $open_url . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Önizle / Aç', 'gs-sheet-manager') . '</a>';
                if (! $is_active) {
                    echo '<button type="button" class="button button-primary gs-sheet-manager-activate-version" data-post-id="' . esc_attr($post_id) . '" data-file-name="' . esc_attr($file_name) . '" data-version="' . esc_attr($ver_num) . '">' . esc_html__('Yayına Al', 'gs-sheet-manager') . '</button>';
                    echo '<button type="button" class="button button-link-delete gs-sheet-manager-delete-version" data-post-id="' . esc_attr($post_id) . '" data-file-name="' . esc_attr($file_name) . '">' . esc_html__('Sil', 'gs-sheet-manager') . '</button>';
                } else {
                    echo '<span class="gs-version-active-note">' . esc_html__('Şu an yayında', 'gs-sheet-manager') . '</span>';
                }
                echo '</div></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        /**
         * Save meta box data when the post is saved.
         *
         * @param int     $post_id Post ID.
         * @param WP_Post $post    Post object.
         */
        public function save_meta_box($post_id, $post)
        {
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return;
            }

            if (! isset($_POST['gs_sheet_manager_meta_nonce']) || ! wp_verify_nonce(wp_unslash($_POST['gs_sheet_manager_meta_nonce']), 'gs_sheet_manager_meta')) {
                return;
            }

            if (! current_user_can('manage_options', $post_id)) {
                return;
            }

            if ('gs_sheet_manager' !== $post->post_type) {
                return;
            }

            if (isset($_POST['gs_source_type'])) {
                update_post_meta($post_id, '_gs_source_type', sanitize_text_field(wp_unslash($_POST['gs_source_type'])));
            }
            if (isset($_POST['gs_uploaded_file_url'])) {
                update_post_meta($post_id, '_gs_uploaded_file_url', esc_url_raw(wp_unslash($_POST['gs_uploaded_file_url'])));
            }
            if (isset($_POST['gs_uploaded_file_id'])) {
                update_post_meta($post_id, '_gs_uploaded_file_id', absint($_POST['gs_uploaded_file_id']));
            }
            if (isset($_POST['gs_sheet_manager_sheet_url'])) {
                $sheet_url = sanitize_text_field(wp_unslash($_POST['gs_sheet_manager_sheet_url']));
                update_post_meta($post_id, '_gs_sheet_url', $sheet_url);
            }

            if (isset($_POST['gs_sheet_manager_mapping_rules'])) {
                $mapping_rules = json_decode(wp_unslash($_POST['gs_sheet_manager_mapping_rules']), true);
                $mapping_rules = $this->sanitize_mapping_rules($mapping_rules);
                update_post_meta($post_id, '_gs_mapping_rules', wp_json_encode($mapping_rules));
            }
        }

        /**
         * Handle AJAX request to fetch the sheet schema from a Google Sheet URL.
         */
        public function ajax_fetch_schema()
        {
            check_ajax_referer('gs_sheet_manager_schema', 'security');

            if (! current_user_can('manage_options')) {
                wp_send_json_error(__('Yetkiniz yok.', 'gs-sheet-manager'), 403);
            }

            $source_type  = isset($_POST['source_type']) ? sanitize_text_field(wp_unslash($_POST['source_type'])) : 'google_sheet';
            $sheet_url    = isset($_POST['sheet_url']) ? sanitize_text_field(wp_unslash($_POST['sheet_url'])) : '';
            $uploaded_url = isset($_POST['uploaded_file_url']) ? sanitize_text_field(wp_unslash($_POST['uploaded_file_url'])) : '';

            $rows = $this->fetch_rows_by_source($source_type, $sheet_url, $uploaded_url);
            if (is_wp_error($rows)) {
                wp_send_json_error($rows->get_error_message());
            }

            $headers = array();
            if (! empty($rows) && is_array($rows[0])) {
                $headers = array_keys($rows[0]);
            }
            if (empty($headers)) {
                wp_send_json_error(__('Şema başlıkları okunamadı.', 'gs-sheet-manager'));
            }

            wp_send_json_success($headers);
        }

        /**
         * Handle the sync action to generate a versioned JSON file.
         */
        public function handle_sync_action()
        {
            if (! isset($_POST['gs_sheet_manager_sync_nonce']) || ! wp_verify_nonce(wp_unslash($_POST['gs_sheet_manager_sync_nonce']), 'gs_sheet_manager_sync')) {
                wp_die(esc_html__('Nonce doğrulaması başarısız.', 'gs-sheet-manager'));
            }

            if (! current_user_can('manage_options')) {
                wp_die(esc_html__('Yetkiniz yok.', 'gs-sheet-manager'));
            }

            $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
            if (! $post_id) {
                wp_die(esc_html__('Geçersiz post ID.', 'gs-sheet-manager'));
            }

            $source_type = get_post_meta($post_id, '_gs_source_type', true) ?: 'google_sheet';
            if (isset($_POST['gs_source_type'])) {
                $source_type = sanitize_text_field(wp_unslash($_POST['gs_source_type']));
            }

            $sheet_url = get_post_meta($post_id, '_gs_sheet_url', true);
            if (isset($_POST['gs_sheet_manager_sheet_url'])) {
                $sheet_url = sanitize_text_field(wp_unslash($_POST['gs_sheet_manager_sheet_url']));
            }

            $uploaded_url = get_post_meta($post_id, '_gs_uploaded_file_url', true);
            if (isset($_POST['gs_uploaded_file_url'])) {
                $uploaded_url = sanitize_text_field(wp_unslash($_POST['gs_uploaded_file_url']));
            }

            $mapping_rules = isset($_POST['gs_sheet_manager_mapping_rules']) ? json_decode(wp_unslash($_POST['gs_sheet_manager_mapping_rules']), true) : array();
            $mapping_rules = $this->sanitize_mapping_rules($mapping_rules);
            update_post_meta($post_id, '_gs_source_type', $source_type);
            update_post_meta($post_id, '_gs_mapping_rules', wp_json_encode($mapping_rules));
            update_post_meta($post_id, '_gs_sheet_url', $sheet_url);
            update_post_meta($post_id, '_gs_uploaded_file_url', $uploaded_url);

            $target_input = ($source_type === 'upload') ? $uploaded_url : $sheet_url;
            if (empty($target_input) || empty($mapping_rules)) {
                $redirect_url = add_query_arg(
                    array(
                        'post'                     => $post_id,
                        'action'                   => 'edit',
                        'post_type'                => 'gs_sheet_manager',
                        'gs_sheet_manager_message' => 'missing_data',
                    ),
                    admin_url('post.php')
                );
                wp_safe_redirect($redirect_url);
                exit;
            }

            $rows = $this->fetch_rows_by_source($source_type, $sheet_url, $uploaded_url);
            if (is_wp_error($rows)) {
                $this->redirect_with_message($post_id, 'fetch_error', $rows->get_error_message());
            }
            if (empty($rows)) {
                $this->redirect_with_message($post_id, 'no_rows', __('Kaynak içinde veri satırı bulunamadı.', 'gs-sheet-manager'));
            }

            $json_array = $this->build_json_array($rows, $mapping_rules);
            if (empty($json_array)) {
                $this->redirect_with_message(
                    $post_id,
                    'mapping_empty',
                    __('Aktif eşleme alanı yok veya eşleme satırlarla uyuşmuyor.', 'gs-sheet-manager')
                );
            }

            $save_result = $this->save_versioned_json($post_id, $json_array);
            if (is_wp_error($save_result)) {
                $this->redirect_with_message(
                    $post_id,
                    'save_error',
                    $save_result->get_error_message()
                );
            }

            $this->redirect_with_message($post_id, 'sync_success');
        }

        /**
         * Handle deletion of a versioned JSON file.
         */
        public function handle_delete_version_action()
        {
            if (! isset($_POST['gs_sheet_manager_delete_nonce']) || ! wp_verify_nonce(wp_unslash($_POST['gs_sheet_manager_delete_nonce']), 'gs_sheet_manager_delete')) {
                wp_die(esc_html__('Nonce doğrulaması başarısız.', 'gs-sheet-manager'));
            }

            if (! current_user_can('manage_options')) {
                wp_die(esc_html__('Yetkiniz yok.', 'gs-sheet-manager'));
            }

            $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
            $file_name = isset($_POST['file_name']) ? sanitize_file_name(wp_unslash($_POST['file_name'])) : '';

            if (! $post_id || empty($file_name)) {
                wp_die(esc_html__('Geçersiz parametre.', 'gs-sheet-manager'));
            }

            $delete_result = $this->delete_version_file($post_id, $file_name);
            if (is_wp_error($delete_result)) {
                wp_die(esc_html($delete_result->get_error_message()));
            }

            $redirect_url = add_query_arg(
                array(
                    'post'                    => $post_id,
                    'action'                  => 'edit',
                    'post_type'               => 'gs_sheet_manager',
                    'gs_sheet_manager_message' => 'delete_success',
                ),
                admin_url('post.php')
            );
            wp_safe_redirect($redirect_url);
            exit;
        }

        /**
         * Handle activating (rolling back to) a specific versioned JSON file.
         */
        public function handle_activate_version_action()
        {
            if (! isset($_POST['gs_sheet_manager_activate_nonce']) || ! wp_verify_nonce(wp_unslash($_POST['gs_sheet_manager_activate_nonce']), 'gs_sheet_manager_activate')) {
                wp_die(esc_html__('Nonce doğrulaması başarısız.', 'gs-sheet-manager'));
            }

            if (! current_user_can('manage_options')) {
                wp_die(esc_html__('Yetkiniz yok.', 'gs-sheet-manager'));
            }

            $post_id   = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
            $file_name = isset($_POST['file_name']) ? sanitize_file_name(wp_unslash($_POST['file_name'])) : '';

            if (! $post_id || empty($file_name) || ! preg_match('/^dgm_\d+_v(\d+)_\d{4}-\d{2}-\d{2}_\d{6}\.json$/', $file_name, $matches)) {
                wp_die(esc_html__('Geçersiz parametre.', 'gs-sheet-manager'));
            }

            $version_num = absint($matches[1]);
            $content     = $this->load_json_file($post_id, $file_name);
            if (is_wp_error($content)) {
                $this->redirect_with_message($post_id, 'save_error', $content->get_error_message());
            }

            $dir = $this->prepare_storage_directory($post_id);
            if (is_wp_error($dir)) {
                $this->redirect_with_message($post_id, 'save_error', $dir->get_error_message());
            }

            $this->init_filesystem();
            global $wp_filesystem;

            $latest_file = trailingslashit($dir) . 'latest.json';
            $data_file   = trailingslashit($dir) . 'data.json';
            if (! $wp_filesystem->put_contents($latest_file, $content, FS_CHMOD_FILE)) {
                $this->redirect_with_message($post_id, 'save_error', __('Latest dosyası güncellenemedi.', 'gs-sheet-manager'));
            }
            $wp_filesystem->put_contents($data_file, $content, FS_CHMOD_FILE);

            update_post_meta($post_id, '_gs_active_version', $version_num);

            $redirect_url = add_query_arg(
                array(
                    'post'                       => $post_id,
                    'action'                     => 'edit',
                    'post_type'                  => 'gs_sheet_manager',
                    'gs_sheet_manager_message'   => 'activate_success',
                    'gs_sheet_manager_version'   => $version_num,
                ),
                admin_url('post.php')
            );
            wp_safe_redirect($redirect_url);
            exit;
        }


        /**
         * Handle bulk deletion of selected versioned JSON files.
         */
        public function handle_bulk_delete_versions_action()
        {
            if (! isset($_POST['gs_sheet_manager_bulk_delete_nonce']) || ! wp_verify_nonce(wp_unslash($_POST['gs_sheet_manager_bulk_delete_nonce']), 'gs_sheet_manager_bulk_delete')) {
                wp_die(esc_html__('Nonce doğrulaması başarısız.', 'gs-sheet-manager'));
            }

            if (! current_user_can('manage_options')) {
                wp_die(esc_html__('Yetkiniz yok.', 'gs-sheet-manager'));
            }

            $post_id    = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
            $file_names = isset($_POST['file_names']) && is_array($_POST['file_names']) ? wp_unslash($_POST['file_names']) : array();

            if (! $post_id || empty($file_names)) {
                wp_die(esc_html__('Geçersiz parametre.', 'gs-sheet-manager'));
            }

            $active_ver = absint(get_post_meta($post_id, '_gs_active_version', true));
            if (empty($active_ver)) {
                $active_ver = absint(get_post_meta($post_id, '_gs_current_version', true));
            }

            $deleted_count = 0;
            foreach ($file_names as $raw_name) {
                $file_name = sanitize_file_name($raw_name);
                if (! preg_match('/^dgm_\d+_v(\d+)_\d{4}-\d{2}-\d{2}_\d{6}\.json$/', $file_name, $matches)) {
                    continue;
                }
                if ($active_ver > 0 && absint($matches[1]) === $active_ver) {
                    continue; // Protect live version
                }
                $res = $this->delete_version_file($post_id, $file_name);
                if (! is_wp_error($res)) {
                    $deleted_count++;
                }
            }

            $redirect_url = add_query_arg(
                array(
                    'post'                              => $post_id,
                    'action'                            => 'edit',
                    'post_type'                         => 'gs_sheet_manager',
                    'gs_sheet_manager_message'          => 'bulk_delete_success',
                    'gs_sheet_manager_deleted_count'    => $deleted_count,
                ),
                admin_url('post.php')
            );
            wp_safe_redirect($redirect_url);
            exit;
        }

        /**
         * Register the REST endpoint to read latest or specific JSON data.
         */
        public function register_rest_routes()
        {
            register_rest_route(
                'gs-api/v1',
                '/data/(?P<post_id>\d+)',
                array(
                    'methods'             => 'GET',
                    'callback'            => array($this, 'rest_get_sheet_data'),
                    'permission_callback' => '__return_true',
                    'args'                => array(
                        'post_id' => array(
                            'validate_callback' => function ($param) {
                                return is_numeric($param);
                            },
                        ),
                        'version' => array(
                            'validate_callback' => function ($param) {
                                return empty($param) || preg_match('/^[0-9]+$/', $param);
                            },
                        ),
                    ),
                )
            );
        }

        /**
         * Callback for the REST endpoint.
         *
         * @param WP_REST_Request $request Request object.
         * @return WP_REST_Response|WP_Error
         */
        public function rest_get_sheet_data($request)
        {
            $post_id = absint($request->get_param('post_id'));
            $version = $request->get_param('version');
            $file_name = ! empty($version) ? sprintf('v%s.json', absint($version)) : 'latest.json';

            $json = $this->load_json_file($post_id, $file_name);
            if (is_wp_error($json)) {
                return new WP_Error('gs_sheet_manager_not_found', $json->get_error_message(), array('status' => 404));
            }

            return rest_ensure_response(json_decode($json, true));
        }

        /**
         * Render the shortcode output as an HTML table from latest JSON data.
         *
         * @param array $atts Shortcode attributes.
         * @return string HTML string.
         */
        public function render_sheet_shortcode($atts)
        {
            $atts   = shortcode_atts(array('id' => 0), $atts, 'google_sheet');
            $post_id = absint($atts['id']);
            if (! $post_id) {
                return esc_html__('Geçersiz Sheet ID.', 'gs-sheet-manager');
            }

            $json = $this->load_json_file($post_id, 'latest.json');
            if (is_wp_error($json)) {
                return '<p>' . esc_html__('Veri bulunamadı.', 'gs-sheet-manager') . '</p>';
            }

            $data = json_decode($json, true);
            if (empty($data) || ! is_array($data)) {
                return '<p>' . esc_html__('Veri boş.', 'gs-sheet-manager') . '</p>';
            }

            $output = '<div class="gs-sheet-manager-shortcode"><table class="wp-list-table widefat fixed striped"><thead><tr>';
            $headers = array_keys(reset($data));
            foreach ($headers as $header) {
                $output .= '<th>' . esc_html($header) . '</th>';
            }
            $output .= '</tr></thead><tbody>';

            foreach ($data as $row) {
                $output .= '<tr>';
                foreach ($headers as $header) {
                    $output .= '<td>' . esc_html(isset($row[$header]) ? $row[$header] : '') . '</td>';
                }
                $output .= '</tr>';
            }

            $output .= '</tbody></table></div>';
            return $output;
        }

        /**
         * Show admin notices after sync and delete actions.
         */
        public function admin_notices()
        {
            if (empty($_GET['gs_sheet_manager_message'])) {
                return;
            }

            $message_key = sanitize_text_field(wp_unslash($_GET['gs_sheet_manager_message']));
            $ver_param = isset($_GET['gs_sheet_manager_version']) ? absint($_GET['gs_sheet_manager_version']) : 0;
            $del_count = isset($_GET['gs_sheet_manager_deleted_count']) ? absint($_GET['gs_sheet_manager_deleted_count']) : 0;
            $messages = array(
                'sync_success'     => __('Sheet başarıyla senkronize edildi ve yeni versiyon yayına alındı.', 'gs-sheet-manager'),
                'activate_success' => $ver_param ? sprintf(__('v%d versiyonu başarıyla yayına alındı. REST API ve kısa kod artık bu veriyi sunuyor.', 'gs-sheet-manager'), $ver_param) : __('Seçilen versiyon yayına alındı.', 'gs-sheet-manager'),
                'delete_success'      => __('Versiyon dosyası silindi.', 'gs-sheet-manager'),
                'bulk_delete_success' => sprintf(__('%d adet versiyon dosyası başarıyla silindi.', 'gs-sheet-manager'), $del_count),
                'missing_data'     => __('URL veya eşleme kuralları eksik.', 'gs-sheet-manager'),
                'invalid_url'      => __('Geçersiz Google Sheet URL si.', 'gs-sheet-manager'),
                'fetch_error'      => __('Google Sheet verisi alınamadı.', 'gs-sheet-manager'),
                'no_rows'          => __('Google Sheet içinde satır bulunamadı.', 'gs-sheet-manager'),
                'mapping_empty'    => __('Eşleme alanları aktif değil veya geçersiz.', 'gs-sheet-manager'),
                'save_error'       => __('JSON dosyası kaydedilirken bir hata oluştu.', 'gs-sheet-manager'),
            );

            if (isset($messages[$message_key])) {
                $type = in_array($message_key, array('sync_success', 'activate_success', 'delete_success', 'bulk_delete_success'), true) ? 'success' : 'error';
                echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($messages[$message_key]) . '</p></div>';
            }

            if (! empty($_GET['gs_sheet_manager_error'])) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(rawurldecode(wp_unslash($_GET['gs_sheet_manager_error']))) . '</p></div>';
            }
        }

        /**
         * Build the JSON array from CSV rows and mapping definitions.
         *
         * @param array $rows          CSV rows.
         * @param array $mapping_rules Mapping definitions.
         * @return array
         */
        private function build_json_array($rows, $mapping_rules)
        {
            $active_rules = array();
            foreach ($mapping_rules as $rule) {
                if (! empty($rule['active']) && ! empty($rule['key']) && ! empty($rule['header'])) {
                    $active_rules[$rule['header']] = $rule['key'];
                }
            }

            if (empty($active_rules)) {
                return array();
            }

            $output = array();
            foreach ($rows as $row) {
                $item = array();
                foreach ($active_rules as $header => $key) {
                    $item[$key] = $this->get_row_value_for_header($row, $header);
                }
                $output[] = $item;
            }

            return $output;
        }

        /**
         * Save a new versioned JSON file and ensure latest.json is updated.
         *
         * @param int   $post_id    Post ID.
         * @param array $json_array JSON array to save.
         * @return true|WP_Error
         */
        private function save_versioned_json($post_id, $json_array)
        {
            $dir = $this->prepare_storage_directory($post_id);
            if (is_wp_error($dir)) {
                return $dir;
            }

            $current_version = absint(get_post_meta($post_id, '_gs_current_version', true));
            $new_version     = $current_version + 1;
            $timestamp       = current_time('Y-m-d_His');
            $json_data       = wp_json_encode($json_array, JSON_UNESCAPED_UNICODE);
            if (false === $json_data) {
                return new WP_Error('json_encode_failed', __('JSON kodlaması başarısız oldu.', 'gs-sheet-manager'));
            }

            $version_file = trailingslashit($dir) . sprintf('dgm_%d_v%d_%s.json', absint($post_id), $new_version, $timestamp);
            $latest_file  = trailingslashit($dir) . 'latest.json';
            $data_file    = trailingslashit($dir) . 'data.json';

            $this->init_filesystem();
            global $wp_filesystem;

            if (! $wp_filesystem->put_contents($version_file, $json_data, FS_CHMOD_FILE)) {
                return new WP_Error('write_failed', __('Versiyon dosyası yazılamadı.', 'gs-sheet-manager'));
            }
            if (! $wp_filesystem->put_contents($latest_file, $json_data, FS_CHMOD_FILE)) {
                return new WP_Error('latest_write_failed', __('Latest dosyası yazılamadı.', 'gs-sheet-manager'));
            }
            $wp_filesystem->put_contents($data_file, $json_data, FS_CHMOD_FILE);

            update_post_meta($post_id, '_gs_current_version', $new_version);
            update_post_meta($post_id, '_gs_active_version', $new_version);
            $this->cleanup_old_versions($dir, $new_version);

            return true;
        }

        /**
         * Remove all plugin-generated data when a sheet post is permanently deleted.
         *
         * @param int $post_id Post ID.
         */
        public function handle_post_deletion($post_id)
        {
            if (empty($post_id)) {
                return;
            }

            if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
                return;
            }

            $post = get_post($post_id);
            if (! $post || 'gs_sheet_manager' !== $post->post_type) {
                return;
            }

            $meta_keys = get_post_custom_keys($post_id);
            if (! empty($meta_keys)) {
                foreach ($meta_keys as $meta_key) {
                    if (0 === strpos($meta_key, '_gs_')) {
                        delete_post_meta($post_id, $meta_key);
                    }
                }
            }

            $upload_dir = wp_upload_dir();
            if (! empty($upload_dir['basedir'])) {
                $dir = trailingslashit($upload_dir['basedir']) . 'gs-api-storage/' . absint($post_id);
                $this->init_filesystem();
                global $wp_filesystem;

                if (! empty($wp_filesystem) && method_exists($wp_filesystem, 'exists') && $wp_filesystem->exists($dir)) {
                    $wp_filesystem->delete($dir, true);
                } elseif (is_dir($dir)) {
                    wp_delete_file($dir);
                }
            }
        }

        /**
         * Get the CSV export URL for a Google Sheet.
         *
         * @param string $sheet_url User-entered sheet URL.
         * @return string|false
         */
        /**
         * Fetch and normalize rows from Google Sheet, JSON, CSV, XML or Uploaded File.
         */
        private function fetch_rows_by_source($source_type, $url, $uploaded_url = '')
        {
            $target_url = ($source_type === 'upload') ? trim($uploaded_url) : trim($url);
            if (empty($target_url)) {
                return new WP_Error('empty_url', __('Lütfen geçerli bir kaynak URL veya dosya girin.', 'gs-sheet-manager'));
            }

            if ($source_type === 'google_sheet') {
                $csv_url = $this->get_csv_export_url($target_url);
                if (! $csv_url) {
                    return new WP_Error('invalid_url', __('Geçerli bir Google Sheet URL si girin.', 'gs-sheet-manager'));
                }
                $content = $this->fetch_csv_content($csv_url);
                if (is_wp_error($content)) {
                    return $content;
                }
                return $this->parse_csv_rows($content);
            }

            $resp = wp_remote_get($target_url, array('timeout' => 20, 'sslverify' => false));
            if (is_wp_error($resp)) {
                return $resp;
            }
            $code = wp_remote_retrieve_response_code($resp);
            if ($code < 200 || $code >= 300) {
                return new WP_Error('http_error', sprintf(__('Veri kaynağına erişilemedi (HTTP %d).', 'gs-sheet-manager'), $code));
            }
            $body = wp_remote_retrieve_body($resp);
            if (trim($body) === '') {
                return new WP_Error('empty_body', __('Veri kaynağı boş yanıt döndürdü.', 'gs-sheet-manager'));
            }

            // Detect format if upload
            $fmt = $source_type;
            if ($fmt === 'upload') {
                $ext = strtolower(pathinfo(parse_url($target_url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
                if (in_array($ext, array('json', 'csv', 'xml'), true)) {
                    $fmt = $ext;
                } else {
                    $trim = ltrim($body);
                    $fmt = ($trim[0] === '{' || $trim[0] === '[') ? 'json' : (($trim[0] === '<') ? 'xml' : 'csv');
                }
            }

            if ($fmt === 'csv') {
                return $this->parse_csv_rows($body);
            }

            if ($fmt === 'json') {
                $decoded = json_decode($body, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return new WP_Error('json_error', __('Geçersiz JSON formatı: ', 'gs-sheet-manager') . json_last_error_msg());
                }
                return $this->normalize_json_rows($decoded);
            }

            if ($fmt === 'xml') {
                libxml_use_internal_errors(true);
                $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
                if ($xml === false) {
                    return new WP_Error('xml_error', __('Geçersiz XML formatı.', 'gs-sheet-manager'));
                }
                $arr = json_decode(wp_json_encode($xml), true);
                return $this->normalize_json_rows($arr);
            }

            return new WP_Error('unknown_format', __('Desteklenmeyen veri formatı.', 'gs-sheet-manager'));
        }

        private function normalize_json_rows($data)
        {
            if (empty($data) || ! is_array($data)) {
                return array();
            }
            // If associative wrapper like {"items": [...]} or {"channel": {"item": [...]}}
            if (! array_is_list($data)) {
                foreach ($data as $v) {
                    if (is_array($v) && array_is_list($v) && ! empty($v) && is_array($v[0])) {
                        $data = $v;
                        break;
                    }
                    if (is_array($v) && ! array_is_list($v)) {
                        foreach ($v as $sub) {
                            if (is_array($sub) && array_is_list($sub) && ! empty($sub) && is_array($sub[0])) {
                                $data = $sub;
                                break 2;
                            }
                        }
                    }
                }
                if (! array_is_list($data)) {
                    $data = array($data);
                }
            }

            $rows = array();
            foreach ($data as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $flat = array();
                foreach ($item as $k => $v) {
                    if (is_array($v)) {
                        $flat[(string) $k] = wp_json_encode($v, JSON_UNESCAPED_UNICODE);
                    } else {
                        $flat[(string) $k] = (string) $v;
                    }
                }
                if (! empty($flat)) {
                    $rows[] = $flat;
                }
            }
            return $rows;
        }
        private function get_csv_export_url($sheet_url)
        {
            if (empty($sheet_url)) {
                return false;
            }

            $sheet_url = trim($sheet_url);

            if (false !== strpos($sheet_url, 'export?format=csv') || false !== strpos($sheet_url, 'pub?output=csv')) {
                return esc_url_raw($sheet_url);
            }

            if (preg_match('#/d/([a-zA-Z0-9-_]+)#', $sheet_url, $matches)) {
                $spreadsheet_id = $matches[1];
            } else {
                return false;
            }

            $gid = '0';
            if (preg_match('/[\?&]gid=([0-9]+)/', $sheet_url, $gid_match)) {
                $gid = $gid_match[1];
            }

            return esc_url_raw(sprintf('https://docs.google.com/spreadsheets/d/%s/export?format=csv&gid=%s', rawurlencode($spreadsheet_id), rawurlencode($gid)));
        }

        /**
         * Build a fallback Google Visualization CSV URL.
         *
         * @param string $csv_url Original export URL.
         * @return string|false
         */
        private function build_gviz_csv_url($csv_url)
        {
            if (! preg_match('#/spreadsheets/d/([a-zA-Z0-9-_]+)/#', $csv_url, $matches)) {
                return false;
            }

            $spreadsheet_id = $matches[1];
            $gid = '0';
            if (preg_match('/[\?&]gid=([0-9]+)/', $csv_url, $gid_match)) {
                $gid = $gid_match[1];
            }

            return esc_url_raw(sprintf('https://docs.google.com/spreadsheets/d/%s/gviz/tq?tqx=out:csv&gid=%s', rawurlencode($spreadsheet_id), rawurlencode($gid)));
        }

        /**
         * Fetch CSV content from a remote URL.
         *
         * @param string $csv_url URL to fetch.
         * @return string|WP_Error
         */
        private function fetch_csv_content($csv_url)
        {
            $request_args = array(
                'timeout'      => 30,
                'redirection'  => 5,
                'headers'      => array(
                    'Accept'     => 'text/csv, */*;q=0.9',
                    'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
                ),
                'httpversion'  => '1.1',
            );

            $response = wp_remote_get(esc_url_raw($csv_url), $request_args);
            if (is_wp_error($response)) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);

            if (200 === $code) {
                if (empty($body)) {
                    return new WP_Error('empty_csv', __('CSV içeriği boş.', 'gs-sheet-manager'));
                }
                return $body;
            }

            $fallback_url = $this->build_gviz_csv_url($csv_url);
            if ($fallback_url && $fallback_url !== $csv_url) {
                $fallback_response = wp_remote_get($fallback_url, $request_args);
                if (! is_wp_error($fallback_response) && 200 === wp_remote_retrieve_response_code($fallback_response)) {
                    $fallback_body = wp_remote_retrieve_body($fallback_response);
                    if (! empty($fallback_body)) {
                        return $fallback_body;
                    }
                }
            }

            $body_excerpt = '';
            if (is_string($body) && '' !== trim($body)) {
                $body_excerpt = wp_trim_words(strip_tags($body), 30, '...');
            }

            $message = sprintf(
                __('CSV alınırken HTTP hatası oluştu. HTTP kodu: %1$s', 'gs-sheet-manager'),
                esc_html($code)
            );

            if (! empty($body_excerpt)) {
                $message .= ' ' . sprintf(__('Sunucu yanıtı: %s', 'gs-sheet-manager'), esc_html($body_excerpt));
            }

            if (is_string($body) && preg_match('/(sign in|authorization required|permission|403|404|not allowed|denied)/i', $body)) {
                $message = __('CSV alınırken erişim hatası oluştu. Lütfen Google Sheet paylaşım ayarlarını "Herkesle paylaşıma açık" olarak kontrol edin.', 'gs-sheet-manager');
            }

            return new WP_Error('http_error', $message);
        }

        /**
         * Normalize a CSV header value.
         *
         * @param string $header Header value.
         * @return string
         */
        private function normalize_csv_header($header)
        {
            if (! is_string($header)) {
                return '';
            }

            $header = trim($header);
            $header = str_replace("\xEF\xBB\xBF", '', $header);
            return trim($header);
        }

        /**
         * Normalize a header for comparison across different encodings and accents.
         *
         * @param string $header Header value.
         * @return string
         */
        private function normalize_header_for_matching($header)
        {
            if (! is_string($header)) {
                return '';
            }

            $header = $this->normalize_csv_header($header);
            $header = strtolower(remove_accents($header));
            $header = preg_replace('/[^a-z0-9]+/', '_', $header);
            return trim($header, '_');
        }

        /**
         * Find a row value matching a header using normalized comparisons.
         *
         * @param array  $row    CSV row data.
         * @param string $header Header name from mapping rule.
         * @return string
         */
        private function get_row_value_for_header($row, $header)
        {
            if (! is_array($row)) {
                return '';
            }

            $target_header = $this->normalize_header_for_matching($header);
            if ('' === $target_header) {
                return '';
            }

            if (isset($row[$header])) {
                return $row[$header];
            }

            foreach ($row as $row_header => $value) {
                if ($this->normalize_header_for_matching($row_header) === $target_header) {
                    return $value;
                }
            }

            return '';
        }

        /**
         * Parse CSV headers from content.
         *
         * @param string $csv_content CSV content.
         * @return array
         */
        private function parse_csv_headers($csv_content)
        {
            $lines = preg_split('/\r\n|\r|\n/', trim($csv_content));
            if (empty($lines)) {
                return array();
            }
            $header_row = str_getcsv(array_shift($lines));
            return array_map(array($this, 'normalize_csv_header'), $header_row);
        }

        /**
         * Parse all CSV rows into an associative array using headers.
         *
         * @param string $csv_content CSV content.
         * @return array
         */
        private function parse_csv_rows($csv_content)
        {
            $rows = array();
            $lines = preg_split('/\r\n|\r|\n/', trim($csv_content));
            if (count($lines) < 2) {
                return array();
            }
            $headers = array_map(array($this, 'normalize_csv_header'), str_getcsv(array_shift($lines)));
            foreach ($lines as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $row_values = str_getcsv($line);
                $row = array();
                foreach ($headers as $index => $header) {
                    $row[$header] = isset($row_values[$index]) ? $row_values[$index] : '';
                }
                $rows[] = $row;
            }
            return $rows;
        }

        /**
         * Sanitize mapping rules from input.
         *
         * @param mixed $rules Raw mapping rules.
         * @return array
         */
        private function sanitize_mapping_rules($rules)
        {
            if (! is_array($rules)) {
                return array();
            }

            $sanitized = array();
            foreach ($rules as $rule) {
                if (! is_array($rule)) {
                    continue;
                }
                $header = isset($rule['header']) ? $this->normalize_csv_header(sanitize_text_field($rule['header'])) : '';
                $key    = isset($rule['key']) ? sanitize_key(str_replace(' ', '_', $rule['key'])) : '';
                $active = isset($rule['active']) ? 1 : 0;
                if (empty($header)) {
                    continue;
                }
                if (empty($key)) {
                    $key = $this->normalize_key($header);
                }
                $sanitized[] = array(
                    'header' => $header,
                    'key'    => $key,
                    'active' => $active,
                );
            }
            return $sanitized;
        }

        /**
         * Normalize a header into a valid array key.
         *
         * @param string $value Input header.
         * @return string
         */
        private function normalize_key($value)
        {
            $value = strtolower(remove_accents($value));
            $value = preg_replace('/[^a-z0-9_]+/', '_', $value);
            $value = trim($value, '_');
            return $value ? $value : 'field_' . rand(1000, 9999);
        }

        /**
         * Initialize the WP_Filesystem API.
         *
         * @return bool|WP_Error
         */
        private function init_filesystem()
        {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            if (! WP_Filesystem()) {
                return new WP_Error('filesystem_init_failed', __('WP Filesystem başlatılamadı.', 'gs-sheet-manager'));
            }
            return true;
        }

        /**
         * Prepare the storage directory for a given post.
         *
         * @param int $post_id Post ID.
         * @return string|WP_Error
         */
        private function prepare_storage_directory($post_id)
        {
            $upload_dir = wp_upload_dir();
            if (empty($upload_dir['basedir'])) {
                return new WP_Error('upload_dir_missing', __('Yükleme dizini alınamadı.', 'gs-sheet-manager'));
            }

            $dir = trailingslashit($upload_dir['basedir']) . 'gs-api-storage/' . absint($post_id);
            $this->init_filesystem();
            global $wp_filesystem;

            if (! empty($wp_filesystem) && method_exists($wp_filesystem, 'is_dir')) {
                if (! $wp_filesystem->is_dir($dir)) {
                    if (! $wp_filesystem->mkdir($dir, FS_CHMOD_DIR)) {
                        if (! wp_mkdir_p($dir)) {
                            return new WP_Error('mkdir_failed', __('Depolama dizini oluşturulamadı.', 'gs-sheet-manager'));
                        }
                    }
                }
            } else {
                if (! wp_mkdir_p($dir)) {
                    return new WP_Error('mkdir_failed', __('Depolama dizini oluşturulamadı.', 'gs-sheet-manager'));
                }
            }

            return $dir;
        }

        /**
         * Get the versioned JSON files for display.
         *
         * @param int $post_id Post ID.
         * @return array
         */
        private function get_version_files($post_id)
        {
            $upload_dir = wp_upload_dir();
            if (empty($upload_dir['basedir'])) {
                return array();
            }

            $dir = trailingslashit($upload_dir['basedir']) . 'gs-api-storage/' . absint($post_id);
            $this->init_filesystem();
            global $wp_filesystem;

            if (! $wp_filesystem->is_dir($dir)) {
                return array();
            }

            $files         = $wp_filesystem->dirlist($dir);
            $version_files = array();
            $tz            = wp_timezone();

            foreach ($files as $name => $meta) {
                if (preg_match('/^dgm_\d+_v(\d+)_(\d{4}-\d{2}-\d{2}_\d{6})\.json$/', $name, $matches)) {
                    $file_path = trailingslashit($dir) . $name;
                    $dt        = DateTime::createFromFormat('Y-m-d_His', $matches[2], $tz);
                    $ts        = $dt ? $dt->getTimestamp() : (int) $meta['time'];
                    $size      = file_exists($file_path) ? (int) filesize($file_path) : (int) ($meta['size'] ?? 0);
                    $rows_cnt  = 0;
                    if (file_exists($file_path)) {
                        $decoded = json_decode(file_get_contents($file_path), true);
                        if (is_array($decoded)) {
                            $rows_cnt = count($decoded);
                        }
                    }

                    $version_files[] = array(
                        'name'   => $name,
                        'time'   => $ts,
                        'number' => absint($matches[1]),
                        'size'   => $size,
                        'rows'   => $rows_cnt,
                    );
                }
            }

            usort($version_files, function ($a, $b) {
                if ($a['number'] === $b['number']) {
                    return $b['time'] - $a['time'];
                }
                return $b['number'] - $a['number'];
            });

            return $version_files;
        }

        /**
         * Delete a specific version file from storage.
         *
         * @param int    $post_id  Post ID.
         * @param string $file_name File name.
         * @return true|WP_Error
         */
        private function delete_version_file($post_id, $file_name)
        {
            $upload_dir = wp_upload_dir();
            if (empty($upload_dir['basedir'])) {
                return new WP_Error('upload_dir_missing', __('Yükleme dizini alınamadı.', 'gs-sheet-manager'));
            }

            $dir = trailingslashit($upload_dir['basedir']) . 'gs-api-storage/' . absint($post_id);
            $this->init_filesystem();
            global $wp_filesystem;

            $file_path = trailingslashit($dir) . basename($file_name);
            if (! $wp_filesystem->exists($file_path)) {
                return new WP_Error('file_missing', __('Dosya bulunamadı.', 'gs-sheet-manager'));
            }
            if (! $wp_filesystem->delete($file_path)) {
                return new WP_Error('delete_failed', __('Dosya silinemedi.', 'gs-sheet-manager'));
            }
            return true;
        }

        /**
         * Load JSON content from a stored file.
         *
         * @param int    $post_id  Post ID.
         * @param string $file_name File name.
         * @return string|WP_Error
         */
        private function load_json_file($post_id, $file_name)
        {
            $upload_dir = wp_upload_dir();
            if (empty($upload_dir['basedir'])) {
                return new WP_Error('upload_dir_missing', __('Yükleme dizini alınamadı.', 'gs-sheet-manager'));
            }

            $dir = trailingslashit($upload_dir['basedir']) . 'gs-api-storage/' . absint($post_id);
            $this->init_filesystem();
            global $wp_filesystem;

            $file_path = trailingslashit($dir) . basename($file_name);
            if (! $wp_filesystem->exists($file_path) && preg_match('/^v(\d+)\.json$/', $file_name, $matches)) {
                $version_files = $wp_filesystem->dirlist($dir);
                $search_version = absint($matches[1]);
                $candidate = '';
                $candidate_time = 0;
                foreach ($version_files as $name => $meta) {
                    if (preg_match('/^dgm_\d+_v' . $search_version . '_\d{4}-\d{2}-\d{2}_\d{6}\.json$/', $name)) {
                        if ($meta['time'] > $candidate_time) {
                            $candidate = $name;
                            $candidate_time = $meta['time'];
                        }
                    }
                }
                if ($candidate) {
                    $file_path = trailingslashit($dir) . $candidate;
                }
            }

            if (! $wp_filesystem->exists($file_path)) {
                return new WP_Error('file_missing', __('JSON dosyası bulunamadı.', 'gs-sheet-manager'));
            }

            $content = $wp_filesystem->get_contents($file_path);
            if (false === $content) {
                return new WP_Error('read_failed', __('JSON dosyası okunamadı.', 'gs-sheet-manager'));
            }

            return $content;
        }

        /**
         * Remove older version files when there are more than 50 versions.
         *
         * @param string $dir Storage directory.
         */
        private function cleanup_old_versions($dir, $active_version = 0)
        {
            $this->init_filesystem();
            global $wp_filesystem;

            $files = $wp_filesystem->dirlist($dir);
            $versions = array();
            foreach ($files as $name => $meta) {
                if (preg_match('/^dgm_\d+_v(\d+)_\d{4}-\d{2}-\d{2}_\d{6}\.json$/', $name, $matches)) {
                    $versions[] = array(
                        'name' => $name,
                        'time' => $meta['time'],
                        'number' => absint($matches[1]),
                    );
                }
            }

            usort($versions, function ($a, $b) {
                return $a['number'] - $b['number'];
            });

            while (count($versions) > 50) {
                $old = array_shift($versions);
                if ($active_version > 0 && $old['number'] === $active_version) {
                    continue;
                }
                $wp_filesystem->delete(trailingslashit($dir) . $old['name']);
            }
        }

        /**
         * Redirect back to edit screen with a message parameter.
         *
         * @param int    $post_id       Post ID.
         * @param string $message       Message key.
         * @param string $error_message Optional error details.
         */
        private function redirect_with_message($post_id, $message, $error_message = '')
        {
            $args = array(
                'post'                    => $post_id,
                'action'                  => 'edit',
                'post_type'               => 'gs_sheet_manager',
                'gs_sheet_manager_message' => $message,
            );

            if (! empty($error_message)) {
                $args['gs_sheet_manager_error'] = rawurlencode($error_message);
            }

            $redirect_url = add_query_arg($args, admin_url('post.php'));
            wp_safe_redirect($redirect_url);
            exit;
        }
    }

}
