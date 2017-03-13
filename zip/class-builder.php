<?php
namespace WordPressdotorg\Plugin_Directory\Zip;
use WordPressdotorg\Plugin_Directory\Tools\SVN;
use Exception;

/**
 * Generates a ZIP file for a Plugin.
 *
 * @package WordPressdotorg\Plugin_Directory\Zip
 */
class Builder {

	const TMP_DIR = '/tmp/plugin-zip-builder';
	const SVN_URL = 'http://plugins.svn.wordpress.org';
	const ZIP_SVN_URL = PLUGIN_ZIP_SVN_URL;

	protected $zip_file = '';
	protected $tmp_build_dir  = '';
	protected $tmp_dir = '';

	protected $slug    = '';
	protected $version = '';
	protected $context = '';

	/**
	 * Generate a ZIP for a provided Plugin versions.
	 *
	 * @param string $slug     The plugin slug.
	 * @param array  $versions The versions of the plugin to build ZIPs for.
	 * @param string $context  The context of this Builder instance (commit #, etc)
	 */
	public function build( $slug, $versions, $context = '' ) {
		// Bail when in an unconfigured environment.
		if ( ! defined( 'PLUGIN_ZIP_SVN_URL' ) ) {
			return false;
		}

		$this->slug     = $slug;
		$this->versions = $versions;
		$this->context  = $context;

		// General TMP directory
		if ( ! is_dir( self::TMP_DIR ) ) {
			mkdir( self::TMP_DIR, 0777, true );
			chmod( self::TMP_DIR, 0777 );
		}

		// Temp Directory for this instance of the Builder class.
		$this->tmp_dir = $this->generate_temporary_directory( self::TMP_DIR, $slug );

		// Create a checkout of the ZIP SVN
		$res_checkout = SVN::checkout(
			self::ZIP_SVN_URL,
			$this->tmp_dir,
			array(
				'depth' => 'empty',
				'username' => PLUGIN_ZIP_SVN_USER,
				'password' => PLUGIN_ZIP_SVN_PASS,
			)
		);

		if ( $res_checkout['result'] ) {

			// Ensure the plugins folder exists within svn
			$plugin_folder = "{$this->tmp_dir}/{$this->slug}/";
			$res = SVN::up(
				$plugin_folder,
				array(
					'depth' => 'empty'
				)
			);
			if ( ! is_dir( $plugin_folder ) ) {
				mkdir( $plugin_folder, 0777, true );
				$res = SVN::add( $plugin_folder );
			}
			if ( ! $res['result'] ) {
				throw new Exception( __METHOD__ . ": Failed to create {$plugin_folder}." );
			}
		} else {
			throw new Exception( __METHOD__ . ": Failed to create checkout of {$svn_url}." );
		}

		// Build the requested ZIPs
		foreach ( $versions as $version ) {
			$this->version = $version;

			if ( 'trunk' == $version ) {
				$this->zip_file = "{$this->tmp_dir}/{$this->slug}/{$this->slug}.zip";
			} else {
				$this->zip_file = "{$this->tmp_dir}/{$this->slug}/{$this->slug}.{$version}.zip";
			}

			// Pull the ZIP file down we're going to modify, which may not already exist.
			SVN::up( $this->zip_file );

			try {

				$this->tmp_build_dir  = $this->zip_file . '-files';
				mkdir( $this->tmp_build_dir, 0777, true );

				$this->export_plugin();
				$this->fix_directory_dates();			
				$this->generate_zip();
				$this->cleanup_plugin_tmp();

			} catch( Exception $e ) {
				// In event of error, skip this file this time.
				$this->cleanup_plugin_tmp();

				// Perform an SVN up to revert any changes made.
				SVN::up( $this->zip_file );
				continue;
			}

			// Add the ZIP file to SVN - This is only really needed for new files which don't exist in SVN.
			SVN::add( $this->zip_file );			
		}

		$res = SVN::commit(
			$this->tmp_dir,
			$this->context ? $this->context : "Updated ZIPs for {$this->slug}.",
			array(
				'username' => PLUGIN_ZIP_SVN_USER,
				'password' => PLUGIN_ZIP_SVN_PASS,
			)
		);

		$this->invalidate_zip_caches( $versions );

		$this->cleanup();

		if ( ! $res['result'] ) {
			if ( $res['errors'] ) {
				throw new Exception( __METHOD__ . ': Failed to commit the new ZIPs: ' . $res['errors'][0]['error_message'] );
			} else {
				throw new Exception( __METHOD__ . ': Commit failed without error, maybe there were no modified files?' );
			}
		}

		return true;
	}

	/**
	 * Generates a temporary unique directory in a given directory
	 *
	 * Performs a similar job to `tempnam()` with an added suffix and doesn't
	 * cut off the $prefix at 60 characters.
	 * As with `tempnam()` the caller is responsible for removing the temorarily file.
	 *
	 * Note: `strlen( $prefix . $suffix )` shouldn't exceed 238 characters.
	 *
	 * @param string $dir The directory to create the file in.
	 * @param string $prefix The file prefix.
	 * @param string $suffix The file suffix, optional.
	 *
	 * @return string Path of unique temporary directory.
	 */
	protected function generate_temporary_directory( $dir, $prefix, $suffix = '' ) {
		$i = 0;
		do {
			$rand = uniqid();
			$filename = "{$dir}/{$prefix}-{$rand}{$i}{$suffix}";
		} while ( false === ($fp = @fopen( $filename, 'x' ) ) && $i++ < 50 );

		if ( $i >= 50 ) {
			throw new Exception( __METHOD__ . ': Could not find unique filename.' );
		}

		fclose( $fp );

		// Convert file to directory.
		unlink( $filename );
		if ( ! mkdir( $filename, 0777, true ) ) {
			throw new Exception( __METHOD__ . ': Could not convert temporary filename to directory.' );
		}
		chmod( $filename, 0777 );

		return $filename;
	}

	/**
	 * Creates an Export of the plugin and redies it for ZIP creation by removing invalid data.
	 */
	protected function export_plugin() {
		if ( 'trunk' == $this->version ) {
			$svn_url = self::SVN_URL . "/{$this->slug}/trunk/";
		} else {
			$svn_url = self::SVN_URL . "/{$this->slug}/tags/{$this->version}/";
		}
		$build_dir = "{$this->tmp_build_dir}/{$this->slug}/";

		$svn_params = array();
		// BudyPress is a special sister project, they have svn:externals.
		if ( 'buddypress' != $this->slug ) {
			$svn_params[] = 'ignore-externals';
		}

		$res = SVN::export( $svn_url, $build_dir, $svn_params );
		// Handle tags which we store as 0.blah but are in /tags/.blah
		if ( ! $res['result'] && '0.' == substr( $this->version, 0, 2 ) ) {
			$_version = substr( $this->version, 1 );
			$svn_url = self::SVN_URL . "/{$this->slug}/tags/{$_version}/";
			$res = SVN::export( $svn_url, $build_dir, $svn_params );
		}
		if ( ! $res['result'] ) {
			throw new Exception( __METHOD__ . ': ' . $res['errors'][0]['error_message'], 404 );
		}

		// Verify that the specified plugin zip will contain files.
		if ( ! array_diff( scandir( $this->tmp_build_dir ), array( '.', '..' ) ) ) {
			throw new Exception( ___METHOD__ . ': No files exist in the plugin directory', 404 );
		}

		// Cleanup any symlinks that shouldn't be there
		$this->exec( sprintf(
			'find %s -type l -print0 | xargs -r0 rm',
			escapeshellarg( $build_dir )
		) );

		return true;
	}

	/**
	 * Corrects the directory dates to match the latest modified file in the plugin export.
	 *
	 * When svn exports a directory, the file entries will reflect their last modified date
	 * however directories are created with their modified date set to the current date.
	 *
	 * This causes issues for ZIPs being built as we can't guarantee that two zips built
	 * from the same source files at different times will have the same checksums.
	 */
	protected function fix_directory_dates() {
		// Find all files, output their modified dates, sort reverse numerically, grab the timestamp from the first entry
		$latest_file_modified_timestamp = $this->exec( sprintf(
			"find %s -type f -printf '%%T@\n' | sort -nr | head -c 10",
			escapeshellarg( $this->tmp_build_dir )
		) );
		if ( ! $latest_file_modified_timestamp ) {
			throw new Exception( __METHOD__ . ': Unable to locate the latest modified files timestamp.', 503 );
		}

		$this->exec( sprintf(
			'find %s -type d -exec touch -m -t %s {} \;',
			escapeshellarg( $this->tmp_build_dir ),
			escapeshellarg( date( 'ymdHi.s', $latest_file_modified_timestamp ) )
		) );
	}

	/**
	 * Generates the actual ZIP file we've painstakingly created the files for.
	 */
	protected function generate_zip() {
		// If we're building an existing zip, remove the existing file first.
		if ( file_exists( $this->zip_file ) ) {
			unlink( $this->zip_file );
		}
		$this->exec( sprintf(
			'cd %s && find %s -print0 | sort -z | xargs -0 zip -Xu %s 2>&1',
			escapeshellarg( $this->tmp_build_dir ),
			escapeshellarg( $this->slug ),
			escapeshellarg( $this->zip_file )
		), $zip_build_output, $return_value );

		if ( $return_value ) {
			throw new Exception( __METHOD__ . ': ZIP generation failed, return code: ' . $return_value, 503 );
		}
	}


	/**
	 * Purge ZIP caches after ZIP building.
	 *
	 * @param array $versions The list of plugin versions of modified zips.
	 * @return bool
	 */
	public function invalidate_zip_caches( $versions ) {
		// TODO: Implement PURGE 
		return true;
		if ( ! defined( 'PLUGIN_ZIP_X_ACCEL_REDIRECT_LOCATION' ) ) {
			return true;
		}

		foreach ( $versions as $version ) {
			if ( 'trunk' == $version ) {
				$zip = "{$this->slug}/{$this->slug}.zip";
			} else {
				$zip = "{$this->slug}/{$this->slug}.{$version}.zip";
			}

			foreach ( $plugins_downloads_load_balancer /* TODO */ as $lb ) {
				$url = 'http://' . $lb . PLUGIN_ZIP_X_ACCEL_REDIRECT_LOCATION . $zip;
				wp_remote_request(
					$url,
					array(
						'method' => 'PURGE',
					)
				);
			}
		}
	}

	/**
	 * Cleans up any temporary directories created by the ZIP Builder.
	 */
	protected function cleanup() {
		if ( $this->tmp_dir ) {
			$this->exec( sprintf( 'rm -rf %s', escapeshellarg( $this->tmp_dir ) ) );
		}
	}

	/**
	 * Cleans up any temporary directories created by the ZIP builder for a specific build.
	 */
	protected function cleanup_plugin_tmp() {
		if ( $this->tmp_build_dir ) {
			$this->exec( sprintf( 'rm -rf %s', escapeshellarg( $this->tmp_build_dir ) ) );
		}
	}

	/**
	 * Executes a command with 'proper' locale/language settings
	 * so that utf8 strings are handled correctly.
	 *
	 * WordPress.org uses the en_US.UTF-8 locale.
	 */
	protected function exec( $command, &$output = null, &$return_val = null ) {
		return exec( 'export LC_CTYPE="en_US.UTF-8" LANG="en_US.UTF-8"; ' . $command, $output, $return_val );
	}

}

