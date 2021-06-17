<?php

namespace WebpConverter;

defined( 'ABSPATH' ) ||
die( 'Constant is missing' );

use WP_Post;
use WebPConvert\WebPConvert;
use Exception;

/**
 * Class Plugin
 * @package WebpConverter
 */
final class Plugin {


	//////// constants


	const NAME = 'webpconverter';
	const UPLOADS_FOLDER = self::NAME;
	const EXTENSION = 'webp';
	const MIME_TYPES = [
		'image/jpeg',
		'image/png',
	];
	const EXTENSIONS = [
		'jpeg',
		'jpg',
		'png',
	];
	const CRON_INTERVAL = 'couple_minutes';
	const CRON_INTERVAL_TIMEOUT = 60 * 2;
	const SETTINGS_OPTION = self::NAME . '__settings';


	//////// static fields


	/**
	 * @var self|null
	 */
	private static $_Instance = null;


	//////// fields


	/**
	 * @var array
	 */
	private $_settings;
	/**
	 * @var int
	 */
	private $_convertedCount;
	/**
	 * @var array
	 */
	private $_urlsToConvert;


	//////// constructor


	/**
	 * Plugin constructor.
	 */
	private function __construct() {

		$this->_settings       = [
			'cronConvertLimit' => 20,
			'convertOptions'   => [
				'png'  => [
					'encoding'      => 'auto',
					'quality'       => 85,
					'alpha-quality' => 85,
					'near-lossless' => 85,
				],
				'jpeg' => [
					'encoding'      => 'auto',
					'quality'       => 85,
					'near-lossless' => 85,
				],
			],
			'errorCallback'    => null,
			'ignore'           => [
				'imageClass' => 'webpconverter--ignore',
				'pages'      => [],
			],
			'isDisableConvert' => false, // for a dev site or another case
		];
		$this->_convertedCount = 0;
		$this->_urlsToConvert  = get_option( self::SETTINGS_OPTION, [] );

	}

	/**
	 * @return self
	 */
	public static function Instance() {

		if ( ! self::$_Instance ) {
			self::$_Instance = new self();
		}

		return self::$_Instance;
	}


	//////// static methods


	/**
	 * @param string $originUrl
	 *
	 * @return string Without a slash on the start
	 */
	private function _getRelativeTargetWebPUrlByOrigin( $originUrl ) {

		// a slash on the start can be missing

		$targetWebPUrl = ltrim( wp_make_link_relative( $originUrl ), '/' );
		$targetWebPUrl = str_replace( 'wp-content/', '', $targetWebPUrl );

		return self::UPLOADS_FOLDER . '/'
		       . $targetWebPUrl . '.'
		       . self::EXTENSION;
	}

	/**
	 * @param int $attachmentId
	 * @param array $args
	 *
	 * @return array Without a slash on the start
	 */
	private function _getFilesByAttachmentId( $attachmentId, $args = [] ) {

		$args = array_merge( [
			'sizes'      => [],
			'isOriginal' => false,
			'isRelative' => false,
		], $args );

		$sizes = $args['sizes'] ?
			$args['sizes'] :
			get_intermediate_image_sizes();

		$files        = [];
		$wpUploadInfo = wp_upload_dir();

		foreach ( $sizes as $size ) {

			$originUrl = wp_get_attachment_image_url( $attachmentId, $size );

			if ( ! $originUrl ) {
				continue;
			}

			$targetPath = '';

			if ( $args['isOriginal'] ) {

				$relativeTargetPath = ltrim( wp_make_link_relative( $originUrl ), '/' );
				$targetPath         = ! $args['isRelative'] ?
					ABSPATH . $relativeTargetPath :
					$relativeTargetPath;

			} else {

				$targetRelativePath = $this->_getRelativeTargetWebPUrlByOrigin( $originUrl );

				$targetPath = ! $args['isRelative'] ?
					$wpUploadInfo['basedir'] . '/' . $targetRelativePath :
					$targetRelativePath;

			}

			if ( in_array( $targetPath, $files, true ) ) {
				continue;
			}

			$files[] = $targetPath;

		}

		return $files;
	}

	/**
	 * @param string $message
	 * @param array $args
	 *
	 * @return void
	 */
	private function _emitError( $message, $args = [] ) {

		if ( ! is_callable( $this->_settings['errorCallback'] ) ) {
			return;
		}

		$errorInfo = [
			'message' => $message,
			'args'    => $args,
		];

		call_user_func_array( $this->_settings['errorCallback'], [ $errorInfo ] );

	}

	/**
	 * @param string $imageUrl
	 *
	 * @return void
	 */
	private function _addImageUrlToList( $imageUrl ) {

		if ( in_array( $imageUrl, $this->_urlsToConvert, true ) ) {
			return;
		}

		$this->_urlsToConvert[] = $imageUrl;

		update_option( self::SETTINGS_OPTION, $this->_urlsToConvert );

	}

	/**
	 * @param string $targetFile
	 * @param string $originFile
	 *
	 * @return void
	 */
	private function _convert( $targetFile, $originFile ) {

		try {

			$targetFolder = dirname( $targetFile );

			if ( ! is_dir( $targetFolder ) ) {
				mkdir( $targetFolder, 0777, true );
			}

			WebPConvert::convert( $originFile, $targetFile, $this->_settings['convertOptions'] );

		} catch ( Exception $exception ) {

			$this->_emitError( 'Fail convert', [
				'$targetFile'    => $targetFile,
				'$originFile'    => $originFile,
				'convertOptions' => $this->_settings['convertOptions'],
				'errorMessage'   => $exception->getMessage(),
			] );

		}

	}

	/**
	 * @return bool
	 */
	private function _isActivePage() {

		$isActivePage = true;
		$relativeUrl  = add_query_arg( null, null );

		foreach ( $this->_settings['ignore']['pages'] as $ignorePage ) {

			$regExp = '/^' . $ignorePage . '$/mi';

			if ( 1 !== preg_match( $regExp, $relativeUrl ) ) {
				continue;
			}

			$isActivePage = false;
			break;

		}

		return $isActivePage;
	}

	//// hooks

	/**
	 * @param array $metadata
	 * @param int $attachmentId
	 * @param string $context
	 *
	 * @return array
	 */
	public function onGenerateAttachmentMetadata( $metadata, $attachmentId, $context ) {

		$mimeType = get_post_mime_type( $attachmentId );

		if ( ! in_array( $mimeType, self::MIME_TYPES, true ) ) {
			return $metadata;
		}

		$originalRelativeFiles = $this->_getFilesByAttachmentId( $attachmentId, [
			'isOriginal' => true,
			'isRelative' => true,
		] );
		$wpUploadInfo          = wp_upload_dir();

		foreach ( $originalRelativeFiles as $originalRelativeFile ) {

			$targetFile   = $wpUploadInfo['basedir'] . '/' . $this->_getRelativeTargetWebPUrlByOrigin( $originalRelativeFile );
			$originalFile = ABSPATH . $originalRelativeFile;
			$this->_convert( $targetFile, $originalFile );

		}

		return $metadata;

	}

	/**
	 * @param int $postId
	 * @param WP_Post $post
	 *
	 * @return void
	 */
	public function onDeleteAttachment( $postId, $post ) {

		$targetFiles = $this->_getFilesByAttachmentId( $postId );

		foreach ( $targetFiles as $targetFile ) {

			if ( ! is_file( $targetFile ) ) {
				continue;
			}

			unlink( $targetFile );

		}

	}

	/**
	 * @param string $originUrl
	 * @param array $args
	 *
	 * @return string|false
	 */
	public function getSource( $originUrl, $args = [] ) {

		$targetLink = false;

		$args = array_merge( [
			'isAllowWrongExtension' => false, // it's using e.g. in RegExp parse
			'options'               => $this->_settings['convertOptions'],
		], $args );

		$filesExtension = explode( '.', $originUrl );
		$filesExtension = count( $filesExtension ) > 1 ?
			$filesExtension[ count( $filesExtension ) - 1 ] :
			'';

		if ( ! in_array( $filesExtension, self::EXTENSIONS, true ) ) {

			if ( ! $args['isAllowWrongExtension'] ) {
				$this->_emitError( 'Requested url is wrong', [
					'$originUrl' => $originUrl,
					'$args'      => $args,
				] );
			}

			return $targetLink;
		}

		$wpUploadInfo       = wp_upload_dir();
		$targetRelativePath = $this->_getRelativeTargetWebPUrlByOrigin( $originUrl );

		$targetSource = $wpUploadInfo['baseurl'] . '/' . $targetRelativePath;
		$targetFile   = $wpUploadInfo['basedir'] . '/' . $targetRelativePath;

		if ( is_file( $targetFile ) ) {
			$targetLink = $targetSource;
		} else if ( ! $this->_settings['isDisableConvert'] ) {
			$this->_addImageUrlToList( $originUrl );
		}

		return $targetLink;
	}

	/**
	 * @param array $settings
	 *
	 * @return void
	 */
	public function setupSettings( $settings ) {
		$this->_settings = array_replace_recursive( $this->_settings, $settings );
	}

	/**
	 * @param array $schedules
	 *
	 * @return array
	 */
	public function cronInterval( $schedules ) {

		$schedules[ self::CRON_INTERVAL ] = array(
			'interval' => self::CRON_INTERVAL_TIMEOUT,
			'display'  => esc_html__( 'Every couple minutes' ),
		);

		return $schedules;
	}

	/**
	 * @return void
	 */
	public function cron() {

		if ( ! $this->_urlsToConvert ||
		     $this->_settings['isDisableConvert'] ) {
			return;
		}

		$length = count( $this->_urlsToConvert ) > $this->_settings['cronConvertLimit'] ?
			$this->_settings['cronConvertLimit'] :
			count( $this->_urlsToConvert );

		$urlsToConvert = array_slice( $this->_urlsToConvert, 0, $length );

		$this->_urlsToConvert = count( $this->_urlsToConvert ) > $length ?
			array_slice( $this->_urlsToConvert, $length ) :
			[];

		update_option( self::SETTINGS_OPTION, $this->_urlsToConvert );

		$wpUploadInfo = wp_upload_dir();

		foreach ( $urlsToConvert as $originUrl ) {

			$originFile         = ABSPATH . ltrim( wp_make_link_relative( $originUrl ), '/' );
			$targetRelativePath = $this->_getRelativeTargetWebPUrlByOrigin( $originUrl );
			$targetFile         = $wpUploadInfo['basedir'] . '/' . $targetRelativePath;

			$this->_convert( $targetFile, $originFile );

		}

	}

	/**
	 * @param string $originContent
	 *
	 * @return string
	 */
	public function onContentOutput( $originContent ) {

		$imageRegExp   = '/<img[^\>]*>/mi';
		$srcRegExp     = '/[\s]+src[\s]*\=[\s]*[\'"]{1}([^\'"]*)[\'"]{1}/mi';
		$classesRegExp = '/[\s]+class[\s]*\=[\s]*[\'"]{1}([^\'"]*)[\'"]{1}/mi';
		$classRegExp   = '/[a-zA-Z0-9\-]+/mi';

		$lazyRegEx = '/loading[\s]*\=[\s]*[\'"]lazy[\'"]/mi'; // e.g. can break sliders
		/*$newImage = ! preg_match( $lazyRegEx, $originImage ) ?
						str_replace( '<img ', '<img loading="lazy" ', $originImage ) :
						$originImage;*/

		preg_match_all( $imageRegExp, $originContent, $originImages, PREG_SET_ORDER );
		$contentParts = preg_split( $imageRegExp, $originContent );

		$content = count( $originImages ) > 0 ?
			'' :
			$originContent;
		$index   = - 1;

		foreach ( $originImages as $originImage ) {

			$index ++;

			//// get origin url

			$originImage = $originImage[0];

			preg_match_all( $srcRegExp, $originImage, $originUrl, PREG_SET_ORDER );

			// image source is missing

			if ( ! isset( $originUrl[0][1] ) ) {
				$content .= $contentParts[ $index ] . $originImage;
				continue;
			}

			$originUrl = $originUrl[0][1];

			//// check for classes

			preg_match_all( $classesRegExp, $originImage, $originClasses, PREG_SET_ORDER );

			$originClasses = isset( $originClasses[0][1] ) ?
				$originClasses[0][1] :
				[];

			if ( $originClasses ) {

				preg_match_all( $classRegExp, $originClasses, $originClasses );
				$originClasses = isset( $originClasses[0] ) ?
					$originClasses[0] :
					[];

			}

			if ( in_array( $this->_settings['ignore']['imageClass'], $originClasses, true ) ) {
				$content .= $contentParts[ $index ] . $originImage;
				continue;
			}

			//// get webp url & replace

			$webPUrl = $this->getSource( $originUrl, [
				'isAllowWrongExtension' => true, // prevent logging about the wrong extension
			] );

			if ( ! $webPUrl ) {
				$content .= $contentParts[ $index ] . $originImage;
				continue;
			}

			$newTag  = "<picture><source srcset='{$webPUrl} 1w' type='image/webp'/>{$originImage}</picture>";
			$content .= $contentParts[ $index ] . $newTag;

		}

		// optional : the last part

		$lastIndex = $index + 1;

		if ( $lastIndex &&
		     isset( $contentParts[ $lastIndex ] ) ) {
			$content .= $contentParts[ $lastIndex ];
		}


		return $content;
	}

	/**
	 * @return void
	 */
	public function onContentStart() {
		ob_start();
	}

	/**
	 * @return void
	 */
	public function onContentEnd() {

		$content = ob_get_clean();

		$content = $this->_isActivePage() ?
			$this->onContentOutput( $content ) :
			$content;

		echo $content;

	}

}
