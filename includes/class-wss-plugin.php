<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WSS_Plugin {

	private static ?self $instance = null;

	private WSS_Cache           $cache;
	private WSS_Rate_Limiter    $rate_limiter;
	private WSS_Links_Indexer   $links_indexer;
	private WSS_Search_Engine   $engine;
	private WSS_Ajax_Handler    $ajax;
	private WSS_Rest_Controller $rest;
	private WSS_Admin_Page      $admin;

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->cache         = new WSS_Cache();
		$this->rate_limiter  = new WSS_Rate_Limiter( $this->cache );
		$this->links_indexer = new WSS_Links_Indexer();
		$this->engine        = new WSS_Search_Engine( $this->cache );
		$this->ajax          = new WSS_Ajax_Handler( $this->engine, $this->rate_limiter );
		$this->rest          = new WSS_Rest_Controller( $this->engine, $this->rate_limiter );
		$this->admin         = new WSS_Admin_Page();
	}

	public function init(): void {
		$this->cache->register_hooks();
		$this->links_indexer->register_hooks();
		$this->ajax->register_hooks();
		$this->rest->register_hooks();
		$this->admin->register_hooks();
	}
}