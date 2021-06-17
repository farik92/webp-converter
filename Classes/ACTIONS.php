<?php

namespace WebpConverter;

defined( 'ABSPATH' ) ||
die( 'Constant is missing' );

/**
 * Class ACTIONS
 * @package WebpConverter
 */
abstract class ACTIONS {


	//////// constants


	const CRON_NAME = Plugin::NAME . '__cron';


	//////// static methods


	/**
	 * @return void
	 */
	public static function SetHooks() {

		$plugin = Plugin::Instance();

		//// actions

		add_action( Plugin::NAME . '__setup_settings', [ $plugin, 'setupSettings', ], 10, 1 );
		add_action( Plugin::NAME . '__content_start', [ $plugin, 'onContentStart', ], 10, 0 );
		add_action( Plugin::NAME . '__content_end', [ $plugin, 'onContentEnd', ], 10, 0 );
		add_action( 'delete_attachment', [ $plugin, 'onDeleteAttachment', ], 10, 2 );
		add_action( self::CRON_NAME, [ $plugin, 'cron', ] );

		//// filters

		add_filter( Plugin::NAME . '__is_active', function ( $isActive ) {
			return true;
		} );
		add_filter( Plugin::NAME . '__get_source', [ $plugin, 'getSource', ], 10, 2 );
		add_filter( 'wp_generate_attachment_metadata', [ $plugin, 'onGenerateAttachmentMetadata', ], 10, 3 );
		add_filter( 'cron_schedules', [ $plugin, 'cronInterval', ], 10, 1 );
		add_filter( 'the_content', [ $plugin, 'onContentOutput', ], 10, 1 );

		//// cron events

		if ( ! wp_next_scheduled( self::CRON_NAME ) ) {
			wp_schedule_event( time(), Plugin::CRON_INTERVAL, self::CRON_NAME );
		}

	}

}
