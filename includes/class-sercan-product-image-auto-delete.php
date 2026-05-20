<?php
if (!defined('ABSPATH')) {
    exit;
}

class Sercan_Product_Image_Auto_Delete
{
    public function __construct()
    {
        add_action('before_delete_post', [$this, 'delete_product_attachments']);
    }

    public function delete_product_attachments($post_id)
    {
        $post_id = absint($post_id);

        if (!$post_id || get_post_type($post_id) !== 'product') {
            return;
        }

        $attachment_ids = $this->get_product_attachment_ids($post_id);

        foreach ($attachment_ids as $attachment_id) {
            if ($this->is_attachment_used_by_other_products($attachment_id, $post_id)) {
                continue;
            }

            if (get_post_type($attachment_id) !== 'attachment') {
                continue;
            }

            wp_delete_attachment($attachment_id, true);
        }
    }

    private function get_product_attachment_ids($product_id)
    {
        $attachment_ids = [];
        $featured_image_id = absint(get_post_thumbnail_id($product_id));

        if ($featured_image_id > 0) {
            $attachment_ids[] = $featured_image_id;
        }

        $gallery_meta = get_post_meta($product_id, '_product_image_gallery', true);

        if (!is_string($gallery_meta) || $gallery_meta === '') {
            return array_values(array_unique($attachment_ids));
        }

        $gallery_ids = array_map('absint', array_filter(array_map('trim', explode(',', $gallery_meta))));

        foreach ($gallery_ids as $gallery_id) {
            if ($gallery_id > 0) {
                $attachment_ids[] = $gallery_id;
            }
        }

        return array_values(array_unique($attachment_ids));
    }

    private function is_attachment_used_by_other_products($attachment_id, $excluded_product_id)
    {
        global $wpdb;

        $attachment_id = absint($attachment_id);
        $excluded_product_id = absint($excluded_product_id);

        if ($attachment_id <= 0 || $excluded_product_id <= 0) {
            return true;
        }

        $thumbnail_match = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} posts ON posts.ID = pm.post_id
                WHERE posts.post_type = %s
                  AND posts.ID != %d
                  AND pm.meta_key = %s
                  AND pm.meta_value = %s
                LIMIT 1",
                'product',
                $excluded_product_id,
                '_thumbnail_id',
                (string) $attachment_id
            )
        );

        if ($thumbnail_match) {
            return true;
        }

        $gallery_pattern = '%,' . $wpdb->esc_like((string) $attachment_id) . ',%';

        $gallery_match = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} posts ON posts.ID = pm.post_id
                WHERE posts.post_type = %s
                  AND posts.ID != %d
                  AND pm.meta_key = %s
                  AND CONCAT(',', pm.meta_value, ',') LIKE %s
                LIMIT 1",
                'product',
                $excluded_product_id,
                '_product_image_gallery',
                $gallery_pattern
            )
        );

        return (bool) $gallery_match;
    }
}
