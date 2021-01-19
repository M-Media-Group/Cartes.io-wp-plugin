<?php

defined('ABSPATH') or die('No script kiddies please!');

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class CartesMapsList extends WP_List_Table
{

    /** Class constructor */
    public function __construct()
    {

        parent::__construct([
            'singular' => __('Map', 'sp'), //singular name of the listed records
            'plural' => __('Maps', 'sp'), //plural name of the listed records
            'ajax' => false, //does this table support ajax?
        ]);

    }

    /**
     * Retrieve maps data from the database
     *
     * @param int $per_page
     * @param int $page_number
     *
     * @return mixed
     */
    public static function get_maps($per_page = 5, $page_number = 1)
    {

        global $wpdb;

        $sql = "SELECT * FROM {$wpdb->prefix}cartes";

        $sql .= " WHERE type = 'map'";

        if (!empty($_REQUEST['orderby'])) {
            $sql .= ' ORDER BY ' . esc_sql($_REQUEST['orderby']);
            $sql .= !empty($_REQUEST['order']) ? ' ' . esc_sql($_REQUEST['order']) : ' ASC';
        }

        $sql .= " LIMIT $per_page";
        $sql .= ' OFFSET ' . ($page_number - 1) * $per_page;

        $result = $wpdb->get_results($sql, 'ARRAY_A');

        return $result;
    }

    /**
     * Delete a customer record.
     *
     * @param int $id customer ID
     */
    public static function delete_customer($id)
    {
        global $wpdb;

        $wpdb->delete(
            "{$wpdb->prefix}cartes",
            ['ID' => $id],
            ['%d']
        );
    }

    /**
     * Returns the count of records in the database.
     *
     * @return null|string
     */
    public static function record_count()
    {
        global $wpdb;

        $sql = "SELECT COUNT(*) FROM {$wpdb->prefix}cartes";

        return $wpdb->get_var($sql);
    }

    /** Text displayed when no customer data is available */
    public function no_items()
    {
        $nonce = wp_create_nonce('cartes_create_map');
        _e("There's no maps here just yet! <a href='/wp-admin/admin-post.php?action=cartes_create_map&_wpnonce=$nonce'>Create a map</a>", 'sp');
    }

    /**
     * Render a column when no column specific method exist.
     *
     * @param array $item
     * @param string $column_name
     *
     * @return mixed
     */
    public function column_default($item, $column_name)
    {
        switch ($column_name) {
            case 'uuid':
                return $item->$column_name;
            case 'description':
                return mb_strimwidth($item->$column_name, 0, 200, "...");
            case 'title':
                return 'Untitled map';
            case 'privacy':
                return '- Map is ' . $item->privacy . '<br>- ' . ($item->users_can_create_markers == 'yes' ? 'Anyone can create markers on this map' : 'Map is locked');
            case 'categories':
                $filtered = array_map(
                    function ($key) {
                        return $key->name;
                    }, $item->categories
                );
                return implode(', ', $filtered);
            default:
                return $item->$column_name;

                // return print_r($item, true); //Show the whole array for troubleshooting purposes
        }
    }

    /**
     * Render the bulk edit checkbox
     *
     * @param array $item
     *
     * @return string
     */
    public function column_cb($item)
    {
        return sprintf(
            '<input type="checkbox" name="bulk-delete[]" value="%s" />', $item['ID']
        );
    }

    /**
     * Method for name column
     *
     * @param array $item an array of DB data
     *
     * @return string
     */

    public function column_title($item)
    {

        $delete_nonce = wp_create_nonce('cartes_edit_map-' . $item->uuid);

        $title = '<strong>' . sprintf('<a href="?page=cartes_main_menu&uuid=%s&_wpnonce=%s">' . ($item->title ?? "Untitled map") . '</a>', esc_attr($item->uuid), $delete_nonce) . '</strong><small>' . $item->uuid . '</small>';

        $actions = [
            'view' => sprintf('<a href="?page=cartes_main_menu&uuid=%s&_wpnonce=%s">View</a>', esc_attr($item->uuid), $delete_nonce),
            'edit' => sprintf('<a href="?page=cartes_main_menu&uuid=%s&action=%s&_wpnonce=%s">Edit</a>', esc_attr($item->uuid), 'edit', $delete_nonce),
        ];

        $actions['delete'] = sprintf('<a href="/wp-admin/admin-post.php?uuid=%s&action=%s&_wpnonce=%s">Delete</a>', esc_attr($item->uuid), 'cartes_delete_map', $delete_nonce);

        $actions['view_external'] = sprintf('<a target="_BLANK" href="https://cartes.io/maps/%s">Open on Cartes.io</a>', esc_attr($item->uuid));

        return $title . $this->row_actions($actions);
    }

    /**
     *  Associative array of columns
     *
     * @return array
     */
    public function get_columns()
    {
        $columns = [
            // 'cb' => '<input type="checkbox" />',
            'title' => __('Title', 'sp'),
            'description' => __('Description', 'sp'),
            'privacy' => __('Privacy', 'sp'),
            // 'users_can_create_markers' => __('Users can create markers', 'sp'),
            // 'uuid' => __('UUID', 'sp'),
            'categories' => __('Categories', 'sp'),
            'markers_count' => __('Active Markers', 'sp'),
            // 'token' => __('Token', 'sp'),
            'created_at' => __('Date', 'sp'),
        ];

        return $columns;
    }

    /**
     * Columns to make sortable.
     *
     * @return array
     */
    public function get_sortable_columns()
    {
        $sortable_columns = array(
            // 'title' => array('title', true),
            // 'id' => array('id', true),
            // 'created_at' => array('created_at', false),
        );

        return $sortable_columns;
    }

    /**
     * Returns an associative array containing the bulk action
     *
     * @return array
     */
    public function get_bulk_actions()
    {
        $actions = [
            // 'bulk-delete' => 'Delete',
        ];

        return $actions;
    }

    /**
     * Handles data query and filter, sorting, and pagination.
     */
    public function prepare_items()
    {

        $map = CartesMap::getInstance();

        $this->_column_headers = $this->get_column_info();

        /** Process bulk action */
        // $this->process_bulk_action();

        // $per_page = $this->get_items_per_page('maps_per_page', 15);
        $current_page = $this->get_pagenum();
        // $total_items = self::record_count();

        // $this->items = self::get_maps($per_page, $current_page);

        $fetched_maps = $map->getWebsiteMaps($current_page);

        $this->set_pagination_args([
            'total_items' => $fetched_maps->total, //WE have to calculate the total number of items
            'per_page' => $fetched_maps->per_page, //WE have to determine how many items to show on a page
            'total_pages' => $fetched_maps->last_page,
        ]);
        $this->items = $fetched_maps->data;

    }

    public function process_bulk_action()
    {

        //Detect when a bulk action is being triggered...
        if ('delete' === $this->current_action()) {

            // In our file that handles the request, verify the nonce.
            $nonce = esc_attr($_REQUEST['_wpnonce']);

            if (!wp_verify_nonce($nonce, 'sp_delete_customer')) {
                die('Go get a life script kiddies');
            } else {
                self::delete_customer(absint($_GET['customer']));

                // esc_url_raw() is used to prevent converting ampersand in url to "#038;"
                // add_query_arg() return the current url
                wp_redirect(esc_url_raw(add_query_arg()));
                exit;
            }

        }

        // If the delete bulk action is triggered
        if ((isset($_POST['action']) && $_POST['action'] == 'bulk-delete')
            || (isset($_POST['action2']) && $_POST['action2'] == 'bulk-delete')
        ) {

            $delete_ids = esc_sql($_POST['bulk-delete']);

            // loop over the array of record IDs and delete them
            foreach ($delete_ids as $id) {
                self::delete_customer($id);

            }

            // esc_url_raw() is used to prevent converting ampersand in url to "#038;"
            // add_query_arg() return the current url
            wp_redirect(esc_url_raw(add_query_arg()));
            exit;
        }
    }
}
