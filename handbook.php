<?php
/**
 * Plugin Name: Handbook
 * Description: Features for a handbook, complete with glossary and table of contents
 * Author: Nacin
 */

require_once dirname( __FILE__ ) . '/inc/glossary.php';
require_once dirname( __FILE__ ) . '/inc/table-of-contents.php';
require_once dirname( __FILE__ ) . '/inc/email-post-changes.php';

//WPorg_Handbook_Glossary::init();

/**
 * Initialize our handbooks
 *
 */
class WPorg_Handbook_Init {

	static function init() {

		$post_types = 'wp';

		$post_types = apply_filters( 'handbook_post_types', $post_types );

		if ( ! is_array( $post_types ) ) {
			$post_types = (array) $post_types;
		}
		foreach ( $post_types as $type ) {
			new WPorg_Handbook( $type );
			new WPorg_Handbook_TOC( $type );
		}
	}
}
add_action( 'after_setup_theme', array( 'WPorg_Handbook_Init', 'init' ) );

class WPorg_Handbook {

	public $post_type = '';

	protected $label = '';

	static function caps() {
		return array(
			'edit_handbook_pages', 'edit_others_handbook_pages',
			'edit_published_handbook_pages',
		);
	}

	static function editor_caps() {
		return array(
			'publish_handbook_pages',
			'delete_handbook_pages', 'delete_others_handbook_pages',
			'delete_published_handbook_pages', 'delete_private_handbook_pages',
			'edit_private_handbook_pages', 'read_private_handbook_pages',
		);
	}

	function __construct( $type ) {
		$this->post_type = $type . '_handbook';
		$this->label = ucwords( $type );
		add_filter( 'user_has_cap', array( $this, 'grant_handbook_caps' ) );
		add_filter( 'init', array( $this, 'register_post_type' ) );
		add_action( 'admin_page_access_denied', array( $this, 'admin_page_access_denied' ) );
		add_filter( 'post_type_link', array( $this, 'post_type_link' ), 10, 2 );
		add_filter( 'pre_get_posts', array( $this, 'pre_get_posts' ) );
		add_action( 'widgets_init', array( $this, 'handbook_sidebar' ), 11 ); // After P2
		add_action( 'wporg_email_changes_for_post_types', array( $this, 'wporg_email_changes_for_post_types' ) );
	}

	function grant_handbook_caps( $caps ) {
		if ( ! is_user_member_of_blog() )
			return $caps;
		foreach ( self::caps() as $cap ) {
			$caps[ $cap ] = true;
		}
		if ( ! empty( $caps['edit_pages'] ) ) {
			foreach ( self::editor_caps() as $cap ) {
				$caps[ $cap ] = true;
			}
		}
		return $caps;
	}

	function register_post_type() {
		register_post_type( $this->post_type, array(
			'labels' => array(
				'name' => "{$this->label} Pages",
				'singular_name' => "{$this->label} Pages",
				'menu_name' => $this->label,
			),
			'public' => true,
			'show_ui' => true,
			'capability_type' => 'handbook_page',
			'map_meta_cap' => true,
			'has_archive' => true,
			'hierarchical' => true,
			'menu_position' => 11,
			'rewrite' => true,
			'delete_with_user' => false,
			'supports' => array( 'title', 'editor', 'author', 'thumbnail', 'page-attributes', 'custom-fields', 'comments', 'revisions' ),
		) );
	}

	function admin_page_access_denied() {
		if ( ! current_user_can( 'read' ) ) {
			wp_redirect( admin_url( 'edit.php?post_type=handbook' ) );
			exit;
		}
	}

	function post_type_link( $link, $post ) {
		if ( $post->post_type === $this->post_type && $post->post_name === $this->post_type )
			return get_post_type_archive_link( $this->post_type );
		return $link;
	}

	function pre_get_posts( $query ) {
		if ( $query->is_main_query() && ! $query->is_admin && $query->is_post_type_archive( 'handbook' ) ) {
			$query->set( 'handbook', 'handbook' );
		}
	}

	function handbook_sidebar() {
		register_sidebar( array( 'id' => $this->post_type, 'name' => $this->label, 'description' => "Used on {$this->label} pages" ) );
		require_once dirname( __FILE__ ) . '/inc/widgets.php';
		register_widget( "WPorg_Handbook_Pages_Widget" );
	}

	function wporg_email_changes_for_post_types( $post_types ) {
		if ( ! in_array( $this->post_type, $post_types ) )
			$post_types[] = $this->post_type;
		return $post_types;
	}
}