/**
 * Make the "Multisite Tags" meta box collapsible on the user profile screens.
 *
 * On the profile / network user-edit screens the box is rendered via do_meta_boxes(),
 * but core's postbox toggle behaviour is not wired up there. This adds a self-contained
 * toggle (no ajax state saving) and collapses the box by default.
 */
jQuery( function( $ ) {
	var $box = $( '#multsite_taxonomy_meta_box' );

	if ( ! $box.length ) {
		return;
	}

	function setClosed( closed ) {
		$box.toggleClass( 'closed', closed );
		$box.find( '.handlediv' ).attr( 'aria-expanded', closed ? 'false' : 'true' );
	}

	// Collapsed by default.
	setClosed( true );

	// Prefer the whole header (WP 5.5+) so clicking the title or the handle both work;
	// bind to a single element to avoid a bubbling double-toggle.
	var $handle = $box.find( '.postbox-header' );
	if ( ! $handle.length ) {
		$handle = $box.find( '.hndle' );
	}

	$handle.css( 'cursor', 'pointer' ).on( 'click', function( e ) {
		e.preventDefault();
		setClosed( ! $box.hasClass( 'closed' ) );
	} );
} );
