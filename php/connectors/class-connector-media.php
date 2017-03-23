<?php
namespace WP_Stream;

class Connector_Media extends Connector {
	/**
	 * Connector slug
	 *
	 * @var string
	 */
	public $name = 'media';

	/**
	 * Actions registered for this connector
	 *
	 * @var array
	 */
	public $actions = array(
		'add_attachment',
		'edit_attachment',
		'delete_attachment',
		'wp_save_image_editor_file',
		'wp_save_image_file',
		'transition_attachment_status',
	);

	/**
	 * Return translated connector label
	 *
	 * @return string Translated connector label
	 */
	public function get_label() {
		return esc_html__( 'Media', 'stream' );
	}

	/**
	 * Return translated action labels
	 *
	 * @return array Action label translations
	 */
	public function get_action_labels() {
		return array(
			'attached'    => esc_html__( 'Attached', 'stream' ),
			'uploaded'    => esc_html__( 'Uploaded', 'stream' ),
			'updated'     => esc_html__( 'Updated', 'stream' ),
			'deleted'     => esc_html__( 'Deleted', 'stream' ),
			'assigned'    => esc_html__( 'Assigned', 'stream' ),
			'unassigned'  => esc_html__( 'Unassigned', 'stream' ),
			'killed'      => esc_html__( 'Killed', 'stream' ),
			'resurrected' => esc_html__( 'Resurrected', 'stream' ),
		);
	}

	/**
	 * Return translated context labels
	 *
	 * Based on extension types used by wp_ext2type() in wp-includes/functions.php.
	 *
	 * @return array Context label translations
	 */
	public function get_context_labels() {
		return array(
			'image'       => esc_html__( 'Image', 'stream' ),
			'audio'       => esc_html__( 'Audio', 'stream' ),
			'video'       => esc_html__( 'Video', 'stream' ),
			'document'    => esc_html__( 'Document', 'stream' ),
			'spreadsheet' => esc_html__( 'Spreadsheet', 'stream' ),
			'interactive' => esc_html__( 'Interactive', 'stream' ),
			'text'        => esc_html__( 'Text', 'stream' ),
			'archive'     => esc_html__( 'Archive', 'stream' ),
			'code'        => esc_html__( 'Code', 'stream' ),
		);
	}

	/**
	 * Return the file type for an attachment which corresponds with a context label
	 *
	 * @param object $file_uri URI of the attachment
	 *
	 * @return string A file type which corresponds with a context label
	 */
	public function get_attachment_type( $file_uri ) {
		$extension      = pathinfo( $file_uri, PATHINFO_EXTENSION );
		$extension_type = wp_ext2type( $extension );

		if ( empty( $extension_type ) ) {
			$extension_type = 'document';
		}

		$context_labels = $this->get_context_labels();

		if ( ! isset( $context_labels[ $extension_type ] ) ) {
			$extension_type = 'document';
		}

		return $extension_type;
	}

	/**
	 * Add action links to Stream drop row in admin list screen
	 *
	 * @filter wp_stream_action_links_{connector}
	 *
	 * @param array  $links  Previous links registered
	 * @param object $record Stream record
	 *
	 * @return array Action links
	 */
	public function action_links( $links, $record ) {
		if ( $record->object_id ) {
			if ( $link = get_edit_post_link( $record->object_id ) ) {
				$links[ esc_html__( 'Edit Media', 'stream' ) ] = $link;
			}
			if ( $link = get_permalink( $record->object_id ) ) {
				$links[ esc_html__( 'View', 'stream' ) ] = $link;
			}
		}

		return $links;
	}

	/**
	 * Tracks creation of attachments
	 *
	 * @action add_attachment
	 *
	 * @param int $post_id
	 */
	public function callback_add_attachment( $post_id ) {
		$post = get_post( $post_id );
		if ( $post->post_parent ) {
			$message = _x(
				'Attached "%1$s" to "%2$s"',
				'1: Attachment title, 2: Parent post title',
				'stream'
			);
		} else {
			$message = esc_html__( 'Added "%s" to Media library', 'stream' );
		}

		$name            = $post->post_title;
		$url             = $post->guid;
		$parent_id       = $post->post_parent;
		$parent          = get_post( $parent_id );
		$parent_title    = $parent_id ? $parent->post_title : null;
		$attachment_type = $this->get_attachment_type( $post->guid );

		$this->log(
			$message,
			compact( 'name', 'parent_title', 'parent_id', 'url' ),
			$post_id,
			$attachment_type,
			$post->post_parent ? 'attached' : 'uploaded'
		);
	}

	/**
	 * Tracks editing attachments
	 *
	 * @action edit_attachment
	 *
	 * @param int $post_id
	 */
	public function callback_edit_attachment( $post_id ) {
		$post            = get_post( $post_id );
		$message         = esc_html__( 'Updated "%s"', 'stream' );
		$name            = $post->post_title;
		$attachment_type = $this->get_attachment_type( $post->guid );

		$this->log(
			$message,
			compact( 'name' ),
			$post_id,
			$attachment_type,
			'updated'
		);
	}

	/**
	 * Tracks deletion of attachments
	 *
	 * @action delete_attachment
	 *
	 * @param int $post_id
	 */
	public function callback_delete_attachment( $post_id ) {
		$post            = get_post( $post_id );
		$parent          = $post->post_parent ? get_post( $post->post_parent ) : null;
		$parent_id       = $parent ? $parent->ID : null;
		$message         = esc_html__( 'Deleted "%s"', 'stream' );
		$name            = $post->post_title;
		$url             = $post->guid;
		$attachment_type = $this->get_attachment_type( $post->guid );

		$this->log(
			$message,
			compact( 'name', 'parent_id', 'url' ),
			$post_id,
			$attachment_type,
			'deleted'
		);
	}

	/**
	 * Tracks changes made in the image editor
	 *
	 * @action delete_attachment
	 *
	 * @param string $dummy
	 * @param string $filename
	 * @param string $image
	 * @param string $mime_type
	 * @param int $post_id
	 */
	public function callback_wp_save_image_editor_file( $dummy, $filename, $image, $mime_type, $post_id ) {
		unset( $dummy );
		unset( $image );
		unset( $mime_type );

		$name = basename( $filename );
		$post = get_post( $post_id );

		$attachment_type = $this->get_attachment_type( $post->guid );

		$this->log(
			__( 'Edited image "%s"', 'stream' ),
			compact( 'name', 'filename', 'post_id' ),
			$post_id,
			$attachment_type,
			'edited'
		);
	}

	public function callback_wp_save_image_file( $dummy, $filename, $image, $mime_type, $post_id ) {
		return $this->callback_wp_save_image_editor_file( $dummy, $filename, $image, $mime_type, $post_id );
	}

	/**
	 * Log legal_kill attachment status changes ( killed / resurrected )
	 *
	 * @action transition_attachment_status
	 *
	 * @param mixed    $new  New Post status.
	 * @param mixed    $old  Old post status.
	 * @param \WP_Post $post Post object.
	 */
	public function callback_transition_attachment_status( $new, $old, $post ) {
		$custom_status = 'legal_kill';
		if ( $custom_status === $new && $custom_status !== $old ) {
			$summary = _x(
				'"%1$s" %2$s killed',
				'1: Attachment title, 2: Attachment type',
				'stream'
			);
			$action = 'killed';
		} else if ( 'inherit' === $new && $custom_status === $old ) {
			$summary = _x(
				'"%1$s" %2$s resurrected',
				'1: Attachment title, 2: Attachment type',
				'stream'
			);
			$action = 'resurrected';
		}

		if ( ! isset( $action ) ) {
			return;
		}

		$attachment_type = $this->get_attachment_type( $post->guid );

		$this->log(
			$summary,
			array(
				'post_title'      => $post->post_title,
				'attachment_type' => $attachment_type,
				'post_date'       => $post->post_date,
				'post_date_gmt'   => $post->post_date_gmt,
				'new_status'      => $new,
				'old_status'      => $old,
			),
			$post->ID,
			$attachment_type,
			$action
		);
	}
}
