jQuery(function ($) {
    let running = false;
    let timer = null;

    function esc(v) {
        return String(v ?? '');
    }

    function clearLoop() {
        if (timer) {
            clearTimeout(timer);
            timer = null;
        }
    }

    function queueNext(ms) {
        clearLoop();
        timer = setTimeout(runLoop, ms);
    }

    function ajaxCleaner(action, data = {}) {
        if (typeof SercanDraftCleaner === 'undefined') {
            return $.Deferred().reject().promise();
        }

        return $.post(SercanDraftCleaner.ajaxUrl, {
            action: action,
            nonce: SercanDraftCleaner.nonce,
            ...data
        });
    }

    function ajaxUrlSplitter(action, data = {}) {
        if (typeof SercanUrlSplitter === 'undefined') {
            return $.Deferred().reject().promise();
        }

        return $.post(SercanUrlSplitter.ajaxUrl, {
            action: action,
            nonce: SercanUrlSplitter.nonce,
            ...data
        });
    }

    function renderState(state) {
        if (!state || $('#sercan-progress-percent').length === 0) return;

        const percent = Number(state.progress_percent || 0);

        $('#sercan-progress-percent').text('%' + percent);
        $('#sercan-remaining-drafts').text(state.remaining_drafts || 0);
        $('#sercan-progress-bar').css('width', percent + '%');

        $('#stat-initial-total').text(state.initial_total_drafts || 0);
        $('#stat-processed').text(state.processed_products || 0);
        $('#stat-deleted-products').text(state.deleted_products || 0);
        $('#stat-deleted-attachments').text(state.deleted_attachments || 0);
        $('#stat-skipped-attachments').text(state.skipped_attachments || 0);
        $('#stat-errors').text(state.errors || 0);

        const statusHtml = `
            <div class="sercan-status-item"><span>Mod</span><strong>${esc(state.mode || '-')}</strong></div>
            <div class="sercan-status-item"><span>Batch</span><strong>${esc(state.batch || 0)}</strong></div>
            <div class="sercan-status-item"><span>Çalışıyor</span><strong>${state.running ? 'Evet' : 'Hayır'}</strong></div>
            <div class="sercan-status-item"><span>Tamamlandı</span><strong>${state.completed ? 'Evet' : 'Hayır'}</strong></div>
            <div class="sercan-status-item"><span>Durdurma Talebi</span><strong>${state.stop_requested ? 'Evet' : 'Hayır'}</strong></div>
            <div class="sercan-status-item"><span>Son Ürün ID</span><strong>${esc(state.last_product_id || 0)}</strong></div>
            <div class="sercan-status-item"><span>Başlangıç</span><strong>${esc(state.started_at || '-')}</strong></div>
            <div class="sercan-status-item"><span>Son Güncelleme</span><strong>${esc(state.updated_at || '-')}</strong></div>
        `;

        $('#sercan-cleaner-status').html(statusHtml);
        $('#sercan-cleaner-log').val((state.log || []).join("\n"));

        const logEl = $('#sercan-cleaner-log')[0];
        if (logEl) {
            logEl.scrollTop = logEl.scrollHeight;
        }
    }

    function runLoop() {
        if (!running || $('#sercan-start-cleaner').length === 0) return;

        ajaxCleaner('sercan_draft_cleaner_run_batch')
            .done(function (response) {
                if (response && response.success && response.data && response.data.state) {
                    renderState(response.data.state);

                    if (response.data.done || response.data.state.completed || !response.data.state.running) {
                        running = false;
                        clearLoop();
                        return;
                    }

                    if (response.data.locked) {
                        queueNext(3000);
                        return;
                    }

                    queueNext(800);
                } else {
                    queueNext(3000);
                }
            })
            .fail(function () {
                queueNext(4000);
            });
    }

    if ($('#sercan-start-cleaner').length > 0 && typeof SercanDraftCleaner !== 'undefined') {
        $('#sercan-start-cleaner').on('click', function () {
            const mode = $('input[name="sercan_mode"]:checked').val();
            const batch = parseInt($('#sercan_batch').val(), 10) || 5;

            ajaxCleaner('sercan_draft_cleaner_start', { mode, batch })
                .done(function (response) {
                    if (!response || !response.success || !response.data || !response.data.state) {
                        alert('İşlem başlatılamadı.');
                        return;
                    }

                    renderState(response.data.state);
                    running = true;
                    clearLoop();
                    runLoop();
                })
                .fail(function () {
                    alert('Başlatma isteği başarısız oldu.');
                });
        });

        $('#sercan-stop-cleaner').on('click', function () {
            ajaxCleaner('sercan_draft_cleaner_stop')
                .done(function (response) {
                    running = false;
                    clearLoop();

                    if (response && response.success && response.data && response.data.state) {
                        renderState(response.data.state);
                    }

                    alert(response?.data?.message || 'İşlem durduruldu.');
                })
                .fail(function () {
                    alert('Durdurma isteği başarısız oldu.');
                });
        });

        $('#sercan-reset-cleaner').on('click', function () {
            if (!confirm('Tüm ilerleme, lock ve log bilgileri sıfırlansın mı?')) {
                return;
            }

            running = false;
            clearLoop();

            ajaxCleaner('sercan_draft_cleaner_reset')
                .done(function (response) {
                    $('#sercan-cleaner-status').html('');
                    $('#sercan-cleaner-log').val('');
                    $('#sercan-progress-percent').text('%0');
                    $('#sercan-remaining-drafts').text('0');
                    $('#sercan-progress-bar').css('width', '0%');

                    $('#stat-initial-total').text('0');
                    $('#stat-processed').text('0');
                    $('#stat-deleted-products').text('0');
                    $('#stat-deleted-attachments').text('0');
                    $('#stat-skipped-attachments').text('0');
                    $('#stat-errors').text('0');

                    alert(response?.data?.message || 'Sıfırlandı.');
                })
                .fail(function () {
                    alert('Reset işlemi başarısız oldu.');
                });
        });

        $('#sercan-refresh-status').on('click', function () {
            ajaxCleaner('sercan_draft_cleaner_status')
                .done(function (response) {
                    if (response && response.success && response.data && response.data.state) {
                        renderState(response.data.state);
                    }
                });
        });

        ajaxCleaner('sercan_draft_cleaner_status')
            .done(function (response) {
                if (response && response.success && response.data && response.data.state) {
                    renderState(response.data.state);

                    const state = response.data.state;
                    if (state.running && !state.completed && !state.stop_requested) {
                        running = true;
                        clearLoop();
                        queueNext(1200);
                    }
                }
            });
    }

    function createDownloadBlob(filename, content) {
        const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }

    function renderStorageDebug(storage) {
        const container = $('#sercan-storage-debug');
        if (!container.length) return;

        if (!storage) {
            container.html('');
            return;
        }

        container.html(`
            <div class="sercan-status-item"><span>Klasör</span><strong>${esc(storage.dir || '-')}</strong></div>
            <div class="sercan-status-item"><span>Var mı</span><strong>${storage.exists ? 'Evet' : 'Hayır'}</strong></div>
            <div class="sercan-status-item"><span>Yazılabilir mi</span><strong>${storage.writable ? 'Evet' : 'Hayır'}</strong></div>
            <div class="sercan-status-item"><span>URL</span><strong>${esc(storage.url || '-')}</strong></div>
        `);
    }

    function renderGeneratedFiles(files) {
        const container = $('#sercan-generated-files-list');
        if (!container.length) return;

        container.empty();

        if (!files || !files.length) {
            container.html('<div class="sercan-empty-state">Henüz oluşturulmuş dosya yok.</div>');
            return;
        }

        files.forEach(function (file) {
            const item = $(`
                <div class="sercan-part-card">
                    <div class="sercan-part-head">
                        <div>
                            <h3>${esc(file.filename)}</h3>
                            <p>${esc(file.filesize || 0)} byte • ${esc(file.chars || 0)} karakter • ${esc(file.lines || 0)} satır</p>
                            <p>Oluşturulma: ${esc(file.created_at || '-')}</p>
                            <p>Yol: ${esc(file.path || '-')}</p>
                        </div>
                        <div class="sercan-part-actions">
                            <a class="button button-secondary" href="${esc(file.file_url || '#')}" target="_blank" rel="noopener noreferrer">Aç</a>
                        </div>
                    </div>
                </div>
            `);

            container.append(item);
        });
    }

    function refreshGeneratedFiles() {
        if ($('#sercan-generated-files-list').length === 0 || typeof SercanUrlSplitter === 'undefined') {
            return;
        }

        ajaxUrlSplitter('sercan_url_splitter_list_files')
            .done(function (response) {
                if (!response || !response.success || !response.data) {
                    $('#sercan-generated-files-list').html('<div class="sercan-empty-state">Dosya listesi alınamadı.</div>');
                    return;
                }

                renderStorageDebug(response.data.storage || null);
                renderGeneratedFiles(response.data.files || []);
            })
            .fail(function () {
                $('#sercan-generated-files-list').html('<div class="sercan-empty-state">Dosya listesi isteği başarısız oldu.</div>');
            });
    }

    function renderUrlSplitterResult(data) {
        $('#sercan-source-total-chars').text(data.total_chars || 0);
        $('#sercan-source-total-lines').text(data.total_lines || 0);
        $('#sercan-source-total-parts').text(data.total_parts || 0);
        $('#sercan-source-status').text('Hazırlandı');

        $('#sercan-source-meta').html(`
            <div class="sercan-status-item"><span>URL</span><strong>${esc(data.url || '-')}</strong></div>
            <div class="sercan-status-item"><span>HTTP Kod</span><strong>${esc(data.status_code || '-')}</strong></div>
            <div class="sercan-status-item"><span>Content Type</span><strong>${esc(data.content_type || '-')}</strong></div>
            <div class="sercan-status-item"><span>Bölme Tipi</span><strong>${esc(data.split_mode || '-')}</strong></div>
            <div class="sercan-status-item"><span>Kaydedilen Dosya</span><strong>${esc(data.saved_files_count || 0)}</strong></div>
            <div class="sercan-status-item"><span>Klasör Yazılabilir</span><strong>${data.storage?.writable ? 'Evet' : 'Hayır'}</strong></div>
        `);

        const container = $('#sercan-source-parts');
        container.empty();

        if (!data.parts || !data.parts.length) {
            container.html('<div class="sercan-empty-state">Parça oluşturulamadı.</div>');
            return;
        }

        data.parts.forEach(function (part) {
            const item = $(`
                <div class="sercan-part-card">
                    <div class="sercan-part-head">
                        <div>
                            <h3>${esc(part.filename)}</h3>
                            <p>Part ${esc(part.index)} • ${esc(part.chars)} karakter • ${esc(part.lines)} satır</p>
                        </div>
                        <div class="sercan-part-actions">
                            <button class="button sercan-copy-part">Kopyala</button>
                            <button class="button button-secondary sercan-download-part">İndir</button>
                        </div>
                    </div>
                    <textarea class="sercan-part-textarea" readonly></textarea>
                </div>
            `);

            item.find('.sercan-part-textarea').val(part.content);

            item.find('.sercan-copy-part').on('click', function () {
                navigator.clipboard.writeText(part.content).then(function () {
                    alert(part.filename + ' panoya kopyalandı.');
                }).catch(function () {
                    alert('Kopyalama başarısız oldu.');
                });
            });

            item.find('.sercan-download-part').on('click', function () {
                createDownloadBlob(part.filename, part.content);
            });

            container.append(item);
        });

        renderStorageDebug(data.storage || null);
        refreshGeneratedFiles();
    }

    if ($('#sercan-fetch-and-split').length > 0 && typeof SercanUrlSplitter !== 'undefined') {
        $('#sercan-fetch-and-split').on('click', function () {
            const url = ($('#sercan-url-source').val() || '').trim();
            const chunkSize = parseInt($('#sercan-url-split-size').val(), 10) || 50000;
            const splitMode = $('#sercan-url-split-mode').val() || 'chars';
            const lineLimit = parseInt($('#sercan-url-line-size').val(), 10) || 500;

            if (!url) {
                alert('Lütfen bir URL girin.');
                return;
            }

            $('#sercan-source-status').text('İşleniyor...');
            $('#sercan-source-parts').html('<div class="sercan-empty-state">Kaynak kod çekiliyor ve parçalanıyor...</div>');

            ajaxUrlSplitter('sercan_url_splitter_fetch', {
                url: url,
                chunk_size: chunkSize,
                split_mode: splitMode,
                line_limit: lineLimit
            }).done(function (response) {
                if (!response || !response.success || !response.data) {
                    $('#sercan-source-status').text('Hata');
                    alert(response?.data?.message || 'İşlem başarısız oldu.');
                    return;
                }

                renderUrlSplitterResult(response.data);
            }).fail(function () {
                $('#sercan-source-status').text('Hata');
                alert('İstek başarısız oldu.');
            });
        });

        $('#sercan-clear-split-results').on('click', function () {
            $('#sercan-url-source').val('');
            $('#sercan-source-total-chars').text('0');
            $('#sercan-source-total-lines').text('0');
            $('#sercan-source-total-parts').text('0');
            $('#sercan-source-status').text('Hazır');
            $('#sercan-source-meta').html('');
            $('#sercan-source-parts').html('<div class="sercan-empty-state">Henüz işlem yapılmadı.</div>');
        });

        $('#sercan-copy-all-parts').on('click', function () {
            const values = [];
            $('.sercan-part-textarea').each(function () {
                values.push($(this).val());
            });

            if (!values.length) {
                alert('Kopyalanacak parça yok.');
                return;
            }

            const allContent = values.join("\n\n====================\n\n");
            navigator.clipboard.writeText(allContent).then(function () {
                alert('Tüm parçalar panoya kopyalandı.');
            }).catch(function () {
                alert('Toplu kopyalama başarısız oldu.');
            });
        });

        $('#sercan-refresh-generated-files').on('click', function () {
            refreshGeneratedFiles();
        });

        $('#sercan-delete-all-generated-files').on('click', function () {
            if (!confirm('Sunucuda oluşturulmuş tüm dosyalar silinsin mi?')) {
                return;
            }

            ajaxUrlSplitter('sercan_url_splitter_delete_all')
                .done(function (response) {
                    if (!response || !response.success || !response.data) {
                        alert(response?.data?.message || 'Dosyalar silinemedi.');
                        return;
                    }

                    alert(response.data.message || 'Dosyalar silindi.');
                    renderStorageDebug(response.data.storage || null);
                    renderGeneratedFiles(response.data.files || []);
                })
                .fail(function () {
                    alert('Silme isteği başarısız oldu.');
                });
        });

        $('#sercan-download-all-txt').on('click', function () {
            ajaxUrlSplitter('sercan_url_splitter_download_all_txt')
                .done(function (response) {
                    if (!response || !response.success || !response.data || !response.data.files || !response.data.files.length) {
                        alert(response?.data?.message || 'İndirilecek TXT bulunamadı.');
                        return;
                    }

                    response.data.files.forEach(function (file, index) {
                        setTimeout(function () {
                            const link = document.createElement('a');
                            link.href = file.url;
                            link.download = file.filename || '';
                            document.body.appendChild(link);
                            link.click();
                            link.remove();
                        }, index * 300);
                    });
                })
                .fail(function () {
                    alert('Toplu TXT indirme isteği başarısız oldu.');
                });
        });

        $('#sercan-download-zip').on('click', function () {
            ajaxUrlSplitter('sercan_url_splitter_create_zip')
                .done(function (response) {
                    if (!response || !response.success || !response.data || !response.data.zip_url) {
                        alert(response?.data?.message || 'ZIP oluşturulamadı.');
                        return;
                    }

                    const link = document.createElement('a');
                    link.href = response.data.zip_url;
                    link.download = response.data.zip_name || 'files.zip';
                    document.body.appendChild(link);
                    link.click();
                    link.remove();
                })
                .fail(function () {
                    alert('ZIP oluşturma isteği başarısız oldu.');
                });
        });

        refreshGeneratedFiles();
    }
});