<?php
namespace WP_Stream;

use DateTime;
use DateTimeZone;
use DateInterval;
use \WP_CLI;
use \WP_Roles;

/**
 * Class Admin_Sumo
 * @package WP_Stream
 */
class Admin_Sumo {
	/**
	 * Hold Plugin class
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * Hold Sumo driver class
	 * @var Sumo_driver
	 */
	protected $sumo_driver;

	/**
	 * Number of records to load per each request
	 */
	const RESULTS_PER_LOAD = 20;

	/**
	 * Class constructor.
	 *
	 * @param Plugin $plugin The main Plugin class.
	 */
	public function __construct( $plugin ) {
		if ( is_customize_preview() ) {
			return;
		}
		$this->plugin = $plugin;
		$this->sumo_driver = $this->plugin->db->driver;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Hook into admin_footer as filter_search is called after admin_enqueue_scripts
		add_action( 'admin_footer', array( $this, 'append_scripts_data' ) );

		// Ajax callback function to check sumo job status
		add_action( 'wp_ajax_stream_sumo_job_check', array( $this, 'check_sumo_job' ) );

		// Ajax callback function to load sumo results
		add_action( 'wp_ajax_stream_sumo_load_results', array( $this, 'load_sumo_job_results' ) );

		add_action( 'wp_stream_settings_option_fields', array( $this, 'init_sumo_menu' ) );

		add_filter('wp_stream_list_table_filters', function( $filters ) {
			unset( $filters['context']['items'][''] ); //it gets selected by default. looks like a bug
			return $filters;
		});
	}

	/**
	 * Override some stream menu items
	 *
	 * @param $fields
	 * @return array
	 */
	public function init_sumo_menu( $fields ) {
		$fields['sumo'] = array(
			'title'  => esc_html__( 'Sumo', 'stream' ),
			'fields' => array(
				array(
					'name'        => 'receiver_endpoint',
					'title'       => esc_html__( 'Receiver endpoint', 'stream' ),
					'type'        => 'text',
					'class'       => 'sumo_text_option',
				),
				array(
					'name'        => 'api_endpoint',
					'title'       => esc_html__( 'API endpoint', 'stream' ),
					'type'        => 'text',
					'class'       => 'sumo_text_option',
				),
				array(
					'name'        => 'api_access_id',
					'title'       => esc_html__( 'API Access ID', 'stream' ),
					'type'        => 'text',
					'class'       => 'sumo_text_option',
					'sticky'      => 'bottom',
				),
				array(
					'name'        => 'api_access_key',
					'title'       => esc_html__( 'API Access key', 'stream' ),
					'type'        => 'text',
					'class'       => 'sumo_text_option',
					'sticky'      => 'bottom',
				),
				array(
					'name'        => 'api_query',
					'title'       => esc_html__( 'API Query', 'stream' ),
					'type'        => 'text',
					'class'       => 'sumo_text_option',
					'sticky'      => 'bottom',
				),
			),
		);

		unset( $fields['advanced'] );
		return $fields;
	}

	/**
	 * Enqueue scripts/styles for sumo admin screen
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		wp_enqueue_script( 'wp-stream-sumo-admin', $this->plugin->dir_url . 'assets/js/admin-sumo.js', array( 'jquery', 'wp-stream-admin' ), $this->plugin->get_version(), true );
		wp_enqueue_style( 'wp-stream-sumo-admin', $this->plugin->dir_url . 'assets/css/admin-sumo.css', array(), $this->plugin->get_version() );
	}

	/**
	 * Append data to sumo admin js
	 */
	public function append_scripts_data() {
		wp_localize_script(
			'wp-stream-sumo-admin',
			'wp_stream',
			array(
					'sumo_job' => base64_encode( json_encode( $this->sumo_driver->get_last_search_job() ) ),
					'sumo_nonce' => wp_create_nonce( 'stream_sumo_nonce' ),
				)
		);
	}

	/**
	 * Ajax callback function to check sumo job status
	 */
	public function check_sumo_job() {
		if ( ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		check_ajax_referer( 'stream_sumo_nonce', 'nonce' );

		$job = $this->get_job_from_request();
		if ( ! $job ) {
			return;
		}

		try {
			$job_info = $this->sumo_driver->check_job_status( $job );
		} catch ( \Exception $exception ) {
			wp_send_json_error( $exception->getMessage() );
		}

		$response = array();
		if ( $job_info ) {
			if ( isset( $job_info->state ) ) {
				$response['status'] = $job_info->state;
			} elseif ( isset( $job_info->status ) ) {
				$response['status'] = $job_info->status;
			}

			if ( isset( $job_info->messageCount ) ) {
				$response['count'] = $job_info->messageCount;
			}
		}

		wp_send_json_success( $response );
	}

	/**
	 * Ajax callback function to load sumo results
	 */
	public function load_sumo_job_results() {
		if ( ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		check_ajax_referer( 'stream_sumo_nonce', 'nonce' );

		$offset = absint( wp_stream_filter_input( INPUT_POST, 'offset' ) );
		$job = $this->get_job_from_request();
		if ( ! $job ) {
			return;
		}

		try {
			$GLOBALS['hook_suffix'] = ''; //to fix php notice in class-wp-screen.php
			$table  = new List_Table( $this->plugin );
			$table->items = $this->sumo_driver->get_results( $job, $offset, self::RESULTS_PER_LOAD );
		} catch ( \Exception $exception ) {
			wp_send_json_error( $exception->getMessage() );
		}

		ob_start();
		$table->display_rows();
		$output = ob_get_clean();

		wp_send_json_success( array(
			'count' => count( $table->items ),
			'list' => $output,
		) );
	}

	/**
	 * Get sumo job from requests and verify it
	 * @return array|bool
	 */
	protected function get_job_from_request() {
		$job = false;
		$job_encoded = wp_stream_filter_input( INPUT_POST, 'job' );

		if ( $job_encoded ) {
			$job = json_decode( base64_decode( $job_encoded ), true );
		}

		if ( ! $job || ! isset( $job['id'] ) || ! is_string( $job['id'] ) || ! isset( $job['cookies'] ) || ! is_array( $job['cookies'] ) ) {
			return false;
		} else {
			return $job;
		}
	}
}
