<?php
if (!defined('ABSPATH')) {
    exit;
}

class Sercan_URL_Source_Splitter
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_submenu'], 30);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        add_action('wp_ajax_sercan_url_splitter_fetch', [$this, 'ajax_fetch_and_split']);
        add_action('wp_ajax_sercan_url_splitter_delete_all', [$this, 'ajax_delete_all_files']);
        add_action('wp_ajax_sercan_url_splitter_list_files', [$this, 'ajax_list_files']);
        add_action('wp_ajax_sercan_url_splitter_download_all_txt', [$this, 'ajax_download_all_txt']);
        add_action('wp_ajax_sercan_url_splitter_create_zip', [$this, 'ajax_create_zip']);
    }

    public function register_submenu()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        add_submenu_page(
            'sercan-kodlamalari',
            'URL Kaynak Kod Bölücü',
            'URL Kaynak Kod Bölücü',
            'manage_options',
            'sercan-url-source-splitter',
            [$this, 'render_page']
        );
    }

    public function enqueue_assets($hook)
    {
        if ($hook !== 'sercan-kodlamalari_page_sercan-url-source-splitter') {
            return;
        }

        wp_enqueue_style(
            'sercan-kodlamalari-admin',
            SERCAN_KODLAMALARI_URL . 'assets/admin.css',
            [],
            file_exists(SERCAN_KODLAMALARI_PATH . 'assets/admin.css') ? filemtime(SERCAN_KODLAMALARI_PATH . 'assets/admin.css') : SERCAN_KODLAMALARI_VERSION
        );

        wp_enqueue_script(
            'sercan-kodlamalari-admin',
            SERCAN_KODLAMALARI_URL . 'assets/admin.js',
            ['jquery'],
            file_exists(SERCAN_KODLAMALARI_PATH . 'assets/admin.js') ? filemtime(SERCAN_KODLAMALARI_PATH . 'assets/admin.js') : SERCAN_KODLAMALARI_VERSION,
            true
        );

        wp_localize_script('sercan-kodlamalari-admin', 'SercanUrlSplitter', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('sercan_url_splitter_nonce'),
            'page'    => 'sercan-url-source-splitter',
        ]);
    }

    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Bu alana erişim yetkiniz yok.');
        }
        ?>
        <div class="wrap sercan-wrap">
            <div class="sercan-header">
                <div>
                    <h1>URL Kaynak Kod Bölücü</h1>
                    <p>Bir URL'nin kaynak kodunu çekin, belirlediğiniz boyuta göre parçalara bölün ve .txt olarak kaydedin.</p>
                </div>
                <div class="sercan-badge">Sercan Kodlamaları</div>
            </div>

            <div class="sercan-grid">
                <div class="sercan-card">
                    <h2>İşlem Ayarları</h2>

                    <div class="sercan-field">
                        <label for="sercan-url-source">URL</label>
                        <input type="url" id="sercan-url-source" class="sercan-text-input" placeholder="https://ornek.com">
                    </div>

                    <div class="sercan-field">
                        <label for="sercan-url-split-size">Parça Boyutu</label>
                        <input type="number" id="sercan-url-split-size" value="50000" min="1000" step="1000">
                        <small>Karakter bazlı bölme yapılır. Önerilen: 30000 - 80000</small>
                    </div>

                    <div class="sercan-field">
                        <label for="sercan-url-split-mode">Bölme Tipi</label>
                        <select id="sercan-url-split-mode" class="sercan-select">
                            <option value="chars">Karakter Sayısına Göre</option>
                            <option value="lines">Satır Sayısına Göre</option>
                        </select>
                    </div>

                    <div class="sercan-field">
                        <label for="sercan-url-line-size">Satır Limiti</label>
                        <input type="number" id="sercan-url-line-size" value="500" min="50" step="50">
                        <small>Sadece "Satır Sayısına Göre" seçiliyse kullanılır.</small>
                    </div>

                    <div class="sercan-actions">
                        <button class="button button-primary" id="sercan-fetch-and-split">Kaynağı Çek ve Böl</button>
                        <button class="button" id="sercan-clear-split-results">Temizle</button>
                        <button class="button" id="sercan-download-all-txt">Tüm TXT'leri İndir</button>
                        <button class="button" id="sercan-download-zip">ZIP Olarak İndir</button>
                        <button class="button button-secondary" id="sercan-delete-all-generated-files">Tüm Oluşturulan Dosyaları Sil</button>
                    </div>
                </div>

                <div class="sercan-card">
                    <h2>Özet</h2>

                    <div class="sercan-stats sercan-stats-two">
                        <div class="sercan-stat-card">
                            <span>Toplam Karakter</span>
                            <strong id="sercan-source-total-chars">0</strong>
                        </div>
                        <div class="sercan-stat-card">
                            <span>Toplam Satır</span>
                            <strong id="sercan-source-total-lines">0</strong>
                        </div>
                        <div class="sercan-stat-card">
                            <span>Toplam Parça</span>
                            <strong id="sercan-source-total-parts">0</strong>
                        </div>
                        <div class="sercan-stat-card">
                            <span>Durum</span>
                            <strong id="sercan-source-status">Hazır</strong>
                        </div>
                    </div>

                    <div id="sercan-source-meta" class="sercan-status-list sercan-source-meta"></div>
                </div>
            </div>

            <div class="sercan-card">
                <div class="sercan-log-head">
                    <h2>Oluşan Parçalar</h2>
                    <button class="button" id="sercan-copy-all-parts">Tümünü Kopyala</button>
                </div>

                <div id="sercan-source-parts" class="sercan-source-parts">
                    <div class="sercan-empty-state">Henüz işlem yapılmadı.</div>
                </div>
            </div>

            <div class="sercan-card">
                <div class="sercan-log-head">
                    <h2>Sunucuda Oluşturulan TXT Dosyaları</h2>
                    <button class="button" id="sercan-refresh-generated-files">Listeyi Yenile</button>
                </div>

                <div id="sercan-storage-debug" class="sercan-source-meta" style="margin-bottom:16px;"></div>

                <div id="sercan-generated-files-list" class="sercan-source-parts">
                    <div class="sercan-empty-state">Henüz oluşturulmuş dosya yok.</div>
                </div>
            </div>
        </div>
        <?php
    }

    private function verify_request()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Yetkisiz işlem.'], 403);
        }

        check_ajax_referer('sercan_url_splitter_nonce', 'nonce');
    }

    private function get_storage_info()
    {
        $dir = trailingslashit(SERCAN_KODLAMALARI_PATH) . 'data/';
        $url = trailingslashit(SERCAN_KODLAMALARI_URL) . 'data/';

        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        return [
            'dir'      => $dir,
            'url'      => $url,
            'exists'   => file_exists($dir),
            'writable' => file_exists($dir) ? is_writable($dir) : false,
        ];
    }

    private function split_by_chars($content, $chunk_size)
    {
        $parts = [];
        $length = strlen($content);
        $offset = 0;
        $index = 1;

        while ($offset < $length) {
            $chunk = substr($content, $offset, $chunk_size);
            $parts[] = [
                'index'   => $index,
                'content' => $chunk,
                'chars'   => strlen($chunk),
                'lines'   => substr_count($chunk, "\n") + 1,
            ];
            $offset += $chunk_size;
            $index++;
        }

        return $parts;
    }

    private function split_by_lines($content, $line_limit)
    {
        $parts = [];
        $lines = preg_split("/\r\n|\n|\r/", $content);
        $chunks = array_chunk($lines, $line_limit);
        $index = 1;

        foreach ($chunks as $chunk_lines) {
            $chunk = implode("\n", $chunk_lines);
            $parts[] = [
                'index'   => $index,
                'content' => $chunk,
                'chars'   => strlen($chunk),
                'lines'   => count($chunk_lines),
            ];
            $index++;
        }

        return $parts;
    }

    private function build_filename_base($url)
    {
        $host = wp_parse_url($url, PHP_URL_HOST);
        $path = wp_parse_url($url, PHP_URL_PATH);

        $base = $host ? $host : 'source';

        if ($path) {
            $path = trim($path, '/');
            $path = str_replace('/', '-', $path);

            if ($path !== '') {
                $base .= '-' . $path;
            }
        }

        $base = strtolower($base);
        $base = preg_replace('/[^a-z0-9\-\.]+/i', '-', $base);
        $base = trim($base, '-');

        return $base ?: 'source';
    }

    private function list_saved_files()
    {
        $storage = $this->get_storage_info();

        if (!$storage['exists']) {
            return [];
        }

        $paths = glob($storage['dir'] . '*.txt');
        if (!is_array($paths)) {
            return [];
        }

        usort($paths, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        $files = [];

        foreach ($paths as $path) {
            if (!file_exists($path) || !is_file($path)) {
                continue;
            }

            $filename = basename($path);
            $content = @file_get_contents($path);

            $files[] = [
                'filename'   => $filename,
                'path'       => $path,
                'file_url'   => $storage['url'] . rawurlencode($filename),
                'filesize'   => @filesize($path) ?: 0,
                'chars'      => ($content !== false) ? strlen($content) : 0,
                'lines'      => ($content !== false) ? (substr_count($content, "\n") + 1) : 0,
                'created_at' => date('Y-m-d H:i:s', @filemtime($path) ?: time()),
            ];
        }

        return $files;
    }

    private function store_parts_as_files($url, $parts)
    {
        $storage = $this->get_storage_info();
        $filename_base = $this->build_filename_base($url);
        $created_files = [];

        if (!$storage['exists'] || !$storage['writable']) {
            return $created_files;
        }

        foreach ($parts as $part) {
            $part_number = str_pad((string) $part['index'], 2, '0', STR_PAD_LEFT);
            $filename = $filename_base . '-part-' . $part_number . '.txt';
            $file_path = $storage['dir'] . $filename;

            $written = @file_put_contents($file_path, $part['content']);

            if ($written === false || !file_exists($file_path)) {
                continue;
            }

            $created_files[] = [
                'filename'   => $filename,
                'path'       => $file_path,
                'file_url'   => $storage['url'] . rawurlencode($filename),
                'source_url' => $url,
                'chars'      => $part['chars'],
                'lines'      => $part['lines'],
                'created_at' => date('Y-m-d H:i:s', filemtime($file_path)),
                'filesize'   => filesize($file_path),
            ];
        }

        return $created_files;
    }

    private function create_zip_from_files($zip_name = '')
    {
        if (!class_exists('ZipArchive')) {
            return new WP_Error('zip_missing', 'Sunucuda ZipArchive desteği yok.');
        }

        $storage = $this->get_storage_info();
        $files = $this->list_saved_files();

        if (empty($files)) {
            return new WP_Error('empty_files', 'İndirilecek TXT dosyası bulunamadı.');
        }

        if (!$storage['exists'] || !$storage['writable']) {
            return new WP_Error('storage_not_writable', 'Data klasörü yazılabilir değil.');
        }

        if (empty($zip_name)) {
            $zip_name = 'sercan-url-source-files-' . date('Ymd-His') . '.zip';
        }

        $zip_path = $storage['dir'] . $zip_name;
        $zip_url  = $storage['url'] . rawurlencode($zip_name);

        if (file_exists($zip_path)) {
            @unlink($zip_path);
        }

        $zip = new ZipArchive();
        $result = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== true) {
            return new WP_Error('zip_create_failed', 'ZIP dosyası oluşturulamadı.');
        }

        foreach ($files as $file) {
            if (!empty($file['path']) && file_exists($file['path'])) {
                $zip->addFile($file['path'], $file['filename']);
            }
        }

        $zip->close();

        if (!file_exists($zip_path)) {
            return new WP_Error('zip_not_found', 'ZIP oluşturuldu ancak bulunamadı.');
        }

        return [
            'zip_name' => $zip_name,
            'zip_path' => $zip_path,
            'zip_url'  => $zip_url,
            'files_count' => count($files),
        ];
    }

    public function ajax_list_files()
    {
        $this->verify_request();

        wp_send_json_success([
            'files'   => $this->list_saved_files(),
            'storage' => $this->get_storage_info(),
        ]);
    }

    public function ajax_delete_all_files()
    {
        $this->verify_request();

        $storage = $this->get_storage_info();
        $paths = glob($storage['dir'] . '*');

        $deleted_count = 0;

        if (is_array($paths)) {
            foreach ($paths as $path) {
                if (file_exists($path) && is_file($path) && @unlink($path)) {
                    $deleted_count++;
                }
            }
        }

        wp_send_json_success([
            'message'       => $deleted_count . ' adet dosya silindi.',
            'deleted_count' => $deleted_count,
            'files'         => $this->list_saved_files(),
            'storage'       => $storage,
        ]);
    }

    public function ajax_download_all_txt()
    {
        $this->verify_request();

        $files = $this->list_saved_files();

        if (empty($files)) {
            wp_send_json_error(['message' => 'İndirilecek TXT dosyası bulunamadı.']);
        }

        $download_urls = [];
        foreach ($files as $file) {
            if (!empty($file['file_url'])) {
                $download_urls[] = [
                    'filename' => $file['filename'],
                    'url' => $file['file_url'],
                ];
            }
        }

        wp_send_json_success([
            'files' => $download_urls,
            'count' => count($download_urls),
        ]);
    }

    public function ajax_create_zip()
    {
        $this->verify_request();

        $result = $this->create_zip_from_files();

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    public function ajax_fetch_and_split()
    {
        $this->verify_request();

        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        $split_mode = isset($_POST['split_mode']) ? sanitize_text_field(wp_unslash($_POST['split_mode'])) : 'chars';
        $chunk_size = isset($_POST['chunk_size']) ? max(1000, (int) $_POST['chunk_size']) : 50000;
        $line_limit = isset($_POST['line_limit']) ? max(50, (int) $_POST['line_limit']) : 500;

        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            wp_send_json_error(['message' => 'Geçerli bir URL girin.']);
        }

        $response = wp_remote_get($url, [
            'timeout'     => 30,
            'redirection' => 5,
            'user-agent'  => 'Mozilla/5.0 (compatible; SercanKodlamalari/3.1; +WordPress)',
            'sslverify'   => false,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'URL alınamadı: ' . $response->get_error_message()]);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $headers = wp_remote_retrieve_headers($response);

        if ((int) $status_code >= 400) {
            wp_send_json_error(['message' => 'Uzak sunucu hata döndürdü. HTTP Kod: ' . $status_code]);
        }

        if ($body === '') {
            wp_send_json_error(['message' => 'Kaynak kod boş döndü.']);
        }

        if ($split_mode === 'lines') {
            $parts = $this->split_by_lines($body, $line_limit);
        } else {
            $split_mode = 'chars';
            $parts = $this->split_by_chars($body, $chunk_size);
        }

        $filename_base = $this->build_filename_base($url);
        $total_chars = strlen($body);
        $total_lines = substr_count($body, "\n") + 1;
        $content_type = isset($headers['content-type']) ? (string) $headers['content-type'] : '';

        $formatted_parts = [];

        foreach ($parts as $part) {
            $part_number = str_pad((string) $part['index'], 2, '0', STR_PAD_LEFT);
            $filename = $filename_base . '-part-' . $part_number . '.txt';

            $formatted_parts[] = [
                'index'    => $part['index'],
                'filename' => $filename,
                'content'  => $part['content'],
                'chars'    => $part['chars'],
                'lines'    => $part['lines'],
            ];
        }

        $created_files = $this->store_parts_as_files($url, $formatted_parts);

        wp_send_json_success([
            'url'               => $url,
            'status_code'       => $status_code,
            'content_type'      => $content_type,
            'split_mode'        => $split_mode,
            'total_chars'       => $total_chars,
            'total_lines'       => $total_lines,
            'total_parts'       => count($formatted_parts),
            'parts'             => $formatted_parts,
            'saved_files_count' => count($created_files),
            'saved_files'       => $created_files,
            'storage'           => $this->get_storage_info(),
        ]);
    }
}