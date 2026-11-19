<?php
/**
 * Plugin Name: AG Neovo Duplicate Post
 * Description: Copy posts and pages to drafts while preserving content, custom fields and language.
 * Version: 1.1.0
 * Requires at least: 6.8
 * Requires PHP: 7.4
 * Text Domain: agneovo-duplicate-post
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 */

namespace AGNeovo\DuplicatePost;

defined( 'ABSPATH' ) || exit;

final class Plugin {
    private const ACTION = 'agneovo_duplicate_post';
    private const BULK_LIMIT = 20;

    public static function boot() {
        // Defer to Yoast until the replacement has been verified and switched over.
        if ( self::legacy_active() ) { return; }
        add_action( 'init', [ __CLASS__, 'translations' ] );
        add_filter( 'post_row_actions', [ __CLASS__, 'row_actions' ], 20, 2 );
        add_filter( 'page_row_actions', [ __CLASS__, 'row_actions' ], 20, 2 );
        add_action( 'admin_action_' . self::ACTION, [ __CLASS__, 'handle' ] );
        add_action( 'post_submitbox_misc_actions', [ __CLASS__, 'submitbox' ] );
        add_action( 'admin_bar_menu', [ __CLASS__, 'admin_bar' ], 90 );
        add_action( 'admin_notices', [ __CLASS__, 'notice' ] );
        foreach ( [ 'post', 'page' ] as $type ) {
            add_filter( 'bulk_actions-edit-' . $type, [ __CLASS__, 'bulk_actions' ] );
            add_filter( 'handle_bulk_actions-edit-' . $type, [ __CLASS__, 'handle_bulk' ], 10, 3 );
        }
    }

    public static function legacy_active() {
        $active = (array) get_option( 'active_plugins', [] );
        $network = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', [] ) : [];
        return in_array( 'duplicate-post/duplicate-post.php', $active, true ) || isset( $network['duplicate-post/duplicate-post.php'] );
    }

    public static function bulk_limit() { return self::BULK_LIMIT; }

    public static function translations() {
        load_plugin_textdomain( 'agneovo-duplicate-post', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    public static function can_copy( $post ) {
        if ( ! $post ) { return false; }
        $post = get_post( $post );
        if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true )
            || in_array( $post->post_status, [ 'trash', 'auto-draft' ], true )
            || get_post_meta( $post->ID, '_dp_is_rewrite_republish_copy', true ) ) {
            return false;
        }
        $type = get_post_type_object( $post->post_type );
        return $type && current_user_can( 'copy_posts' )
            && current_user_can( 'read_post', $post->ID ) && current_user_can( 'edit_post', $post->ID )
            && current_user_can( $type->cap->create_posts );
    }

    public static function url( $post, $edit = true ) {
        return wp_nonce_url( add_query_arg( [
            'action' => self::ACTION, 'post' => $post->ID, 'open' => $edit ? 'edit' : 'list',
        ], admin_url( 'admin.php' ) ), self::ACTION . '_' . $post->ID );
    }

    public static function row_actions( $actions, $post ) {
        if ( ! self::can_copy( $post ) ) { return $actions; }
        $actions['agneovo_clone'] = '<a href="' . esc_url( self::url( $post, false ) ) . '">' . esc_html__( 'Clone', 'agneovo-duplicate-post' ) . '</a>';
        $actions['agneovo_new_draft'] = '<a href="' . esc_url( self::url( $post ) ) . '">' . esc_html__( 'New Draft', 'agneovo-duplicate-post' ) . '</a>';
        return $actions;
    }

    public static function submitbox() {
        $post = get_post();
        if ( ! self::can_copy( $post ) ) { return; }
        echo '<div class="misc-pub-section agneovo-duplicate-post"><a href="' . esc_url( self::url( $post ) ) . '">' . esc_html__( 'Copy to a new draft', 'agneovo-duplicate-post' ) . '</a></div>';
    }

    public static function admin_bar( $bar ) {
        $post = null;
        if ( is_admin() ) {
            $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
            if ( $screen && $screen->base === 'post' && $screen->action !== 'add' ) { $post = get_post(); }
        } elseif ( is_singular( [ 'post', 'page' ] ) ) {
            $post = get_queried_object();
        }
        if ( ! self::can_copy( $post ) ) { return; }
        $bar->add_node( [ 'id' => self::ACTION, 'title' => __( 'Copy to a new draft', 'agneovo-duplicate-post' ), 'href' => self::url( $post ) ] );
    }

    public static function handle() {
        $id = isset( $_GET['post'] ) && is_scalar( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
        $post = $id ? get_post( $id ) : null;
        if ( ! $post || ! self::can_copy( $post ) ) {
            wp_die( esc_html__( 'You cannot copy this item.', 'agneovo-duplicate-post' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( self::ACTION . '_' . $id );
        $new_id = self::copy( $id );
        if ( is_wp_error( $new_id ) ) {
            wp_die( esc_html__( 'The draft could not be created.', 'agneovo-duplicate-post' ), '', [ 'response' => 500 ] );
        }
        $edit = isset( $_GET['open'] ) && $_GET['open'] === 'edit';
        $target = $edit ? get_edit_post_link( $new_id, 'raw' ) : add_query_arg( [
            'post_type' => $post->post_type, 'agneovo_copied' => 1,
        ], admin_url( 'edit.php' ) );
        wp_safe_redirect( $target );
        exit;
    }

    /** Create a draft using WordPress APIs. The caller must validate request nonces. */
    public static function copy( $source_id ) {
        $source = is_numeric( $source_id ) && (int) $source_id > 0 ? get_post( (int) $source_id ) : null;
        if ( ! self::can_copy( $source ) ) {
            return new \WP_Error( 'agneovo_duplicate_forbidden', 'Copying this item is not permitted.' );
        }
        $language = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $source->ID ) : false;
        $new_id = wp_insert_post( wp_slash( [
            'post_type' => $source->post_type, 'post_status' => 'draft',
            'post_title' => $source->post_title, 'post_content' => $source->post_content,
            'post_content_filtered' => $source->post_content_filtered, 'post_excerpt' => $source->post_excerpt,
            'post_author' => get_current_user_id(), 'post_parent' => $source->post_parent,
            'menu_order' => $source->menu_order, 'comment_status' => $source->comment_status,
            'ping_status' => $source->ping_status, 'post_password' => '', 'post_name' => '',
        ] ), true );
        if ( is_wp_error( $new_id ) ) { return $new_id; }
        // Polylang filters term assignment by the destination language. Set it
        // before copying categories, otherwise the source terms may be replaced.
        if ( $language && function_exists( 'pll_set_post_language' ) ) {
            pll_set_post_language( $new_id, $language );
            if ( pll_get_post_language( $new_id ) !== $language ) { return self::rollback( $new_id ); }
        }

        foreach ( get_post_meta( $source->ID ) as $key => $values ) {
            if ( ! self::copy_meta_key( $key ) ) { continue; }
            // Reading all metadata returns raw serialized cache values. Read each
            // key through the API so arrays/objects are unserialized exactly once.
            $values = get_post_meta( $source->ID, $key, false );
            delete_post_meta( $new_id, $key );
            foreach ( $values as $value ) {
                $value = map_deep( $value, static function ( $item ) { return is_string( $item ) ? addslashes( $item ) : $item; } );
                if ( ! add_post_meta( $new_id, $key, $value ) ) { return self::rollback( $new_id ); }
            }
        }
        foreach ( get_object_taxonomies( $source->post_type ) as $taxonomy ) {
            if ( in_array( $taxonomy, [ 'language', 'post_translations' ], true ) ) { continue; }
            $terms = wp_get_object_terms( $source->ID, $taxonomy, [ 'fields' => 'ids' ] );
            if ( is_wp_error( $terms ) ) { return self::rollback( $new_id ); }
            $result = wp_set_object_terms( $new_id, array_map( 'intval', $terms ), $taxonomy );
            if ( is_wp_error( $result ) ) { return self::rollback( $new_id ); }
        }
        add_post_meta( $new_id, '_agneovo_duplicate_source', $source->ID, true );
        return $new_id;
    }

    public static function copy_meta_key( $key ) {
        return ! in_array( $key, [ '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_desired_post_slug', '_wp_trash_meta_status', '_wp_trash_meta_time', '_pingme', '_encloseme' ], true )
            && strpos( $key, '_dp_' ) !== 0 && strpos( $key, '_agneovo_duplicate_' ) !== 0 && strpos( $key, '_oembed_' ) !== 0;
    }

    private static function rollback( $new_id ) {
        // Only remove the new, unpublished draft created by this call.
        wp_delete_post( $new_id, true );
        return new \WP_Error( 'agneovo_duplicate_failed', 'The new draft could not be completed.' );
    }

    public static function bulk_actions( $actions ) {
        if ( current_user_can( 'copy_posts' ) ) { $actions[self::ACTION] = __( 'Copy to new drafts', 'agneovo-duplicate-post' ); }
        return $actions;
    }

    public static function handle_bulk( $redirect, $action, $ids ) {
        if ( $action !== self::ACTION ) { return $redirect; }
        if ( ! current_user_can( 'copy_posts' ) ) { wp_die( esc_html__( 'You cannot copy this item.', 'agneovo-duplicate-post' ), '', [ 'response' => 403 ] ); }
        check_admin_referer( 'bulk-posts' );
        $ids = array_values( array_unique( array_map( 'absint', (array) $ids ) ) );
        if ( count( $ids ) > self::BULK_LIMIT ) { wp_die( esc_html__( 'Select no more than 20 items at a time.', 'agneovo-duplicate-post' ), '', [ 'response' => 400 ] ); }
        // Validate the whole selection before creating any drafts.
        foreach ( $ids as $id ) {
            if ( ! $id || ! self::can_copy( get_post( $id ) ) ) { wp_die( esc_html__( 'You cannot copy this item.', 'agneovo-duplicate-post' ), '', [ 'response' => 403 ] ); }
        }
        $count = 0;
        foreach ( $ids as $id ) {
            $result = self::copy( $id );
            if ( is_wp_error( $result ) ) { break; }
            ++$count;
        }
        $redirect = remove_query_arg( [ 'agneovo_copied', 'agneovo_copy_failed' ], $redirect );
        return add_query_arg( [ 'agneovo_copied' => $count, 'agneovo_copy_failed' => count( $ids ) - $count ], $redirect );
    }

    public static function notice() {
        if ( ! current_user_can( 'copy_posts' ) || ! isset( $_GET['agneovo_copied'] ) || ! is_scalar( $_GET['agneovo_copied'] ) ) { return; }
        $count = min( self::BULK_LIMIT, absint( $_GET['agneovo_copied'] ) );
        if ( $count ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%d draft created.', '%d drafts created.', $count, 'agneovo-duplicate-post' ), $count ) ) . '</p></div>';
        }
        if ( ! empty( $_GET['agneovo_copy_failed'] ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Some drafts could not be created.', 'agneovo-duplicate-post' ) . '</p></div>';
        }
    }
}

require_once __DIR__ . '/includes/class-admin.php';
Admin::register();

add_action( 'plugins_loaded', [ Plugin::class, 'boot' ], 20 );
