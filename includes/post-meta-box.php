<?php
/**
 * Add a meta box to the post edit screen, plus handlers for saving.
 */

namespace tenup_podcasting;

/**
 * Add a Podcasting metabox to the post edit screen.
 */
function add_podcasting_meta_box() {
	add_meta_box(
		'podcasting',
		__( 'Podcasting' ),
		__NAMESPACE__ . '\meta_box_html',
		'post',
		'advanced',
		'default',
		array(
			'__back_compat_meta_box' => true,
		)
	);
}
add_action( 'add_meta_boxes', __NAMESPACE__ . '\add_podcasting_meta_box' );

/**
 * Output the Podcasting meta box.
 * @param  object WP_Post $post The current post.
 */
function meta_box_html( $post ) {
	$options = wp_parse_args(
		get_post_meta( $post->ID, 'podcast_episode', true ),
		array(
			'closed_captioned'  => 'no',
			'explicit_content'  => 'no',
			'podcast_enclosure' => '',
		)
	);

	wp_nonce_field( plugin_basename( __FILE__ ), 'podcasting' );

	$enclosure_url = ( isset( $options['enclosure']['url'] ) ) ? $options['enclosure']['url'] : '';
	?>
	<p>
		<label for="podcast_closed_captioned">
			<?php esc_html_e( 'Closed Captioned' ); ?>
			<input type="checkbox" id="podcast_closed_captioned" name="podcast_closed_captioned" <?php checked( $options['closed_captioned'], 'yes', false ); ?> />
		</label>
	</p>

	<p>
		<label for="podcast_explicit_content">
			<?php esc_html_e( 'Explicit Content' ); ?>
			<select id="podcast_explicit_content" name="podcast_explicit_content">
				<option value="no"<?php selected( $options['explicit_content'], 'no', false ); ?>><?php esc_html_e( 'No' ); ?></option>
				<option value="yes"<?php selected( $options['explicit_content'], 'yes', false ); ?>><?php esc_html_e( 'Yes' ); ?></option>
				<option value="clean"<?php selected( $options['explicit_content'], 'clean', false ); ?>><?php esc_html_e( 'Clean' ); ?></option>
			</select>
		</label>
	</p>

	<p>
		<label for="podcasting-enclosure-url"><?php esc_html_e( 'Enclosure' ); ?></label>
		<input type="text" id="podcasting-enclosure-url" name="podcast_enclosure_url" value="<?php echo esc_url( $enclosure_url ); ?>" size="35" />
		<input type="button" id="podcasting-enclosure-button" value="<?php echo esc_attr__( 'Choose File' ); ?>" class="button">
	</p>

	<p class="howto"><?php esc_html_e( 'Optional: Use this field if you have more than one audio/video file in your post.' ); ?></p>

	<?php
}

/**
 * Handle the post save event, saving any data from the meta box.
 * @param  [type] $post_id [description]
 * @return [type]          [description]
 */
function save_meta_box( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if (
		! wp_verify_nonce( ( isset( $_POST['podcasting'] ) ? sanitize_key( $_POST['podcasting'] ) : '' ), plugin_basename( __FILE__ ) ) ||
		( isset( $_POST['post_type'] ) && 'post' !== sanitize_text_field( $_POST['post_type'] ) ) ||
		! current_user_can( 'edit_post', $post_id )
	) {
		return;
	}

	$url = false;
	$podcast_options = array(
		'closed_captioned'  => 'no',
		'explicit_content'  => 'no',
		'podcast_enclosure' => '',
	);

	if ( isset( $_POST['podcast_closed_captioned'] ) && 'on' === $_POST['podcast_closed_captioned'] )
		$podcast_options['closed_captioned'] = 'yes';

	if ( isset( $_POST['podcast_explicit_content'] ) && in_array( $_POST['podcast_explicit_content'], array( 'yes', 'no', 'clean' ), true ) )
		$podcast_options['explicit_content'] = sanitize_text_field( $_POST['podcast_explicit_content'] );

	if ( isset( $_POST['podcast_enclosure_url'] ) && ! empty( $_POST['podcast_enclosure_url'] ) ) {
		$url = sanitize_text_field( $_POST['podcast_enclosure_url'] );
	} else {
		// Search for an audio shortcode to determine the audio enclosure url.
		$pattern = get_shortcode_regex();
		$post = get_post( $post_id );

		if (
			preg_match_all( '/'. $pattern .'/s', $post->post_content, $matches )
			&& array_key_exists( 2, $matches )
			&& in_array( 'audio', $matches[2], true )
		) {
			preg_match( '/.*mp3=\\"(.*)\\".*/', $matches[0][0], $matches2 );
			if ( isset( $matches2[1] ) ) {
				$url = $matches2[1];
			}
		}
	}

	/**
	 * Retrieve the enclosure and store its metadata in post meta.
	 *
	 * @todo only retrieve enclosure metadata when a podcasting term id is selected and the url has changed.
	 */
	if ( $url ) {
		// Modeled after WordPress do_enclose()
		$headers = wp_get_http_headers( $url );
		if ( $headers ) {
			if ( ! empty( $headers['location'] ) ) {
				$headers = wp_get_http_headers( $headers['location'] );
			}

			// Grab a temporary copy of the file to determine the audio duration.
			$temp_file = download_url( $url, 30 );
			$meta_data = wp_read_audio_metadata( $temp_file );
			$duration  = isset( $meta_data['length'] ) ? $meta_data['length'] : false;

			$len           = isset( $headers['content-length'] ) ? (int) $headers['content-length'] : 0;
			$type          = isset( $headers['content-type'] )   ? $headers['content-type']         : '';
			$allowed_types = array( 'video', 'audio' );

			// Check to see if we can figure out the mime type from the extension
			$url_parts = wp_parse_url( $url );
			if ( false !== $url_parts ) {
				$extension = pathinfo( $url_parts['path'], PATHINFO_EXTENSION );
				if ( ! empty( $extension ) ) {
					foreach ( wp_get_mime_types() as $exts => $mime ) {
						if ( preg_match( '!^(' . $exts . ')$!i', $extension ) ) {
							$type = $mime;
							break;
						}
					}
				}
			}

			if ( in_array( substr( $type, 0, strpos( $type, '/' ) ), $allowed_types, true ) ) {
				$podcast_options['enclosure'] = array(
					'url'      => esc_url_raw( $url ),
					'length'   => $len,
					'mime'     => $type,
					'duration' => $duration,
				);
			}
		}
	}

	update_post_meta( $post_id, 'podcast_episode', $podcast_options );
}
add_action( 'save_post', __NAMESPACE__ . '\save_meta_box' );

/**
 * Enqueue helper script for the post edit and new post screens.
 *
 * @param  string $hook_suffix The current admin page.
 */
function edit_post_enqueues( $hook_suffix ) {
	$screens = array(
		'post.php',
		'post-new.php'
	);

	if ( ! in_array( $hook_suffix, $screens, true ) ) {
		return;
	}

	wp_enqueue_script(
		'podcasting_edit_post_screen',
		plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/podcasting-edit-post.js',
		array( 'jquery' ),
		'20120911',
		true
	);

	wp_localize_script( 'podcasting_edit_post_screen', 'Podcasting', array(
		'postID'     => get_the_ID(),
		'modalUrl'   => get_media_modal_url(),
	) );
}
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\edit_post_enqueues' );

/**
 * Helper function to retrieve the media URL to use in the post meta box.
 * @return string The constructed media url.
 */
function get_media_modal_url() {
	$post_id = get_the_ID();

	$url = 'media-upload.php';

	$query = array(
		'type'      => 'audio',
		'post_id'   => $post_id,
		'tab'       => 'library',
		'TB_iframe' => 'true',
	);

	$url = add_query_arg( $query, $url );

	return esc_url( $url );
}
