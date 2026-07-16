/* global showNotice, validateForm */
/**
 * Term screen behaviour for the network multisite-taxonomy list tables.
 *
 * Adding and deleting terms deliberately run as ordinary requests rather than ajax.
 * Multitaxo_Plugin::load_multisite_taxonomy() already handles `add-multisite-tag`, `delete` and
 * `bulk-delete` server-side and redirects back to the list (POST/redirect/GET), so the table is
 * always re-rendered from the database. That matters here because these lists are sorted,
 * paginated and filtered: splicing a row in on the client dropped new terms at the top out of
 * order, showed terms the current view filters out, and silently did nothing whenever the
 * returned markup did not line up. Letting the server render is also the only thing that stays
 * correct when terms are added or removed from another context, and it keeps working when the
 * ajax layer (or JS entirely) fails.
 *
 * This file only layers the client-side niceties on top of that flow: validate before an add,
 * confirm before a delete.
 */

jQuery( document ).ready( function( $ ) {

	/**
	 * Confirm before following a row-action delete link.
	 *
	 * Returning false cancels the navigation; otherwise the link is followed normally and the
	 * server deletes the term and redirects back to a freshly rendered list.
	 */
	$( '#the-list' ).on( 'click', '.delete-multisite-term', function() {
		if ( 'undefined' === typeof showNotice ) {
			return true;
		}

		return showNotice.warn();
	} );

	/**
	 * The same confirmation for the delete link on the single term edit screen.
	 */
	$( '#edittag' ).on( 'click', '.delete', function( e ) {
		if ( 'undefined' === typeof showNotice ) {
			return true;
		}

		if ( ! showNotice.warn() ) {
			e.preventDefault();
		}
	} );

	/**
	 * Validate the add form before it submits natively. Returning false keeps the user on the
	 * screen with the offending fields flagged; anything the client cannot catch is reported by
	 * the server on the redirect.
	 */
	$( '#addtag' ).on( 'submit', function() {
		return validateForm( $( this ) );
	} );

} );
