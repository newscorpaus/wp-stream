<?php
namespace WP_Stream;

class Connector_Posts extends Connector {
	/**
	 * Connector slug
	 *
	 * @var string
	 */
	public $name = 'posts';

	/**
	 * Actions registered for this connector
	 *
	 * @var array
	 */
	public $actions = array(
		'transition_post_status',
		'deleted_post',
	);

	public function __construct() {
		add_action( 'post_updated', array( $this, 'callback_post_updated' ), 10, 3 );
	}

	/**
	 * Return translated connector label
	 *
	 * @return string Translated connector label
	 */
	public function get_label() {
		return esc_html__( 'Posts', 'stream' );
	}

	/**
	 * Return translated action labels
	 *
	 * @return array Action label translations
	 */
	public function get_action_labels() {
		return array(
			'updated'   => esc_html__( 'Updated', 'stream' ),
			'created'   => esc_html__( 'Created', 'stream' ),
			'trashed'   => esc_html__( 'Trashed', 'stream' ),
			'untrashed' => esc_html__( 'Restored', 'stream' ),
			'deleted'   => esc_html__( 'Deleted', 'stream' ),
		);
	}

	/**
	 * Return translated context labels
	 *
	 * @return array Context label translations
	 */
	public function get_context_labels() {
		global $wp_post_types;

		$post_types = wp_filter_object_list( $wp_post_types, array(), null, 'label' );
		$post_types = array_diff_key( $post_types, array_flip( $this->get_excluded_post_types() ) );

		add_action( 'registered_post_type', array( $this, '_registered_post_type' ), 10, 2 );

		return $post_types;
	}

	/**
	 * Add action links to Stream drop row in admin list screen
	 *
	 * @filter wp_stream_action_links_{connector}
	 *
	 * @param array $links   Previous links registered
	 * @param Record $record Stream record
	 *
	 * @return array Action links
	 */
	public function action_links( $links, $record ) {
		$post = get_post( $record->object_id );

		if ( $post ) {
			$post_type_name = $this->get_post_type_name( get_post_type( $post->ID ) );

			if ( $view_link = get_permalink( $post->ID ) ) {
				$links[ esc_html__( 'View Post', 'stream' ) ] = $view_link;
			}

			$links[ sprintf( esc_html_x( 'Edit %s', 'Post type singular name', 'stream' ), $post_type_name ) ] = get_edit_post_link( $post->ID );

			$revision_id = absint( $record->get_meta( 'revision_id', true ) );
			if ( $revision_id ) {
				$revisions = wp_get_post_revisions( $post, array( 'check_enabled' => true ) );

				if ( sizeof( $revisions ) > 1 ) {
					$links[ esc_html__( 'Revision', 'stream' ) ] = get_edit_post_link( $revision_id );
				}
			}
		}

		return $links;
	}

	/**
	 * Catch registeration of post_types after initial loading, to cache its labels
	 *
	 * @action registered_post_type
	 *
	 * @param string $post_type Post type slug
	 * @param array  $args      Arguments used to register the post type
	 */
	public function _registered_post_type( $post_type, $args ) {
		unset( $args );

		$post_type_obj = get_post_type_object( $post_type );
		$label         = $post_type_obj->label;

		wp_stream_get_instance()->connectors->term_labels['stream_context'][ $post_type ] = $label;
	}

	/**
	 * Log all post updates
	 *
	 * @param int $post_id
	 * @param \WP_Post $post
	 * @param \WP_Post $post_old
	 */
	public function callback_post_updated( $post_id, $post, $old_post ) {
		if ( in_array( $post->post_type, $this->get_excluded_post_types() ) ) {
			return;
		}

		if ( in_array( $post->post_status, array( 'auto-draft', 'inherit' ) ) ) {
			return;
		} elseif ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( $post->post_status === $old_post->post_status ) {
			$summary = _x(
				'%5$s "%1$s" %2$s updated',
				'1: Post title, 2: Post type singular name, 5: Post status',
				'stream'
			);
		} else {
			$summary = _x(
				'"%1$s" %2$s updated and moved from %6$s to %5$s',
				'1: Post title, 2: Post type singular name, 5: New post status, 6: Old post status',
				'stream'
			);
		}

		if ( 'auto-draft' === $old_post->post_status && 'auto-draft' !== $post->post_status ) {
			$action = 'created';
		} elseif ( 'trash' === $post->post_status ) {
			$action  = 'trashed';
		} elseif ( 'trash' === $old_post->post_status && 'trash' !== $post->post_status ) {
			$action  = 'untrashed';
		}

		if ( empty( $action ) ) {
			$action = 'updated';
		}

		$revision_id = null;

		if ( wp_revisions_enabled( $post ) ) {
			$revision = get_children(
				array(
					'post_type'      => 'revision',
					'post_status'    => 'inherit',
					'post_parent'    => $post_id,
					'posts_per_page' => 1, // VIP safe
					'orderby'        => 'post_date',
					'order'          => 'DESC',
				)
			);

			if ( $revision ) {
				$revision    = array_values( $revision );
				$revision_id = $revision[0]->ID;
			}
		}

		$post_type_name = strtolower( $this->get_post_type_name( $post->post_type ) );

		$this->log(
			$summary,
			array(
				'post_title'    => $post->post_title,
				'singular_name' => $post_type_name,
				'post_date'     => $post->post_date,
				'post_date_gmt' => $post->post_date_gmt,
				'new_status'    => $post->post_status,
				'old_status'    => $old_post->post_status,
				'revision_id'   => $revision_id,
			),
			$post_id,
			$post->post_type,
			$action
		);
	}

	/**
	 * Log post deletion
	 *
	 * @action deleted_post
	 *
	 * $param integer $post_id
	 */
	public function callback_deleted_post( $post_id ) {
		$post = get_post( $post_id );

		// We check if post is an instance of WP_Post as it doesn't always resolve in unit testing
		if ( ! ( $post instanceof \WP_Post ) || in_array( $post->post_type, $this->get_excluded_post_types() )  ) {
			return;
		}

		// Ignore auto-drafts that are deleted by the system, see issue-293
		if ( 'auto-draft' === $post->post_status ) {
			return;
		}

		$post_type_name = strtolower( $this->get_post_type_name( $post->post_type ) );

		$this->log(
			_x(
				'"%1$s" %2$s deleted from trash',
				'1: Post title, 2: Post type singular name',
				'stream'
			),
			array(
				'post_title'    => $post->post_title,
				'singular_name' => $post_type_name,
			),
			$post->ID,
			$post->post_type,
			'deleted'
		);
	}

	/**
	 * Constructs list of excluded post types for the Posts connector
	 *
	 * @return array List of excluded post types
	 */
	public function get_excluded_post_types() {
		return apply_filters(
			'wp_stream_posts_exclude_post_types',
			array(
				'nav_menu_item',
				'attachment',
				'revision',
			)
		);
	}

	/**
	 * Gets the singular post type label
	 *
	 * @param string $post_type_slug
	 *
	 * @return string Post type label
	 */
	public function get_post_type_name( $post_type_slug ) {
		$name = esc_html__( 'Post', 'stream' ); // Default

		if ( post_type_exists( $post_type_slug ) ) {
			$post_type = get_post_type_object( $post_type_slug );
			$name      = $post_type->labels->singular_name;
		}

		return $name;
	}

	/**
	 * Get an adjacent post revision ID
	 *
	 * @param int $revision_id
	 * @param bool $previous
	 *
	 * @return int $revision_id
	 */
	public function get_adjacent_post_revision( $revision_id, $previous = true ) {
		if ( empty( $revision_id ) || ! wp_is_post_revision( $revision_id ) ) {
			return false;
		}

		$revision = wp_get_post_revision( $revision_id );
		$operator = ( $previous ) ? '<' : '>';
		$order    = ( $previous ) ? 'DESC' : 'ASC';

		global $wpdb;

		// @codingStandardsIgnoreStart
		$revision_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID
				FROM $wpdb->posts AS p
				WHERE p.post_date {$operator} %s
					AND p.post_type = 'revision'
					AND p.post_parent = %d
				ORDER BY p.post_date {$order}
				LIMIT 1",
				$revision->post_date,
				$revision->post_parent
			)
		);
		// @codingStandardsIgnoreEnd

		$revision_id = absint( $revision_id );

		if ( ! wp_is_post_revision( $revision_id ) ) {
			return false;
		}

		return $revision_id;
	}
}
