<?php

defined('ABSPATH') or die('No script kiddies please!');

//FUNCTIONS TO ADD BUT PROB OUTSIDE THIS CLASS SCOPE
// private function getWebsiteMaps(){}

// private function getPublicMaps(){}

// Start up the engine
class CartesMap
{

    /**
     * Static property to hold our singleton instance
     *
     */
    public static $instance = false;

    private $api_url = 'https://cartes.io/api/';
    private $map;
    private $markers;

    /**
     * This is our constructor
     *
     * @return void
     */
    public function __construct()
    {}

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
     * If an instance exists, this returns it.  If not, it creates one and
     * retuns it.
     *
     * @return cartes
     */

    public function getPublicMaps($page_num = 1)
    {
        return $this->handleRequestWithAPI("maps?orderBy=markers_count&page=$page_num");
    }

    public function getLocalMap($uuid = null)
    {
        global $wpdb;

        if (!$uuid) {
            $uuid = $this->map->uuid;
        }

        $table_name = $wpdb->prefix . 'cartes';

        $map = $wpdb->get_row(
            "SELECT uuid FROM $table_name WHERE uuid = '$uuid'"
        );

        return $map;
    }

    public function getWebsiteMaps($page_num = 1)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cartes';

        $website_maps = $wpdb->get_results(
            "SELECT uuid FROM $table_name WHERE type = 'map'"
        );

        $uuids = array();

        foreach ($website_maps as $map) {
            array_push($uuids, $map->uuid);
        }

        if (count($uuids) < 1) {
            $maps = (object) [
                'total' => 0,
                'per_page' => 1,
                'last_page' => 1,
                'data' => null,
            ];
        } else {
            $uuids_string = implode("&ids[]=", $uuids);
            $maps = $this->handleRequestWithAPI("maps?ids[]=$uuids_string&page=$page_num");
            $this->cleanLocalDBFromDeletedMaps($maps->data, $uuids);
        }
        return $maps;
    }

    public function getWebsiteMarkers()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cartes';

        $website_markers = $wpdb->get_results(
            "SELECT uuid FROM $table_name WHERE type = marker"
        );

        $uuids = array();

        foreach ($website_markers as $marker) {
            array_push($uuids, $marker->uuid);
        }
        $uuids_string = implode(",", $uuids);
        $maps = $this->handleRequestWithAPI("maps?ids[]=$uuids_string");
        return $maps;
    }

    public function getMapByUuid($map_uuid)
    {
        if ($this->map && $this->map->uuid == $map_uuid) {
            return $this->map;
        }
        $map = $this->handleRequestWithAPI('maps/' . $map_uuid);
        $this->map = $map;
        return $this->map;
    }

    public function importMapToWebsite()
    {
        // return $this->handleRequestWithAPI('maps');
    }

    public function getMapToken()
    {
        if ($this->map && isset($this->map->token)) {
            return $this->map->token;
        }

        global $wpdb;

        $table_name = $wpdb->prefix . 'cartes';
        $uuid = $this->map->uuid;

        $map = $wpdb->get_row(
            "SELECT uuid, token FROM $table_name WHERE uuid = '$uuid'"
        );

        if (isset($map->token)) {
            $this->map->token = $map->token;
            return $map->token;
        }
        return false;
    }

    public function getMarkers()
    {
        return $this->handleRequestWithAPI('maps/' . $this->map->uuid . '/markers?show_expired=true');
    }

    private function createMarker()
    {
        return $this->handleRequestWithAPI('maps/' . $this->map->uuid . '/markers', 'POST');
    }

    private function deleteMarker()
    {
        return $this->handleRequestWithAPI('maps/' . $this->map->uuid . '/markers/' . $marker_id, 'DELETE');
    }

    public function createMapLocally($map)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cartes';

        $wpdb->insert(
            $table_name,
            array(
                'uuid' => $map->uuid,
                'token' => $map->token,
                'created_at' => current_time('mysql', 1),
            )
        );
        return $map;
    }

    public function createMap()
    {
        $map = $this->handleRequestWithAPI('maps', 'POST', ['users_can_create_markers' => 'yes']);
        $this->map = $map;
        $this->createMapLocally($map);
        return $this->map;
    }

    private function deleteMapLocally($uuid = null)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cartes';

        if (!$uuid && !$this->map->uuid) {
            wp_die('You need to specify a map UUID to delete.');
        }

        $wpdb->delete(
            $table_name,
            array(
                'uuid' => $uuid ?? $this->map->uuid,
            )
        );
        return true;
    }

    public function deleteMap()
    {
        try {
            if ($this->getMapToken()) {
                $this->handleRequestWithAPI('maps/' . $this->map->uuid, 'DELETE', ['token' => $this->getMapToken()]);
            }
            $this->deleteMapLocally();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function updateMap()
    {
        $data = [
            'token' => $this->getMapToken(),
            'title' => sanitize_text_field($_REQUEST['title']),
            'description' => sanitize_textarea_field($_REQUEST['description']),
            'privacy' => sanitize_text_field($_REQUEST['privacy']),
            'users_can_create_markers' => sanitize_text_field($_REQUEST['users_can_create_markers']),
            // 'options.default_expiration_time' => 'nullable|numeric|between:1,525600',
            // 'options.limit_to_geographical_body_type' => 'nullable|in:land,water,no',
        ];
        return $this->handleRequestWithAPI('maps/' . $this->map->uuid, 'PUT', $data);
    }

    public function cleanLocalDBFromDeletedMaps($maps, $uuids, $type = 'map')
    {

        $filtered = array_map(
            function ($key) {
                return $key->uuid;
            }, $maps
        );

        foreach ($uuids as $uuid) {
            if (!in_array($uuid, $filtered)) {
                $this->deleteMapLocally($uuid);
            }
        }
        return true;
    }

    private function handleRequestWithAPI($endpoint, $request_method = 'GET', $data = null)
    {
        $server_url = $this->api_url . $endpoint;
        $response = wp_remote_request($server_url, [
            'timeout' => 45,
            'method' => $request_method,
            'body' => $data,
            'blocking' => true,
            'headers' => [
                'Accept' => 'application/json'
            ],
        ]);
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            wp_die("Something went wrong: $error_message");
            return false;
        }

        $code = $response['response']['code'];

        if ($code === 200 || $code === 201) {
            return json_decode($response['body']);
        }

        // Non admin pages dont deserve error reporting
        if (!is_admin() && !wp_is_json_request()) {
            return false;
        }

        if ($code === 404) {
            // In order to prevent wp_die from rendering the full white screen in some cases, call this if
            if (!wp_doing_ajax() && !wp_is_json_request() && !wp_is_jsonp_request() && !wp_is_xml_request()) {
                return false;
            }
            wp_die("Looks like the map you are looking for doesn't exist. Double check the UUID.");
        } elseif ($code === 429) {
            wp_die("<h1>Woah - hold on there Speedy Gonzales!</h1><p>You are making maps and markers too quickly.<br/><br/>This is an anti-spam mechanism: please wait a few minutes before creating new maps or markers and then try again. Refresh this page once in a while until this notice goes away.</p>" . '<a href="" >Refresh page</a>' . "<div style='float:right;'><a href='/wp-admin/admin.php?page=cartes_main_menu'>All maps</a> | <a href='/wp-admin/admin.php?page=cartes_discover_maps'>Discover maps</a></div>", "Too many requests", ['back_link' => true]);
            // return false;
        }
        // return new WP_Error('broke', __("I've fallen and can't get up", "cartes"));
        wp_die("[Cartes] Something went wrong, code $code");
        return false;
    }

    /// end class
}

// Instantiate our class
