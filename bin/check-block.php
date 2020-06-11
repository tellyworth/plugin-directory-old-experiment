<?php

namespace WordPressdotorg\Plugin_Directory;

use WordPressdotorg\Plugin_Directory\CLI\Block_Plugin_Checker;

// This script should only be called in a CLI environment.
if ( 'cli' != php_sapi_name() ) {
	die();
}

$opts = getopt( '', array( 'post:', 'url:', 'abspath:', 'slug:', 'all' ) );

if ( empty( $opts['url'] ) ) {
	$opts['url'] = 'https://wordpress.org/plugins/';
}
if ( empty( $opts['abspath'] ) && false !== strpos( __DIR__, 'wp-content' ) ) {
	$opts['abspath'] = substr( __DIR__, 0, strpos( __DIR__, 'wp-content' ) );
}

// Bootstrap WordPress
$_SERVER['HTTP_HOST']   = parse_url( $opts['url'], PHP_URL_HOST );
$_SERVER['REQUEST_URI'] = parse_url( $opts['url'], PHP_URL_PATH );

require rtrim( $opts['abspath'], '/' ) . '/wp-load.php';

if ( ! class_exists( '\WordPressdotorg\Plugin_Directory\Plugin_Directory' ) ) {
	fwrite( STDERR, "Error! This site doesn't have the Plugin Directory plugin enabled.\n" );
	if ( defined( 'WPORG_PLUGIN_DIRECTORY_BLOGID' ) ) {
		fwrite( STDERR, "Run the following command instead:\n" );
		fwrite( STDERR, "\tphp " . implode( ' ', $argv ) . ' --url ' . get_site_url( WPORG_PLUGIN_DIRECTORY_BLOGID, '/' ) . "\n" );
	}
	die();
}

function fetch_plugin( $slug, $stable_tag = null ) {
	printf( "%s\n", $plugin->slug );
	
	$path = uniqid( "/tmp/blockplugin" ) . '-' . $slug;

	if ( file_exists( $path ) )
		return false;

	if ( $stable_tag && 'trunk' !== $stable_tag )
		$subdir = '/tags/' . $stable_tag;
	else
		$subdir = '/trunk';

	$cmd = "svn export " . escapeshellarg( "https://plugins.svn.wordpress.org/" . $slug . $subdir ) . " " . escapeshellarg( $path );
	shell_exec( $cmd );

	return $path;
}

if ( !empty( $opts['slug'] ) ) {
	$args = array(
		'post_type' => 'plugin',
		'post_status' => 'publish',
		'posts_per_page' => 1,
		'name' => $opts['slug'],
		'tax_query' => array(
			array(
				'taxonomy' => 'plugin_section',
				'field'    => 'slug',
				'terms'    => 'block',
			),
		),
	);
} else {

	$args = array(
		'post_type' => 'plugin',
		'post_status' => 'publish',
		'nopaging' => true,
		'tax_query' => array(
			array(
				'taxonomy' => 'plugin_section',
				'field'    => 'slug',
				'terms'    => 'block',
			),
		),
	);
}
$query = new \WP_Query( $args );

$count_plugins = $count_new_plugins = $count_checked = $count_blocks = $count_block_json = 0;
#var_dump( $query );
#
while ( $query->have_posts() ) {
	++ $count_checked;
	$query->the_post();
	$plugin = get_post();

	echo "Checking $plugin->post_name\n";

	$path = fetch_plugin( $plugin->post_name, $plugin->stable_tag );

	$checker = new Block_Plugin_Checker( $plugin->post_name );
	$results = $checker->run_check_plugin_files( $path );

	foreach ( $results as $item ) {
		echo "$item->type\t$item->check_name\t$item->message\n";
		if ( $item->data ) {
			print_r( $item->data );
			echo "\n";
		}
	}

	shell_exec( 'rm -rf ' . escapeshellarg( $path ) );
}

echo "Checked: " . number_format( $count_checked) . "\n";
