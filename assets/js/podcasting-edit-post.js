jQuery( document ).ready( function( $ ) {
	$( '#podcasting-enclosure-button' ).click( function( e ) {
		e.preventDefault();

		var $this = $( this ),
			$input = $( 'input#podcasting-enclosure-url' ),
			mediaUploader;
		
		// If the uploader object has already been created, reopen the dialog.
		if ( mediaUploader ) {
			mediaUploader.open();
			return;
		}

		mediaUploader = wp.media.frames.file_frame = wp.media( {
			title: $this.data( 'modalTitle' ),
			button: {
				text: $this.data( 'modalButton' )
			},
			library: {
				type: 'audio'
			},
			multiple: false
		});

		mediaUploader.off( 'select' );
		mediaUploader.on( 'select', function() {
			var attachment = mediaUploader.state().get('selection').first();

			$input.val( attachment.get('url') );
		});

		mediaUploader.open();
	} );
} );