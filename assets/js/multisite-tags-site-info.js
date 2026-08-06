/**
 * Site-info "Multisite Tags" unsaved-changes hint.
 *
 * The tag picker on network/site-info.php feels interactive (add/remove/checklist
 * all run client-side), but nothing persists until the surrounding site-info form
 * is submitted via "Save Changes". This surfaces that: it reveals a notice once the
 * user actually changes a tag, and guards against navigating away unsaved.
 *
 * Detection reads the picker's own state rather than guessing at individual buttons:
 * the flat picker keeps the assigned terms in a hidden textarea (.the-multi-taxonomy)
 * and the hierarchical picker in its checkboxes. We snapshot that on load and compare.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		const picker = $( '#multisite-tax-picker' );
		if ( ! picker.length ) {
			return;
		}

		const notice = $( '.multitax-unsaved-notice' );
		const form = picker.closest( 'form' );
		let submitting = false;

		// A stable string of the currently-assigned terms across every taxonomy in the picker.
		const serialize = () => {
			const flat = picker
				.find( '.the-multi-taxonomy' )
				.map( ( i, el ) => el.value )
				.get()
				.join( '|' );
			const checked = picker
				.find( 'input[type="checkbox"]:checked' )
				.map( ( i, el ) => el.value )
				.get()
				.join( '|' );
			return flat + '||' + checked;
		};

		const initial = serialize();
		const isDirty = () => ! submitting && serialize() !== initial;

		// The picker updates its own state inside its click/keyup/change handlers, so re-check
		// on the next tick to read the value after those handlers have run. Listening on the
		// document rather than the picker also catches the autocomplete menu, which jQuery UI
		// appends to the body: picking a suggestion there assigns a term too.
		$( document ).on( 'click keyup change autocompleteselect', () => {
			window.setTimeout( () => notice.toggle( isDirty() ), 0 );
		} );

		// Submitting the form is the intended save: stop warning about it.
		form.on( 'submit', () => {
			submitting = true;
			notice.hide();
		} );

		$( window ).on( 'beforeunload', ( e ) => {
			if ( ! isDirty() ) {
				return undefined;
			}
			// Browsers show their own generic text; returnValue just has to be set.
			e.preventDefault();
			e.returnValue = '';
			return '';
		} );
	} );
} )( jQuery );
