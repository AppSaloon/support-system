<?php

namespace SmartcatSupport;

use smartcat\core\AbstractPlugin;
use smartcat\core\HookSubscriber;
use smartcat\mail\Mailer;
use SmartcatSupport\component\CommentComponent;
use SmartcatSupport\component\ProductComponent;
use SmartcatSupport\component\RegistrationComponent;
use SmartcatSupport\component\TemplateComponent;
use SmartcatSupport\component\TicketComponent;
use SmartcatSupport\component\TicketCptComponent;
use SmartcatSupport\component\TicketTableComponent;
use SmartcatSupport\descriptor\Option;
use SmartcatSupport\util\UserUtils;

class Plugin extends AbstractPlugin implements HookSubscriber {

    public function start() {
        $this->add_api_subscriber( $this );
        $this->add_api_subscriber( include $this->dir . 'config/admin_settings.php' );

        $this->config_dir = $this->dir . '/config/';
        $this->template_dir = $this->dir . '/templates/';

        $this->woo_active = class_exists( 'WooCommerce' );
        $this->edd_active = class_exists( 'Easy_Digital_Downloads' );

        // Notify subscribers if there is a version upgrade
        $version = get_option( Option::PLUGIN_VERSION, 0 );

        if( $this->version > $version ) {
            do_action( $this->id . '_upgrade', $version, $this->version );
            update_option( Option::PLUGIN_VERSION, $this->version );
        }

        Mailer::init( $this );

        include_once $this->dir . '/lib/tgm/tgmpa.php';
    }

    public function activate() {
        $roles = array(
            array( add_role( 'support_admin', __( 'Support Admin', PLUGIN_ID ) ), true ),
            array( add_role( 'support_agent', __( 'Support Agent', PLUGIN_ID ) ), true ),
            array( add_role( 'support_user', __( 'Support User', PLUGIN_ID ) ), false ),
            array( get_role( 'administrator' ), true )
        );

        foreach( $roles as $role ) {
            if( $role[0] instanceof \WP_Role ) {
                UserUtils::add_caps( $role[0], $role[1] );
            }
        }

        $this->create_email_templates();
        $this->setup_template_page();
    }

    public function deactivate() {
        UserUtils::remove_caps( get_role( 'administrator' ), true );

        remove_role( 'support_admin' );
        remove_role( 'support_agent' );
        remove_role( 'support_user' );

        Mailer::cleanup();

        do_action( $this->id . '_cleanup' );
    }

    public function admin_enqueue() {
        wp_enqueue_media();
        wp_enqueue_script( 'wp_media_uploader',
            $this->url . 'assets/lib/wp_media_uploader.js', array( 'jquery' ), $this->version );

        wp_register_script( 'support-admin-js',
            $this->url . 'assets/admin/admin.js', array( 'jquery' ), $this->version );

        wp_localize_script( 'support-admin-js', 'SupportSystem', array( 'ajaxURL' => admin_url( 'admin-ajax.php' ) ) );
        wp_enqueue_script( 'support-admin-js' );

        wp_enqueue_style( 'support-admin-icons',
            $this->url . '/assets/icons.css', null, $this->version );

        wp_enqueue_style( 'support-admin-css',
            $this->url . '/assets/admin/admin.css', null, $this->version );
    }

    public function register_dependencies() {
        $plugins = array(
            array(
                'name'     => 'WP SMTP',
                'slug'     => 'wp-smtp',
                'required' => false
            )
        );

        $config = array(
            'id'           => 'smartcat_support_required_plugins',
            'default_path' => '',
            'menu'         => 'tgmpa-install-plugins',
            'parent_slug'  => 'plugins.php',
            'capability'   => 'manage_options',
            'has_notices'  => true,
            'dismissable'  => true,
            'dismiss_msg'  => '',
            'is_automatic' => false,
            'message'      => '',
            'strings'      => array(
                'notice_can_install_required' => _n_noop(
                    'Smartcat Support requires the following plugin: %1$s.',
                    'Smartcat Support requires the following plugins: %1$s.',
                    PLUGIN_ID
                ),
                'notice_can_install_recommended' => _n_noop(
                    'Smartcat Support recommends the following plugin: %1$s.',
                    'Smartcat Support recommends the following plugins: %1$s.',
                    PLUGIN_ID
                ),
            )
        );

        tgmpa( $plugins, $config );
    }

    public function subscribed_hooks() {
        return array(
            'admin_enqueue_scripts' => array( 'admin_enqueue' ),
            'tgmpa_register' => array( 'register_dependencies' ),
            'mailer_consumers' => array( 'mailer_checkin' ),
            'mailer_text_domain' => array( 'mailer_text_domain' ),
            'template_include' => array( 'swap_template' ),
            'pre_update_option_' . Option::RESTORE_TEMPLATE => array( 'restore_template' )
        );
    }

    public function mailer_checkin( $consumers ) {
        return $consumers[] = $this->id;
    }

    public function mailer_text_domain( $text_domain ) {
        return PLUGIN_ID;
    }

    public function components() {
        $components = array(
            TicketCptComponent::class,
            TicketComponent::class,
            TicketTableComponent::class,
            CommentComponent::class
        );

        if( $this->edd_active || $this->woo_active ) {
            $components[] = ProductComponent::class;
        }

        if( get_option( Option::ALLOW_SIGNUPS ) == 'on' ) {
            $components[] = RegistrationComponent::class;
        }

        return $components;
    }

    public function swap_template( $template ) {
        if( is_page( get_option( Option::TEMPLATE_PAGE_ID ) ) ) {
            $template = $this->template_dir . '/app.php';
        }

        return $template;
    }

    public function restore_template( $val ) {
        if( $val == 'on' ) {
            $this->setup_template_page();
        }

        return '';
    }

    private function create_email_templates() {
        if( empty( get_post( get_option( Option::WELCOME_EMAIL_TEMPLATE ) ) ) ) {
            $id = wp_insert_post(
                array(
                    'post_type'     => 'email_template',
                    'post_status'   => 'publish',
                    'post_title'    => __( 'Welcome to Support', PLUGIN_ID ),
                    'post_content'  => file_get_contents( $this->dir . '/emails/welcome.md' )
                )
            );

            if( !empty( $id ) ) {
                update_option( Option::WELCOME_EMAIL_TEMPLATE, $id );
            }
        }

        if( empty( get_post( get_option( Option::CLOSED_EMAIL_TEMPLATE ) ) ) ) {
            $id = wp_insert_post(
                array(
                    'post_type'     => 'email_template',
                    'post_status'   => 'publish',
                    'post_title'    => __( 'Your ticket has been closed', PLUGIN_ID ),
                    'post_content'  => file_get_contents( $this->dir . '/emails/ticket_closed.md' )
                )
            );

            if( !empty( $id ) ) {
                update_option( Option::CLOSED_EMAIL_TEMPLATE, $id );
            }
        }
    }

    private function setup_template_page() {
        $post_id = null;
        $post = get_post( get_option( Option::TEMPLATE_PAGE_ID ) ) ;

        if( empty( $post ) ) {
            $post_id = wp_insert_post(
                array(
                    'post_type' =>  'page',
                    'post_status' => 'publish',
                    'post_title' => __( 'Support', PLUGIN_ID )
                )
            );
        } else if( $post->post_status == 'trash' ) {
            wp_untrash_post( $post->ID );

            $post_id = $post->ID;
        } else {
            $post_id = $post->ID;
        }

        if( !empty( $post_id ) ) {
            update_option( Option::TEMPLATE_PAGE_ID, $post_id );
        }
    }
}