<?php
namespace WP_Stream;

class DB_Driver_Sumo implements DB_Driver {
	/**
	 * Hold Plugin class
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * Last created API job
	 * @var Search_job
	 */
	protected $search_job;

	/**
	 * Hold sumo config
	 * @var Config
	 */
	protected $config = array();

	/**
	 * Class constructor.
	 *
	 * @param Plugin $plugin The main Plugin class.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
		add_action( 'init', array( $this, 'init' ) );
	}

	/**
	 * Init Sumo config from plugin settings
	 */
	public function init() {
		foreach ( array( 'sumo_api_endpoint', 'sumo_receiver_endpoint', 'sumo_api_access_id', 'sumo_api_access_key', 'sumo_api_query' ) as $field ) {
			$this->config[ $field ] = isset( $this->plugin->settings->options[ $field ] ) ? $this->plugin->settings->options[ $field ] : false;
		}
	}

	/**
	 * Insert a record
	 *
	 * @param array $record
	 *
	 * @return int
	 */
	public function insert_record( $record ) {
		if ( ! $this->config['sumo_receiver_endpoint'] ) {
			return false;
		}

		$record['site'] = get_bloginfo( 'url' );

		$response = wp_remote_post( $this->config['sumo_receiver_endpoint'], array(
			'method'      => 'POST',
			'httpversion' => '1.1',
			'blocking'    => false,
			'body'        => wp_json_encode( $record, JSON_UNESCAPED_SLASHES ),
			)
		);

		return is_wp_error( $response ) ? 0 : 1;
	}

	/**
	 * Retrieve records
	 *
	 * @param array $args
	 *
	 * @return array
	 */
	public function get_records( $args ) {

		$auth = $this->get_auth_header();
		if ( ! $this->config['sumo_api_endpoint'] || ! $auth ) {
			return array();
		}

		$headers = $auth;
		$headers['Content-type']  = 'application/json';

		$query = $this->config['sumo_api_query'];
		$params = $this->get_search_params( $args );

		foreach ( $params as $param_name => $param_value ) {
			$query .= ' and ' . ( $param_name ? $param_name . '=' : '') . '"' . $param_value . '"';
		}

		$search_interval = $this->get_search_interval( $args );

		$search_params = array(
			'query'    => $query,
			'from'     => $search_interval['from'],
			'to'       => $search_interval['to'],
			'timeZone' => date_default_timezone_get(),
		);

		$response = wp_remote_post( $this->config['sumo_api_endpoint'], array(
			'method'      => 'POST',
			'timeout'     => 60,
			'headers'     => $headers,
			'body'        => wp_json_encode( $search_params, JSON_UNESCAPED_SLASHES ),
			)
		);

		if ( is_wp_error( $response ) || ( isset( $response['response']['code'] ) && 202 !== $response['response']['code'] ) ) {
			return;
		}

		$result = json_decode( $response['body'] );
		if ( ! $result ) {
			// TODO notify about error
			return;
		}

		if ( $result && isset( $result->id ) ) {
			$cookies = array();
			if ( isset( $response['cookies'] ) ) {
				foreach ( $response['cookies'] as $cookie ) {
					$cookies[ $cookie->name ] = $cookie->value;
				}
			}

			$this->search_job = array(
				'id' => $result->id,
				'cookies' => $cookies,
			);
		}

		return array();
	}


	/**
	 * Check job status
	 *
	 * @param array $job
	 * @return object|void
	 */
	public function check_job_status( $job ) {
		return $this->call_job_api( $job );
	}


	/**
	 * Retrieve Search Job results
	 *
	 * @param array $job
	 * @param int $offset
	 * @param int $limit
	 *
	 * @return array
	 */
	public function get_results( $job, $offset, $limit ) {
		$params = array(
			'offset' => $offset,
			'limit'  => $limit,
		);

		$result = $this->call_job_api( $job, 'messages', $params );

		$messages = array();
		if ( $result && is_array( $result->messages ) ) {
			foreach ( $result->messages as $message ) {
				if ( isset( $message->map->_raw ) && isset( $message->map->_raw ) ) {
					$decoded_message = json_decode( $message->map->_raw );
					$decoded_message->meta = (array) $decoded_message->meta;
					$messages[] = $decoded_message;
				}
			}
		}

		return $messages;
	}


	/**
	 * Return last created search job
	 * @return Search_job
	 */
	public function get_last_search_job() {
		return $this->search_job;
	}

	/**
	 * Send Sumo Search Job API request
	 *
	 * @see https://github.com/SumoLogic/sumo-api-doc/wiki/search-job-api
	 *
	 * @param array $job
	 * @param string $endpoint
	 * @param array $params
	 * @return object|void
	 */
	protected function call_job_api( $job, $endpoint = '', $params = array() ) {
		if ( ! isset( $job['id'] ) || ! isset( $job['cookies'] ) || ! is_array( $job['cookies'] ) ) {
			return;
		}

		if ( ! $this->config['sumo_api_endpoint'] ) {
			return false;
		}

		$auth = $this->get_auth_header();
		if ( ! $auth ) {
			return;
		}

		$url = trailingslashit( $this->config['sumo_api_endpoint'] ) . $job['id'];
		if ( isset( $endpoint ) ) {
			$url .= '/' . $endpoint;
		}

		if ( $params ) {
			$url .= '?' . build_query( $params );
		}

		$args = array(
			'headers' => $auth,
			'cookies' => $job['cookies'],
		);

		$response = vip_safe_wp_remote_get( $url, null, 10, 3, 10, $args );

		if ( is_wp_error( $response ) ) {
			throw new \Exception( 'API call error: ' . $response->get_error_message() );
		}

		return json_decode( $response['body'] );
	}


	/**
	 * Return auth header for Sumo API calls
	 * @return array|void
	 */
	protected function get_auth_header() {
		if ( ! $this->config['sumo_api_access_id'] || ! $this->config['sumo_api_access_key'] ) {
			return;
		}

		return array(
			'Authorization' => 'Basic ' . base64_encode( $this->config['sumo_api_access_id'] . ':' . $this->config['sumo_api_access_key'] ),
		);
	}

	/**
	 * Convert stream search filter to Sumo query params
	 *
	 * @param $args
	 * @return array
	 */
	protected function get_search_params( $args ) {
		$params = array(
			'site' => get_bloginfo( 'url' ),
		);

		if ( ! empty( $args['search'] ) ) {
			$params[''] = $args['search'];
		}

		if ( is_numeric( $args['object_id'] ) ) {
			$params['object_id'] = $args['object_id'];
		}

		if ( is_numeric( $args['user_id'] ) ) {
			$params['user_id'] = $args['user_id'];
		}

		if ( ! empty( $args['user_role'] ) ) {
			$params['user_role'] = $args['user_role'];
		}

		if ( ! empty( $args['connector'] ) ) {
			$params['connector'] = $args['connector'];
		}

		if ( ! empty( $args['context'] ) ) {
			$params['context'] = $args['context'];
		}

		if ( ! empty( $args['action'] ) ) {
			$params['action'] = $args['action'];
		}

		return $params;
	}


	/**
	 * Return Sumo formatted search interval
	 * @param $args
	 * @return array
	 */
	protected function get_search_interval( $args ) {

		if ( ! empty( $args['date_from'] ) ) {
			$from = strtotime( $args['date_from'] . ' 00:00:00' );
		}

		if ( ! empty( $args['date_to'] ) ) {
			$to = strtotime( $args['date_to'] . ' 23:59:59' );
		}

		if ( ! empty( $args['date'] ) ) {
			$from = strtotime( $args['date'] . ' 00:00:00' );
			$to   = strtotime( $args['date'] . ' 23:59:59' );
		}

		if ( empty( $from ) ) {
			$from = strtotime( '-1 month' );
		}

		if ( empty( $to ) ) {
			$to = strtotime( 'now' );
		}

		return array(
			'from' => date( 'Y-m-d\TH:i:s', $from ),
			'to'   => date( 'Y-m-d\TH:i:s', $to ),
		);
	}

	/**
	 * Returns array of existing values for requested column.
	 *
	 * @param string $column
	 *
	 * @return array
	 */
	public function get_column_values( $column ) {
		// TODO: Implement method
		return array();
	}

	/**
	 * Purge storage
	 * Not used in Sumo
	 */
	public function purge_storage() {
	}

	/**
	 * Init storage
	 * Not used in Sumo
	 */
	public function setup_storage() {
	}
}
