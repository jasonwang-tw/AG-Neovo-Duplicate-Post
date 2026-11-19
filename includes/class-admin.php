<?php
namespace AGNeovo\DuplicatePost;
defined( 'ABSPATH' ) || exit;

/** Operational information only. No copy action or settings are submitted here. */
final class Admin {
    const SLUG = 'agneovo-duplicate-post';

    public static function register() {
        // This page is also available when Yoast makes our copy actions stand by.
        add_action( 'init', [ Plugin::class, 'translations' ] );
        add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
        add_filter( 'plugin_action_links_' . plugin_basename( dirname( __DIR__ ) . '/agneovo-duplicate-post.php' ), [ __CLASS__, 'links' ] );
    }

    public static function url() {
        return admin_url( 'tools.php?page=' . self::SLUG );
    }

    public static function menu() {
        add_management_page( 'AG Neovo Duplicate Post', 'Duplicate Post', 'edit_posts', self::SLUG, [ __CLASS__, 'render' ] );
    }

    public static function links( $links ) {
        if ( current_user_can( 'edit_posts' ) ) {
            array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'agneovo-duplicate-post' ) . '</a>' );
        }
        return $links;
    }

    public static function information() {
        $hooks = has_filter( 'post_row_actions', [ Plugin::class, 'row_actions' ] ) !== false
            && has_filter( 'page_row_actions', [ Plugin::class, 'row_actions' ] ) !== false
            && has_action( 'admin_action_agneovo_duplicate_post', [ Plugin::class, 'handle' ] ) !== false
            && has_filter( 'handle_bulk_actions-edit-post', [ Plugin::class, 'handle_bulk' ] ) !== false
            && has_filter( 'handle_bulk_actions-edit-page', [ Plugin::class, 'handle_bulk' ] ) !== false;
        if ( Plugin::legacy_active() ) {
            $status = 'standby'; $title = __( 'Standby: Yoast Duplicate Post is active', 'agneovo-duplicate-post' );
            $detail = __( 'Yoast provides the copy actions. This plugin will take over after Yoast is deactivated.', 'agneovo-duplicate-post' );
        } elseif ( $hooks ) {
            $status = 'active'; $title = __( 'Copy actions are registered', 'agneovo-duplicate-post' );
            $detail = __( 'The post and page list actions and bulk handlers are available. Access is checked for each source item.', 'agneovo-duplicate-post' );
        } else {
            $status = 'inactive'; $title = __( 'Copy actions are not fully registered', 'agneovo-duplicate-post' );
            $detail = __( 'Check whether another plugin or customization removed the copy hooks.', 'agneovo-duplicate-post' );
        }
        $post = get_post_type_object( 'post' ); $page = get_post_type_object( 'page' );
        $header = get_file_data( dirname( __DIR__ ) . '/agneovo-duplicate-post.php', [ 'version' => 'Version' ] );
        return [
            'version' => $header['version'], 'limit' => Plugin::bulk_limit(),
            'status' => $status, 'title' => $title, 'detail' => $detail,
            'copy_posts' => current_user_can( 'copy_posts' ),
            'create_posts' => $post && current_user_can( $post->cap->create_posts ),
            'create_pages' => $page && current_user_can( $page->cap->create_posts ),
            'polylang' => function_exists( 'pll_get_post_language' ) && function_exists( 'pll_set_post_language' ),
        ];
    }

    private static function row( $label, $value ) {
        echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
    }

    public static function render() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'You cannot view this page.', 'agneovo-duplicate-post' ), '', [ 'response' => 403 ] );
            return;
        }
        $info = self::information();
        $sections = [
            'status' => __( 'Operation status', 'agneovo-duplicate-post' ),
            'scope' => __( 'Copy scope', 'agneovo-duplicate-post' ),
            'data' => __( 'Preserved and excluded data', 'agneovo-duplicate-post' ),
            'permissions' => __( 'Your account permissions', 'agneovo-duplicate-post' ),
            'help' => __( 'Instructions and recovery', 'agneovo-duplicate-post' ),
        ];
        ?>
        <div class="wrap" id="agneovo-duplicate-post-admin">
            <h1 id="agneovo-duplicate-top" tabindex="-1">AG Neovo Duplicate Post</h1>
            <p><?php esc_html_e( 'Read-only information. There are no settings to save, and viewing this page does not create drafts.', 'agneovo-duplicate-post' ); ?></p>
            <nav aria-label="<?php esc_attr_e( 'Page sections', 'agneovo-duplicate-post' ); ?>"><p>
                <?php $first = true; foreach ( $sections as $id => $label ) : ?>
                    <?php if ( ! $first ) { echo ' &middot; '; } $first = false; ?><a href="#agneovo-duplicate-<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </p></nav>
            <h2 id="agneovo-duplicate-status" tabindex="-1"><?php echo esc_html( $sections['status'] ); ?></h2>
            <div class="notice notice-<?php echo $info['status'] === 'active' ? 'success' : 'warning'; ?> inline"><p><strong><?php echo esc_html( $info['title'] ); ?></strong></p><p><?php echo esc_html( $info['detail'] ); ?></p></div>
            <table class="form-table" role="presentation"><tbody>
                <?php self::row( __( 'Plugin version', 'agneovo-duplicate-post' ), $info['version'] ); ?>
                <?php self::row( __( 'Multilingual support', 'agneovo-duplicate-post' ), $info['polylang'] ? __( 'Polylang detected: keep the source language without joining its translation group.', 'agneovo-duplicate-post' ) : __( 'Polylang is not available. Standard WordPress copying still works.', 'agneovo-duplicate-post' ) ); ?>
            </tbody></table>
            <h2 id="agneovo-duplicate-scope" tabindex="-1"><?php echo esc_html( $sections['scope'] ); ?></h2>
            <table class="form-table" role="presentation"><tbody>
                <?php self::row( __( 'Supported content', 'agneovo-duplicate-post' ), __( 'Posts and pages only. Every copy is a new draft.', 'agneovo-duplicate-post' ) ); ?>
                <?php self::row( __( 'Available actions', 'agneovo-duplicate-post' ), __( 'Clone returns to the list; New Draft opens the new draft for editing. Both create a draft.', 'agneovo-duplicate-post' ) ); ?>
                <?php self::row( __( 'Bulk limit', 'agneovo-duplicate-post' ), sprintf( __( 'Up to %d items per batch.', 'agneovo-duplicate-post' ), $info['limit'] ) ); ?>
                <?php self::row( __( 'Outside the scope', 'agneovo-duplicate-post' ), __( 'Products, orders, coupons, ACF field groups, and rewrite or republish operations are not included. Products and ACF groups use their own native duplicate actions.', 'agneovo-duplicate-post' ) ); ?>
            </tbody></table>
            <h2 id="agneovo-duplicate-data" tabindex="-1"><?php echo esc_html( $sections['data'] ); ?></h2>
            <table class="form-table" role="presentation"><tbody>
                <?php self::row( __( 'Content and fields', 'agneovo-duplicate-post' ), __( 'Keep title, content, excerpt, ACF values and field references, featured image ID, template, post format, parent, menu order, and comment or ping settings.', 'agneovo-duplicate-post' ) ); ?>
                <?php self::row( __( 'Categories and tags', 'agneovo-duplicate-post' ), __( 'Keep applicable taxonomy terms. Retain the source language, but do not copy its translation group.', 'agneovo-duplicate-post' ) ); ?>
                <?php self::row( __( 'Images and media', 'agneovo-duplicate-post' ), __( 'Reuse existing media IDs and files. No extra media files are created.', 'agneovo-duplicate-post' ) ); ?>
                <?php self::row( __( 'New draft defaults', 'agneovo-duplicate-post' ), __( 'Draft status, the current operator as author, a new date, and no inherited slug. The original URL is not reused.', 'agneovo-duplicate-post' ) ); ?>
                <?php self::row( __( 'Excluded information', 'agneovo-duplicate-post' ), __( 'Do not copy the password, existing comments, child pages, edit locks, old slugs, trash state, oEmbed cache, or rewrite and republish relations.', 'agneovo-duplicate-post' ) ); ?>
            </tbody></table>
            <h2 id="agneovo-duplicate-permissions" tabindex="-1"><?php echo esc_html( $sections['permissions'] ); ?></h2>
            <p><?php esc_html_e( 'These are basic capabilities for your current account. Each source must also pass read and edit permission checks; trash, auto-drafts, and pending rewrite copies are excluded.', 'agneovo-duplicate-post' ); ?></p>
            <table class="form-table" role="presentation"><tbody>
                <?php self::row( __( 'Copy capability (copy_posts)', 'agneovo-duplicate-post' ), $info['copy_posts'] ? __( 'Available', 'agneovo-duplicate-post' ) : __( 'Not available', 'agneovo-duplicate-post' ) ); ?>
                <?php self::row( __( 'Create post drafts', 'agneovo-duplicate-post' ), $info['create_posts'] ? __( 'Available', 'agneovo-duplicate-post' ) : __( 'Not available', 'agneovo-duplicate-post' ) ); ?>
                <?php self::row( __( 'Create page drafts', 'agneovo-duplicate-post' ), $info['create_pages'] ? __( 'Available', 'agneovo-duplicate-post' ) : __( 'Not available', 'agneovo-duplicate-post' ) ); ?>
            </tbody></table>
            <?php if ( ! $info['copy_posts'] ) : ?><div class="notice notice-warning inline"><p><?php esc_html_e( 'Your account lacks copy_posts. Ask an administrator to review the existing permissions; this page does not change roles or grant access.', 'agneovo-duplicate-post' ); ?></p></div><?php endif; ?>
            <h2 id="agneovo-duplicate-help" tabindex="-1"><?php echo esc_html( $sections['help'] ); ?></h2>
            <ol>
                <li><?php esc_html_e( 'Open the Posts or Pages list. Hover over the source item and choose Clone or New Draft.', 'agneovo-duplicate-post' ); ?></li>
                <li><?php esc_html_e( 'For multiple items, select them and use Copy to new drafts. The classic editor and admin toolbar also provide a copy link when permitted.', 'agneovo-duplicate-post' ); ?></li>
                <li><?php esc_html_e( 'Review the new draft, including ACF fields, terms, images, language and URL, before publishing. The source content remains unchanged.', 'agneovo-duplicate-post' ); ?></li>
                <li><?php esc_html_e( 'To return to Yoast, activate Yoast Duplicate Post first. This plugin then stands by. Keep existing drafts and source content; no data migration is needed.', 'agneovo-duplicate-post' ); ?></li>
            </ol>
            <p><a class="button" href="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>"><?php esc_html_e( 'Open Posts', 'agneovo-duplicate-post' ); ?></a>
                <?php if ( current_user_can( 'edit_pages' ) ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=page' ) ); ?>"><?php esc_html_e( 'Open Pages', 'agneovo-duplicate-post' ); ?></a><?php endif; ?>
            </p>
            <p><a href="#agneovo-duplicate-top"><?php esc_html_e( 'Back to top', 'agneovo-duplicate-post' ); ?></a></p>
        </div>
        <?php
    }
}
