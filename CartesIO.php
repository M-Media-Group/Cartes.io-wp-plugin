<?php
/*
Plugin Name: Maps by Cartes.io - live community and private maps for anything
Plugin URI: https://cartes.io/
Description: Create free anonymous live maps on your website. You can also unlock your maps so that other people can contribute to it.
Author: M Media
Version: 1.0.2
Author URI: https://profiles.wordpress.org/Mmediagroup/
License: GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: Cartes

{Plugin Name} is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.

{Plugin Name} is distributed in the hope that it will be useful to Cartes.io clients,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with {Plugin Name}. If not, see {License URI}.
 */

if (!defined('cartes_VER')) {
    define('cartes_VER', '1.0.2');
}

require_once 'CartesMap.php';
require_once 'CartesWPList.php';

// Start up the engine
class Cartes
{
    protected $cartes_db_version = '0.0.1';

    /**
     * Static property to hold our singleton instance
     *
     */
    public static $instance = false;
    private $maps_table_class;

    /**
     * This is our constructor
     *
     * @return void
     */
    private function __construct()
    {
        register_activation_hook(__FILE__, array($this, 'cartes_install'));
        register_deactivation_hook(__FILE__, array($this, 'cartes_uninstall'));

        // back end
        add_action('plugins_loaded', array($this, 'textdomain'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        add_action('do_meta_boxes', array($this, 'create_metaboxes'), 10, 2);

        add_action('init', array($this, 'handle_admin_init'));
        add_action('admin_menu', array($this, 'cartes_create_menu'));
        add_action('wp_dashboard_setup', array($this, 'my_custom_dashboard_widgets'));
        add_action('admin_bar_menu', array($this, 'cartes_remove_toolbar_nodes'), 999);
        add_filter('admin_footer_text', array($this, 'remove_footer_admin'));
        add_filter('allow_minor_auto_core_updates', '__return_true');
        add_filter('allow_major_auto_core_updates', '__return_true');
        add_filter('auto_update_plugin', '__return_true');
        add_filter('auto_update_theme', '__return_true');
        // add_filter('wp_get_attachment_url', array($this, 'wp_get_attachment_url_callback'), 999, 2);
        add_action('plugins_loaded', array($this, 'cartes_update_db_check'));
        add_action('admin_post_cartes_create_map', array($this, 'admin_post_create_new_map'));
        add_action('admin_post_cartes_delete_map', array($this, 'admin_post_delete_map'));
        add_action('admin_post_cartes_edit_map', array($this, 'admin_post_edit_map'));

        // front end
        remove_action('welcome_panel', 'wp_welcome_panel');
        add_shortcode('cartes_map', array($this, 'map_shortcode'));

    }

    public function cartes_update_db_check()
    {
        // SP_Plugin::get_instance();
        if (get_option('cartes_db_version') !== $this->cartes_db_version) {
            $this->cartes_install();
        }
    }

    /**
     * If an instance exists, this returns it.  If not, it creates one and
     * retuns it.
     *
     * @return cartes
     */

    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self;
        }

        return self::$instance;
    }
    /**
     * load textdomain
     *
     * @return void
     */

    public function cartes_install()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cartes';
        $installed_ver = get_option("cartes_db_version");

        if ($installed_ver !== $this->cartes_db_version) {

            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE IF NOT EXISTS $table_name (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
                uuid varchar(55) DEFAULT '' NOT NULL,
                token varchar(255) DEFAULT '' NULL,
                type enum('map', 'marker') DEFAULT 'map' NOT NULL,
                PRIMARY KEY (id),
                UNIQUE U_Map (uuid, type)
            ) $charset_collate;";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);

            // Add demo map
            $maps = new CartesMap;
            if (!$maps->getLocalMap('048eebe4-8dac-46e2-a947-50b6b8062fec')) {
                $demo_map = (object) [
                    'uuid' => '048eebe4-8dac-46e2-a947-50b6b8062fec',
                    'token' => '',
                ];
                $maps->createMapLocally($demo_map);
            }

            update_option('cartes_db_version', $this->cartes_db_version);
        }
        return true;
    }

    /**
     * load textdomain
     *
     * @return void
     */

    public function cartes_uninstall()
    {

    }

    /**
     * load textdomain
     *
     * @return void
     */

    public function textdomain()
    {

    }

    /**
     * Admin styles
     *
     * @return void
     */

    public function admin_scripts()
    {
        wp_enqueue_style('custom_wp_admin_css', plugins_url('assets/css/admin-style.css', __FILE__), array(), cartes_VER, 'all');
    }

    /**
     * call metabox
     *
     * @return void
     */

    public function create_metaboxes($context)
    {

    }

    /**
     * display meta fields for notes meta
     *
     * @return void
     */

    public function custom_dashboard_help($post)
    {

    }

    /**
     * load textdomain
     *
     * @return void
     */

    public function cartes_create_menu()
    {
        //create new top-level menu
        $hook_name = add_menu_page('Maps', 'Maps',
            'publish_pages', 'cartes_main_menu', array($this, 'cartes_my_maps_page'),
            'dashicons-admin-site', 25);
        // Initialize the list table instance when the page is loaded.
        add_action("load-$hook_name", [$this, 'init_list_table']);
        add_submenu_page('cartes_main_menu', 'All Maps', 'All Maps', 'manage_options', 'cartes_main_menu');

        add_submenu_page('cartes_main_menu', 'Discover Maps',
            'Discover Maps', 'manage_options', 'cartes_discover_maps', [
                $this,
                'cartes_discover_maps_page',
            ]);
        $nonce = wp_create_nonce('cartes_create_map');

        add_submenu_page('cartes_main_menu', 'Add New',
            'Add New', 'manage_options', "/admin-post.php?action=cartes_create_map&_wpnonce=$nonce");
    }

    public function init_list_table()
    {
        $this->maps_table_class = new CartesMapsList;
    }

    public function admin_post_create_new_map($skip_nonce = false)
    {
        // do something
        if (!$skip_nonce && !wp_verify_nonce($_REQUEST['_wpnonce'], "cartes_create_map")) {
            wp_die('Uh oh - something went wrong creating a new map! Try to go to Maps, then try to do what you did before.');
        }

        $map = new CartesMap();
        try {
            $map = $map->createMap();
        } catch (Exception $e) {
            wp_die('error');
        }

        $delete_nonce = wp_create_nonce('cartes_edit_map-' . $map->uuid);

        wp_safe_redirect('/wp-admin/admin.php?page=cartes_main_menu&uuid=' . $map->uuid . '&_wpnonce=' . $delete_nonce);
    }

    public function admin_post_delete_map()
    {
        // do something
        $uuid = sanitize_text_field($_REQUEST['uuid']);
        if (!wp_verify_nonce($_REQUEST['_wpnonce'], "cartes_edit_map-{$uuid}")) {
            wp_die('Uh oh - something went wrong creating a new map! Try to go to Maps, then try to do what you did before.');
        }

        $map = new CartesMap();
        $map->getMapByUuid($uuid);
        try {
            $map->deleteMap();
        } catch (Exception $e) {
            wp_die('error');
        }

        wp_safe_redirect('/wp-admin/admin.php?page=cartes_main_menu');
    }

    public function admin_post_edit_map()
    {
        // do something
        $uuid = sanitize_text_field($_REQUEST['uuid']);
        // if (!wp_verify_nonce($_REQUEST['_wpnonce'], "cartes_edit_map-{$uuid}")) {
        //     wp_die('Uh oh - something went wrong creating a new map! Try to go to Maps, then try to do what you did before.');
        // }

        $map = new CartesMap();
        $map->getMapByUuid($uuid);
        try {
            $map->updateMap();
        } catch (Exception $e) {
            wp_die('error');
        }

        wp_safe_redirect($_SERVER['HTTP_REFERER']);
    }

    public function cartes_my_maps_page()
    {
        //create new top-level menu
        $map = new CartesMap();
        // $cartes_map = $map->getMapByUuid('a61bce50-20be-4b31-a7ee-cfaa31325813');

        // $markers = $map->getMarkers();
        if (isset($_GET['uuid'])) {
            return $this->cartes_single_map_page(sanitize_text_field($_GET['uuid']));
        }
        $nonce = wp_create_nonce('cartes_create_map');

        echo "<div class='wrap'><h1 class='wp-heading-inline'>Maps</h1>
        " . sprintf('<a class="page-title-action" href="/wp-admin/admin-post.php?action=cartes_create_map&_wpnonce=%s">Add New</a>', $nonce) . "
        <hr class='wp-header-end'>";

        ?>
                        <form method="post">
                            <?php
$this->maps_table_class->prepare_items();
        $this->maps_table_class->display();?>
        </form>
            <br class="clear">
        <?php

        echo "</div>";
        echo "Maps by <a href='https://cartes.io' target='_BLANK'>Cartes.io</a>";
    }

    public function cartes_single_map_page($uuid)
    {
        // if (!wp_verify_nonce($_REQUEST['_wpnonce'], "cartes_edit_map-{$uuid}")) {
        //     die('Uh oh - something went wrong! Try to go to Maps, then try to do what you did before.');
        // }

        //create new top-level menu
        $map_instance = new CartesMap();
        $map = $map_instance->getMapByUuid($uuid);

        // $markers = $map_instance->getMarkers();
        echo $this->display_map($map);
        echo "<div class='card'>
        <h1>" . ($map->title ?? "Untitled map") . "</h1>
        <p style='white-space: pre-wrap;'>" . $map->description . "</p>
        <small>Created " . human_time_diff(strtotime($map->created_at)) . " ago</small>
        </div>";

        if ($map_instance->getMapToken() && wp_verify_nonce($_REQUEST['_wpnonce'], "cartes_edit_map-{$uuid}")) {
            echo " <form action='/wp-admin/admin-post.php' method='post' class='card'>
            <input type='hidden' name='action' value='cartes_edit_map'>
            <input type='hidden' name='uuid' value='" . $map->uuid . "'>
       <h3>Map settings</h3>
       <p>Edit basic info about your map.</p>
       <table class='form-table'>
           <tbody>
               <tr>
                   <th scope='row'>
                       <label for='my-text-field'>Map title</label>
                   </th>
                   <td>
                       <input type='text' value='" . $map->title . "' id='title' name='title'>
                       <br>
                       <span class='description'>Use a short and clear title for best results.</span>
                   </td>
               </tr>
               <tr>
                   <th scope='row'>
                       <label for='my-text-field'>Description</label>
                   </th>
                   <td>
                       <textarea name='description' placeholder='Description'>" . $map->description . "</textarea>
                       <br>
                       <span class='description'>Be as descriptive as possible to help people understand what this map is about.</span>
                   </td>
               </tr>
           </tbody>
       </table>
       <h3>Privacy control</h3>
       <p>Control who can see and interact with your map.</p>
       <table class='form-table'>
           <tbody>
           <tr>
               <th scope='row'>Publicity</th>
               <td>
                   <fieldset>
                       <legend class='screen-reader-text'><span>Publicity settings</span></legend>
                       <label><input type='radio' " . ($map->privacy == 'public' ? 'checked' : '') . " value='public' name='privacy'> Public &mdash; This map may show up in 'Discover Maps' and search results</label>
                       <br>
                       <label><input type='radio' " . ($map->privacy == 'unlisted' ? 'checked' : '') . " value='unlisted' name='privacy'> Unlisted &mdash; Only people that know the map UUID or link will be able to see it <b>(default)</b></label>
                       <br>
                   </fieldset>
               </td>
           </tr>
           <tr>
               <th scope='row'>Lock map</th>
               <td>
                   <fieldset>
                       <legend class='screen-reader-text'><span>Lock settings</span></legend>
                       <label><input type='radio' " . ($map->users_can_create_markers == 'yes' ? 'checked' : '') . " value='yes' name='users_can_create_markers'> Unlocked &mdash; Anyone can create new markers on this map <b>(default)</b></label>
                       <br>
                       <label><input type='radio' " . ($map->users_can_create_markers == 'yes' ? '' : 'checked') . " value='no' name='users_can_create_markers'> Locked &mdash; No one can create markers on this map</label>
                       <br>
                   </fieldset>
               </td>
           </tr>
           </tbody>
       </table>
           <p class='submit'><input type='submit' value='Save Changes' class='button-primary' name='Submit'></p>
       </form>";

            echo "<div class='card'>
               <h3>Creating markers on your map</h3>
               <p>To create markers on your map, first unlock it in Map Settings, then right click anywhere on the map to add markers.</p>
               <p>When you're done, you can lock your map to prevent further changes or keep it unlocked and let other people contribute.</p>
               <hr/>
               <p>Alternatively, you can use custom integrations to post on your map.</p>
                <a class='button' target='_BLANK' href='https://zapier.com/developer/public-invite/106065/2f8aa2be5a007de74f8a1014c014c778/'>Zapier integration</a>
                <a class='button' target='_BLANK' href='https://github.com/M-Media-Group/Cartes.io/wiki/API'>Use the API</a>
               </div>";

        }

        echo "<div class='card'>
        <h3>Ways you can share this map</h3>
        <p>Share your map with your visitors to gain more visibility. There's a few ways to do this.</p>
        <p>- Use the shortcode anywhere on your website:<br/><code>[cartes_map uuid='" . $map->uuid . "']</code></p>
        <p>- Share this external link:<br/><code>https://cartes.io/maps/" . $map->uuid . "</code></p>
        <p>- Embed using an iFrame:<br/><code>&lt;iframe src='https://cartes.io/embeds/maps/" . $map->uuid . "?type=map' width='100%'' height='400' frameborder='0'></iframe></code></p>

        </div>";

        // echo "<h2>Markers</h2>";
        // foreach ($markers as $marker) {
        //     echo "<div class='card'>
        //     <h3 style='margin-bottom:0;'>" . $marker->category->name . "</h3>
        //     <p>" . $marker->description . "</p>
        //     <small>Updated: " . human_time_diff(strtotime($marker->updated_at)) . " ago</small>
        //     </div>";
        // }
        echo "Maps by <a href='https://cartes.io' target='_BLANK'>Cartes.io</a>";
        return;
    }

    public function cartes_discover_maps_page()
    {
        //create new top-level menu
        $map = new CartesMap();
        $public_maps = $map->getPublicMaps();
        $nonce = wp_create_nonce('cartes_create_map');

        function map_object($map)
        {
            return "<div class='card'>
            <h3 style='margin-bottom:0;'>" . ($map->title ?? "Untitled map") . "</h3>
            <small>" . ($map->privacy == 'public' ? "Public" : "Unlisted") . "</small>
            <p style='white-space: pre-wrap;'>" . ($map->description ?? "No description") . "</p>
            <small>" . $map->markers_count . " currently active markers | " . ($map->users_can_create_markers == 'yes' ? "Anyone can add markers" : "You can only view this map") . " | Updated: " . human_time_diff(strtotime($map->updated_at)) . " ago</small>
            <p>
                <a class='button' href='?page=cartes_main_menu&uuid=" . $map->uuid . "'>View map</a>
                <a style='margin-left:1rem;' target='_BLANK' href='https://cartes.io/maps/" . $map->uuid . "'>Open on Cartes.io</a>
            </p>
            </div>";
        }

        echo "<div class='wrap'><h1 class='wp-heading-inline'>Discover public maps</h1>
        <hr class='wp-header-end'>";

        foreach ($public_maps->data as $map) {
            echo map_object($map);
        }
        echo "</div>";
        echo "Maps by <a href='https://cartes.io' target='_BLANK'>Cartes.io</a>";
    }

    /**
     * load textdomain
     *
     * @return void
     */

    public function my_custom_dashboard_widgets()
    {

    }

    /**
     * load textdomain
     *
     * @return void
     */

    public function cartes_remove_toolbar_nodes($wp_admin_bar)
    {

    }

    /**
     * load textdomain
     *
     * @return void
     */

    public function handle_admin_init()
    {
        register_nav_menu('cartes-menu', __('Cartes.io Menu'));
    }

    /**
     * load textdomain
     *
     * @return void
     */

    public function remove_footer_admin()
    {

    }

    public function display_map($map)
    {
        if (!isset($map->uuid)) {
            return false;
        }
        return "<iframe src='https://cartes.io/embeds/maps/" . $map->uuid . "?type=map' width='100%' height='400' frameborder='0'></iframe>";
    }

    public function map_shortcode($atts)
    {
        $map_instance = new CartesMap;
        $a = shortcode_atts(array(
            'uuid' => null,
        ), $atts);
        if (!$a['uuid']) {
            wp_die('You need to specify a "uuid" attribute when using the cartes_map shortcode.');
        }
        $map = $map_instance->getMapByUuid($a['uuid']);

        return $this->display_map($map);
    }
    /// end class
}

// Instantiate our class
$cartes = cartes::getInstance();
