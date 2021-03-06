<?php
/*
Plugin Name:	Debugger Plugin
Description:	Code for debugging code
Author:			Modern Tribe, Inc.
Version:		1.2
Author URI:		http://tri.be

Usage:

You can use this plugin to manually log data or to capture logging on WordPress actions. You can capture load time, memory, backrace, data dumps, urls, and server IPs.

Firstly, you can manually log things using the following function:
do_action( 'log', $message, $group, $data );

The $group allows you to selectively output logging based on groups of log messages. The $message is the string you want to see in the log. $data is an optional parameter for the data that you want to display in the log (objects, arrays, or any other sort of data really).

To render messages to the log, you must configure wp-config.php as follows:

// Run debug on only these groups. Use 'ALL' to debug everything. The group 'ACTIONS' is reserved for WordPress actions.
define( 'DEBUG_GROUPS', 'ACTIONS,default,myspecialgroup' );

// Display these outputs in the log for each log message.
define( 'DEBUG_PARAMS', 'time,timedelta,memory,memorydelta,data,backtrace,url,server' );

// WordPress actions that you wish to log.
define( 'DEBUG_ACTIONS', 'wp_head,switch_theme,wp_footer' );

// WordPress actions that you wish to log.
define( 'DEBUG_URLS', 'myurl.com' );


TODO:

* admin panel per site
* admin panel globally
* wordpress error logging
* sql query logging
* WP_Error integration
* Alerts ex: if this takes more than this much memory then log it...

*/

// Block direct requests
if ( !defined('ABSPATH') )
	die('-1');

if ( !class_exists('Debugger') ) {

	class Debugger {

		private static $instance;
		/**
		 * Create the instance of the class
		 *
		 * @static
		 * @return void
		 */
		public static function init() {
			self::$instance = self::instance();
		}

		/** Singleton */

		/**
		 * Get (and instantiate, if necessary) the instance of the class
		 * @static
		 * @return singleton instance
		 */
		public static function instance() {
			if ( !is_a(self::$instance, __CLASS__) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		const PLUGIN_DOMAIN = 'debugger';

		// Debug vars
		/*private static $groups = array('ACTIONS','default');
		private static $parameters = array('time','memory','data','backtrace','url','server');
		private static $actions = array('wp_head','switch_theme','wp_footer');*/
		private static $groups = array( 'ALL' );
		private static $parameters = array( 'time', 'timedelta', 'memory', 'memorydelta', 'data', 'backtrace', 'url', 'server' );
		private static $actions = array();
		private static $ok_urls = false;

		private $time;
		private $time_previous;
		private $memory;
		private $memory_previous;

		// Constructor
		public function __construct() {
			// Set logger to default to error_log.
			add_action( 'debugger_render_log_entry', array( $this, 'render_log' ), 10, 3 );

			// Set up vars once plugins are loaded.
			add_action( 'plugins_loaded', array( $this, 'start_the_party' ), 10 );
		}

		// Set up vars
		public function start_the_party() {

			// Check to see if wp-config has defined any of the vars
			if (defined('DEBUG_GROUPS')) {
				self::$groups = apply_filters('debugger_groups',explode(',',DEBUG_GROUPS));
			}
			if ( empty( self::$groups ) ) return;

			if (defined('DEBUG_PARAMS')) {
				self::$parameters = apply_filters('debugger_params',explode(',',DEBUG_PARAMS));
			}

			if (defined('DEBUG_ACTIONS')) {
				self::$actions = apply_filters('debugger_actions',explode(',',DEBUG_ACTIONS));
			}

			if (defined('DEBUG_URLS')) {
				self::$ok_urls = apply_filters('debugger_urls',explode(',',DEBUG_URLS));
			}
			if ( is_array(self::$ok_urls) && !in_array($_SERVER["HTTP_HOST"],self::$ok_urls) ) return;

			// Hook into all the actions in the config
			if (is_array(self::$actions) && count(self::$actions)>0) {
				foreach (self::$actions as $k => $action) {
					add_action($action,array($this,'autolog_action'),1,2);
				}
			}

			// Action for people to log messages to.
			add_action( 'log', array( $this, 'log' ), 1, 3 );

			require_once('lib/debug-bar.class.php');

			do_action( 'debugger_render_log_entry', '===== INITIALIZING DEBUGGER =====' );
		}

		private function get_time() {
			global $timestart;
			$timeend = microtime( true );
			if ( !$this->time )
				$this->time = ($timeend - $timestart) * 1000;
		}

		private function get_memory() {
			if ( !$this->memory )
				$this->memory = ceil( memory_get_usage(true) / 1024 ); // kb
		}

		// Log the actions/filters
		public function autolog_action() {
			self::log('Action: '.current_filter(),'ACTIONS');
		}

		// Log messages
		public function log($message='Log Message',$group='default',$data=null) {
			// If URLs are specified then check that the logging url matches the url specified
			if (is_array(self::$ok_urls) && !in_array($_SERVER["HTTP_HOST"],self::$ok_urls)) {
				return;
			}

			// Check to see if group reporting is set in config
			if (in_array($group,self::$groups) || in_array('ALL',self::$groups)) {

				$log_data = array();

				// Report time
				if (in_array('time',self::$parameters)) {
					$this->get_time();
					$log_data['time'] = number_format_i18n( $this->time ).' ms'; // ms
				}

				// Report delta time
				if (in_array('timedelta',self::$parameters)) {
					$this->get_time();
					if ( isset( $this->time_previous[$group] ) ) {
						$log_data['timedelta'] = number_format_i18n( $this->time - $this->time_previous[$group] ) . " ms (since last '$group')";
					}
					$this->time_previous[$group] = $this->time;
				}

				$this->time = false;

				// Report memory
				if (in_array('memory',self::$parameters)) {
					$this->get_memory();
					$log_data['memory'] = number_format_i18n( $this->memory );
				}

				// Report delta memory
				if (in_array('memorydelta',self::$parameters)) {
					$this->get_memory();
					if ( isset( $this->memory_previous[$group] ) ) {
						$log_data['memorydelta'] = number_format_i18n( $this->memory - $this->memory_previous[$group] ) . " kB (since last '$group')";
					}
					$this->memory_previous[$group] = $this->memory;
				}

				$this->memory = false;

				// Report URL
				if (in_array('url',self::$parameters)) {
					$log_data['url'] = $_SERVER["HTTP_HOST"].$_SERVER['REQUEST_URI'];
				}

				// Report Server IP
				if (in_array('server',self::$parameters)) {
					$log_data['server'] = $_SERVER['SERVER_ADDR'];
				}

				// Report Backtrace
				if (in_array('backtrace',self::$parameters)) {
					$log_data['backtrace'] = debug_backtrace();
				}

				// Report data
				if (in_array('data',self::$parameters) && isset($data)) {
					$log_data['data'] = print_r( $data, true );
				}
				do_action( 'debugger_render_log_entry', $message, $group, $log_data );
			}

		}

		public function render_log( $message='Log Message', $group='', $data=null ) {

			if ( !empty( $group ) ) $message .= " ($group)";

			if (!empty($data)) {
				if ( count( self::$parameters ) > 1 ) {
					$message .= ': ' . print_r( $data, true );
				} else {
					$message .= ': ' . array_shift( $data );
				}
			}

			error_log( $message );
		}

	}
	Debugger::init();
}
?>