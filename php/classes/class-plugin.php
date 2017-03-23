<?php
namespace WP_Stream;
use NewsCorpAU\Foundation;

class Plugin extends Foundation\Plugin_Base {
	/**
	 * Plugin version number
	 *
	 * @const string
	 */
	const VERSION = '3.0.4';

	/**
	 * WP-CLI command
	 *
	 * @const string
	 */
	const WP_CLI_COMMAND = 'stream';

	public $name = 'stream';

	/**
	 * @var Admin
	 */
	public $admin;

	/**
	 * @var Connectors
	 */
	public $connectors;

	/**
	 * @var DB
	 */
	public $db;

	/**
	 * @var Log
	 */
	public $log;

	/**
	 * @var Settings
	 */
	public $settings;

	/**
	 * URLs and Paths used by the plugin
	 *
	 * @var array
	 */
	public $locations = array();

	/**
	 * Class constructor
	 */
	public function __construct( $config = array() ) {
		$config = array_merge(
			array(
				'driver' => '\WP_Stream\DB_Driver_Sumo',
				'admin' => '\WP_Stream\Admin_Sumo',
			),
			$config
		);

		parent::__construct( $config );

		if ( ! is_admin() ) {
			return;
		}

		spl_autoload_register( array( $this, 'autoload' ) );

		// Load helper functions
		require_once $this->dir_path . '/php/includes/functions.php';

		// Load DB helper interface/class
		$driver = null;
		$driver_class = $config['driver'];
		if ( class_exists( $driver_class ) ) {
			$driver = new $driver_class( $this );
		}

		$error = false;
		if ( empty( $driver ) ) {
			$error = 'Stream: Could not load chosen DB driver.';
		} elseif ( ! $driver instanceof DB_Driver ) {
			$error = 'Stream: DB driver must implement DB Driver interface.';
		}

		if ( $error ) {
			echo '<div class="error"><p><strong>' . esc_html__( $error, 'stream' ) . '</strong></p></div>';
			return;
		}

		$this->db = new DB( $this, $driver );

		add_action( 'wp_stream_no_tables', function() {
			return true;
		} );

		// Load logger class
		$this->log = apply_filters( 'wp_stream_log_handler', new Log( $this ) );

		// Load settings and connectors after widgets_init and before the default init priority
		add_action( 'init', array( $this, 'init' ), 9 );

		// Add frontend indicator
		add_action( 'wp_head', array( $this, 'frontend_indicator' ) );

		// Load admin area classes
		if ( is_admin() || ( defined( 'WP_STREAM_DEV_DEBUG' ) && WP_STREAM_DEV_DEBUG ) ) {
			$this->admin   = new Admin( $this );
			if ( isset( $config['admin'] ) ) {
				if ( class_exists( $config['admin'] ) ) {
					$this->storage_admin = new $config['admin']( $this );
				}
			}
		}

		// Load WP-CLI command
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( self::WP_CLI_COMMAND, 'WP_Stream\CLI' );
		}
	}

	/**
	 * Autoloader for classes
	 *
	 * @param string $class
	 */
	function autoload( $class ) {
		if ( ! preg_match( '/^(?P<namespace>.+)\\\\(?P<autoload>[^\\\\]+)$/', $class, $matches ) ) {
			return;
		}

		static $reflection;

		if ( empty( $reflection ) ) {
			$reflection = new \ReflectionObject( $this );
		}

		if ( $reflection->getNamespaceName() !== $matches['namespace'] ) {
			return;
		}

		$autoload_name = $matches['autoload'];
		$autoload_path = sprintf( '%sclass-%s.php',  $this->dir_path . '/php/classes/' , strtolower( str_replace( '_', '-', $autoload_name ) ) );

		if ( is_readable( $autoload_path ) ) {
			require_once $autoload_path;
		}
	}

	/*
	 * Load Settings and Connectors
	 *
	 * @action init
	 */
	public function init() {
		$this->settings   = new Settings( $this );
		$this->connectors = new Connectors( $this );
	}

	/**
	 * Displays an HTML comment in the frontend head to indicate that Stream is activated,
	 * and which version of Stream is currently in use.
	 *
	 * @action wp_head
	 *
	 * @return string|void An HTML comment, or nothing if the value is filtered out.
	 */
	public function frontend_indicator() {
		$comment = sprintf( 'Stream WordPress user activity plugin v%s', esc_html( $this->get_version() ) ); // Localization not needed

		/**
		 * Filter allows the HTML output of the frontend indicator comment
		 * to be altered or removed, if desired.
		 *
		 * @return string  The content of the HTML comment
		 */
		$comment = apply_filters( 'wp_stream_frontend_indicator', $comment );

		if ( ! empty( $comment ) ) {
			echo sprintf( "<!-- %s -->\n", esc_html( $comment ) ); // xss ok
		}
	}

	/**
	 * Getter for the version number.
	 *
	 * @return string
	 */
	public function get_version() {
		return self::VERSION;
	}
}
