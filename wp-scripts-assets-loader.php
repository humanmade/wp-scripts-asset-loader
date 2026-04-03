<?php
/**
 * Asset Management
 *
 * Handles enqueueing of styles and scripts with granular block-level assets.
 */

class WP_Scripts_Asset_Loader {

	/**
	 * The assets handle prefix.
	 *
	 * @var string
	 */
	protected $handle;

	/**
	 * The build directory path.
	 *
	 * @var string
	 */
	protected $path;

	/**
	 * The build directory URL.
	 *
	 * @var string
	 */
	protected $url;

	/**
	 * Blocks for this asset set.
	 *
	 * @var array
	 */
	protected $blocks = [];

	/**
	 * Block paths list for this instance.
	 *
	 * @var array
	 */
	protected $block_paths = [];

	/**
	 * Class instances count.
	 *
	 * @var integer
	 */
	protected static $instances = 0;

	/**
	 * Class instance ID.
	 *
	 * @var integer
	 */
	protected $instance_id = 0;

	/**
	 * Enqueue a src directory assets and blocks.
	 *
	 * @param string $handle The asset handle prefix.
	 * @param string $path The path to the build directory.
	 * @param string $url The URL to the build directory.
	 * @return void
	 */
	public function __construct( string $handle, string $path, string $url ) {
		$this->handle = $handle;
		$this->path = untrailingslashit( $path );
		$this->url = untrailingslashit( $url );

		// Bump the class instance to distinguish asset handles.
		$this->instance_id = ++self::$instances;

		if ( did_action( 'init' ) ) {
			_doing_it_wrong( __FUNCTION__, 'new WP_Scripts_Asset_Loader() must be called before the init action, or on init priority 1.', '1.0.0' );
		}

		add_action( 'init', [ $this, 'enqueue_block_assets' ], 5 );
		add_action( 'enqueue_block_assets', [ $this, 'enqueue_global_assets' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_global_editor_assets' ] );
		add_filter( 'block_type_metadata', [ $this, 'extend_block_type_metadata' ], 10, 2 );
	}

	/**
	 * Enqueue global assets.
	 */
	public function enqueue_global_assets() {
		$asset_data = $this->get_asset_file( '/global/main.asset.php' );

		if ( is_readable( $this->path . '/global/main.css' ) ) {
			wp_enqueue_style(
				$this->handle . '-css',
				$this->url . '/global/main.css',
				$asset_data['dependencies'],
				$asset_data['version']
			);
		}

		if ( is_readable( $this->path . '/global/main.js' ) ) {
			wp_enqueue_script(
				$this->handle . '-js',
				$this->url . '/global/main.js',
				$asset_data['dependencies'],
				$asset_data['version']
			);
		}
	}

	/**
	 * Enqueue global editor only assets.
	 */
	public function enqueue_global_editor_assets() {
		$asset_data = $this->get_asset_file( '/global/editor.asset.php' );

		if ( is_readable( $this->path . '/global/editor.css' ) ) {
			wp_enqueue_style(
				$this->handle . '-css',
				$this->url . '/global/editor.css',
				$asset_data['dependencies'],
				$asset_data['version']
			);
		}

		if ( is_readable( $this->path . '/global/editor.js' ) ) {
			wp_enqueue_script(
				$this->handle . '-js',
				$this->url . '/global/editor.js',
				$asset_data['dependencies'],
				$asset_data['version'],
				[
					'in_footer' => true,
				]
			);
		}
	}

	/**
	 * Return all theme blocks and block extensions.
	 *
	 * @return array
	 */
	protected function get_blocks() : array {
		if ( ! empty( $this->blocks ) ) {
			return $this->blocks;
		}

		$blocks_dir = $this->path . '/blocks';

		// Find all block.json files recursively in the build directory.
		$block_json_pattern = $blocks_dir . '/*/*/block.json';
		$block_json_files = glob( $block_json_pattern );

		$this->blocks = array_combine(
			$block_json_files,
			array_map( function ( $block_json_file ) {
				$block_json_content = file_get_contents( $block_json_file );
				return json_decode( $block_json_content, true );
			}, $block_json_files )
		);

		return $this->blocks;
	}

	/**
	 * Enqueue granular block assets.
	 *
	 * For blocks with editorScript (custom blocks), registers them via register_block_type.
	 * For core blocks (style-only overrides), uses wp_enqueue_block_style to load CSS only when blocks are present.
	 * Automatically discovers all blocks from build directory.
	 */
	public function enqueue_block_assets() {
		$blocks_dir = $this->path . '/blocks';
		$blocks_url = $this->url . '/blocks';

		// Find all block.json files recursively in the build directory.
		$block_json_files = $this->get_blocks();

		foreach ( $block_json_files as $block_json_file => $block_config ) {
			// Read the block.json to get the block name.
			if ( ! isset( $block_config['name'] ) ) {
				continue;
			}

			$block_name = $block_config['name'];

			// Get the directory containing the block.json.
			$block_dir = dirname( $block_json_file );

			// If block has a title defined, assume it's a custom block - register it.
			if ( isset( $block_config['title'] ) ) {
				register_block_type( $block_dir );
				continue;
			}

			// Otherwise, it's a style-only override for core/third-party blocks.
			$relative_block_dir = str_replace( $blocks_dir, '', $block_dir . '/' );

			// Get the block file name (last part of the path).
			$block_slug = basename( $block_dir );

			// Look for the corresponding .asset.php file.
			$asset_file = $block_dir . '/' . $block_slug . '.asset.php';

			if ( ! file_exists( $asset_file ) ) {
				continue;
			}

			// Load asset data.
			$asset_data = include $asset_file;

			foreach ( [ 'style', 'editorStyle', 'viewStyle' ] as $style_type ) {

				if ( isset( $block_config[ $style_type ] ) ) {
					// Support multiple style files.
					$instance_sub_id = 0;

					foreach ( (array) $block_config[ $style_type ] as $style ) {
						// For non CSS files assume it's a handle and call enqueue block style directly.
						// This defers enqueueing to the render_block hook.
						if ( strpos( $style, '.css' ) === false ) {
							wp_enqueue_block_style( $block_name, [ 'handle' => $style ] );
							continue;
						}

						// Create a handle from block name.
						$handle = implode( '-', [
							$this->handle,
							str_replace( '/', '-', $block_name ),
							_wp_to_kebab_case( $style_type ),
							$this->instance_id,
							++$instance_sub_id
						] );

						$style_file = remove_block_asset_path_prefix( $style );

						// Determine the CSS file to load based on RTL.
						$css_file = $relative_block_dir . $style_file;

						// Register early so it's available for other blocks.
						wp_register_style(
							$handle,
							$blocks_url . $css_file,
							$asset_data['dependencies'] ?? [],
							$asset_data['version'] ?? null,
						);

						// Borrowed from wp_enqueue_block_style() static callback implementation.
						wp_style_add_data( $handle, 'path', $blocks_dir . $css_file );

						// Get the RTL file path.
						$rtl_file_path = $relative_block_dir . basename( $style_file, '.css' ) . '-rtl.css';

						// Add RTL stylesheet.
						if ( file_exists( $rtl_file_path ) ) {
							wp_style_add_data( $handle, 'rtl', 'replace' );

							if ( is_rtl() ) {
								wp_style_add_data( $handle, 'path', $rtl_file_path );
							}
						}

						if ( $style_type === 'style' ) {
							wp_enqueue_block_style( $block_name, [ 'handle' => $handle ] );
						}

						if ( $style_type === 'editorStyle' && is_admin() ) {
							wp_enqueue_block_style( $block_name, [ 'handle' => $handle ] );
						}

						if ( $style_type === 'viewStyle' && ! is_admin() ) {
							wp_enqueue_block_style( $block_name, [ 'handle' => $handle ] );
						}
					}
				}
			}
		}
	}

	/**
	 * Get asset file data.
	 *
	 * @param string $filepath Path to asset file.
	 * @return array Asset data.
	 */
	protected function get_asset_file( $filepath ) {
		$asset_path = $this->path . $filepath;

		if ( ! file_exists( $asset_path ) ) {
			return [
				'dependencies' => [],
				'version' => null,
			];
		}

		return include $asset_path;
	}

	/**
	 * Filters the settings determined from the block type metadata.
	 *
	 * @param array $metadata Metadata provided for registering a block type.
	 * @return array Array of determined settings for registering a block type.
	 */
	public function extend_block_type_metadata( $metadata ) {
		$blocks = $this->get_blocks();
		$block_names = wp_list_pluck( $blocks, 'name' );

		if ( empty( $this->block_paths ) ) {
			$this->block_paths = array_combine(
				$block_names,
				array_keys( $blocks )
			);
		}

		$blocks = array_combine(
			$block_names,
			$blocks
		);

		$block_type = $metadata['name'];

		// Return early if we're not doing anything with this block type,
		// or if we're registering the block rather than extending an existing one.
		if ( ! isset( $blocks[ $block_type ] ) || isset( $blocks[ $block_type ]['title'] ) ) {
			return $metadata;
		}

		// Add / extend block supports.
		if ( isset( $blocks[ $block_type ]['supports'] ) ) {
			foreach ( $blocks[ $block_type ]['supports'] as $feature => $value ) {
				if ( ! isset( $metadata['supports'][ $feature ] ) || ! is_array( $value ) ) {
					$metadata['supports'][ $feature ] = $value;
				} else {
					$metadata['supports'][ $feature ] = wp_parse_args( $value, $metadata['supports'][ $feature ] );
				}
			}
		}

		$block_path = $this->block_paths[ $block_type ];

		$instance_id = $this->instance_id;

		// Ensure our extended blocks view script handles start from a higher
		// index to avoid collisions.
		foreach ( [ 'editorScript', 'script', 'viewScript', 'viewScriptModule' ] as $script_type ) {
			if ( isset( $blocks[ $block_type ][ $script_type ] ) ) {
				$metadata[ $script_type ] = array_filter( array_values( array_unique( array_merge(
					(array) ( $metadata[ $script_type ] ?? [] ),
					array_map( function ( $script ) use ( $metadata, $block_path, $script_type, $instance_id ) {
						static $instance_sub_id = 0;
						if ( strpos( $script, '?skip_enqueue' ) !== false ) {
							return '';
						}
						$meta_for_path = $metadata;
						$meta_for_path['file'] = $block_path;
						$meta_for_path[ $script_type ] = $script;
						if ( $script_type !== 'viewScriptModule' ) {
							return register_block_script_handle( $meta_for_path, $script_type, ( 100 + $instance_id ) . ++$instance_sub_id );
						} else {
							return register_block_script_module_id( $meta_for_path, $script_type, ( 100 + $instance_id ) . ++$instance_sub_id );
						}
					}, (array) $blocks[ $block_type ][ $script_type ] )
				) ) ) );
			}
		}

		return $metadata;
	}

}
