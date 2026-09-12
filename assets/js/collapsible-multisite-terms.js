/**
 * Folds the sub-terms away in the multisite terms list table.
 *
 * Everything this needs is in the markup: Multisite_Terms_List_Table gives each row a level-N
 * class, puts a disclosure button on the rows that have sub-terms on this page, and prints the
 * tree already collapsed. This only flips the hidden attribute.
 */
( function () {
	'use strict';

	var table = document.querySelector( '.wp-list-table.mtax-collapsible' );

	if ( ! table ) {
		return;
	}

	/**
	 * The rows, read fresh every time: Quick Edit replaces the row it saved, so a row held on to
	 * from page load can be one that is no longer in the table.
	 *
	 * @return {Array} The rows of the table body, in document order.
	 */
	function currentRows() {
		return Array.prototype.slice.call( table.querySelectorAll( 'tbody > tr' ) );
	}

	/**
	 * The depth of a row.
	 *
	 * @param {HTMLTableRowElement} row A row of the list table.
	 * @return {number} Depth, 0 for a top-level term.
	 */
	function levelOf( row ) {
		var match = /(?:^|\s)level-(\d+)/.exec( row.className );

		return match ? parseInt( match[ 1 ], 10 ) : 0;
	}

	/**
	 * The rows nested under a row, however deep, as far as this page goes.
	 *
	 * @param {Array}  rows  All rows of the table body.
	 * @param {number} index Position of the row in `rows`.
	 * @return {Array} The descendant rows, outermost first.
	 */
	function descendantsOf( rows, index ) {
		var level = levelOf( rows[ index ] );
		var found = [];
		var i;

		for ( i = index + 1; i < rows.length && levelOf( rows[ i ] ) > level; i++ ) {
			found.push( rows[ i ] );
		}

		return found;
	}

	/**
	 * Open or close one row's sub-terms.
	 *
	 * Opening only brings back the direct children: anything deeper stays folded under the child
	 * it belongs to, so a subtree opens one level per click. Closing folds the whole subtree and
	 * resets it, so re-opening starts from the same collapsed state.
	 *
	 * @param {Array}   rows  All rows of the table body.
	 * @param {number}  index Position of the row in `rows`.
	 * @param {boolean} open  Whether the sub-terms should end up visible.
	 * @return {void}
	 */
	function setOpen( rows, index, open ) {
		var toggle = rows[ index ].querySelector( '.mtax-term-toggle' );
		var level = levelOf( rows[ index ] );

		if ( ! toggle ) {
			return;
		}

		toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );

		descendantsOf( rows, index ).forEach( function ( row ) {
			var nested = row.querySelector( '.mtax-term-toggle' );

			row.hidden = ! open || levelOf( row ) !== level + 1;

			if ( ! open && nested ) {
				nested.setAttribute( 'aria-expanded', 'false' );
			}
		} );
	}

	table.addEventListener( 'click', function ( event ) {
		var rows = currentRows();
		var row = event.target.closest( 'tbody > tr' );
		var index = row ? rows.indexOf( row ) : -1;
		var toggle = row ? row.querySelector( '.mtax-term-toggle' ) : null;

		if ( -1 === index || ! toggle ) {
			return;
		}

		/*
		 * The whole row folds, so that a tree this wide can be walked without aiming at the arrow.
		 * Anything with a behaviour of its own — the bulk checkbox, the term link, the row actions
		 * — keeps it; the toggle itself is let through because that is the one control that folds.
		 */
		if ( ! event.target.closest( '.mtax-term-toggle' ) && event.target.closest( 'a, input, label, button, select, textarea' ) ) {
			return;
		}

		setOpen( rows, index, 'true' !== toggle.getAttribute( 'aria-expanded' ) );
	} );

	var toggleAll = document.querySelector( '.mtax-toggle-all' );

	if ( toggleAll ) {
		toggleAll.addEventListener( 'click', function () {
			var open = 'true' !== toggleAll.getAttribute( 'aria-expanded' );

			toggleAll.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			toggleAll.textContent = toggleAll.dataset[ open ? 'collapseLabel' : 'expandLabel' ];

			currentRows().forEach( function ( row ) {
				var toggle = row.querySelector( '.mtax-term-toggle' );

				row.hidden = ! open && levelOf( row ) > 0;

				if ( toggle ) {
					toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				}
			} );
		} );
	}
}() );
