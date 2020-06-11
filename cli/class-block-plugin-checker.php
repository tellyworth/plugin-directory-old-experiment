<?php
namespace WordPressdotorg\Plugin_Directory\CLI;

use WordPressdotorg\Plugin_Directory\Readme\Parser;
use WordPressdotorg\Plugin_Directory\Tools\Filesystem;

/**
 * A class that can examine a plugin, and evaluate and return status info that would be useful for a validator or similar tool.
 *
 * Note: I've written this as one class for convenience, but as it's evolved I think it would make sense to split it in two: one for collecting data
 * (particularly the prepare*() and find*() functions), and a separate class for the checks.
 *
 * @package WordPressdotorg\Plugin_Directory\CLI
 */
class Block_Plugin_Checker {

	protected $path_to_plugin = null;
	protected $check_methods = array();
	protected $results = array();

	protected $slug = null;
	protected $readme_path = null;
	protected $readme = null;
	protected $headers = null;
	protected $blocks = null;
	protected $block_json_files = null;

	/**
	 * Constructor.
	 *
	 * @param string $slug The plugin slug, if known. Optional.
	 */
	public function __construct( $slug = null ) {
		$this->slug = $slug;
	}

	/**
	 * Check a plugin that has been unzipped or exported to a local filesystem.
	 *
	 * @param string $path_to_plugin Location where the plugin has been stored.
	 * @return array A list of status items.
	 */
	public function run_check_plugin_files( $path_to_plugin ) {
		$this->path_to_plugin = $path_to_plugin;
		$this->init();
		$this->prepare_data();
		$this->run_all_checks();
		return $this->results;
	}

	protected function init() {

		// Find all the methods on this class starting with `check_`
		$methods = get_class_methods( $this );
		foreach ( $methods as $method ) {
			if ( 0 === strpos( $method, 'check_' ) )
				$this->check_methods[] = $method;
		}
	}

	protected function prepare_data() {
		// Parse and stash the readme data
		$this->readme_path = Import::find_readme_file( $this->path_to_plugin );
		$this->readme = new Parser( $this->readme_path );

		// Parse and stash plugin headers
		$this->headers = Import::find_plugin_headers( $this->path_to_plugin );

		// Parse and stash block info
		$this->blocks = $this->find_blocks( $this->path_to_plugin );
	}

	public function run_all_checks() {
		foreach ( array_unique( $this->check_methods ) as $method ) {
			call_user_func( array( $this, $method ) );
		}
	}

	public function find_blocks( $base_dir ) {
		$block_json_files = Filesystem::list_files( $base_dir, true, '!(?:^|/)block\.json$!i' );
		if ( ! empty( $block_json_files ) ) {
			foreach ( $block_json_files as $filename ) {
				$blocks_in_file = Import::find_blocks_in_file( $filename );
				$relative_filename = str_replace( "$base_dir/", '', $filename );
				$potential_block_directories[] = dirname( $relative_filename );
				foreach ( $blocks_in_file as $block ) {
					$blocks[ $block->name ] = $block;
					$this->block_json_files[ $block->name ] = $filename;
				}
			}
		} else {
			foreach ( Filesystem::list_files( $base_dir, true, '!\.(?:php|js|jsx)$!i' ) as $filename ) {
				$blocks_in_file = Import::find_blocks_in_file( $filename );
				if ( ! empty( $blocks_in_file ) ) {
					$relative_filename = str_replace( "$base_dir/", '', $filename );
					$potential_block_directories[] = dirname( $relative_filename );
					foreach ( $blocks_in_file as $block ) {
						if ( preg_match( '!\.(?:js|jsx)$!i', $relative_filename ) && empty( $block->script ) )
							$block->script = $relative_filename;
						$blocks[ $block->name ] = $block;
					}
				}
			}
		}

		return $blocks;
	}

	public function find_block_scripts() {
		$block_scripts = array();
		foreach ( $this->blocks as $block ) {
			$scripts = Import::extract_file_paths_from_block_json( $block );
			if ( isset( $block_scripts[ $block->name ] ) ) {
				$block_scripts[ $block->name ] = array_merge( $block_scripts[ $block->name ], $scripts );
			} else {
				$block_scripts[ $block->name ] = $scripts;
			}
		}

		return $block_scripts;
	}

	/**
	 * Used by check_*() functions to record info.
	 *
	 * @param string $check_name An unambiguous name of the check. Should normally be the calling __FUNCTION__.
	 * @param string $type The type of info being recorded: 'error', 'problem', 'info', etc.
	 * @param string $message A human-readable message explaining the info.
	 * @param mixed $data Additional data related to the info, optional. Typically a string or array.
	 */
	protected function record_result( $check_name, $type, $message, $data = null ) {
		$this->results[] = (object) array(
			'check_name' => $check_name,
			'type' => $type,
			'message' => $message, 
			'data' => $data );
	}

	/**
	 * Check functions below here. Must be named `check_*()`.
	 */

	/**
	 * Readme.txt file must be present.
	 */
	function check_readme_exists() {
		if ( !file_exists( $this->path_to_plugin . '/readme.txt' ) ) {
			$this->record_result( __FUNCTION__,
				'error',
				__( 'Missing readme.txt file' )
			);
		}
	}

	/**
	 * Readme should have a license.
	 */
	function check_license() {
		if ( empty( $this->readme->license ) ) {
			$this->record_result( __FUNCTION__,
				'problem',
				__( 'Missing license in readme.txt.' )
			);
		} else {
			$this->record_result( __FUNCTION__,
				'info',
				__( 'Found a license in readme.txt.' ),
				$this->readme->license
			);
		}
	}

	/**
	 * Does the plugin have a block name that already exists in the DB?
	 * Note that this isn't a blocker if we're re-running checks on a plugin that has already been uploaded, since it will match with itself.
	 */
	function check_for_duplicate_block_name() {
		foreach ( $this->blocks as $block ) {
			if ( !trim( strval( $block->name ) ) )
				continue;

			$query_args = array(
				'post_type' => 'plugin',
				'meta_query' => array(
					array(
						'key' => 'block_name',
						'value' => $block->name,
					)
				)
			);

			$query = new \WP_Query( $query_args );
			if ( $query->found_posts > 0 ) {
				foreach ( $query->posts as $post ) {
					if ( $this->slug && $this->slug === $post->post_name )
						continue; // It's this very same plugin

					$this->record_result( __FUNCTION__, 
						'info', 
						sprintf( __( 'Block name already exists in plugin %s' ), $query->posts[0]->post_name ), 
						[ 'block_name' => $block->name, 'slug' => $post->post_name ]
					);
				}
			}
		}

	}

	/**
	 * There should be at least one block.
	 */
	function check_for_blocks() {
		if ( 0 === count( $this->blocks ) ) {
			$this->record_result( __FUNCTION__,
				'problem',
				__( 'No blocks found in plugin.' )
			);
		} else {
			$this->record_result( __FUNCTION__,
				'info',
				sprintf( __( 'Found %d blocks' ), count( $this->blocks ) ),
				array_keys( $this->blocks )
			);
		}
	}

	/**
	 * Every block should have a block.json file, ideally.
	 */
	function check_for_block_json() {
		foreach ( $this->blocks as $block_name => $block_info ) {
			if ( !empty( $this->block_json_files[ $block_name ] ) ) {
				$this->record_result( __FUNCTION__,
					'info',
					sprintf( __( 'block.json file exists for block %s' ), $block_name ),
					$this->block_json_files[ $block_name ]
				);
			} else {
				$this->record_result( __FUNCTION__,
					'problem',
					sprintf( __( 'Missing block.json file for block %s' ), $block_name ),
					$block_name
				);
			}
		}
	}

	/**
	 * Do the blocks all have available script assets, either discoverable or in the block.json file?
	 */
	function check_for_block_scripts() {
		$block_scripts = $this->find_block_scripts();

		foreach ( $this->blocks as $block_name => $block_info ) {
			if ( !empty( $block_scripts[ $block_name ] ) ) {
				$this->record_result( __FUNCTION__,
					'info',
					sprintf( __( 'Scripts found for block %s' ), $block_name ),
					$block_scripts[ $block_name ]
				);
			} else {
				$this->record_result( __FUNCTION__,
					'problem',
					sprintf( __( 'No scripts found for block %s' ), $block_name ),
					$block_name
				);
			}
		}
	}

	/**
	 * Do the script files all exist?
	 */
	function check_for_block_script_files() {
		foreach ( $this->find_block_scripts() as $block_name => $scripts ) {
			foreach ( $scripts as $script ) {
				if ( file_exists( $this->path_to_plugin . $script ) ) {
					$this->record_result( __FUNCTION__,
						'info',
						sprintf( __( 'Script file exists for block %s' ), $block_name ),
						$script
					);
				} else {
					$this->record_result( __FUNCTION__,
						'problem',
						sprintf( __( 'Missing script file for block %s' ), $block_name ),
						$script
					);
				}
			}
		}
	}
}
