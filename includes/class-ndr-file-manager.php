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
        add_action('wp_ajax_ndr_fm_upload', array($this, 'ajax_upload'));
        add_shortcode('ndr_root_url', array(__CLASS__, 'get_root_url'));
        add_action('wp_ajax_ndr_fm_check_conflicts', array($this, 'ajax_check_conflicts'));
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

    public static function get_root_url()
    {
        $root = self::get_root_dir();
        $absp = rtrim(realpath(ABSPATH), '/');
        if ($absp && strpos($root, $absp) === 0) {
            $rel = ltrim(substr($root, strlen($absp)), '/');
            return untrailingslashit(trailingslashit(is_multisite() ? network_site_url('/') : site_url('/')) . str_replace('%2F', '/', rawurlencode($rel)));
        }
        return '';
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
            return trailingslashit(is_multisite() ? network_site_url('/') : site_url('/')) . str_replace('%2F', '/', rawurlencode($rel));
        }
        return '';
    }

    private function list_all_dirs()
    {
        $root = self::get_root_dir();
        $dirs = array('' => '/ (Kök Dizin)');
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            if ($item->isDir()) {
                $rel = $this->get_rel_path($item->getPathname());
                if ($rel !== '') {
                    $dirs[$rel] = '/' . $rel;
                }
            }
        }
        natcasesort($dirs);
        return $dirs;
    }

    private function redirect_back($subpath, $msg = '', $err = '', $extra = array())
    {
        $args = array_merge(array(
            'post_type' => 'ndr_api_source',
            'page'      => 'ndr-file-manager',
        ), $extra);
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

    public function ajax_check_conflicts()
    {
        check_ajax_referer('ndr_fm_action', 'ndr_fm_nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Yetkiniz yok.'), 403);
        }
        $sub   = sanitize_text_field(wp_unslash($_POST['current_dir'] ?? ''));
        $dir   = $this->resolve_safe_path($sub);
        $names = isset($_POST['file_names']) ? (array) wp_unslash($_POST['file_names']) : array();

        $conflicts = array();
        foreach ($names as $raw) {
            $fname = sanitize_file_name($raw);
            if ($fname !== '' && file_exists($dir . '/' . $fname)) {
                $conflicts[] = $fname;
            }
        }
        wp_send_json_success(array('conflicts' => $conflicts));
    }

    public function ajax_upload()
    {
        check_ajax_referer('ndr_fm_action', 'ndr_fm_nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Yetkiniz yok.'), 403);
        }
        $sub           = sanitize_text_field(wp_unslash($_POST['current_dir'] ?? ''));
        $dir           = $this->resolve_safe_path($sub);
        $conflict_mode = sanitize_text_field(wp_unslash($_POST['conflict_mode'] ?? 'overwrite'));

        if (empty($_FILES['fm_files']['name'][0])) {
            wp_send_json_error(array('message' => 'Yüklenecek dosya seçilmedi.'));
        }

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
            if (file_exists($dest)) {
                if ($conflict_mode === 'skip') {
                    continue;
                } elseif ($conflict_mode === 'rename') {
                    $pi  = pathinfo($fname);
                    $ext = isset($pi['extension']) && $pi['extension'] !== '' ? '.' . $pi['extension'] : '';
                    $n   = 1;
                    while (file_exists($dir . '/' . $pi['filename'] . '_' . $n . $ext)) {
                        $n++;
                    }
                    $dest = $dir . '/' . $pi['filename'] . '_' . $n . $ext;
                }
            }
            if (move_uploaded_file($_FILES['fm_files']['tmp_name'][$i], $dest)) {
                $ok++;
            }
        }

        if ($ok > 0) {
            wp_send_json_success(array('uploaded' => $ok));
        }
        wp_send_json_error(array('message' => 'Dosya(lar) yüklenemedi veya atlandı.'));
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
            $raw  = sanitize_text_field(wp_unslash($_GET['fm_download']));
            $rel  = ($sub !== '' && strpos($raw, '/') === false) ? $sub . '/' . $raw : $raw;
            $file = $this->resolve_safe_path($rel);
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

        $sub     = sanitize_text_field(wp_unslash($_POST['current_dir'] ?? ''));
        $dir     = $this->resolve_safe_path($sub);
        $rel_dir = $this->get_rel_path($dir);
        $action  = sanitize_text_field(wp_unslash($_POST['fm_action'] ?? ''));

        // Fallback non-AJAX upload
        if ($action === 'upload' && ! empty($_FILES['fm_files']['name'][0])) {
            $count = count($_FILES['fm_files']['name']);
            $ok = 0;
            for ($i = 0; $i < $count; $i++) {
                if ($_FILES['fm_files']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $fname = sanitize_file_name($_FILES['fm_files']['name'][$i]);
                if ($fname && move_uploaded_file($_FILES['fm_files']['tmp_name'][$i], $dir . '/' . $fname)) {
                    $ok++;
                }
            }
            $this->redirect_back($rel_dir, $ok > 0 ? 'uploaded' : '', $ok === 0 ? 'Dosya yüklenemedi.' : '');
        }

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

        if ($action === 'rename') {
            $old_rel  = sanitize_text_field(wp_unslash($_POST['old_name'] ?? ''));
            $new_name = sanitize_file_name(wp_unslash($_POST['new_name'] ?? ''));
            $old_path = $this->resolve_safe_path(($rel_dir && strpos($old_rel, '/') === false ? $rel_dir . '/' : '') . $old_rel);
            if ($old_rel && $new_name && file_exists($old_path) && $old_path !== self::get_root_dir()) {
                $parent = dirname($old_path);
                rename($old_path, $parent . '/' . $new_name);
                $this->redirect_back($rel_dir, 'renamed');
            }
            $this->redirect_back($rel_dir, '', 'Yeniden adlandırma başarısız.');
        }

        if ($action === 'save_file') {
            $frel    = sanitize_text_field(wp_unslash($_POST['file_name'] ?? ''));
            $content = wp_unslash($_POST['file_content'] ?? '');
            $fpath   = $this->resolve_safe_path(($rel_dir && strpos($frel, '/') === false ? $rel_dir . '/' : '') . $frel);
            if (is_file($fpath) && is_writable($fpath)) {
                file_put_contents($fpath, $content);
                $this->redirect_back($rel_dir, 'saved');
            }
            $this->redirect_back($rel_dir, '', 'Dosya kaydedilemedi.');
        }

        if ($action === 'delete_one') {
            $trel  = sanitize_text_field(wp_unslash($_POST['target_name'] ?? ''));
            $fpath = $this->resolve_safe_path(($rel_dir && strpos($trel, '/') === false ? $rel_dir . '/' : '') . $trel);
            if ($fpath !== self::get_root_dir() && file_exists($fpath)) {
                $this->delete_recursive($fpath);
                $this->redirect_back($rel_dir, 'deleted');
            }
            $this->redirect_back($rel_dir, '', 'Silme işlemi başarısız.');
        }

        if ($action === 'copy_one' || $action === 'move_one') {
            $trel      = sanitize_text_field(wp_unslash($_POST['target_name'] ?? ''));
            $target_sub = sanitize_text_field(wp_unslash($_POST['target_dir'] ?? ''));
            $src_path  = $this->resolve_safe_path(($rel_dir && strpos($trel, '/') === false ? $rel_dir . '/' : '') . $trel);
            $dest_dir  = $this->resolve_safe_path($target_sub);

            if ($src_path === self::get_root_dir() || ! file_exists($src_path) || ! is_dir($dest_dir)) {
                $this->redirect_back($rel_dir, '', 'Geçersiz kaynak veya hedef dizin.');
            }
            $dest_path = $dest_dir . '/' . basename($src_path);
            if ($action === 'copy_one') {
                if ($src_path === $dest_path) {
                    $pi = pathinfo(basename($src_path));
                    $dest_path = $dest_dir . '/' . $pi['filename'] . '_kopya' . (isset($pi['extension']) ? '.' . $pi['extension'] : '');
                }
                $this->copy_recursive($src_path, $dest_path);
                $this->redirect_back($rel_dir, 'copied');
            } else {
                if ($src_path !== $dest_path && strpos($dest_path, $src_path . '/') !== 0) {
                    rename($src_path, $dest_path);
                    $this->redirect_back($rel_dir, 'moved');
                }
                $this->redirect_back($rel_dir, '', 'Aynı dizine veya alt dizinine taşınamaz.');
            }
        }

        if ($action === 'unzip') {
            if (! class_exists('ZipArchive')) {
                $this->redirect_back($rel_dir, '', 'Sunucuda PHP ZipArchive desteği yok.');
            }
            $trel  = sanitize_text_field(wp_unslash($_POST['target_name'] ?? ''));
            $fpath = $this->resolve_safe_path(($rel_dir && strpos($trel, '/') === false ? $rel_dir . '/' : '') . $trel);
            if (! is_file($fpath) || strtolower(pathinfo($fpath, PATHINFO_EXTENSION)) !== 'zip') {
                $this->redirect_back($rel_dir, '', 'Geçerli bir ZIP dosyası bulunamadı.');
            }
            $extract_dir = dirname($fpath);
            $zip = new ZipArchive();
            if ($zip->open($fpath) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->getNameIndex($i);
                    if (strpos($entry, '..') !== false || strpos($entry, '/') === 0) {
                        continue;
                    }
                    $dest = $extract_dir . '/' . $entry;
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

        if ($action === 'bulk') {
            $bulk_op    = sanitize_text_field(wp_unslash($_POST['bulk_op'] ?? ''));
            $target_sub = sanitize_text_field(wp_unslash($_POST['bulk_target_dir'] ?? ''));
            $items      = isset($_POST['selected_items']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['selected_items'])) : array();
            if (empty($items)) {
                $this->redirect_back($rel_dir, '', 'Hiçbir öğe seçilmedi.');
            }

            $cnt = 0;
            if ($bulk_op === 'delete') {
                foreach ($items as $it) {
                    $p = $this->resolve_safe_path(($rel_dir && strpos($it, '/') === false ? $rel_dir . '/' : '') . $it);
                    if ($p !== self::get_root_dir() && file_exists($p) && $this->delete_recursive($p)) {
                        $cnt++;
                    }
                }
                $this->redirect_back($rel_dir, 'bulk_deleted', '', array('fm_cnt' => $cnt));
            }

            if ($bulk_op === 'copy' || $bulk_op === 'move') {
                $dest_dir = $this->resolve_safe_path($target_sub);
                foreach ($items as $it) {
                    $src = $this->resolve_safe_path(($rel_dir && strpos($it, '/') === false ? $rel_dir . '/' : '') . $it);
                    if ($src === self::get_root_dir() || ! file_exists($src)) continue;
                    $dest = $dest_dir . '/' . basename($src);
                    if ($bulk_op === 'copy') {
                        if ($src === $dest) {
                            $pi = pathinfo(basename($src));
                            $dest = $dest_dir . '/' . $pi['filename'] . '_kopya' . (isset($pi['extension']) ? '.' . $pi['extension'] : '');
                        }
                        if ($this->copy_recursive($src, $dest)) $cnt++;
                    } else {
                        if ($src !== $dest && strpos($dest, $src . '/') !== 0 && @rename($src, $dest)) {
                            $cnt++;
                        }
                    }
                }
                $this->redirect_back($rel_dir, $bulk_op === 'copy' ? 'bulk_copied' : 'bulk_moved', '', array('fm_cnt' => $cnt));
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
                        $p = $this->resolve_safe_path(($rel_dir && strpos($it, '/') === false ? $rel_dir . '/' : '') . $it);
                        $name = basename($p);
                        if (is_file($p)) {
                            $zip->addFile($p, $name);
                            $cnt++;
                        } elseif (is_dir($p)) {
                            $this->add_dir_to_zip($zip, $p, $name);
                            $cnt++;
                        }
                    }
                    $zip->close();
                    $this->redirect_back($rel_dir, 'zipped', '', array('fm_cnt' => $cnt));
                }
                $this->redirect_back($rel_dir, '', 'ZIP paketi oluşturulamadı.');
            }
        }
    }

    private function copy_recursive($src, $dst)
    {
        if (is_file($src)) {
            return @copy($src, $dst);
        }
        if (is_dir($src)) {
            wp_mkdir_p($dst);
            foreach (scandir($src) as $f) {
                if ($f === '.' || $f === '..') continue;
                $this->copy_recursive($src . '/' . $f, $dst . '/' . $f);
            }
            return true;
        }
        return false;
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

    private function get_file_type_info($path, $is_dir)
    {
        if ($is_dir) {
            return array('label' => 'Klasör', 'icon' => 'dashicons-category', 'color' => '#d97706', 'is_img' => false, 'is_edit' => false, 'mode' => '');
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $img_exts = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif');
        $code_modes = array(
            'json' => 'application/json',
            'js'   => 'application/javascript',
            'css'  => 'text/css',
            'html' => 'htmlmixed',
            'htm'  => 'htmlmixed',
            'xml'  => 'application/xml',
            'svg'  => 'application/xml',
            'csv'  => 'text/plain',
            'txt'  => 'text/plain',
            'md'   => 'text/plain',
        );
        if (in_array($ext, $img_exts, true)) {
            return array('label' => 'Görsel (' . strtoupper($ext) . ')', 'icon' => 'dashicons-format-image', 'color' => '#059669', 'is_img' => true, 'is_edit' => ($ext === 'svg'), 'mode' => $code_modes[$ext] ?? '');
        }
        if ($ext === 'zip') {
            return array('label' => 'ZIP Arşiv', 'icon' => 'dashicons-media-archive', 'color' => '#7c3aed', 'is_img' => false, 'is_edit' => false, 'mode' => '');
        }
        if (isset($code_modes[$ext])) {
            return array('label' => strtoupper($ext) . ' Dosyası', 'icon' => 'dashicons-media-code', 'color' => '#2563eb', 'is_img' => false, 'is_edit' => true, 'mode' => $code_modes[$ext]);
        }
        return array('label' => $ext ? strtoupper($ext) : 'Dosya', 'icon' => 'dashicons-media-default', 'color' => '#64748b', 'is_img' => false, 'is_edit' => false, 'mode' => '');
    }

    public function render_page()
    {
        $root    = self::get_root_dir();
        $sub     = sanitize_text_field(wp_unslash($_GET['dir'] ?? ''));
        $cur_dir = $this->resolve_safe_path($sub);
        $rel_dir = $this->get_rel_path($cur_dir);

        $search_q = trim(sanitize_text_field(wp_unslash($_GET['fm_q'] ?? '')));
        $sort_by  = sanitize_text_field(wp_unslash($_GET['sort'] ?? 'name'));
        $order    = strtolower(sanitize_text_field(wp_unslash($_GET['order'] ?? 'asc'))) === 'desc' ? 'desc' : 'asc';
        $per_page = absint($_GET['per_page'] ?? 50);
        if (! in_array($per_page, array(50, 100, 200), true)) {
            $per_page = 50;
        }
        $paged = max(1, absint($_GET['paged'] ?? 1));

        // Edit file mode
        $edit_file  = isset($_GET['edit']) ? sanitize_text_field(wp_unslash($_GET['edit'])) : '';
        $edit_path  = $edit_file ? $this->resolve_safe_path(($rel_dir && strpos($edit_file, '/') === false ? $rel_dir . '/' : '') . $edit_file) : '';
        $is_editing = ($edit_file && is_file($edit_path));
        $edit_info  = $is_editing ? $this->get_file_type_info($edit_path, false) : null;

        $fm_cnt = absint($_GET['fm_cnt'] ?? 0);
        $msg_map = array(
            'uploaded'     => 'Dosya(lar) başarıyla yüklendi.',
            'mkdir_ok'     => 'Klasör oluşturuldu.',
            'renamed'      => 'Öğe yeniden adlandırıldı.',
            'saved'        => 'Dosya içeriği kaydedildi.',
            'deleted'      => 'Öğe silindi.',
            'copied'       => 'Öğe hedef klasöre kopyalandı.',
            'moved'        => 'Öğe hedef klasöre taşındı.',
            'bulk_deleted' => "{$fm_cnt} öğe toplu olarak silindi.",
            'bulk_copied'  => "{$fm_cnt} öğe hedef klasöre kopyalandı.",
            'bulk_moved'   => "{$fm_cnt} öğe hedef klasöre taşındı.",
            'zipped'       => "{$fm_cnt} öğe ZIP paketi haline getirildi.",
            'unzipped'     => 'ZIP arşivi çıkarıldı.',
        );
        $fm_msg = sanitize_text_field(wp_unslash($_GET['fm_msg'] ?? ''));
        $fm_err = sanitize_text_field(wp_unslash($_GET['fm_err'] ?? ''));

        // Gather entries (recursive if searching, otherwise current dir)
        $rows = array();
        $total_dirs = 0;
        $total_files = 0;
        $total_bytes = 0;

        if ($search_q !== '') {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($cur_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $item) {
                $name = $item->getFilename();
                if (mb_stripos($name, $search_q, 0, 'UTF-8') === false) {
                    continue;
                }
                $full   = $item->getPathname();
                $is_dir = $item->isDir();
                $info   = $this->get_file_type_info($full, $is_dir);
                $size   = $is_dir ? 0 : (int) $item->getSize();
                $mtime  = (int) $item->getMTime();
                if ($is_dir) $total_dirs++; else { $total_files++; $total_bytes += $size; }
                $rows[] = array(
                    'name'   => $name,
                    'rel'    => $this->get_rel_path($full),
                    'full'   => $full,
                    'is_dir' => $is_dir,
                    'type'   => $info['label'],
                    'info'   => $info,
                    'size'   => $size,
                    'mtime'  => $mtime,
                );
            }
        } else {
            foreach (scandir($cur_dir) as $e) {
                if ($e === '.' || $e === '..') continue;
                $full   = $cur_dir . '/' . $e;
                $is_dir = is_dir($full);
                $info   = $this->get_file_type_info($full, $is_dir);
                $size   = $is_dir ? 0 : (int) filesize($full);
                $mtime  = (int) filemtime($full);
                if ($is_dir) $total_dirs++; else { $total_files++; $total_bytes += $size; }
                $rows[] = array(
                    'name'   => $e,
                    'rel'    => $this->get_rel_path($full),
                    'full'   => $full,
                    'is_dir' => $is_dir,
                    'type'   => $info['label'],
                    'info'   => $info,
                    'size'   => $size,
                    'mtime'  => $mtime,
                );
            }
        }

        // Sort (folders first, then by selected column)
        usort($rows, function ($a, $b) use ($sort_by, $order) {
            if ($a['is_dir'] !== $b['is_dir']) {
                return $a['is_dir'] ? -1 : 1;
            }
            $cmp = 0;
            if ($sort_by === 'size') {
                $cmp = $a['size'] <=> $b['size'];
            } elseif ($sort_by === 'date') {
                $cmp = $a['mtime'] <=> $b['mtime'];
            } elseif ($sort_by === 'type') {
                $cmp = strnatcasecmp($a['type'], $b['type']);
            } else {
                $cmp = strnatcasecmp($a['name'], $b['name']);
            }
            return ($order === 'desc') ? -$cmp : $cmp;
        });

        $total_items = count($rows);
        $total_pages = max(1, (int) ceil($total_items / $per_page));
        $paged       = min($paged, $total_pages);
        $offset      = ($paged - 1) * $per_page;
        $paged_rows  = array_slice($rows, $offset, $per_page);
        $all_dirs    = $this->list_all_dirs();

        $base_page_args = array(
            'post_type' => 'ndr_api_source',
            'page'      => 'ndr-file-manager',
            'per_page'  => $per_page,
            'sort'      => $sort_by,
            'order'     => $order,
        );
        if ($rel_dir !== '') $base_page_args['dir'] = $rel_dir;
        if ($search_q !== '') $base_page_args['fm_q'] = $search_q;

        $sort_link = function ($col, $label) use ($base_page_args, $sort_by, $order) {
            $next_order = ($sort_by === $col && $order === 'asc') ? 'desc' : 'asc';
            $u = add_query_arg(array_merge($base_page_args, array('sort' => $col, 'order' => $next_order, 'paged' => 1)), admin_url('edit.php'));
            $arrow = ($sort_by === $col) ? ($order === 'asc' ? ' ▲' : ' ▼') : '';
            return '<a href="' . esc_url($u) . '">' . esc_html($label . $arrow) . '</a>';
        };
        ?>
        <style>
            .ndr-fm-bar{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:10px 14px;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;}
            .ndr-fm-table th,.ndr-fm-table td{vertical-align:middle !important;padding:5px 10px !important;line-height:1.35;}
            .ndr-fm-actions-cell{position:relative;text-align:right;}
            .ndr-fm-menu-wrap{position:relative;display:inline-block;}
            .ndr-fm-menu-trigger{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;font-size:12px;border:1px solid #dcdcde;background:#fff;border-radius:4px;cursor:pointer;color:#2c3338;}
            .ndr-fm-menu-trigger:hover{border-color:#2271b1;color:#2271b1;background:#f0f6fc;}
            .ndr-fm-dropdown{display:none;position:absolute;right:0;top:100%;min-width:175px;background:#fff;border:1px solid #dcdcde;border-radius:6px;box-shadow:0 8px 24px rgba(0,0,0,.14);z-index:9999;padding:4px 0;text-align:left;}
            .ndr-fm-menu-wrap:hover .ndr-fm-dropdown,.ndr-fm-menu-wrap.is-open .ndr-fm-dropdown{display:block;}
            .ndr-fm-ctx-menu{display:none;position:fixed;min-width:185px;background:#fff;border:1px solid #dcdcde;border-radius:6px;box-shadow:0 10px 28px rgba(0,0,0,.18);z-index:999999;padding:5px 0;}
            .ndr-fm-ctx-menu.is-open{display:block;}
            .ndr-fm-menu-item{display:flex;align-items:center;gap:8px;width:100%;padding:6px 12px;border:none;background:transparent;text-align:left;font-size:12.5px;color:#1d2327;cursor:pointer;text-decoration:none;box-sizing:border-box;}
            .ndr-fm-menu-item:hover{background:#f0f6fc;color:#2271b1;}
            .ndr-fm-menu-item.is-danger{color:#b32d2e;}
            .ndr-fm-menu-item.is-danger:hover{background:#fcf0f1;color:#b32d2e;}
            .ndr-fm-menu-item .dashicons{font-size:15px;width:15px;height:15px;line-height:15px;}
            .ndr-fm-name-cell{display:flex;align-items:center;gap:8px;}
            .ndr-fm-icon-btn{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;padding:0;border:1px solid #dcdcde;background:#fff;border-radius:4px;cursor:pointer;color:#3c434a;text-decoration:none;transition:all .15s;}
            .ndr-fm-icon-btn:hover{border-color:#2271b1;color:#2271b1;background:#f0f6fc;}
            .ndr-fm-icon-btn.is-danger:hover{border-color:#d63638;color:#d63638;background:#fcf0f1;}
            .ndr-fm-icon-btn .dashicons{font-size:16px;width:16px;height:16px;line-height:16px;}
            .ndr-fm-progress-wrap{display:none;background:#fff;border:1px solid #c3dafe;border-left:4px solid #2271b1;padding:10px 14px;border-radius:4px;margin-bottom:10px;}
            .ndr-fm-progress-bar{height:10px;background:#e2e8f0;border-radius:5px;overflow:hidden;margin-top:6px;}
            .ndr-fm-progress-fill{height:100%;width:0%;background:#2271b1;transition:width .15s;}
            .ndr-fm-stats-footer{background:#f6f7f7;border:1px solid #dcdcde;border-top:none;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;font-size:12.5px;color:#50575e;}
            .ndr-fm-modal-bg{display:none;position:fixed;inset:0;background:rgba(15,23,42,.75);z-index:100000;align-items:center;justify-content:center;padding:20px;}
            .ndr-fm-modal-bg.is-open{display:flex;}
            .ndr-fm-modal-box{background:#fff;border-radius:8px;padding:18px 20px;max-width:90vw;max-height:90vh;overflow:auto;min-width:340px;box-shadow:0 20px 50px rgba(0,0,0,.35);}
            .CodeMirror{border:1px solid #8c8f94;border-radius:4px;height:380px;min-height:180px;resize:vertical;overflow:auto !important;font-size:13px;}
        </style>

        <div class="wrap">
            <h1 style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                <span><?php esc_html_e('Dosya Yöneticisi', 'ndr-rest-api-to-render'); ?></span>
                <span style="font-size:12.5px;font-weight:400;color:#646970;">
                    Kök: <code><?php echo esc_html($root); ?></code>
                    &bull; <a href="<?php echo esc_url(admin_url('edit.php?post_type=ndr_api_source&page=ndr-data-settings')); ?>"><?php esc_html_e('Değiştir', 'ndr-rest-api-to-render'); ?></a>
                </span>
            </h1>

            <?php if ($fm_msg && isset($msg_map[$fm_msg])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($msg_map[$fm_msg]); ?></p></div>
            <?php endif; ?>
            <?php if ($fm_err) : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html($fm_err); ?></p></div>
            <?php endif; ?>

            <!-- Progress Indicator (Upload & Bulk Actions) -->
            <div class="ndr-fm-progress-wrap" id="ndr-fm-progress-wrap">
                <div style="display:flex;justify-content:space-between;font-size:13px;">
                    <strong id="ndr-fm-progress-label">İşlem yapılıyor...</strong>
                    <span id="ndr-fm-progress-pct">0%</span>
                </div>
                <div class="ndr-fm-progress-bar"><div class="ndr-fm-progress-fill" id="ndr-fm-progress-fill"></div></div>
            </div>

            <?php if ($is_editing) : ?>
                <div style="background:#fff;border:1px solid #dcdcde;padding:16px;border-radius:6px;margin-bottom:14px;">
                    <h2 style="margin-top:0;"><?php echo esc_html(sprintf(__('Düzenleniyor: %s', 'ndr-rest-api-to-render'), $edit_file)); ?></h2>
                    <form method="post">
                        <?php wp_nonce_field('ndr_fm_action', 'ndr_fm_nonce'); ?>
                        <input type="hidden" name="fm_action" value="save_file" />
                        <input type="hidden" name="current_dir" value="<?php echo esc_attr($rel_dir); ?>" />
                        <input type="hidden" name="file_name" value="<?php echo esc_attr($edit_file); ?>" />
                        <textarea id="ndr_fm_code_editor" name="file_content" rows="16" class="widefat ndr-code-editor" data-mode="<?php echo esc_attr($edit_info['mode'] ?: 'text/plain'); ?>"><?php echo esc_textarea((string) file_get_contents($edit_path)); ?></textarea>
                        <p style="margin-top:10px;margin-bottom:0;">
                            <button type="submit" class="button button-primary"><?php esc_html_e('Değişiklikleri Kaydet', 'ndr-rest-api-to-render'); ?></button>
                            <a href="<?php echo esc_url(add_query_arg($base_page_args, admin_url('edit.php'))); ?>" class="button button-secondary"><?php esc_html_e('Kapat', 'ndr-rest-api-to-render'); ?></a>
                        </p>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Top Bar: Breadcrumb + Recursive Search -->
            <div class="ndr-fm-bar">
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <span class="dashicons dashicons-portfolio" style="color:#d97706;"></span>
                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=ndr_api_source&page=ndr-file-manager&per_page=' . $per_page)); ?>"><strong>Kök</strong></a>
                    <?php
                    if ($rel_dir !== '') {
                        $acc = '';
                        foreach (explode('/', $rel_dir) as $part) {
                            $acc = ($acc === '') ? $part : $acc . '/' . $part;
                            $u = add_query_arg(array('post_type' => 'ndr_api_source', 'page' => 'ndr-file-manager', 'dir' => $acc, 'per_page' => $per_page), admin_url('edit.php'));
                            echo ' / <a href="' . esc_url($u) . '">' . esc_html($part) . '</a>';
                        }
                    }
                    if ($search_q !== '') {
                        echo ' <span class="description">(&ldquo;' . esc_html($search_q) . '&rdquo; araması — alt klasörler dahil)</span>';
                    }
                    ?>
                </div>

                <form method="get" style="display:flex;gap:6px;align-items:center;">
                    <input type="hidden" name="post_type" value="ndr_api_source" />
                    <input type="hidden" name="page" value="ndr-file-manager" />
                    <?php if ($rel_dir !== '') : ?><input type="hidden" name="dir" value="<?php echo esc_attr($rel_dir); ?>" /><?php endif; ?>
                    <input type="hidden" name="per_page" value="<?php echo esc_attr($per_page); ?>" />
                    <input type="search" name="fm_q" value="<?php echo esc_attr($search_q); ?>" placeholder="Klasör ve alt klasörlerde ara..." style="min-width:230px;" />
                    <button type="submit" class="button button-secondary"><?php esc_html_e('Ara', 'ndr-rest-api-to-render'); ?></button>
                    <?php if ($search_q !== '') : ?>
                        <a href="<?php echo esc_url(add_query_arg(array('post_type' => 'ndr_api_source', 'page' => 'ndr-file-manager', 'dir' => $rel_dir, 'per_page' => $per_page), admin_url('edit.php'))); ?>" class="button"><?php esc_html_e('Temizle', 'ndr-rest-api-to-render'); ?></a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Action Bar: Upload (AJAX with progress) + New Folder + Per-Page -->
            <div class="ndr-fm-bar">
                <form id="ndr-fm-upload-form" method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="ndr_fm_nonce" value="<?php echo esc_attr(wp_create_nonce('ndr_fm_action')); ?>" />
                    <input type="hidden" name="current_dir" value="<?php echo esc_attr($rel_dir); ?>" />
                    <span class="dashicons dashicons-upload" style="color:#2271b1;"></span>
                    <input type="file" id="ndr-fm-upload-input" name="fm_files[]" multiple />
                    <button type="submit" class="button button-primary button-small"><?php esc_html_e('Yükle', 'ndr-rest-api-to-render'); ?></button>
                </form>

                <form method="post" style="display:flex;gap:6px;align-items:center;">
                    <?php wp_nonce_field('ndr_fm_action', 'ndr_fm_nonce'); ?>
                    <input type="hidden" name="fm_action" value="mkdir" />
                    <input type="hidden" name="current_dir" value="<?php echo esc_attr($rel_dir); ?>" />
                    <input type="text" name="folder_name" placeholder="Yeni klasör adı" required style="width:150px;" />
                    <button type="submit" class="button button-secondary button-small">+ <?php esc_html_e('Klasör', 'ndr-rest-api-to-render'); ?></button>
                </form>

                <div style="display:flex;align-items:center;gap:6px;">
                    <span class="description"><?php esc_html_e('Göster:', 'ndr-rest-api-to-render'); ?></span>
                    <?php foreach (array(50, 100, 200) as $pp) :
                        $pp_u = add_query_arg(array_merge($base_page_args, array('per_page' => $pp, 'paged' => 1)), admin_url('edit.php'));
                    ?>
                        <a href="<?php echo esc_url($pp_u); ?>" class="button button-small <?php echo ($per_page === $pp) ? 'button-primary' : ''; ?>"><?php echo $pp; ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Bulk Form & Minimalist File Table -->
            <form method="post" id="ndr-fm-bulk-form">
                <?php wp_nonce_field('ndr_fm_action', 'ndr_fm_nonce'); ?>
                <input type="hidden" name="fm_action" value="bulk" />
                <input type="hidden" name="current_dir" value="<?php echo esc_attr($rel_dir); ?>" />

                <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap;">
                    <select name="bulk_op" id="ndr-fm-bulk-op">
                        <option value=""><?php esc_html_e('Toplu İşlemler', 'ndr-rest-api-to-render'); ?></option>
                        <option value="copy"><?php esc_html_e('Seçilenleri Kopyala', 'ndr-rest-api-to-render'); ?></option>
                        <option value="move"><?php esc_html_e('Seçilenleri Taşı', 'ndr-rest-api-to-render'); ?></option>
                        <option value="zip"><?php esc_html_e('Seçilenleri ZIP Yap', 'ndr-rest-api-to-render'); ?></option>
                        <option value="delete"><?php esc_html_e('Seçilenleri Sil', 'ndr-rest-api-to-render'); ?></option>
                    </select>
                    <select name="bulk_target_dir" id="ndr-fm-bulk-target" style="display:none;">
                        <?php foreach ($all_dirs as $d_val => $d_lbl) : ?>
                            <option value="<?php echo esc_attr($d_val); ?>"><?php echo esc_html('Hedef: ' . $d_lbl); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button button-secondary" id="ndr-fm-bulk-apply"><?php esc_html_e('Uygula', 'ndr-rest-api-to-render'); ?></button>
                </div>

                <table class="wp-list-table widefat fixed striped ndr-fm-table">
                    <thead>
                        <tr>
                            <th style="width:30px;"><input type="checkbox" id="ndr-fm-select-all" /></th>
                            <th><?php echo $sort_link('name', 'Ad'); ?></th>
                            <th style="width:130px;"><?php echo $sort_link('type', 'Tür'); ?></th>
                            <th style="width:100px;"><?php echo $sort_link('size', 'Boyut'); ?></th>
                            <th style="width:145px;"><?php echo $sort_link('date', 'Değiştirilme'); ?></th>
                            <th style="width:105px;text-align:right;"><?php esc_html_e('İşlemler', 'ndr-rest-api-to-render'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($paged_rows)) : ?>
                            <tr><td colspan="6"><?php esc_html_e('Kayıt bulunamadı.', 'ndr-rest-api-to-render'); ?></td></tr>
                        <?php endif; ?>

                        <?php foreach ($paged_rows as $r) :
                            $info    = $r['info'];
                            $pub_url = $r['is_dir'] ? '' : $this->get_public_url($r['full']);
                            $open_u  = $r['is_dir']
                                ? add_query_arg(array('post_type' => 'ndr_api_source', 'page' => 'ndr-file-manager', 'dir' => $r['rel'], 'per_page' => $per_page), admin_url('edit.php'))
                                : $pub_url;
                            $dl_u    = wp_nonce_url(add_query_arg(array('post_type' => 'ndr_api_source', 'page' => 'ndr-file-manager', 'dir' => $rel_dir, 'fm_download' => $r['rel']), admin_url('edit.php')), 'ndr_fm_download');
                            $ed_u    = add_query_arg(array_merge($base_page_args, array('edit' => $r['rel'])), admin_url('edit.php'));
                        ?>
                            <tr class="ndr-fm-row"
                                data-rel="<?php echo esc_attr($r['rel']); ?>"
                                data-name="<?php echo esc_attr($r['name']); ?>"
                                data-is-dir="<?php echo $r['is_dir'] ? '1' : '0'; ?>"
                                data-is-img="<?php echo $info['is_img'] ? '1' : '0'; ?>"
                                data-is-edit="<?php echo $info['is_edit'] ? '1' : '0'; ?>"
                                data-is-zip="<?php echo (! $r['is_dir'] && strtolower(pathinfo($r['name'], PATHINFO_EXTENSION)) === 'zip') ? '1' : '0'; ?>"
                                data-pub-url="<?php echo esc_attr($pub_url); ?>"
                                data-dl-url="<?php echo esc_attr($dl_u); ?>"
                                data-ed-url="<?php echo esc_attr($ed_u); ?>">
                                <td><input type="checkbox" name="selected_items[]" value="<?php echo esc_attr($r['rel']); ?>" data-size="<?php echo esc_attr($r['size']); ?>" class="ndr-fm-cb" /></td>
                                <td>
                                    <div class="ndr-fm-name-cell">
                                        <span class="dashicons <?php echo esc_attr($info['icon']); ?>" style="color:<?php echo esc_attr($info['color']); ?>;"></span>
                                        <div>
                                            <?php if ($r['is_dir']) : ?>
                                                <a href="<?php echo esc_url($open_u); ?>"><strong><?php echo esc_html($r['name']); ?></strong></a>
                                            <?php elseif ($info['is_img']) : ?>
                                                <a href="<?php echo esc_url($pub_url); ?>" class="ndr-fm-img-preview" data-title="<?php echo esc_attr($r['name']); ?>"><strong><?php echo esc_html($r['name']); ?></strong></a>
                                            <?php elseif ($info['is_edit']) : ?>
                                                <a href="<?php echo esc_url($ed_u); ?>"><strong><?php echo esc_html($r['name']); ?></strong></a>
                                            <?php else : ?>
                                                <strong><?php echo esc_html($r['name']); ?></strong>
                                            <?php endif; ?>
                                            <?php if ($search_q !== '' && strpos($r['rel'], '/') !== false) : ?>
                                                <div style="font-size:11px;color:#646970;">/<?php echo esc_html(dirname($r['rel'])); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td><span style="font-size:12px;color:#50575e;"><?php echo esc_html($r['type']); ?></span></td>
                                <td><?php echo $r['is_dir'] ? '—' : esc_html(size_format($r['size'])); ?></td>
                                <td><span style="font-size:12px;"><?php echo esc_html(date_i18n('d.m.Y H:i', $r['mtime'])); ?></span></td>
                                <td class="ndr-fm-actions-cell">
                                    <div class="ndr-fm-menu-wrap">
                                        <button type="button" class="ndr-fm-menu-trigger">İşlemler &#9662;</button>
                                        <div class="ndr-fm-dropdown">
                                            <?php if ($info['is_img'] && $pub_url) : ?>
                                                <a href="<?php echo esc_url($pub_url); ?>" class="ndr-fm-menu-item ndr-fm-img-preview" data-title="<?php echo esc_attr($r['name']); ?>"><span class="dashicons dashicons-visibility"></span> Önizle</a>
                                            <?php endif; ?>
                                            <?php if ($info['is_edit']) : ?>
                                                <a href="<?php echo esc_url($ed_u); ?>" class="ndr-fm-menu-item"><span class="dashicons dashicons-edit"></span> Düzenle</a>
                                            <?php endif; ?>
                                            <?php if (! $r['is_dir']) : ?>
                                                <a href="<?php echo esc_url($dl_u); ?>" class="ndr-fm-menu-item"><span class="dashicons dashicons-download"></span> İndir</a>
                                            <?php endif; ?>
                                            <?php if ($pub_url) : ?>
                                                <button type="button" class="ndr-fm-menu-item ndr-fm-copy-url" data-url="<?php echo esc_attr($pub_url); ?>"><span class="dashicons dashicons-admin-links"></span> URL Kopyala</button>
                                            <?php endif; ?>
                                            <?php if (! $r['is_dir'] && strtolower(pathinfo($r['name'], PATHINFO_EXTENSION)) === 'zip') : ?>
                                                <button type="button" class="ndr-fm-menu-item ndr-fm-unzip-btn" data-name="<?php echo esc_attr($r['rel']); ?>"><span class="dashicons dashicons-open-folder"></span> ZIP Aç</button>
                                            <?php endif; ?>
                                            <button type="button" class="ndr-fm-menu-item ndr-fm-copymove-btn" data-mode="copy" data-name="<?php echo esc_attr($r['rel']); ?>"><span class="dashicons dashicons-admin-page"></span> Kopyala</button>
                                            <button type="button" class="ndr-fm-menu-item ndr-fm-copymove-btn" data-mode="move" data-name="<?php echo esc_attr($r['rel']); ?>"><span class="dashicons dashicons-migrate"></span> Taşı</button>
                                            <button type="button" class="ndr-fm-menu-item ndr-fm-rename-btn" data-name="<?php echo esc_attr($r['rel']); ?>" data-short="<?php echo esc_attr($r['name']); ?>"><span class="dashicons dashicons-tag"></span> Ad Değiştir</button>
                                            <button type="button" class="ndr-fm-menu-item is-danger ndr-fm-del-btn" data-name="<?php echo esc_attr($r['rel']); ?>" data-short="<?php echo esc_attr($r['name']); ?>"><span class="dashicons dashicons-trash"></span> Sil</button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>

            <!-- Bottom Stats & Pagination -->
            <div class="ndr-fm-stats-footer">
                <div>
                    <strong>Toplam:</strong> <?php echo esc_html($total_dirs); ?> klasör, <?php echo esc_html($total_files); ?> dosya (<?php echo esc_html(size_format($total_bytes)); ?>)
                    &bull; <strong>Seçilen:</strong> <span id="ndr-fm-selected-stats">0 öğe (0 B)</span>
                </div>
                <?php if ($total_pages > 1) : ?>
                    <div style="display:flex;gap:4px;align-items:center;">
                        <span>Sayfa <?php echo $paged; ?> / <?php echo $total_pages; ?>:</span>
                        <?php for ($p = 1; $p <= $total_pages; $p++) :
                            if ($p > 10 && $p < $total_pages && abs($p - $paged) > 2) continue;
                            $pu = add_query_arg(array_merge($base_page_args, array('paged' => $p)), admin_url('edit.php'));
                        ?>
                            <a href="<?php echo esc_url($pu); ?>" class="button button-small <?php echo ($p === $paged) ? 'button-primary' : ''; ?>"><?php echo $p; ?></a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Hidden single-action form -->
            <form method="post" id="ndr-fm-single-form" style="display:none;">
                <?php wp_nonce_field('ndr_fm_action', 'ndr_fm_nonce'); ?>
                <input type="hidden" name="current_dir" value="<?php echo esc_attr($rel_dir); ?>" />
                <input type="hidden" name="fm_action" id="ndr-fm-single-action" value="" />
                <input type="hidden" name="target_name" id="ndr-fm-single-target" value="" />
                <input type="hidden" name="target_dir" id="ndr-fm-single-tdir" value="" />
                <input type="hidden" name="old_name" id="ndr-fm-single-old" value="" />
                <input type="hidden" name="new_name" id="ndr-fm-single-new" value="" />
            </form>

            <!-- Right-click Context Menu -->
            <div class="ndr-fm-ctx-menu" id="ndr-fm-ctx-menu"></div>

            <!-- Upload Conflict Modal -->
            <div class="ndr-fm-modal-bg" id="ndr-fm-conflict-modal">
                <div class="ndr-fm-modal-box" style="max-width:460px;">
                    <h3 style="margin-top:0;color:#b32d2e;">&#9888; Dosya Çakışması Tespit Edildi</h3>
                    <p style="margin-bottom:8px;">Aşağıdaki dosya(lar) bu klasörde zaten mevcut. Nasıl devam etmek istersiniz?</p>
                    <ul id="ndr-fm-conflict-list" style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:8px 12px 8px 26px;max-height:140px;overflow:auto;font-family:monospace;font-size:12.5px;"></ul>
                    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;flex-wrap:wrap;">
                        <button type="button" class="button" id="ndr-fm-conf-cancel">İptal</button>
                        <button type="button" class="button button-secondary" id="ndr-fm-conf-rename">Ad Değiştir (Yeni İsimle Yükle)</button>
                        <button type="button" class="button button-primary" id="ndr-fm-conf-overwrite">Üzerine Yaz</button>
                    </div>
                </div>
            </div>

            <!-- Image Preview Modal -->
            <div class="ndr-fm-modal-bg" id="ndr-fm-img-modal">
                <div class="ndr-fm-modal-box" style="text-align:center;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:12px;">
                        <strong id="ndr-fm-img-title"></strong>
                        <div>
                            <a href="#" id="ndr-fm-img-newtab" target="_blank" class="button button-small">Yeni Sekmede Aç</a>
                            <button type="button" class="button button-small" id="ndr-fm-img-close">&times; Kapat</button>
                        </div>
                    </div>
                    <img id="ndr-fm-img-el" src="" alt="" style="max-width:80vw;max-height:72vh;border-radius:6px;border:1px solid #dcdcde;" />
                </div>
            </div>

            <!-- Copy / Move Target Modal -->
            <div class="ndr-fm-modal-bg" id="ndr-fm-cm-modal">
                <div class="ndr-fm-modal-box">
                    <h3 id="ndr-fm-cm-title" style="margin-top:0;">Hedef Klasör Seçin</h3>
                    <p><select id="ndr-fm-cm-select" class="widefat">
                        <?php foreach ($all_dirs as $d_val => $d_lbl) : ?>
                            <option value="<?php echo esc_attr($d_val); ?>"><?php echo esc_html($d_lbl); ?></option>
                        <?php endforeach; ?>
                    </select></p>
                    <p style="margin-bottom:0;display:flex;gap:8px;justify-content:flex-end;">
                        <button type="button" class="button" id="ndr-fm-cm-cancel">İptal</button>
                        <button type="button" class="button button-primary" id="ndr-fm-cm-confirm">Onayla</button>
                    </p>
                </div>
            </div>

            <script>
            jQuery(function($){
                function fmtBytes(b) {
                    if (!b) return '0 B';
                    var k = 1024, sizes = ['B','KB','MB','GB'], i = Math.floor(Math.log(b)/Math.log(k));
                    return (b/Math.pow(k,i)).toFixed(i?1:0) + ' ' + sizes[i];
                }
                function updateSelStats() {
                    var cnt = 0, bytes = 0;
                    $('.ndr-fm-cb:checked').each(function(){
                        cnt++;
                        bytes += parseInt($(this).data('size'), 10) || 0;
                    });
                    $('#ndr-fm-selected-stats').text(cnt + ' öğe (' + fmtBytes(bytes) + ')');
                }
                $('#ndr-fm-select-all').on('change', function(){
                    $('.ndr-fm-cb').prop('checked', $(this).is(':checked'));
                    updateSelStats();
                });
                $(document).on('change', '.ndr-fm-cb', updateSelStats);

                // Bulk op toggle target dir
                $('#ndr-fm-bulk-op').on('change', function(){
                    var v = $(this).val();
                    if (v === 'copy' || v === 'move') $('#ndr-fm-bulk-target').show();
                    else $('#ndr-fm-bulk-target').hide();
                });

                $('#ndr-fm-bulk-form').on('submit', function(e){
                    var op = $('#ndr-fm-bulk-op').val();
                    var cnt = $('.ndr-fm-cb:checked').length;
                    if (!op || !cnt) { e.preventDefault(); alert('Lütfen öğe ve toplu işlem seçin.'); return; }
                    if (op === 'delete' && !confirm('Seçilen ' + cnt + ' öğeyi silmek istediğinize emin misiniz?')) {
                        e.preventDefault(); return;
                    }
                    $('#ndr-fm-progress-wrap').show();
                    $('#ndr-fm-progress-label').text('Toplu işlem yürütülüyor (' + cnt + ' öğe)...');
                    $('#ndr-fm-progress-pct').text('İşleniyor');
                    $('#ndr-fm-progress-fill').css('width', '65%');
                });

                // AJAX Upload with Conflict Check + Real Progress Bar
                function executeUpload(formEl, conflictMode) {
                    var files = $('#ndr-fm-upload-input')[0].files;
                    var fd = new FormData(formEl);
                    fd.append('action', 'ndr_fm_upload');
                    fd.append('conflict_mode', conflictMode || 'overwrite');
                    $('#ndr-fm-progress-wrap').show();
                    $('#ndr-fm-progress-label').text('Dosyalar yükleniyor (' + files.length + ' dosya)...');
                    $('#ndr-fm-progress-pct').text('0%');
                    $('#ndr-fm-progress-fill').css('width', '0%');

                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', ajaxurl, true);
                    xhr.upload.onprogress = function(ev) {
                        if (ev.lengthComputable) {
                            var pct = Math.round((ev.loaded / ev.total) * 100);
                            $('#ndr-fm-progress-pct').text(pct + '% (' + fmtBytes(ev.loaded) + ' / ' + fmtBytes(ev.total) + ')');
                            $('#ndr-fm-progress-fill').css('width', pct + '%');
                        }
                    };
                    xhr.onload = function() {
                        try {
                            var res = JSON.parse(xhr.responseText);
                            if (res && res.success) {
                                $('#ndr-fm-progress-pct').text('Tamamlandı!');
                                window.location.reload();
                            } else {
                                alert((res && res.data && res.data.message) ? res.data.message : 'Yükleme hatası.');
                            }
                        } catch (err) { alert('Sunucu yanıtı okunamadı.'); }
                    };
                    xhr.send(fd);
                }

                var pendingUploadForm = null;
                $('#ndr-fm-upload-form').on('submit', function(e){
                    e.preventDefault();
                    var formEl = this;
                    var files = $('#ndr-fm-upload-input')[0].files;
                    if (!files.length) { alert('Lütfen dosya seçin.'); return; }

                    var names = [];
                    for (var i = 0; i < files.length; i++) {
                        names.push(files[i].name);
                    }

                    $.post(ajaxurl, {
                        action: 'ndr_fm_check_conflicts',
                        ndr_fm_nonce: $(formEl).find('input[name="ndr_fm_nonce"]').val(),
                        current_dir: $(formEl).find('input[name="current_dir"]').val(),
                        file_names: names
                    }).done(function(res){
                        if (res && res.success && res.data && res.data.conflicts && res.data.conflicts.length) {
                            pendingUploadForm = formEl;
                            var listHtml = res.data.conflicts.map(function(n){
                                return '<li>' + $('<div/>').text(n).html() + '</li>';
                            }).join('');
                            $('#ndr-fm-conflict-list').html(listHtml);
                            $('#ndr-fm-conflict-modal').addClass('is-open');
                        } else {
                            executeUpload(formEl, 'overwrite');
                        }
                    }).fail(function(){
                        executeUpload(formEl, 'overwrite');
                    });
                });

                $('#ndr-fm-conf-cancel').on('click', function(){
                    $('#ndr-fm-conflict-modal').removeClass('is-open');
                    pendingUploadForm = null;
                });
                $('#ndr-fm-conf-rename').on('click', function(){
                    $('#ndr-fm-conflict-modal').removeClass('is-open');
                    if (pendingUploadForm) executeUpload(pendingUploadForm, 'rename');
                });
                $('#ndr-fm-conf-overwrite').on('click', function(){
                    $('#ndr-fm-conflict-modal').removeClass('is-open');
                    if (pendingUploadForm) executeUpload(pendingUploadForm, 'overwrite');
                });

                // Single Actions
                $(document).on('click', '.ndr-fm-del-btn', function(){
                    var name = $(this).data('name'), sh = $(this).data('short');
                    if (!confirm(sh + ' silinsin mi?')) return;
                    $('#ndr-fm-single-action').val('delete_one');
                    $('#ndr-fm-single-target').val(name);
                    $('#ndr-fm-single-form').submit();
                });
                $(document).on('click', '.ndr-fm-unzip-btn', function(){
                    var name = $(this).data('name');
                    if (!confirm(name + ' arşivi açılsın mı?')) return;
                    $('#ndr-fm-progress-wrap').show();
                    $('#ndr-fm-progress-label').text('ZIP arşivi açılıyor...');
                    $('#ndr-fm-progress-fill').css('width', '70%');
                    $('#ndr-fm-single-action').val('unzip');
                    $('#ndr-fm-single-target').val(name);
                    $('#ndr-fm-single-form').submit();
                });
                $(document).on('click', '.ndr-fm-rename-btn', function(){
                    var oldRel = $(this).data('name'), sh = $(this).data('short');
                    var newName = prompt('Yeni ad:', sh);
                    if (!newName || newName === sh) return;
                    $('#ndr-fm-single-action').val('rename');
                    $('#ndr-fm-single-old').val(oldRel);
                    $('#ndr-fm-single-new').val(newName);
                    $('#ndr-fm-single-form').submit();
                });
                $(document).on('click', '.ndr-fm-copy-url', function(){
                    var $b = $(this), u = $b.data('url');
                    if (navigator.clipboard) navigator.clipboard.writeText(u);
                    $b.css('color', '#00a32a');
                    setTimeout(function(){ $b.css('color', ''); }, 1200);
                });

                // Image Preview Modal
                $(document).on('click', '.ndr-fm-img-preview', function(e){
                    e.preventDefault();
                    var url = $(this).attr('href'), t = $(this).data('title');
                    $('#ndr-fm-img-title').text(t);
                    $('#ndr-fm-img-el').attr('src', url);
                    $('#ndr-fm-img-newtab').attr('href', url);
                    $('#ndr-fm-img-modal').addClass('is-open');
                });
                $('#ndr-fm-img-close, #ndr-fm-img-modal').on('click', function(e){
                    if (e.target === this) $('#ndr-fm-img-modal').removeClass('is-open');
                });

                // Single Copy/Move Modal
                var cmMode = '', cmTarget = '';
                $(document).on('click', '.ndr-fm-copymove-btn', function(){
                    cmMode = $(this).data('mode');
                    cmTarget = $(this).data('name');
                    $('#ndr-fm-cm-title').text((cmMode === 'copy' ? 'Kopyala: ' : 'Taşı: ') + cmTarget);
                    $('#ndr-fm-cm-modal').addClass('is-open');
                });
                $('#ndr-fm-cm-cancel').on('click', function(){ $('#ndr-fm-cm-modal').removeClass('is-open'); });
                $('#ndr-fm-cm-confirm').on('click', function(){
                    $('#ndr-fm-single-action').val(cmMode === 'copy' ? 'copy_one' : 'move_one');
                    $('#ndr-fm-single-target').val(cmTarget);
                    $('#ndr-fm-single-tdir').val($('#ndr-fm-cm-select').val());
                    $('#ndr-fm-single-form').submit();
                });
                // Right-click context menu on table rows
                var $ctx = $('#ndr-fm-ctx-menu');
                $(document).on('contextmenu', 'tr.ndr-fm-row', function(e){
                    e.preventDefault();
                    var $menuHtml = $(this).find('.ndr-fm-dropdown').html();
                    $ctx.html($menuHtml).addClass('is-open');
                    var x = Math.min(e.clientX, window.innerWidth - 200);
                    var y = Math.min(e.clientY, window.innerHeight - $ctx.outerHeight() - 10);
                    $ctx.css({ left: x + 'px', top: y + 'px' });
                });
                $(document).on('click', function(e){
                    if (!$(e.target).closest('#ndr-fm-ctx-menu').length) {
                        $ctx.removeClass('is-open');
                    }
                    if (!$(e.target).closest('.ndr-fm-menu-wrap').length) {
                        $('.ndr-fm-menu-wrap').removeClass('is-open');
                    }
                });
                $(document).on('click', '.ndr-fm-menu-trigger', function(e){
                    e.stopPropagation();
                    var $w = $(this).closest('.ndr-fm-menu-wrap');
                    $('.ndr-fm-menu-wrap').not($w).removeClass('is-open');
                    $w.toggleClass('is-open');
                });
                $(document).on('click', '#ndr-fm-ctx-menu .ndr-fm-menu-item', function(){
                    $ctx.removeClass('is-open');
                });
            });
            </script>
        </div>
        <?php
    }
}