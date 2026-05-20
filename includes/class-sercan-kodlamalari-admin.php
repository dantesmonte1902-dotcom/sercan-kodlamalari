<?php
if (!defined('ABSPATH')) {
    exit;
}

class Sercan_Kodlamalari_Admin
{
    const OPTION_RECENT_TOOLS = 'sercan_kodlamalari_recent_tools';
    const OPTION_MODULES = 'sercan_kodlamalari_modules_v1';
    const TRANSIENT_MODULES_NOTICE = 'sercan_kodlamalari_modules_notice';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_admin_menu'], 9);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets'], 99);
        add_action('admin_init', [$this, 'track_recent_tool']);
        add_action('admin_init', [$this, 'handle_modules_save']);
    }

    public function register_admin_menu()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        add_menu_page(
            'Sercan Kodlamaları',
            'Sercan Kodlamaları',
            'manage_options',
            'sercan-kodlamalari',
            [$this, 'render_main_page'],
            'dashicons-admin-generic',
            2
        );

        add_submenu_page(
            'sercan-kodlamalari',
            'Modüller',
            'Modüller',
            'manage_options',
            'sercan-kodlamalari-moduller',
            [$this, 'render_modules_page']
        );
    }

    public function enqueue_assets($hook)
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';

        $allowed_pages = [
            'sercan-kodlamalari',
            'sercan-kodlamalari-moduller',
            'sercan-draft-product-cleaner',
            'sercan-url-source-splitter',
        ];

        if (!in_array($page, $allowed_pages, true)) {
            return;
        }

        $css_file = SERCAN_KODLAMALARI_PATH . 'assets/admin.css';
        $css_url  = SERCAN_KODLAMALARI_URL . 'assets/admin.css';
        $css_ver  = file_exists($css_file) ? filemtime($css_file) : SERCAN_KODLAMALARI_VERSION;

        wp_enqueue_style(
            'sercan-kodlamalari-admin',
            $css_url,
            [],
            $css_ver
        );
    }

    public function track_recent_tool()
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';

        $tools = $this->get_tools();

        foreach ($tools as $tool) {
            if ($page === $tool['slug']) {
                $recent = get_option(self::OPTION_RECENT_TOOLS, []);

                if (!is_array($recent)) {
                    $recent = [];
                }

                $recent = array_values(array_filter($recent, function ($item) use ($tool) {
                    return !isset($item['slug']) || $item['slug'] !== $tool['slug'];
                }));

                array_unshift($recent, [
                    'slug' => $tool['slug'],
                    'title' => $tool['title'],
                    'url' => $tool['url'],
                    'icon' => $tool['icon'],
                    'used_at' => current_time('mysql'),
                ]);

                $recent = array_slice($recent, 0, 5);

                update_option(self::OPTION_RECENT_TOOLS, $recent, false);
                break;
            }
        }
    }

    private function get_tools()
    {
        return [
            [
                'slug' => 'sercan-draft-product-cleaner',
                'title' => 'Taslak Ürün Temizleyici',
                'description' => 'WooCommerce taslak ürünlerini güvenli şekilde temizler. Batch işlem, ilerleme takibi ve log desteği sunar.',
                'icon' => '🧹',
                'url' => admin_url('admin.php?page=sercan-draft-product-cleaner'),
                'button' => 'Aracı Aç',
                'status' => 'Hazır',
                'category' => 'WooCommerce',
            ],
            [
                'slug' => 'sercan-url-source-splitter',
                'title' => 'URL Kaynak Kod Bölücü',
                'description' => 'Bir URL’nin kaynak kodunu çeker, uzun içerikleri parçalara böler ve GitHub Copilot için .txt dosyaları üretir.',
                'icon' => '🧩',
                'url' => admin_url('admin.php?page=sercan-url-source-splitter'),
                'button' => 'Aracı Aç',
                'status' => 'Hazır',
                'category' => 'Developer Tools',
            ],
        ];
    }

    public static function get_registered_modules()
    {
        return [
            'product_image_auto_delete' => [
                'title' => 'Ürün Görsellerini Otomatik Sil',
                'description' => 'Ürün veya ürün galerisi silindiğinde ilişkili medya dosyalarını da sunucudan kaldırır.',
                'icon' => '🖼️',
            ],
        ];
    }

    public static function get_modules_state()
    {
        $state = get_option(self::OPTION_MODULES, []);

        if (!is_array($state)) {
            $state = [];
        }

        $defaults = [];

        foreach (self::get_registered_modules() as $module_key => $module) {
            $defaults[$module_key] = 'no';
        }

        return wp_parse_args($state, $defaults);
    }

    public static function is_module_enabled($module_key)
    {
        $module_key = sanitize_key($module_key);
        $state = self::get_modules_state();

        return isset($state[$module_key]) && $state[$module_key] === 'yes';
    }

    public function handle_modules_save()
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        if ($page !== 'sercan-kodlamalari-moduller') {
            return;
        }

        if (!isset($_POST['sercan_modules_save'])) {
            return;
        }

        check_admin_referer('sercan_modules_save_action', 'sercan_modules_nonce');

        $submitted_modules = isset($_POST['sercan_modules']) && is_array($_POST['sercan_modules'])
            ? wp_unslash($_POST['sercan_modules'])
            : [];

        $state = [];

        foreach (self::get_registered_modules() as $module_key => $module) {
            $raw_value = isset($submitted_modules[$module_key]) ? sanitize_text_field($submitted_modules[$module_key]) : '0';
            $state[$module_key] = $raw_value === '1' ? 'yes' : 'no';
        }

        update_option(self::OPTION_MODULES, $state, false);

        $redirect_url = add_query_arg(
            [
                'page' => 'sercan-kodlamalari-moduller',
            ],
            admin_url('admin.php')
        );

        set_transient(self::TRANSIENT_MODULES_NOTICE, 'updated', MINUTE_IN_SECONDS);
        wp_safe_redirect($redirect_url);
        exit;
    }

    private function get_quick_stats($tools)
    {
        return [
            [
                'label' => 'Toplam Araç',
                'value' => count($tools),
                'color' => 'blue',
            ],
            [
                'label' => 'Erişim',
                'value' => 'Admin',
                'color' => 'purple',
            ],
            [
                'label' => 'Yapı',
                'value' => 'Modüler',
                'color' => 'green',
            ],
            [
                'label' => 'Durum',
                'value' => 'Aktif',
                'color' => 'orange',
            ],
        ];
    }

    public function render_main_page()
    {
        $tools = $this->get_tools();
        $stats = $this->get_quick_stats($tools);
        $recent_tools = get_option(self::OPTION_RECENT_TOOLS, []);

        if (!is_array($recent_tools)) {
            $recent_tools = [];
        }

        $upcoming_tools = [
            [
                'title' => 'Ürün Sağlık Merkezi',
                'description' => 'Eksik görsel, eksik fiyat, SKU ve kategori gibi ürün problemlerini toplu analiz eder.',
            ],
            [
                'title' => 'Kullanılmayan Medya Temizleyici',
                'description' => 'Hiçbir içerikte kullanılmayan medya dosyalarını tespit eder ve güvenli temizlik sunar.',
            ],
            [
                'title' => 'Akıllı Stok Uyarı Paneli',
                'description' => 'Düşük stok, kritik stok ve yeniden sipariş planlaması için yönetim ekranı sağlar.',
            ],
        ];
        ?>
        <div class="wrap sercan-wrap">
            <div class="sercan-home-hero sercan-home-hero-pro">
                <div class="sercan-home-hero-content">
                    <div class="sercan-home-badge">Sercan Kodlamaları</div>
                    <h1>Özel Yönetim ve Operasyon Paneli</h1>
                    <p>
                        WordPress ve WooCommerce yönetiminde ihtiyaç duyduğunuz özel araçları tek merkezden yönetin.
                        Operasyon, temizlik, veri işleme ve geliştirici araçlarını aynı panel altında modern bir arayüzle kullanın.
                    </p>

                    <div class="sercan-home-actions">
                        <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=sercan-draft-product-cleaner')); ?>">
                            Taslak Temizleyiciyi Aç
                        </a>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=sercan-kodlamalari-moduller')); ?>">
                            Modülleri Yönet
                        </a>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=sercan-url-source-splitter')); ?>">
                            Kaynak Kod Bölücüyü Aç
                        </a>
                    </div>
                </div>

                <div class="sercan-home-hero-side">
                    <?php foreach ($stats as $stat): ?>
                        <div class="sercan-home-stat sercan-home-stat-<?php echo esc_attr($stat['color']); ?>">
                            <span><?php echo esc_html($stat['label']); ?></span>
                            <strong><?php echo esc_html($stat['value']); ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="sercan-home-mini-grid">
                <div class="sercan-mini-card">
                    <span>Hızlı Erişim</span>
                    <strong><?php echo esc_html(count($tools)); ?> araç hazır</strong>
                </div>
                <div class="sercan-mini-card">
                    <span>Son Kullanım</span>
                    <strong><?php echo !empty($recent_tools) ? esc_html($recent_tools[0]['title']) : 'Henüz yok'; ?></strong>
                </div>
                <div class="sercan-mini-card">
                    <span>Yetki</span>
                    <strong>Sadece Admin</strong>
                </div>
                <div class="sercan-mini-card">
                    <span>Genişleme</span>
                    <strong>Yeni modüle hazır</strong>
                </div>
            </div>

            <div class="sercan-home-section">
                <div class="sercan-section-head">
                    <div>
                        <h2>Kullanılabilir Araçlar</h2>
                        <p>Aktif ve kullanıma hazır modülleriniz burada listelenir.</p>
                    </div>
                </div>

                <div class="sercan-tools-grid">
                    <?php foreach ($tools as $tool): ?>
                        <div class="sercan-tool-card sercan-tool-card-premium">
                            <div class="sercan-tool-top">
                                <div class="sercan-tool-icon"><?php echo esc_html($tool['icon']); ?></div>
                                <div class="sercan-tool-badges">
                                    <span class="sercan-chip"><?php echo esc_html($tool['category']); ?></span>
                                    <span class="sercan-chip sercan-chip-success"><?php echo esc_html($tool['status']); ?></span>
                                </div>
                            </div>

                            <h3><?php echo esc_html($tool['title']); ?></h3>
                            <p><?php echo esc_html($tool['description']); ?></p>

                            <div class="sercan-tool-footer">
                                <a class="button button-secondary" href="<?php echo esc_url($tool['url']); ?>">
                                    <?php echo esc_html($tool['button']); ?>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="sercan-home-two-col">
                <div class="sercan-home-section">
                    <div class="sercan-section-head">
                        <div>
                            <h2>Son Kullanılan Araçlar</h2>
                            <p>En son açtığınız modüller hızlı erişim için burada tutulur.</p>
                        </div>
                    </div>

                    <div class="sercan-list-card">
                        <?php if (!empty($recent_tools)): ?>
                            <?php foreach ($recent_tools as $recent): ?>
                                <div class="sercan-list-item">
                                    <div class="sercan-list-icon"><?php echo esc_html($recent['icon']); ?></div>
                                    <div class="sercan-list-content">
                                        <strong><?php echo esc_html($recent['title']); ?></strong>
                                        <span><?php echo esc_html($recent['used_at']); ?></span>
                                    </div>
                                    <div class="sercan-list-action">
                                        <a class="button" href="<?php echo esc_url($recent['url']); ?>">Aç</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="sercan-empty-state">Henüz kullanılan araç kaydı yok.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="sercan-home-section">
                    <div class="sercan-section-head">
                        <div>
                            <h2>Yakında Eklenebilecek Araçlar</h2>
                            <p>Bu panel için uygun sonraki geliştirme fikirleri.</p>
                        </div>
                    </div>

                    <div class="sercan-list-card">
                        <?php foreach ($upcoming_tools as $item): ?>
                            <div class="sercan-upcoming-item">
                                <strong><?php echo esc_html($item['title']); ?></strong>
                                <p><?php echo esc_html($item['description']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="sercan-home-section">
                <div class="sercan-section-head">
                    <div>
                        <h2>Panel Hakkında</h2>
                        <p>Bu yapı uzun vadeli kullanım için modüler olarak tasarlandı.</p>
                    </div>
                </div>

                <div class="sercan-info-grid">
                    <div class="sercan-info-card">
                        <h3>Modüler Yapı</h3>
                        <p>Her yeni araç bağımsız bir modül olarak eklenebilir. Böylece panel büyüse bile düzenli kalır.</p>
                    </div>

                    <div class="sercan-info-card">
                        <h3>Güvenlik</h3>
                        <p>Araçlar admin yetkisi ile sınırlandırılır. AJAX işlemlerinde nonce ve yetki kontrolleri uygulanır.</p>
                    </div>

                    <div class="sercan-info-card">
                        <h3>Ölçeklenebilirlik</h3>
                        <p>İleride raporlama, medya yönetimi, stok analizi ve ürün sağlık araçları da aynı panel altında toplanabilir.</p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_modules_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Bu alana erişim yetkiniz yok.');
        }

        $modules = self::get_registered_modules();
        $state = self::get_modules_state();
        $show_notice = get_transient(self::TRANSIENT_MODULES_NOTICE) === 'updated';

        if ($show_notice) {
            delete_transient(self::TRANSIENT_MODULES_NOTICE);
        }
        ?>
        <div class="wrap sercan-wrap">
            <div class="sercan-header">
                <div>
                    <h1>Modüller</h1>
                    <p>Sercan Kodlamaları içindeki modülleri buradan aktif veya pasif hale getirebilirsiniz.</p>
                </div>
                <div class="sercan-badge">Sercan Kodlamaları</div>
            </div>

            <?php if ($show_notice) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>Modül ayarları güncellendi.</p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=sercan-kodlamalari-moduller')); ?>">
                <?php wp_nonce_field('sercan_modules_save_action', 'sercan_modules_nonce'); ?>

                <div class="sercan-modules-grid">
                    <?php foreach ($modules as $module_key => $module) : ?>
                        <div class="sercan-module-card">
                            <div class="sercan-module-head">
                                <div class="sercan-tool-icon"><?php echo esc_html($module['icon']); ?></div>
                                <div class="sercan-module-content">
                                    <div class="sercan-module-topline">
                                        <h2><?php echo esc_html($module['title']); ?></h2>
                                        <label class="sercan-switch" for="sercan-module-<?php echo esc_attr($module_key); ?>">
                                            <input type="hidden" name="sercan_modules[<?php echo esc_attr($module_key); ?>]" value="0">
                                            <input
                                                type="checkbox"
                                                id="sercan-module-<?php echo esc_attr($module_key); ?>"
                                                name="sercan_modules[<?php echo esc_attr($module_key); ?>]"
                                                value="1"
                                                <?php checked(isset($state[$module_key]) && $state[$module_key] === 'yes'); ?>
                                            >
                                            <span class="sercan-switch-slider" aria-hidden="true"></span>
                                            <span class="sercan-switch-text"><?php echo isset($state[$module_key]) && $state[$module_key] === 'yes' ? 'Aktif' : 'Pasif'; ?></span>
                                        </label>
                                    </div>
                                    <p><?php echo esc_html($module['description']); ?></p>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="sercan-actions sercan-actions-spaced">
                    <button type="submit" name="sercan_modules_save" value="1" class="button button-primary">Ayarları Kaydet</button>
                </div>
            </form>
        </div>
        <?php
    }
}
