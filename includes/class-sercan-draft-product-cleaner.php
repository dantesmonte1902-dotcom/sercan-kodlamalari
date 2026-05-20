<?php
if (!defined('ABSPATH')) {
    exit;
}

class Sercan_Draft_Product_Cleaner
{
    const OPTION_STATE = 'sercan_draft_cleaner_state_v3';
    const OPTION_LOCK = 'sercan_draft_cleaner_lock_v3';
    const CRON_HOOK = 'sercan_draft_cleaner_cron_process_v3';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_submenu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        add_action('wp_ajax_sercan_draft_cleaner_start', [$this, 'ajax_start']);
        add_action('wp_ajax_sercan_draft_cleaner_run_batch', [$this, 'ajax_run_batch']);
        add_action('wp_ajax_sercan_draft_cleaner_reset', [$this, 'ajax_reset']);
        add_action('wp_ajax_sercan_draft_cleaner_status', [$this, 'ajax_status']);
        add_action('wp_ajax_sercan_draft_cleaner_stop', [$this, 'ajax_stop']);

        add_action(self::CRON_HOOK, [$this, 'cron_process']);
    }

    public function register_submenu()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        add_submenu_page(
            'sercan-kodlamalari',
            'Taslak Ürün Temizleyici',
            'Taslak Ürün Temizleyici',
            'manage_options',
            'sercan-draft-product-cleaner',
            [$this, 'render_page']
        );
    }

    public function enqueue_assets($hook)
    {
        if ($hook !== 'sercan-kodlamalari_page_sercan-draft-product-cleaner') {
            return;
        }

        wp_enqueue_style(
            'sercan-kodlamalari-admin',
            SERCAN_KODLAMALARI_URL . 'assets/admin.css',
            [],
            SERCAN_KODLAMALARI_VERSION
        );

        wp_enqueue_script(
            'sercan-kodlamalari-admin',
            SERCAN_KODLAMALARI_URL . 'assets/admin.js',
            ['jquery'],
            SERCAN_KODLAMALARI_VERSION,
            true
        );

        wp_localize_script('sercan-kodlamalari-admin', 'SercanDraftCleaner', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('sercan_draft_cleaner_nonce'),
        ]);
    }

    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Bu alana erişim yetkiniz yok.');
        }

        $state = $this->get_state();
        ?>
        <div class="wrap sercan-wrap">
            <div class="sercan-header">
                <div>
                    <h1>Taslak Ürün Temizleyici</h1>
                    <p>WooCommerce taslak ürünlerini kontrollü şekilde siler ve kullanılan görselleri korumaya çalışır.</p>
                </div>
                <div class="sercan-badge">Sercan Kodlamaları</div>
            </div>

            <div class="sercan-grid">
                <div class="sercan-card">
                    <h2>İşlem Ayarları</h2>

                    <div class="sercan-field">
                        <label>Mod</label>
                        <div class="sercan-radio-group">
                            <label class="sercan-radio">
                                <input type="radio" name="sercan_mode" value="dry_run" <?php checked($state['mode'] === 'dry_run' || empty($state['mode'])); ?>>
                                <span>Dry Run</span>
                            </label>
                            <label class="sercan-radio">
                                <input type="radio" name="sercan_mode" value="delete" <?php checked($state['mode'] === 'delete'); ?>>
                                <span>Gerçek Silme</span>
                            </label>
                        </div>
                    </div>

                    <div class="sercan-field">
                        <label for="sercan_batch">Batch Boyutu</label>
                        <input type="number" id="sercan_batch" value="<?php echo esc_attr((int) $state['batch']); ?>" min="1" max="50">
                        <small>Önerilen başlangıç: 3 - 5</small>
                    </div>

                    <div class="sercan-actions">
                        <button class="button button-primary" id="sercan-start-cleaner">Başlat</button>
                        <button class="button" id="sercan-stop-cleaner">Durdur</button>
                        <button class="button" id="sercan-refresh-status">Yenile</button>
                        <button class="button button-secondary" id="sercan-reset-cleaner">Reset</button>
                    </div>
                </div>

                <div class="sercan-card">
                    <h2>İlerleme</h2>

                    <div class="sercan-progress-meta">
                        <div>
                            <span class="sercan-label">İlerleme</span>
                            <strong id="sercan-progress-percent">0%</strong>
                        </div>
                        <div>
                            <span class="sercan-label">Kalan Draft</span>
                            <strong id="sercan-remaining-drafts">0</strong>
                        </div>
                    </div>

                    <div class="sercan-progress">
                        <div class="sercan-progress-bar" id="sercan-progress-bar" style="width:0%;"></div>
                    </div>

                    <div class="sercan-stats" id="sercan-stat-cards">
                        <div class="sercan-stat-card">
                            <span>Başlangıç Draft</span>
                            <strong id="stat-initial-total">0</strong>
                        </div>
                        <div class="sercan-stat-card">
                            <span>İşlenen Ürün</span>
                            <strong id="stat-processed">0</strong>
                        </div>
                        <div class="sercan-stat-card">
                            <span>Silinen Ürün</span>
                            <strong id="stat-deleted-products">0</strong>
                        </div>
                        <div class="sercan-stat-card">
                            <span>Silinen Görsel</span>
                            <strong id="stat-deleted-attachments">0</strong>
                        </div>
                        <div class="sercan-stat-card">
                            <span>Korunan Görsel</span>
                            <strong id="stat-skipped-attachments">0</strong>
                        </div>
                        <div class="sercan-stat-card">
                            <span>Hata</span>
                            <strong id="stat-errors">0</strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="sercan-grid sercan-grid-bottom">
                <div class="sercan-card">
                    <h2>Canlı Durum</h2>
                    <div id="sercan-cleaner-status" class="sercan-status-list"></div>
                </div>

                <div class="sercan-card">
                    <div class="sercan-log-head">
                        <h2>İşlem Logu</h2>
                        <button class="button" id="sercan-copy-log">Log Kopyala</button>
                    </div>
                    <textarea id="sercan-cleaner-log" readonly placeholder="Loglar burada görünecek..."></textarea>
                </div>
            </div>
        </div>
        <?php
    }

    private function get_default_state()
    {
        return [
            'mode' => '',
            'batch' => 5,
            'running' => false,
            'completed' => false,
            'stop_requested' => false,
            'processed_products' => 0,
            'deleted_products' => 0,
            'deleted_attachments' => 0,
            'skipped_attachments' => 0,
            'errors' => 0,
            'last_product_id' => 0,
            'started_at' => '',
            'updated_at' => '',
            'initial_total_drafts' => 0,
            'remaining_drafts' => 0,
            'progress_percent' => 0,
            'log' => [],
        ];
    }

    private function get_state()
    {
        $state = get_option(self::OPTION_STATE, []);
        if (!is_array($state)) {
            $state = [];
        }

        return wp_parse_args($state, $this->get_default_state());
    }

    private function save_state($state)
    {
        update_option(self::OPTION_STATE, $state, false);
    }

    private function append_log(&$state, $message)
    {
        $line = '[' . current_time('mysql') . '] ' . $message;
        $state['log'][] = $line;

        if (count($state['log']) > 1000) {
            $state['log'] = array_slice($state['log'], -1000);
        }

        $state['updated_at'] = current_time('mysql');
    }

    private function verify_request()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Yetkisiz işlem.'], 403);
        }

        check_ajax_referer('sercan_draft_cleaner_nonce', 'nonce');
    }

    private function get_draft_count()
    {
        $counts = wp_count_posts('product');
        return isset($counts->draft) ? (int) $counts->draft : 0;
    }

    private function update_progress(&$state)
    {
        $remaining = $this->get_draft_count();
        $initial = max(0, (int) $state['initial_total_drafts']);

        $state['remaining_drafts'] = $remaining;

        if ($initial > 0) {
            $done = max(0, $initial - $remaining);
            $percent = ($done / $initial) * 100;
            $state['progress_percent'] = min(100, max(0, round($percent, 2)));
        } else {
            $state['progress_percent'] = 0;
        }
    }

    private function acquire_lock()
    {
        $lock = get_option(self::OPTION_LOCK, []);

        if (!empty($lock['locked'])) {
            $locked_at = isset($lock['time']) ? (int) $lock['time'] : 0;

            if ($locked_at > 0 && (time() - $locked_at) < 90) {
                return false;
            }
        }

        update_option(self::OPTION_LOCK, [
            'locked' => 1,
            'time'   => time(),
        ], false);

        return true;
    }

    private function refresh_lock()
    {
        update_option(self::OPTION_LOCK, [
            'locked' => 1,
            'time'   => time(),
        ], false);
    }

    private function release_lock()
    {
        delete_option(self::OPTION_LOCK);
    }

    private function schedule_cron()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 10, self::CRON_HOOK);
        }
    }

    private function clear_cron()
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
        }
    }

    public function ajax_start()
    {
        $this->verify_request();

        $mode = isset($_POST['mode']) ? sanitize_text_field(wp_unslash($_POST['mode'])) : 'dry_run';
        $mode = ($mode === 'delete') ? 'delete' : 'dry_run';
        $batch = isset($_POST['batch']) ? max(1, min(50, (int) $_POST['batch'])) : 5;

        $state = $this->get_default_state();
        $state['mode'] = $mode;
        $state['batch'] = $batch;
        $state['running'] = true;
        $state['completed'] = false;
        $state['stop_requested'] = false;
        $state['started_at'] = current_time('mysql');
        $state['updated_at'] = current_time('mysql');
        $state['initial_total_drafts'] = $this->get_draft_count();
        $state['remaining_drafts'] = $state['initial_total_drafts'];
        $state['progress_percent'] = 0;

        $this->append_log($state, 'İşlem başlatıldı. Mod: ' . $mode . ' | Batch: ' . $batch . ' | Toplam draft: ' . $state['initial_total_drafts']);
        $this->save_state($state);
        $this->release_lock();
        $this->clear_cron();
        $this->schedule_cron();

        wp_send_json_success([
            'state' => $state,
        ]);
    }

    public function ajax_stop()
    {
        $this->verify_request();

        $state = $this->get_state();
        $state['stop_requested'] = true;
        $state['running'] = false;
        $this->append_log($state, 'Durdurma talebi alındı.');
        $this->save_state($state);
        $this->clear_cron();
        $this->release_lock();

        wp_send_json_success([
            'state' => $state,
            'message' => 'İşlem durduruldu.',
        ]);
    }

    public function ajax_reset()
    {
        $this->verify_request();

        delete_option(self::OPTION_STATE);
        $this->clear_cron();
        $this->release_lock();

        wp_send_json_success([
            'message' => 'Durum sıfırlandı.',
        ]);
    }

    public function ajax_status()
    {
        $this->verify_request();

        $state = $this->get_state();
        $this->update_progress($state);
        $this->save_state($state);

        wp_send_json_success([
            'state' => $state,
        ]);
    }

    private function get_product_attachment_ids($product)
    {
        $attachment_ids = [];

        $thumbnail_id = get_post_thumbnail_id($product->get_id());
        if ($thumbnail_id) {
            $attachment_ids[] = (int) $thumbnail_id;
        }

        $gallery_ids = $product->get_gallery_image_ids();
        if (!empty($gallery_ids)) {
            $attachment_ids = array_merge($attachment_ids, array_map('intval', $gallery_ids));
        }

        return array_values(array_unique(array_filter($attachment_ids)));
    }

    private function is_attachment_used_elsewhere($attachment_id, $current_product_id)
    {
        global $wpdb;

        $attachment_id = (int) $attachment_id;
        $current_product_id = (int) $current_product_id;

        if ($attachment_id <= 0) {
            return false;
        }

        $parent_id = (int) wp_get_post_parent_id($attachment_id);
        if ($parent_id > 0 && $parent_id !== $current_product_id) {
            return true;
        }

        $featured_in_others = $wpdb->get_var($wpdb->prepare("
            SELECT post_id
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_thumbnail_id'
              AND meta_value = %d
              AND post_id != %d
            LIMIT 1
        ", $attachment_id, $current_product_id));

        if (!empty($featured_in_others)) {
            return true;
        }

        $gallery_in_others = $wpdb->get_var($wpdb->prepare("
            SELECT post_id
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_product_image_gallery'
              AND post_id != %d
              AND (
                  meta_value = %s
                  OR meta_value LIKE %s
                  OR meta_value LIKE %s
                  OR meta_value LIKE %s
              )
            LIMIT 1
        ",
            $current_product_id,
            (string) $attachment_id,
            $attachment_id . ',%',
            '%,' . $attachment_id . ',%',
            '%,' . $attachment_id
        ));

        if (!empty($gallery_in_others)) {
            return true;
        }

        $file_path = get_attached_file($attachment_id);
        $file_url = wp_get_attachment_url($attachment_id);

        if ($file_url) {
            $content_usage = $wpdb->get_var($wpdb->prepare("
                SELECT ID
                FROM {$wpdb->posts}
                WHERE ID != %d
                  AND post_content LIKE %s
                LIMIT 1
            ", $current_product_id, '%' . $wpdb->esc_like($file_url) . '%'));

            if (!empty($content_usage)) {
                return true;
            }
        }

        if ($file_path) {
            $basename = wp_basename($file_path);

            if ($basename) {
                $content_usage_by_name = $wpdb->get_var($wpdb->prepare("
                    SELECT ID
                    FROM {$wpdb->posts}
                    WHERE ID != %d
                      AND post_content LIKE %s
                    LIMIT 1
                ", $current_product_id, '%' . $wpdb->esc_like($basename) . '%'));

                if (!empty($content_usage_by_name)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function process_batch($context = 'ajax')
    {
        if (!function_exists('wc_get_products')) {
            return ['success' => false, 'message' => 'WooCommerce aktif değil.'];
        }

        $state = $this->get_state();

        if (empty($state['running']) || !empty($state['stop_requested'])) {
            $this->release_lock();
            return ['success' => true, 'done' => true, 'state' => $state];
        }

        if (!empty($state['completed'])) {
            $this->release_lock();
            return ['success' => true, 'done' => true, 'state' => $state];
        }

        $products = wc_get_products([
            'status'  => 'draft',
            'limit'   => (int) $state['batch'],
            'orderby' => 'ID',
            'order'   => 'ASC',
            'return'  => 'objects',
        ]);

        if (empty($products)) {
            $state['running'] = false;
            $state['completed'] = true;
            $this->update_progress($state);
            $this->append_log($state, 'Taslak ürün kalmadı. İşlem tamamlandı.');
            $this->save_state($state);
            $this->clear_cron();
            $this->release_lock();

            return ['success' => true, 'done' => true, 'state' => $state];
        }

        $this->append_log($state, strtoupper($context) . ' batch başladı. Ürün sayısı: ' . count($products));

        foreach ($products as $product) {
            if (!empty($state['stop_requested'])) {
                $state['running'] = false;
                $this->append_log($state, 'Stop isteği nedeniyle işlem durduruldu.');
                $this->update_progress($state);
                $this->save_state($state);
                $this->clear_cron();
                $this->release_lock();

                return ['success' => true, 'done' => true, 'state' => $state];
            }

            $product_id = $product->get_id();
            $product_name = $product->get_name();
            $attachment_ids = $this->get_product_attachment_ids($product);

            $state['processed_products']++;
            $state['last_product_id'] = $product_id;
            $this->append_log($state, 'İşleniyor: #' . $product_id . ' | ' . $product_name);

            if ($state['mode'] === 'dry_run') {
                $this->append_log($state, 'DRY RUN: Ürün silinecek: #' . $product_id);

                foreach ($attachment_ids as $attachment_id) {
                    if ($this->is_attachment_used_elsewhere($attachment_id, $product_id)) {
                        $state['skipped_attachments']++;
                        $this->append_log($state, 'DRY RUN: Görsel korunacak: #' . $attachment_id);
                    } else {
                        $this->append_log($state, 'DRY RUN: Görsel silinecek: #' . $attachment_id);
                    }
                }

                $this->refresh_lock();
                $this->update_progress($state);
                $this->save_state($state);
                continue;
            }

            $deleted = wp_delete_post($product_id, true);

            if ($deleted) {
                $state['deleted_products']++;
                $this->append_log($state, 'Ürün silindi: #' . $product_id);

                foreach ($attachment_ids as $attachment_id) {
                    $attachment_post = get_post($attachment_id);

                    if (!$attachment_post || $attachment_post->post_type !== 'attachment') {
                        $this->append_log($state, 'Geçersiz attachment atlandı: #' . $attachment_id);
                        continue;
                    }

                    if ($this->is_attachment_used_elsewhere($attachment_id, $product_id)) {
                        $state['skipped_attachments']++;
                        $this->append_log($state, 'Görsel korundu, başka yerde kullanılıyor: #' . $attachment_id);
                        continue;
                    }

                    $attachment_deleted = wp_delete_attachment($attachment_id, true);

                    if ($attachment_deleted) {
                        $state['deleted_attachments']++;
                        $this->append_log($state, 'Görsel silindi: #' . $attachment_id);
                    } else {
                        $state['errors']++;
                        $this->append_log($state, 'HATA: Görsel silinemedi: #' . $attachment_id);
                    }
                }
            } else {
                $state['errors']++;
                $this->append_log($state, 'HATA: Ürün silinemedi: #' . $product_id);
            }

            $this->refresh_lock();
            $this->update_progress($state);
            $this->save_state($state);
        }

        $this->update_progress($state);
        $this->save_state($state);
        $this->schedule_cron();
        $this->release_lock();

        return [
            'success' => true,
            'done' => false,
            'state' => $state,
        ];
    }

    public function ajax_run_batch()
    {
        $this->verify_request();

        if (!$this->acquire_lock()) {
            $state = $this->get_state();
            $this->update_progress($state);
            $this->save_state($state);

            wp_send_json_success([
                'state' => $state,
                'locked' => true,
                'message' => 'İşlem devam ediyor, birazdan tekrar denenecek.',
            ]);
        }

        $result = $this->process_batch('ajax');

        if (!empty($result['success'])) {
            wp_send_json_success([
                'state'  => $result['state'],
                'done'   => !empty($result['done']),
                'locked' => false,
            ]);
        }

        wp_send_json_error($result);
    }

    public function cron_process()
    {
        $state = $this->get_state();

        if (empty($state['running']) || !empty($state['completed']) || !empty($state['stop_requested'])) {
            $this->clear_cron();
            $this->release_lock();
            return;
        }

        if (!$this->acquire_lock()) {
            $this->schedule_cron();
            return;
        }

        $result = $this->process_batch('cron');

        if (empty($result['done'])) {
            $this->schedule_cron();
        }
    }
}