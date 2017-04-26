<?php

namespace SmartcatSupport\admin;

use smartcat\core\AbstractComponent;

class Reports extends AbstractComponent {

    public function start() {
        $this->plugin->add_api_subscriber( new ReportsMenuPage(
            array(
                'type'          => 'submenu',
                'parent_menu'   => 'ucare_support',
                'menu_slug'     => 'ucare_support',
                'page_title'    => __( 'Reports', \SmartcatSupport\PLUGIN_ID ),
                'menu_title'    => __( 'Reports', \SmartcatSupport\PLUGIN_ID ),
                'capability'    => 'manage_support',
                'tabs' => array(
                    'overview' => new ReportsOverviewTab( __( 'Overview', \SmartcatSupport\PLUGIN_ID ) )
                )

            ) )
        );
    }

}