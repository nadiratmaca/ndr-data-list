<?php
if (! defined('ABSPATH')) {
    exit;
}

class NDR_API_File_Manager
{
    public function __construct()
    {
        add_action('admin_menu', array($this, 'register_menu'), 24);
        add_action('admin_init', array($this, 'handle_actions'));
    }

    public function register_menu()
    {
        add_submenu_page(
            'edit.php?post_type=ndr_api_source',
            __('Dosya Yöneticisi', 'ndr-rest-api-to-render'),
            __('Dosya Yöneticisi', 'ndr-rest-api-to-render'),
            'manage_options',
            'ndr-file-manager',
            array($this, 'render_page')
        );
    }

    public static function get_root_dir()
    {
        $settings = class_exists('NDR_API_Settings_Page') ? NDR_API_Settings_Page::get_settings() : array();
        $rel = trim((string) ($settings['fm_root_dir'] ?? 'wp-content/uploads/ndr-files'));
        if ($rel === '') {
            $rel = 'wp-content/uploads/ndr-files';
        }

        // Allow absolute path if inside ABSPATH, otherwise treat as relative to ABSPATH
        $abs = (strpos($rel, '/') === 0) ? $rel : trailingslashit(ABSPATH) . ltrim($rel, '/');
        if (! file_exists($abs)) {
            wp_mkdir_p($abs);
        }
        $real_root = realpath($abs);
        $real_absp = realpath(ABSPATH);
        if (! $real_root || ! $real_absp || strpos($real_root, $real_absp) !== 0) {
            $fallback = trailingslashit(ABSPATH) . 'wp-content/uploads/ndr-files';
            wp_mkdir_p($fallback);
            $real_root = realpath($fallback);
        }
        return rtrim($real_root, '/');
    }

    private function resolve_safe_path($subpath = '')
    {
        $root = self::get_root_dir();
        $subpath = trim(str_replace(array("\0", '..'), '', (string) $subpath), '/');
        $target = ($subpath === '') ? $root : $root . '/' . $subpath;
        $real = realpath($target);
        if (! $real || strpos($real, $root) !== 0) {
            return $root;
        }
        return rtrim($real, '/');
    }

    private function get_rel_path($abs_path)
    {
        $root = self::get_root_dir();
        if ($abs_path === $root) {
            return '';
        }
        return ltrim(substr($abs_path, strlen($root)), '/');
    }

    private function get_public_url($abs_path)
    {
        $absp = rtrim(realpath(ABSPATH), '/');
        if (strpos($abs_path, $absp) === 0) {
            $rel = ltrim(substr($abs_path, strlen($absp)), '/');
            return trailingslashit(site_url('/')) . str_replace('%2F', '/', rawurlencode($rel));
        }
        return '';
    }

    private function redirect_back($subpath, $msg = '', $err = '')
    {
        $args = array(
            'post_type' => 'ndr_api_source',
            'page'      => 'ndr-file-manager',
        );
        if ($subpath !== '') {
            $args['dir'] = $subpath;
        }
        if ($msg !== '') {
            $args['fm_msg'] = $msg;
        }
        if ($err !== '') {
            $args['fm_err'] = rawurlencode($err);
        }
        wp_safe_redirect(add_query_arg($args, admin_url('edit.php')));
        exit;
    }

    public function handle_actions()
    {
        if (! is_admin() || ! isset($_GET['page']) || 'ndr-file-manager' !== $_GET['page']) {
            return;
        }
        if (! current_user_can('manage_options')) {
            return;
        }

        // Download single file
        if (isset($_GET['fm_download'], $_GET['_wpnonce']) && wp_verify_nonce(wp_unslash($_GET['_wpnonce']), 'ndr_fm_download')) {
            $sub  = sanitize_text_field(wp_unslash($_GET['dir'] ?? ''));
            $dir  = $this->resolve_safe_path($sub);
            $name = basename(sanitize_text_field(wp_unslash($_GET['fm_download'])));
            $file = $this->resolve_safe_path(($sub ? $sub . '/' : '') . $name);
            if (is_file($file)) {
                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . basename($file) . '"');
                header('Content-Length: ' . filesize($file));
                readfile($file);
                exit;
            }
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ! isset($_POST['ndr_fm_nonce'])) {
            return;
        }
        if (! wp_verify_nonce(wp_unslash($_POST['ndr_fm_nonce']), 'ndr_fm_action')) {
            wp_die(esc_html__('Güvenlik doğrulaması başarısız.', 'ndr-rest-api-to-render'));
        }

        $sub = sanitize_text_field(wp_unslash($_POST['current_dir'] ?? ''));
        $dir = $this->resolve_safe_path($sub);
        $rel_dir = $this->get_rel_path($dir);
        $action = sanitize_text_field(wp_unslash($_POST['fm_action'] ?? ''));

        // 1. Upload multiple files
        if ($action === 'upload' && ! empty($_FILES['fm_files']['name'][0])) {
            $count = count($_FILES['fm_files']['name']);
            $ok = 0;
            for ($i = 0; $i < $count; $i++) {
                if ($_FILES['fm_files']['error'][$i] !== UPLOAD_ERR_OK) {
                    continue;
                }
                $fname = sanitize_file_name($_FILES['fm_files']['name'][$i]);
                if ($fname === '') {
                    continue;
                }
                $dest = $dir . '/' . $fname;
                if (move_uploaded_file($_FILES['fm_files']['tmp_name'][$i], $dest)) {
                    $ok++;
                }
            }
            $this->redirect_back($rel_dir, $ok > 0 ? 'uploaded' : '', $ok === 0 ? 'Dosya yüklenemedi.' : '');
        }

        // 2. Create Folder
        if ($action === 'mkdir') {
            $folder = sanitize_file_name(wp_unslash($_POST['folder_name'] ?? ''));
            if ($folder === '') {
                $this->redirect_back($rel_dir, '', 'Geçerli bir klasör adı girin.');
            }
            if (wp_mkdir_p($dir . '/' . $folder)) {
                $this->redirect_back($rel_dir, 'mkdir_ok');
            }
            $this->redirect_back($rel_dir, '', 'Klasör oluşturulamadı.');
        }

        // 3. Rename
        if ($action === 'rename') {
            $old_name = basename(sanitize_text_field(wp_unslash($_POST['old_name'] ?? '')));
            $new_name = sanitize_file_name(wp_unslash($_POST['new_name'] ?? ''));
            $old_path = $this->resolve_safe_path(($rel_dir ? $rel_dir . '/' : '') . $old_name);
            if ($old_name && $new_name && file_exists($old_path) && $old_path !== self::get_root_dir()) {
                rename($old_path, $dir . '/' . $new_name);
                $this->redirect_back($rel_dir, 'renamed');
            }
            $this->redirect_back($rel_dir, '', 'Yeniden adlandırma başarısız.');
        }

        // 4. Save Edited File Content
        if ($action === 'save_file') {
            $fname   = basename(sanitize_text_field(wp_unslash($_POST['file_name'] ?? '')));
            $content = wp_unslash($_POST['file_content'] ?? '');
            $fpath   = $this->resolve_safe_path(($rel_dir ? $rel_dir . '/' : '') . $fname);
            if (is_file($fpath) && is_writable($fpath)) {
                file_put_contents($fpath, $content);
                $this->redirect_back($rel_dir, 'saved');
            }
            $this->redirect_back($rel_dir, '', 'Dosya kaydedilemedi.');
        }

        // 5. Single Delete
        if ($action === 'delete_one') {
            $fname = basename(sanitize_text_field(wp_unslash($_POST['target_name'] ?? '')));
            $fpath = $this->resolve_safe_path(($rel_dir ? $rel_dir . '/' : '') . $fname);
            if ($fpath !== self::get_root_dir() && file_exists($fpath)) {
                $this->delete_recursive($fpath);
                $this->redirect_back($rel_dir, 'deleted');
            }
            $this->redirect_back($rel_dir, '', 'Silme işlemi başarısız.');
        }

        // 6. Unzip
        if ($action === 'unzip') {
            if (! class_exists('ZipArchive')) {
                $this->redirect_back($rel_dir, '', 'Sunucuda PHP ZipArchive desteği yok.');
            }
            $fname = basename(sanitize_text_field(wp_unslash($_POST['target_name'] ?? '')));
            $fpath = $this->resolve_safe_path(($rel_dir ? $rel_dir . '/' : '') . $fname);
            if (! is_file($fpath) || strtolower(pathinfo($fpath, PATHINFO_EXTENSION)) !== 'zip') {
                $this->redirect_back($rel_dir, '', 'Geçerli bir ZIP dosyası bulunamadı.');
            }
            $zip = new ZipArchive();
            if ($zip->open($fpath) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->getNameIndex($i);
                    if (strpos($entry, '..') !== false || strpos($entry, '/') === 0) {
                        continue; // Zip-Slip protection
                    }
                    $dest = $dir . '/' . $entry;
                    if (substr($entry, -1) === '/') {
                        wp_mkdir_p($dest);
                    } else {
                        wp_mkdir_p(dirname($dest));
                        copy('zip://' . $fpath . '#' . $entry, $dest);
                    }
                }
                $zip->close();
                $this->redirect_back($rel_dir, 'unzipped');
            }
            $this->redirect_back($rel_dir, '', 'ZIP arşivi açılamadı.');
        }

        // 7. Bulk Actions (bulk_delete / bulk_zip)
        if ($action === 'bulk') {
            $bulk_op = sanitize_text_field(wp_unslash($_POST['bulk_op'] ?? ''));
            $items   = isset($_POST['selected_items']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['selected_items'])) : array();
            if (empty($items)) {
                $this->redirect_back($rel_dir, '', 'Hiçbir öğe seçilmedi.');
            }

            if ($bulk_op === 'delete') {
                foreach ($items as $it) {
                    $p = $this->resolve_safe_path(($rel_dir ? $rel_dir . '/' : '') . basename($it));
                    if ($p !== self::get_root_dir() && file_exists($p)) {
                        $this->delete_recursive($p);
                    }
                }
                $this->redirect_back($rel_dir, 'bulk_deleted');
            }

            if ($bulk_op === 'zip') {
                if (! class_exists('ZipArchive')) {
                    $this->redirect_back($rel_dir, '', 'Sunucuda PHP ZipArchive desteği yok.');
                }
                $zip_name = 'paket_' . date('Y-m-d_His') . '.zip';
                $zip_path = $dir . '/' . $zip_name;
                $zip = new ZipArchive();
                if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                    foreach ($items as $it) {
                        $name = basename($it);
                        $p = $this->resolve_safe_path(($rel_dir ? $rel_dir . '/' : '') . $name);
                        if (is_file($p)) {
                            $zip->addFile($p, $name);
                        } elseif (is_dir($p)) {
                            $this->add_dir_to_zip($zip, $p, $name);
                        }
                    }
                    $zip->close();
                    $this->redirect_back($rel_dir, 'zipped');
                }
                $this->redirect_back($rel_dir, '', 'ZIP paketi oluşturulamadı.');
            }
        }
    }

    private function add_dir_to_zip($zip, $dir, $base)
    {
        $zip->addEmptyDir($base);
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $full = $dir . '/' . $f;
            if (is_dir($full)) {
                $this->add_dir_to_zip($zip, $full, $base . '/' . $f);
            } else {
                $zip->addFile($full, $base . '/' . $f);
            }
        }
    }

    private function delete_recursive($path)
    {
        if (is_file($path) || is_link($path)) {
            return @unlink($path);
        }
        if (is_dir($path)) {
            foreach (scandir($path) as $f) {
                if ($f === '.' || $f === '..') continue;
                $this->delete_recursive($path . '/' . $f);
            }
            return @rmdir($path);
        }
        return false;
    }

    public function render_page()
    {
        $root    = self::get_root_dir();
        $sub     = sanitize_text_field(wp_unslash($_GET['dir'] ?? ''));
        $cur_dir = $this->resolve_safe_path($sub);
        $rel_dir = $this->get_rel_path($cur_dir);

        // Edit file mode?
        $edit_file = isset($_GET['edit']) ? basename(sanitize_text_field(wp_unslash($_GET['edit']))) : '';
        $edit_path = $edit_file ? $this->resolve_safe_path(($rel_dir ? $rel_dir . '/' : '') . $edit_file) : '';
        $is_editing = ($edit_file && is_file($edit_path));

        $msg_map = array(
            'uploaded'     => 'Dosya(lar) başarıyla yüklendi.',
            'mkdir_ok'     => 'Klasör oluşturuldu.',
            'renamed'      => 'Öğe yeniden adlandırıldı.',
            'saved'        => 'Dosya içeriği kaydedildi.',
            'deleted'      => 'Öğe silindi.',
            'bulk_deleted' => 'Seçilen öğeler silindi.',
            'zipped'       => 'ZIP paketi oluşturuldu.',
            'unzipped'     => 'ZIP arşivi mevcut klasöre açıldı.',
        );
        $fm_msg = sanitize_text_field(wp_unslash($_GET['fm_msg'] ?? ''));
        $fm_err = sanitize_text_field(wp_unslash($_GET['fm_err'] ?? ''));

        $entries = scandir($cur_dir);
        $dirs = array();
        $files = array();
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') continue;
            $full = $cur_dir . '/' . $e;
            if (is_dir($full)) {
                $dirs[] = $e;
            } else {
                $files[] = $e;
            }
        }
        natcasesort($dirs);
        natcasesort($files);

        $editable_exts = array('json', 'csv', 'xml', 'txt', 'html', 'htm', 'css', 'js', 'md', 'svg');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Dosya Yöneticisi', 'ndr-rest-api-to-render'); ?></h1>
            <p class="description">
                <strong><?php esc_html_e('Kök Dizin:', 'ndr-rest-api-to-render'); ?></strong> <code><?php echo esc_html($root); ?></code>
                &mdash; <a href="<?php echo esc_url(admin_url('edit.php?post_type=ndr_api_source&page=ndr-data-settings')); ?>"><?php esc_html_e('Kök Dizini Değiştir (Ayarlar)', 'ndr-rest-api-to-render'); ?></a>
            </p>

            <?php if ($fm_msg && isset($msg_map[$fm_msg])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($msg_map[$fm_msg]); ?></p></div>
            <?php endif; ?>
            <?php if ($fm_err) : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html($fm_err); ?></p></div>
            <?php endif; ?>

            <!-- Breadcrumb -->
            <div style="background:#fff;border:1px solid #dcdcde;padding:10px 14px;border-radius:4px;margin:12px 0;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                <strong><?php esc_html_e('Konum:', 'ndr-rest-api-to-render'); ?></strong>
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=ndr_api_source&page=ndr-file-manager')); ?>">🏠 Kök</a>
                <?php
                if ($rel_dir !== '') {
                    $acc = '';
                    foreach (explode('/', $rel_dir) as $part) {
                        $acc = ($acc === '') ? $part : $acc . '/' . $part;
                        $u = add_query_arg(array('post_type' => 'ndr_api_source', 'page' => 'ndr-file-manager', 'dir' => $acc), admin_url('edit.php'));
                        echo ' / <a href="' . esc_url($u) . '">' . esc_html($part) . '</a>';
                    }
                }
                ?>
            </div>

            <?php if ($is_editing) : ?>
                <div style="background:#fff;border:1px solid #dcdcde;padding:16px;border-radius:6px;margin-bottom:16px;">
                    <h2 style="margin-top:0;"><?php echo esc_html(sprintf(__('Dosya Düzenleniyor: %s', 'ndr-rest-api-to-render'), $edit_file)); ?></h2>
                    <form method="post">
                        <?php wp_nonce_field('ndr_fm_action', 'ndr_fm_nonce'); ?>
                        <input type="hidden" name="fm_action" value="save_file" />
                        <input type="hidden" name="current_dir" value="<?php echo esc_attr($rel_dir); ?>" />
                        <input type="hidden" name="file_name" value="<?php echo esc_attr($edit_file); ?>" />
                        <textarea name="file_content" rows="18" class="widefat" style="font-family:monospace;font-size:13px;"><?php echo esc_textarea((string) file_get_contents($edit_path)); ?></textarea>
                        <p style="margin-top:10px;">
                            <button type="submit" class="button button-primary"><?php esc_html_e('Değişiklikleri Kaydet', 'ndr-rest-api-to-render'); ?></button>
                            <a href="<?php echo esc_url(add_query_arg(array('post_type' => 'ndr_api_source', 'page' => 'ndr-file-manager', 'dir' => $rel_dir), admin_url('edit.php'))); ?>" class="button button-secondary"><?php esc_html_e('İptal / Geri Dön', 'ndr-rest-api-to-render'); ?></a>
                        </p>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Upload & Create Folder Bar -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                <form method="post" enctype="multipart/form-data" style="background:#fff;border:1px solid #dcdcde;padding:12px 14px;border-radius:4px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <?php wp_nonce_field('ndr_fm_action', 'ndr_fm_nonce'); ?>
                    <input type="hidden" name="fm_action" value="upload" />
                    <input type="hidden" name="current_dir" value="<?php echo esc_attr($rel_dir); ?>" />
                    <strong><?php esc_html_e('Dosya Yükle:', 'ndr-rest-api-to-render'); ?></strong>
                    <input type="file" name="fm_files[]" multiple required />
                    <button type="submit" class="button button-primary"><?php esc_html_e('Yükle', 'ndr-rest-api-to-render'); ?></button>
                </form>

                <form method="post" style="background:#fff;border:1px solid #dcdcde;padding:12px 14px;border-radius:4px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <?php wp_nonce_field('ndr_fm_action', 'ndr_fm_nonce'); ?>
                    <input type="hidden" name="fm_action" value="mkdir" />
                    <input type="hidden" name="current_dir" value="<?php echo esc_attr($rel_dir); ?>" />
                    <strong><?php esc_html_e('Yeni Klasör:', 'ndr-rest-api-to-render'); ?></strong>
                    <input type="text" name="folder_name" placeholder="klasor-adi" required />
                    <button type="submit" class="button button-secondary"><?php esc_html_e('Oluştur', 'ndr-rest-api-to-render'); ?></button>
                </form>
            </div>

            <!-- Bulk Actions & File Table -->
            <form method="post" id="ndr-fm-bulk-form">
                <?php wp_nonce_field('ndr_fm_action', 'ndr_fm_nonce'); ?>
                <input type="hidden" name="fm_action" value="bulk" />
                <input type="hidden" name="current_dir" value="<?php echo esc_attr($rel_dir); ?>" />

                <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
                    <select name="bulk_op" id="ndr-fm-bulk-op">
                        <option value=""><?php esc_html_e('Toplu İşlemler', 'ndr-rest-api-to-render'); ?></option>
                        <option value="zip"><?php esc_html_e('Seçilenleri ZIP Paketi Yap', 'ndr-rest-api-to-render'); ?></option>
                        <option value="delete"><?php esc_html_e('Seçilenleri Sil', 'ndr-rest-api-to-render'); ?></option>
                    </select>
                    <button type="submit" class="button button-secondary" id="ndr-fm-bulk-apply"><?php esc_html_e('Uygula', 'ndr-rest-api-to-render'); ?></button>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:32px;"><input type="checkbox" id="ndr-fm-select-all" /></th>
                            <th><?php esc_html_e('Ad', 'ndr-rest-api-to-render'); ?></th>
                            <th style="width:110px;"><?php esc_html_e('Boyut', 'ndr-rest-api-to-render'); ?></th>
                            <th style="width:165px;"><?php esc_html_e('Değiştirilme', 'ndr-rest-api-to-render'); ?></th>
                            <th style="width:320px;"><?php esc_html_e('İşlemler', 'ndr-rest-api-to-render'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($dirs) && empty($files)) : ?>
                            <tr><td colspan="5"><?php esc_html_e('Bu klasör boş.', 'ndr-rest-api-to-render'); ?></td></tr>
                        <?php endif; ?>

                        <?php foreach ($dirs as $d) :
                            $full = $cur_dir . '/' . $d;
                            $sub_u = ($rel_dir === '') ? $d : $rel_dir . '/' . $d;
                            $open_u = add_query_arg(array('post_type' => 'ndr_api_source', 'page' => 'ndr-file-manager', 'dir' => $sub_u), admin_url('edit.php'));
                        ?>
                            <tr>
                                <td><input type="checkbox" name="selected_items[]" value="<?php echo esc_attr($d); ?>" class="ndr-fm-cb" /></td>
                                <td>📁 <a href="<?php echo esc_url($open_u); ?>"><strong><?php echo esc_html($d); ?></strong></a></td>
                                <td>—</td>
                                <td><?php echo esc_html(date_i18n('d.m.Y H:i', filemtime($full))); ?></td>
                                <td>
                                    <button type="button" class="button button-small ndr-fm-rename-btn" data-name="<?php echo esc_attr($d); ?>"><?php esc_html_e('Yeniden Adlandır', 'ndr-rest-api-to-render'); ?></button>
                                    <button type="button" class="button button-small button-link-delete ndr-fm-del-btn" data-name="<?php echo esc_attr($d); ?>"><?php esc_html_e('Sil', 'ndr-rest-api-to-render'); ?></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php foreach ($files as $f) :
                            $full = $cur_dir . '/' . $f;
                            $ext  = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                            $pub_url = $this->get_public_url($full);
                            $dl_url  = wp_nonce_url(add_query_arg(array('post_type' => 'ndr_api_source', 'page' => 'ndr-file-manager', 'dir' => $rel_dir, 'fm_download' => $f), admin_url('edit.php')), 'ndr_fm_download');
                            $ed_url  = add_query_arg(array('post_type' => 'ndr_api_source', 'page' => 'ndr-file-manager', 'dir' => $rel_dir, 'edit' => $f), admin_url('edit.php'));
                        ?>
                            <tr>
                                <td><input type="checkbox" name="selected_items[]" value="<?php echo esc_attr($f); ?>" class="ndr-fm-cb" /></td>
                                <td>
                                    📄 <strong><?php echo esc_html($f); ?></strong>
                                    <?php if ($pub_url) : ?>
                                        <button type="button" class="button-link ndr-fm-copy-url" data-url="<?php echo esc_attr($pub_url); ?>" style="font-size:11px;margin-left:6px;">[URL Kopyala]</button>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(size_format(filesize($full))); ?></td>
                                <td><?php echo esc_html(date_i18n('d.m.Y H:i', filemtime($full))); ?></td>
                                <td>
                                    <a href="<?php echo esc_url($dl_url); ?>" class="button button-small"><?php esc_html_e('İndir', 'ndr-rest-api-to-render'); ?></a>
                                    <?php if (in_array($ext, $editable_exts, true)) : ?>
                                        <a href="<?php echo esc_url($ed_url); ?>" class="button button-small"><?php esc_html_e('Düzenle', 'ndr-rest-api-to-render'); ?></a>
                                    <?php endif; ?>
                                    <?php if ($ext === 'zip') : ?>
                                        <button type="button" class="button button-small button-primary ndr-fm-unzip-btn" data-name="<?php echo esc_attr($f); ?>"><?php esc_html_e('ZIP Aç', 'ndr-rest-api-to-render'); ?></button>
                                    <?php endif; ?>
                                    <button type="button" class="button button-small ndr-fm-rename-btn" data-name="<?php echo esc_attr($f); ?>"><?php esc_html_e('Ad Değiştir', 'ndr-rest-api-to-render'); ?></button>
                                    <button type="button" class="button button-small button-link-delete ndr-fm-del-btn" data-name="<?php echo esc_attr($f); ?>"><?php esc_html_e('Sil', 'ndr-rest-api-to-render'); ?></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>

            <!-- Hidden single-action form -->
            <form method="post" id="ndr-fm-single-form" style="display:none;">
                <?php wp_nonce_field('ndr_fm_action', 'ndr_fm_nonce'); ?>
                <input type="hidden" name="current_dir" value="<?php echo esc_attr($rel_dir); ?>" />
                <input type="hidden" name="fm_action" id="ndr-fm-single-action" value="" />
                <input type="hidden" name="target_name" id="ndr-fm-single-target" value="" />
                <input type="hidden" name="old_name" id="ndr-fm-single-old" value="" />
                <input type="hidden" name="new_name" id="ndr-fm-single-new" value="" />
            </form>

            <script>
            jQuery(function($){
                $('#ndr-fm-select-all').on('change', function(){
                    $('.ndr-fm-cb').prop('checked', $(this).is(':checked'));
                });
                $('#ndr-fm-bulk-form').on('submit', function(e){
                    var op = $('#ndr-fm-bulk-op').val();
                    if (!op) { e.preventDefault(); alert('Lütfen bir toplu işlem seçin.'); return; }
                    if (op === 'delete' && !confirm('Seçilen dosya/klasörleri silmek istediğinize emin misiniz?')) {
                        e.preventDefault();
                    }
                });
                $('.ndr-fm-del-btn').on('click', function(){
                    var name = $(this).data('name');
                    if (!confirm(name + ' silinsin mi?')) return;
                    $('#ndr-fm-single-action').val('delete_one');
                    $('#ndr-fm-single-target').val(name);
                    $('#ndr-fm-single-form').submit();
                });
                $('.ndr-fm-unzip-btn').on('click', function(){
                    var name = $(this).data('name');
                    if (!confirm(name + ' arşivi mevcut klasöre açılsın mı?')) return;
                    $('#ndr-fm-single-action').val('unzip');
                    $('#ndr-fm-single-target').val(name);
                    $('#ndr-fm-single-form').submit();
                });
                $('.ndr-fm-rename-btn').on('click', function(){
                    var oldName = $(this).data('name');
                    var newName = prompt('Yeni ad:', oldName);
                    if (!newName || newName === oldName) return;
                    $('#ndr-fm-single-action').val('rename');
                    $('#ndr-fm-single-old').val(oldName);
                    $('#ndr-fm-single-new').val(newName);
                    $('#ndr-fm-single-form').submit();
                });
                $('.ndr-fm-copy-url').on('click', function(){
                    var $b = $(this), u = $b.data('url');
                    if (navigator.clipboard) navigator.clipboard.writeText(u);
                    $b.text('[Kopyalandı!]');
                    setTimeout(function(){ $b.text('[URL Kopyala]'); }, 1400);
                });
            });
            </script>
        </div>
        <?php
    }
}